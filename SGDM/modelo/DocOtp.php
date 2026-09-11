<?php
require_once __DIR__ . '/OtpModel.php';

/**
 * Códigos OTP de un solo uso para ver un documento restringido.
 *
 * Mismo diseño de seguridad que el 2FA del login (OtpModel), pero en su propia
 * tabla `doc_otps` y con clave por (usuario, materia), para no interferir con el
 * segundo factor del inicio de sesión. Toda la lógica vive en OtpModel; acá solo
 * se fija la tabla y se traducen los parámetros a la clave.
 */
class DocOtp extends OtpModel {

    protected string $table = 'doc_otps';

    /** Clave del código de documentación: usuario + materia. */
    private function clave(int $usuarioId, string $materia): array {
        return ['usuario_id' => $usuarioId, 'materia' => $materia];
    }

    /** Genera un código para (usuario, materia) y lo devuelve en claro. */
    public function generar(int $usuarioId, string $materia): string {
        return $this->generarCodigo($this->clave($usuarioId, $materia));
    }

    /**
     * Verifica el código presentado. Si es correcto, lo consume.
     *
     * @param string|null $error Recibe el mensaje para el usuario si falla.
     */
    public function verificar(int $usuarioId, string $materia, string $codigo, ?string &$error = null): bool {
        return $this->verificarCodigo($this->clave($usuarioId, $materia), $codigo, $error);
    }

    /** Elimina el código activo (consumido, expirado o cancelado). */
    public function invalidar(int $usuarioId, string $materia): void {
        $this->invalidarCodigo($this->clave($usuarioId, $materia));
    }

    /** Indica si hay un código vigente (existe y no expiró). */
    public function activo(int $usuarioId, string $materia): bool {
        return $this->activoDeClave($this->clave($usuarioId, $materia));
    }

    /** Segundos que faltan para poder reenviar (0 si ya se puede). */
    public function cooldownRestante(int $usuarioId, string $materia): int {
        return $this->cooldownDeClave($this->clave($usuarioId, $materia));
    }
}
