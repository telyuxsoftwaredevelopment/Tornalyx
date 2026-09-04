<?php
require_once __DIR__ . '/Model.php';

/**
 * Códigos OTP de un solo uso para ver un documento restringido.
 *
 * Mismo diseño de seguridad que OtpCode (login 2FA) pero en su propia tabla
 * `doc_otps`, con clave por (usuario, materia), para no interferir con el
 * segundo factor del inicio de sesión:
 *   - el código se guarda hasheado (nunca en claro);
 *   - vence a los 10 minutos;
 *   - se invalida tras MAX_ATTEMPTS verificaciones fallidas;
 *   - reenvío con un enfriamiento mínimo entre correos.
 */
class DocOtp extends Model {

    protected string $table = 'doc_otps';

    /** Vigencia del código en segundos (10 minutos). */
    private const TTL_SECONDS = 600;

    /** Verificaciones fallidas antes de invalidar el código. */
    private const MAX_ATTEMPTS = 5;

    /** Segundos mínimos entre reenvíos. */
    private const RESEND_COOLDOWN = 60;

    /**
     * Genera un código de 6 dígitos para (usuario, materia), lo guarda hasheado
     * (reemplazando cualquier anterior) y lo devuelve en claro para enviarlo.
     */
    public function generar(int $usuarioId, string $materia): string {
        $codigo  = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash    = password_hash($codigo, PASSWORD_BCRYPT, ['cost' => 10]);
        $expires = date('Y-m-d H:i:s', time() + self::TTL_SECONDS);

        $stmt = $this->db->prepare(
            'INSERT INTO doc_otps (usuario_id, materia, code_hash, expires_at, attempts, last_sent_at)
                  VALUES (?, ?, ?, ?, 0, NOW())
             ON DUPLICATE KEY UPDATE
                  code_hash    = VALUES(code_hash),
                  expires_at   = VALUES(expires_at),
                  attempts     = 0,
                  last_sent_at = NOW()'
        );
        $stmt->execute([$usuarioId, $materia, $hash, $expires]);

        return $codigo;
    }

    /**
     * Verifica el código presentado. Si es correcto, lo consume.
     *
     * @param string|null $error Recibe el mensaje para el usuario si falla.
     */
    public function verificar(int $usuarioId, string $materia, string $codigo, ?string &$error = null): bool {
        $stmt = $this->db->prepare(
            'SELECT code_hash, attempts, UNIX_TIMESTAMP(expires_at) AS exp
               FROM doc_otps WHERE usuario_id = ? AND materia = ?'
        );
        $stmt->execute([$usuarioId, $materia]);
        $row = $stmt->fetch();

        if (!$row) {
            $error = 'No hay un código activo. Solicitá uno nuevo.';
            return false;
        }
        if (time() > (int) $row['exp']) {
            $this->invalidar($usuarioId, $materia);
            $error = 'El código expiró. Solicitá uno nuevo.';
            return false;
        }
        if ((int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            $this->invalidar($usuarioId, $materia);
            $error = 'Demasiados intentos. Solicitá un código nuevo.';
            return false;
        }

        if (!password_verify($codigo, $row['code_hash'])) {
            $this->db->prepare('UPDATE doc_otps SET attempts = attempts + 1 WHERE usuario_id = ? AND materia = ?')
                     ->execute([$usuarioId, $materia]);
            $restantes = self::MAX_ATTEMPTS - ((int) $row['attempts'] + 1);
            $error = $restantes > 0
                ? "Código incorrecto. Intentos restantes: {$restantes}."
                : 'Código incorrecto. Solicitá un código nuevo.';
            if ($restantes <= 0) {
                $this->invalidar($usuarioId, $materia);
            }
            return false;
        }

        $this->invalidar($usuarioId, $materia);
        return true;
    }

    /** Elimina el código activo (consumido, expirado o cancelado). */
    public function invalidar(int $usuarioId, string $materia): void {
        $this->db->prepare('DELETE FROM doc_otps WHERE usuario_id = ? AND materia = ?')
                 ->execute([$usuarioId, $materia]);
    }

    /** Indica si hay un código vigente (existe y no expiró). */
    public function activo(int $usuarioId, string $materia): bool {
        $stmt = $this->db->prepare(
            'SELECT UNIX_TIMESTAMP(expires_at) AS exp FROM doc_otps WHERE usuario_id = ? AND materia = ?'
        );
        $stmt->execute([$usuarioId, $materia]);
        $row = $stmt->fetch();
        return $row && time() <= (int) $row['exp'];
    }

    /** Segundos que faltan para poder reenviar (0 si ya se puede). */
    public function cooldownRestante(int $usuarioId, string $materia): int {
        $stmt = $this->db->prepare(
            'SELECT UNIX_TIMESTAMP(last_sent_at) AS t FROM doc_otps WHERE usuario_id = ? AND materia = ?'
        );
        $stmt->execute([$usuarioId, $materia]);
        $row = $stmt->fetch();
        if (!$row) {
            return 0;
        }
        return max(0, self::RESEND_COOLDOWN - (time() - (int) $row['t']));
    }
}
