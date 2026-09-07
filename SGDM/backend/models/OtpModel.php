<?php
require_once __DIR__ . '/Model.php';

/**
 * Base común de los códigos de verificación de un solo uso (OTP) por email.
 *
 * El segundo factor del login (OtpCode / login_otps) y el acceso a la
 * documentación restringida (DocOtp / doc_otps) comparten exactamente el mismo
 * diseño de seguridad; lo único que cambia es la CLAVE que identifica a cada
 * código: el login usa solo el usuario, y la documentación usa (usuario, materia).
 *
 * Por eso la lógica vive acá una sola vez, parametrizada por esa clave, y cada
 * subclase se limita a fijar su tabla y a traducir sus parámetros públicos a la
 * clave correspondiente. Defensas (idénticas en ambos casos):
 *   - el código se guarda hasheado (nunca en claro);
 *   - vence a los 10 minutos;
 *   - se invalida tras MAX_ATTEMPTS verificaciones fallidas;
 *   - reenvío con un enfriamiento mínimo entre correos.
 *
 * La "clave" es un mapa columna => valor (p. ej. ['usuario_id' => 7] o
 * ['usuario_id' => 7, 'materia' => 'ciberseguridad']). Los NOMBRES de columna
 * son identificadores fijos que define la subclase, nunca entran del usuario:
 * por eso es seguro interpolarlos en el SQL. Los VALORES siempre van por
 * prepared statement.
 */
abstract class OtpModel extends Model {

    /** Vigencia del código en segundos (10 minutos). */
    protected const TTL_SECONDS = 600;

    /** Verificaciones fallidas antes de invalidar el código. */
    protected const MAX_ATTEMPTS = 5;

    /** Segundos mínimos entre reenvíos de código. */
    protected const RESEND_COOLDOWN = 60;

    /**
     * Genera un código de 6 dígitos para la clave dada, lo persiste hasheado
     * (reemplazando cualquier código previo de esa misma clave) y lo devuelve en
     * claro para poder enviarlo por correo.
     *
     * @param array<string,mixed> $clave columna => valor que identifica el código.
     */
    protected function generarCodigo(array $clave): string {
        $codigo  = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash    = password_hash($codigo, PASSWORD_BCRYPT, ['cost' => 10]);
        $expires = date('Y-m-d H:i:s', time() + self::TTL_SECONDS);

        // Columnas de clave + las fijas del código. attempts y last_sent_at son
        // literales (0 y NOW()), no placeholders, igual que en el SQL original.
        $cols = implode(', ', array_merge(array_keys($clave), ['code_hash', 'expires_at', 'attempts', 'last_sent_at']));
        $vals = implode(', ', array_merge(array_fill(0, count($clave), '?'), ['?', '?', '0', 'NOW()']));

        $stmt = $this->db->prepare(
            "INSERT INTO {$this->table} ({$cols})
                  VALUES ({$vals})
             ON DUPLICATE KEY UPDATE
                  code_hash    = VALUES(code_hash),
                  expires_at   = VALUES(expires_at),
                  attempts     = 0,
                  last_sent_at = NOW()"
        );
        $stmt->execute(array_merge(array_values($clave), [$hash, $expires]));

        return $codigo;
    }

    /**
     * Verifica el código presentado para una clave. Si es correcto, lo consume.
     *
     * @param array<string,mixed> $clave  columna => valor que identifica el código.
     * @param string|null         $error  Recibe el mensaje para el usuario si falla.
     * @return bool true si el código es válido (y queda consumido).
     */
    protected function verificarCodigo(array $clave, string $codigo, ?string &$error = null): bool {
        $where = $this->whereDeClave($clave);

        $stmt = $this->db->prepare(
            "SELECT code_hash, attempts, UNIX_TIMESTAMP(expires_at) AS exp
               FROM {$this->table} WHERE {$where}"
        );
        $stmt->execute(array_values($clave));
        $row = $stmt->fetch();

        if (!$row) {
            $error = 'No hay un código activo. Solicitá uno nuevo.';
            return false;
        }
        if (time() > (int) $row['exp']) {
            $this->invalidarCodigo($clave);
            $error = 'El código expiró. Solicitá uno nuevo.';
            return false;
        }
        if ((int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            $this->invalidarCodigo($clave);
            $error = 'Demasiados intentos. Solicitá un código nuevo.';
            return false;
        }

        if (!password_verify($codigo, $row['code_hash'])) {
            $this->db->prepare("UPDATE {$this->table} SET attempts = attempts + 1 WHERE {$where}")
                     ->execute(array_values($clave));
            $restantes = self::MAX_ATTEMPTS - ((int) $row['attempts'] + 1);
            $error = $restantes > 0
                ? "Código incorrecto. Intentos restantes: {$restantes}."
                : 'Código incorrecto. Solicitá un código nuevo.';
            if ($restantes <= 0) {
                $this->invalidarCodigo($clave);
            }
            return false;
        }

        // Éxito: consumir el código para que no pueda reutilizarse.
        $this->invalidarCodigo($clave);
        return true;
    }

    /**
     * Elimina el código activo de una clave (consumido, expirado o cancelado).
     *
     * @param array<string,mixed> $clave columna => valor que identifica el código.
     */
    protected function invalidarCodigo(array $clave): void {
        $this->db->prepare("DELETE FROM {$this->table} WHERE {$this->whereDeClave($clave)}")
                 ->execute(array_values($clave));
    }

    /**
     * Indica si hay un código vigente para la clave (existe y no expiró).
     *
     * @param array<string,mixed> $clave columna => valor que identifica el código.
     */
    protected function activoDeClave(array $clave): bool {
        $stmt = $this->db->prepare(
            "SELECT UNIX_TIMESTAMP(expires_at) AS exp FROM {$this->table} WHERE {$this->whereDeClave($clave)}"
        );
        $stmt->execute(array_values($clave));
        $row = $stmt->fetch();
        return $row && time() <= (int) $row['exp'];
    }

    /**
     * Segundos que faltan para poder reenviar un código a esa clave (0 si ya se
     * puede).
     *
     * @param array<string,mixed> $clave columna => valor que identifica el código.
     */
    protected function cooldownDeClave(array $clave): int {
        $stmt = $this->db->prepare(
            "SELECT UNIX_TIMESTAMP(last_sent_at) AS t FROM {$this->table} WHERE {$this->whereDeClave($clave)}"
        );
        $stmt->execute(array_values($clave));
        $row = $stmt->fetch();
        if (!$row) {
            return 0;
        }
        return max(0, self::RESEND_COOLDOWN - (time() - (int) $row['t']));
    }

    /**
     * Arma el "col1 = ? AND col2 = ?" a partir de las columnas de la clave.
     * Las columnas son identificadores fijos de la subclase (no entran del
     * usuario), así que su interpolación en el SQL es segura.
     *
     * @param array<string,mixed> $clave
     */
    private function whereDeClave(array $clave): string {
        return implode(' AND ', array_map(
            static fn(string $columna): string => "{$columna} = ?",
            array_keys($clave)
        ));
    }
}
