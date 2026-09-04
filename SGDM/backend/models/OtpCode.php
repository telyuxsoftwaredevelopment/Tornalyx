<?php
require_once __DIR__ . '/Model.php';

/**
 * Gestiona los códigos de verificación de un solo uso (OTP) para el segundo
 * factor por email. Mantiene como mucho un código activo por usuario en la
 * tabla `login_otps`.
 *
 * Defensas del paso 2FA (independientes del LoginThrottle del paso 1):
 *   - el código se guarda hasheado (nunca en claro);
 *   - vence a los 10 minutos;
 *   - se invalida tras MAX_ATTEMPTS verificaciones fallidas;
 *   - reenvío con un enfriamiento mínimo entre correos.
 */
class OtpCode extends Model {

    protected string $table = 'login_otps';

    /** Vigencia del código en segundos (10 minutos). */
    private const TTL_SECONDS = 600;

    /** Verificaciones fallidas antes de invalidar el código. */
    private const MAX_ATTEMPTS = 5;

    /** Segundos mínimos entre reenvíos de código. */
    private const RESEND_COOLDOWN = 60;

    /**
     * Genera un código nuevo de 6 dígitos para el usuario, lo persiste hasheado
     * (reemplazando cualquier código previo) y lo devuelve en claro para poder
     * enviarlo por correo.
     */
    public function generar(int $usuarioId): string {
        $codigo  = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash    = password_hash($codigo, PASSWORD_BCRYPT, ['cost' => 10]);
        $expires = date('Y-m-d H:i:s', time() + self::TTL_SECONDS);

        $stmt = $this->db->prepare(
            'INSERT INTO login_otps (usuario_id, code_hash, expires_at, attempts, last_sent_at)
                  VALUES (?, ?, ?, 0, NOW())
             ON DUPLICATE KEY UPDATE
                  code_hash    = VALUES(code_hash),
                  expires_at   = VALUES(expires_at),
                  attempts     = 0,
                  last_sent_at = NOW()'
        );
        $stmt->execute([$usuarioId, $hash, $expires]);

        return $codigo;
    }

    /**
     * Verifica el código presentado por el usuario.
     *
     * @param string|null $error  Recibe el mensaje para el usuario si falla.
     * @return bool true si el código es válido (y queda consumido).
     */
    public function verificar(int $usuarioId, string $codigo, ?string &$error = null): bool {
        $stmt = $this->db->prepare(
            'SELECT code_hash, attempts, UNIX_TIMESTAMP(expires_at) AS exp
               FROM login_otps WHERE usuario_id = ?'
        );
        $stmt->execute([$usuarioId]);
        $row = $stmt->fetch();

        if (!$row) {
            $error = 'No hay un código activo. Solicitá uno nuevo.';
            return false;
        }
        if (time() > (int) $row['exp']) {
            $this->invalidar($usuarioId);
            $error = 'El código expiró. Solicitá uno nuevo.';
            return false;
        }
        if ((int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            $this->invalidar($usuarioId);
            $error = 'Demasiados intentos. Solicitá un código nuevo.';
            return false;
        }

        if (!password_verify($codigo, $row['code_hash'])) {
            $this->db->prepare('UPDATE login_otps SET attempts = attempts + 1 WHERE usuario_id = ?')
                     ->execute([$usuarioId]);
            $restantes = self::MAX_ATTEMPTS - ((int) $row['attempts'] + 1);
            $error = $restantes > 0
                ? "Código incorrecto. Intentos restantes: {$restantes}."
                : 'Código incorrecto. Solicitá un código nuevo.';
            if ($restantes <= 0) {
                $this->invalidar($usuarioId);
            }
            return false;
        }

        // Éxito: consumir el código para que no pueda reutilizarse.
        $this->invalidar($usuarioId);
        return true;
    }

    /**
     * Elimina el código activo del usuario (consumido, expirado o cancelado).
     */
    public function invalidar(int $usuarioId): void {
        $this->db->prepare('DELETE FROM login_otps WHERE usuario_id = ?')->execute([$usuarioId]);
    }

    /**
     * Segundos que faltan para poder reenviar (0 si ya se puede).
     */
    public function cooldownRestante(int $usuarioId): int {
        $stmt = $this->db->prepare(
            'SELECT UNIX_TIMESTAMP(last_sent_at) AS t FROM login_otps WHERE usuario_id = ?'
        );
        $stmt->execute([$usuarioId]);
        $row = $stmt->fetch();
        if (!$row) {
            return 0;
        }
        $elapsed = time() - (int) $row['t'];
        return max(0, self::RESEND_COOLDOWN - $elapsed);
    }
}
