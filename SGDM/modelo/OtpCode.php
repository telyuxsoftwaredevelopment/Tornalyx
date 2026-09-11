<?php
require_once __DIR__ . '/OtpModel.php';

/**
 * Códigos de verificación de un solo uso (OTP) para el segundo factor por email
 * del inicio de sesión. Se identifican solo por usuario: hay como mucho un
 * código activo por usuario en la tabla `login_otps`.
 *
 * Toda la lógica (generación, verificación, invalidación, enfriamiento) vive en
 * OtpModel; acá solo se fija la tabla y se traducen los parámetros a la clave.
 */
class OtpCode extends OtpModel {

    protected string $table = 'login_otps';

    /** Clave del código de login: únicamente el usuario. */
    private function clave(int $usuarioId): array {
        return ['usuario_id' => $usuarioId];
    }

    /** Genera un código nuevo para el usuario y lo devuelve en claro. */
    public function generar(int $usuarioId): string {
        return $this->generarCodigo($this->clave($usuarioId));
    }

    /**
     * Verifica el código presentado por el usuario.
     *
     * @param string|null $error Recibe el mensaje para el usuario si falla.
     * @return bool true si el código es válido (y queda consumido).
     */
    public function verificar(int $usuarioId, string $codigo, ?string &$error = null): bool {
        return $this->verificarCodigo($this->clave($usuarioId), $codigo, $error);
    }

    /** Elimina el código activo del usuario (consumido, expirado o cancelado). */
    public function invalidar(int $usuarioId): void {
        $this->invalidarCodigo($this->clave($usuarioId));
    }

    /** Segundos que faltan para poder reenviar (0 si ya se puede). */
    public function cooldownRestante(int $usuarioId): int {
        return $this->cooldownDeClave($this->clave($usuarioId));
    }
}
