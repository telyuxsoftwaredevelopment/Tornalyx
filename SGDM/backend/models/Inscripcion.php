<?php
require_once __DIR__ . '/Model.php';

/**
 * Modelo de Inscripción: vincula un usuario (solo o con equipo) a un torneo.
 */
class Inscripcion extends Model {

    protected string $table = 'inscripciones';

    /**
     * Inscribe a un usuario en un torneo.
     *
     * @param int      $usuarioId
     * @param int      $torneoId
     * @param int|null $equipoId  Null en torneos individuales.
     * @param string   $estado    'pendiente' o 'aprobada'.
     * @return int ID de la inscripción.
     */
    public function inscribir(int $usuarioId, int $torneoId, ?int $equipoId, string $estado = 'pendiente'): int {
        return $this->insert([
            'usuario_id' => $usuarioId,
            'torneo_id'  => $torneoId,
            'equipo_id'  => $equipoId,
            'estado'     => $estado,
        ]);
    }

    /**
     * Inscripción de un usuario en un torneo, si existe.
     *
     * @return array|null
     */
    public function deUsuario(int $usuarioId, int $torneoId): ?array {
        $stmt = $this->db->prepare(
            'SELECT * FROM inscripciones WHERE usuario_id = ? AND torneo_id = ? LIMIT 1'
        );
        $stmt->execute([$usuarioId, $torneoId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Inscripciones de un torneo con los datos del participante y su equipo,
     * para la tabla de gestión del organizador.
     *
     * @return array
     */
    public function listarPorTorneo(int $torneoId): array {
        $stmt = $this->db->prepare(
            'SELECT i.id, i.estado, i.fecha_inscripcion, i.usuario_id, i.equipo_id,
                    u.nombre, u.apellido, u.email, u.avatar_url,
                    e.nombre AS equipo
               FROM inscripciones i
               INNER JOIN usuarios u ON u.id = i.usuario_id
               LEFT JOIN equipos  e ON e.id = i.equipo_id
              WHERE i.torneo_id = ?
              ORDER BY i.fecha_inscripcion ASC'
        );
        $stmt->execute([$torneoId]);
        return $stmt->fetchAll();
    }

    /**
     * Cambia el estado de una inscripción ('aprobada' | 'rechazada').
     */
    public function resolver(int $id, string $estado): bool {
        return $this->update($id, ['estado' => $estado]);
    }

    /**
     * Cantidad de inscripciones aprobadas de un torneo (control de cupo).
     */
    public function contarAprobadas(int $torneoId): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM inscripciones WHERE torneo_id = ? AND estado = 'aprobada'"
        );
        $stmt->execute([$torneoId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * IDs de los usuarios aprobados de un torneo individual, en orden de
     * inscripción: son los contendientes del fixture.
     *
     * @return int[]
     */
    public function idsAprobados(int $torneoId): array {
        $stmt = $this->db->prepare(
            "SELECT usuario_id FROM inscripciones
              WHERE torneo_id = ? AND estado = 'aprobada'
              ORDER BY fecha_inscripcion ASC"
        );
        $stmt->execute([$torneoId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Usuarios que juegan un partido, para saber quién puede confirmar
     * asistencia: en torneos individuales son los dos contendientes; en
     * torneos por equipos, los inscriptos de ambos equipos.
     *
     * @return int[]
     */
    public function usuariosDePartido(int $partidoId): array {
        $stmt = $this->db->prepare(
            'SELECT local_id, visitante_id, tipo_contendiente FROM partidos WHERE id = ?'
        );
        $stmt->execute([$partidoId]);
        $p = $stmt->fetch();
        if (!$p) {
            return [];
        }

        $lados = [(int) $p['local_id'], (int) $p['visitante_id']];
        if ($p['tipo_contendiente'] === 'usuario') {
            return $lados;
        }

        $stmt = $this->db->prepare(
            'SELECT DISTINCT usuario_id FROM inscripciones WHERE equipo_id IN (?, ?)'
        );
        $stmt->execute($lados);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
