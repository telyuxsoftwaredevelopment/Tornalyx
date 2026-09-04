<?php
require_once __DIR__ . '/Model.php';

/**
 * Autorización de acceso a documentación restringida.
 *
 * Cada fila representa la solicitud de un usuario para una materia sensible
 * (p. ej. 'ciberseguridad') y su estado: pendiente, aprobado o rechazado. Un
 * administrador la resuelve desde el panel. El flujo es "el usuario solicita →
 * el admin aprueba".
 */
class DocAcceso extends Model {

    protected string $table = 'doc_acceso';

    /**
     * Estado de autorización de un usuario para una materia.
     *
     * @return string|null 'pendiente'|'aprobado'|'rechazado', o null si nunca solicitó.
     */
    public function estado(int $usuarioId, string $materia): ?string {
        $stmt = $this->db->prepare(
            'SELECT estado FROM doc_acceso WHERE usuario_id = ? AND materia = ?'
        );
        $stmt->execute([$usuarioId, $materia]);
        $row = $stmt->fetch();
        return $row ? (string) $row['estado'] : null;
    }

    /** Vigencia del token de aprobación por email, en segundos (7 días). */
    private const TOKEN_TTL = 604800;

    /**
     * Registra (o reabre) una solicitud de acceso y genera el token de aprobación
     * por email. Si el usuario ya está aprobado, no hace nada y devuelve null;
     * en cualquier otro caso deja la solicitud pendiente con un token nuevo y
     * devuelve el token EN CLARO (para armar el enlace del correo al aprobador).
     */
    public function solicitar(int $usuarioId, string $materia): ?string {
        if ($this->estado($usuarioId, $materia) === 'aprobado') {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $token);
        $exp   = date('Y-m-d H:i:s', time() + self::TOKEN_TTL);

        $stmt = $this->db->prepare(
            'INSERT INTO doc_acceso
                  (usuario_id, materia, estado, solicitado_at, aprob_token_hash, aprob_token_exp)
                  VALUES (?, ?, \'pendiente\', NOW(), ?, ?)
             ON DUPLICATE KEY UPDATE
                  estado           = \'pendiente\',
                  solicitado_at    = NOW(),
                  resuelto_at      = NULL,
                  resuelto_por     = NULL,
                  aprob_token_hash = VALUES(aprob_token_hash),
                  aprob_token_exp  = VALUES(aprob_token_exp)'
        );
        $stmt->execute([$usuarioId, $materia, $hash, $exp]);

        return $token;
    }

    /**
     * Busca la solicitud asociada a un token de aprobación (por su hash), siempre
     * que el token no haya expirado. Devuelve la fila con los datos del usuario
     * para mostrarlos en la pantalla de aprobación, o null si no es válido.
     *
     * @return array<string,mixed>|null
     */
    public function buscarPorToken(string $token): ?array {
        if ($token === '') {
            return null;
        }
        $hash = hash('sha256', $token);
        $stmt = $this->db->prepare(
            'SELECT a.id, a.usuario_id, a.materia, a.estado,
                    UNIX_TIMESTAMP(a.aprob_token_exp) AS exp,
                    u.nombre, u.apellido, u.email
               FROM doc_acceso a
               JOIN usuarios u ON u.id = a.usuario_id
              WHERE a.aprob_token_hash = ?'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if ($row['exp'] !== null && time() > (int) $row['exp']) {
            return null;
        }
        return $row;
    }

    /**
     * Resuelve una solicitud a partir de su token de aprobación (enlace del
     * correo): la aprueba o la rechaza y consume el token (un solo uso). Devuelve
     * la fila con el nuevo estado, o null si el token o la acción son inválidos.
     *
     * @return array<string,mixed>|null
     */
    public function resolverPorToken(string $token, string $accion): ?array {
        $estado = match ($accion) {
            'aprobar'  => 'aprobado',
            'rechazar' => 'rechazado',
            default    => null,
        };
        if ($estado === null) {
            return null;
        }

        $row = $this->buscarPorToken($token);
        if ($row === null) {
            return null;
        }

        $stmt = $this->db->prepare(
            'UPDATE doc_acceso
                SET estado = ?, resuelto_at = NOW(),
                    aprob_token_hash = NULL, aprob_token_exp = NULL
              WHERE id = ?'
        );
        $stmt->execute([$estado, $row['id']]);

        $row['estado'] = $estado;
        return $row;
    }

    /**
     * Lista las solicitudes con los datos del usuario, para el panel de admin.
     * Ordena primero las pendientes y, dentro de cada grupo, las más nuevas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listarSolicitudes(): array {
        $sql = 'SELECT a.id, a.usuario_id, a.materia, a.estado,
                       a.solicitado_at, a.resuelto_at,
                       u.nombre, u.apellido, u.email
                  FROM doc_acceso a
                  JOIN usuarios u ON u.id = a.usuario_id
              ORDER BY (a.estado = \'pendiente\') DESC, a.solicitado_at DESC';
        return $this->db->query($sql)->fetchAll();
    }

    /**
     * Resuelve una solicitud por su id: la aprueba o la rechaza y registra quién
     * y cuándo. Devuelve el nuevo estado, o null si la acción es inválida.
     */
    public function resolverPorId(int $id, string $accion, int $adminId): ?string {
        $estado = match ($accion) {
            'aprobar'  => 'aprobado',
            'rechazar' => 'rechazado',
            default    => null,
        };
        if ($estado === null) {
            return null;
        }
        $stmt = $this->db->prepare(
            'UPDATE doc_acceso
                SET estado = ?, resuelto_at = NOW(), resuelto_por = ?
              WHERE id = ?'
        );
        $stmt->execute([$estado, $adminId, $id]);
        return $estado;
    }
}
