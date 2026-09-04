<?php
require_once __DIR__ . '/Model.php';

/**
 * Modelo de Equipo. Los equipos son por torneo: un jugador crea el equipo
 * (queda como capitán) o se suma a uno existente al inscribirse.
 */
class Equipo extends Model {

    protected string $table = 'equipos';

    /**
     * Crea un equipo para un torneo con el usuario dado como capitán.
     *
     * @return int ID del equipo.
     */
    public function crear(int $capitanId, int $torneoId, string $nombre, string $estado = 'pendiente'): int {
        return $this->insert([
            'capitan_id' => $capitanId,
            'torneo_id'  => $torneoId,
            'nombre'     => $nombre,
            'estado'     => $estado,
        ]);
    }

    /**
     * Indica si un torneo ya tiene un equipo con ese nombre (la BD además lo
     * impide con una clave única; acá damos un mensaje claro antes de fallar).
     */
    public function existeNombre(int $torneoId, string $nombre): bool {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM equipos WHERE torneo_id = ? AND nombre = ? LIMIT 1'
        );
        $stmt->execute([$torneoId, $nombre]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Equipos de un torneo con su capitán y la cantidad de jugadores.
     * $soloAprobados limita el resultado a los equipos confirmados.
     *
     * @return array
     */
    public function listarPorTorneo(int $torneoId, bool $soloAprobados = false): array {
        $sql = 'SELECT e.id, e.nombre, e.estado, e.capitan_id, e.created_at,
                       TRIM(CONCAT(u.nombre, \' \', COALESCE(u.apellido, \'\'))) AS capitan,
                       (SELECT COUNT(*) FROM inscripciones i
                         WHERE i.equipo_id = e.id)                AS jugadores
                  FROM equipos e
                  INNER JOIN usuarios u ON u.id = e.capitan_id
                 WHERE e.torneo_id = ?';
        if ($soloAprobados) {
            $sql .= " AND e.estado = 'aprobado'";
        }
        $sql .= ' ORDER BY e.nombre ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$torneoId]);
        return $stmt->fetchAll();
    }

    /**
     * IDs de los equipos aprobados de un torneo: son los contendientes del
     * fixture cuando el torneo se juega por equipos.
     *
     * @return int[]
     */
    public function idsAprobados(int $torneoId): array {
        $stmt = $this->db->prepare(
            "SELECT id FROM equipos
              WHERE torneo_id = ? AND estado = 'aprobado'
              ORDER BY created_at ASC"
        );
        $stmt->execute([$torneoId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Cambia el estado de un equipo ('aprobado' | 'rechazado').
     */
    public function resolver(int $id, string $estado): bool {
        return $this->update($id, ['estado' => $estado]);
    }

    /**
     * Jugadores inscriptos con un equipo.
     *
     * @return array
     */
    public function miembros(int $equipoId): array {
        $stmt = $this->db->prepare(
            'SELECT u.id, u.nombre, u.apellido, u.avatar_url, i.estado
               FROM inscripciones i
               INNER JOIN usuarios u ON u.id = i.usuario_id
              WHERE i.equipo_id = ?
              ORDER BY u.nombre ASC'
        );
        $stmt->execute([$equipoId]);
        return $stmt->fetchAll();
    }
}
