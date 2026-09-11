<?php
require_once __DIR__ . '/Model.php';

/**
 * Modelo de Torneo. Gestiona CRUD de torneos y consultas relacionadas.
 */
class Torneo extends Model {

    protected string $table = 'torneos';

    /**
     * Columnas de torneos para listados y detalle: todas menos banner_data
     * (el BLOB de la foto de fondo). Igual que Usuario::buscar() con
     * avatar_data, incluir el blob acá inflaría cada listado — la imagen
     * se sirve aparte por GET /api/torneo/{id}/banner, que sí hace su
     * propio findById() completo.
     */
    private const COLUMNAS = 't.id, t.organizador_id, t.nombre, t.descripcion, t.reglamento,
        t.premios, t.discord_url, t.disciplina, t.formato, t.requiere_equipos, t.estado,
        t.max_participantes, t.publico, t.banner_url, t.fecha_inicio, t.fecha_fin,
        t.created_at, t.updated_at';

    /**
     * Lista torneos públicos con datos del organizador.
     *
     * @param array $filtros  ['estado'=>..., 'disciplina'=>..., 'formato'=>...]
     * @return array
     */
    public function listarPublicos(array $filtros = []): array {
        // Los borradores nunca salen en el listado público: son torneos que el
        // organizador todavía está preparando.
        $sql    = 'SELECT ' . self::COLUMNAS . ', u.nombre AS org_nombre, u.apellido AS org_apellido, '
                . self::METRICAS_SQL . '
                   FROM torneos t
                   INNER JOIN usuarios u ON u.id = t.organizador_id
                   WHERE t.publico = 1 AND t.estado <> \'borrador\'';
        $params = [];

        if (!empty($filtros['estado'])) {
            $sql .= ' AND t.estado = ?';
            $params[] = $filtros['estado'];
        }
        if (!empty($filtros['disciplina'])) {
            $sql .= ' AND t.disciplina = ?';
            $params[] = $filtros['disciplina'];
        }
        if (!empty($filtros['formato'])) {
            $sql .= ' AND t.formato = ?';
            $params[] = $filtros['formato'];
        }

        $sql .= ' ORDER BY t.created_at DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Subconsultas de métricas que acompañan a cada torneo en los paneles de
     * gestión (inscriptos aprobados, equipos aprobados y avance de partidos).
     */
    private const METRICAS_SQL = '
        (SELECT COUNT(*) FROM inscripciones i
          WHERE i.torneo_id = t.id AND i.estado = \'aprobada\')      AS inscriptos,
        (SELECT COUNT(*) FROM equipos eq
          WHERE eq.torneo_id = t.id AND eq.estado = \'aprobado\')    AS equipos,
        (SELECT COUNT(*) FROM partidos p
          WHERE p.torneo_id = t.id)                                  AS partidos,
        (SELECT COUNT(*) FROM partidos p
          WHERE p.torneo_id = t.id AND p.estado = \'finalizado\')    AS partidos_jugados';

    /**
     * Lista torneos de un organizador específico, con métricas de gestión.
     *
     * @param int $organizadorId
     * @return array
     */
    public function listarDeOrganizador(int $organizadorId): array {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNAS . ', ' . self::METRICAS_SQL . '
               FROM torneos t
              WHERE t.organizador_id = ?
              ORDER BY t.created_at DESC'
        );
        $stmt->execute([$organizadorId]);
        return $stmt->fetchAll();
    }

    /**
     * Lista todos los torneos con métricas de gestión (panel de administración).
     *
     * @return array
     */
    public function listarTodosConMetricas(): array {
        $stmt = $this->db->query(
            'SELECT ' . self::COLUMNAS . ', ' . self::METRICAS_SQL . '
               FROM torneos t
              ORDER BY t.created_at DESC'
        );
        return $stmt->fetchAll();
    }

    /**
     * Indica si un organizador ya tiene un torneo con ese nombre. Evita
     * duplicados por doble envío del formulario o por error de carga.
     * $excluirId se usa al editar, para no chocar contra el propio torneo.
     *
     * @param int      $organizadorId
     * @param string   $nombre
     * @param int|null $excluirId
     * @return bool
     */
    public function existeNombreDeOrganizador(int $organizadorId, string $nombre, ?int $excluirId = null): bool {
        $sql    = 'SELECT 1 FROM torneos WHERE organizador_id = ? AND nombre = ?';
        $params = [$organizadorId, $nombre];
        if ($excluirId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excluirId;
        }
        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Busca un torneo por ID incluyendo el nombre del organizador y las
     * métricas de participación (para la página de detalle).
     *
     * @param int $id
     * @return array|null
     */
    public function findConOrganizador(int $id): ?array {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNAS . ', u.nombre AS org_nombre, u.apellido AS org_apellido, ' . self::METRICAS_SQL . '
               FROM torneos t
               INNER JOIN usuarios u ON u.id = t.organizador_id
              WHERE t.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Cambia el estado de un torneo.
     *
     * @param int    $torneoId
     * @param string $nuevoEstado
     * @return bool
     */
    public function cambiarEstado(int $torneoId, string $nuevoEstado): bool {
        return $this->update($torneoId, ['estado' => $nuevoEstado]);
    }

    /**
     * Retorna la tabla de posiciones de un torneo.
     *
     * @param int $torneoId
     * @return array
     */
    public function getPosiciones(int $torneoId): array {
        // Resuelve el nombre del contendiente (equipo o usuario) según su tipo,
        // para que la tabla muestre nombres reales y no IDs.
        $stmt = $this->db->prepare(
            'SELECT p.*,
                    COALESCE(e.nombre, TRIM(CONCAT(u.nombre, \' \', COALESCE(u.apellido, \'\')))) AS contendiente
               FROM posiciones p
               LEFT JOIN equipos  e ON p.tipo = \'equipo\'  AND e.id = p.contendiente_id
               LEFT JOIN usuarios u ON p.tipo = \'usuario\' AND u.id = p.contendiente_id
              WHERE p.torneo_id = ?
              ORDER BY p.pts DESC, p.dg DESC'
        );
        $stmt->execute([$torneoId]);
        return $stmt->fetchAll();
    }

    /**
     * Equipos aprobados de un torneo, para la pestaña "Equipos" del detalle
     * público. Devuelve id y nombre, ordenados alfabéticamente.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEquipos(int $torneoId): array {
        $stmt = $this->db->prepare(
            'SELECT id, nombre FROM equipos
              WHERE torneo_id = ? AND estado = \'aprobado\'
              ORDER BY nombre ASC'
        );
        $stmt->execute([$torneoId]);
        return $stmt->fetchAll();
    }

    /**
     * Torneos en los que un usuario está inscripto (historial del perfil),
     * con el equipo con el que participa cuando corresponde.
     *
     * @param int $usuarioId
     * @return array
     */
    public function listarDeParticipante(int $usuarioId): array {
        $stmt = $this->db->prepare(
            'SELECT t.id, t.nombre, t.disciplina, t.formato, t.estado,
                    i.estado AS inscripcion_estado, i.fecha_inscripcion,
                    e.nombre AS equipo
               FROM inscripciones i
               INNER JOIN torneos t ON t.id = i.torneo_id
               LEFT JOIN equipos  e ON e.id = i.equipo_id
              WHERE i.usuario_id = ?
              ORDER BY i.fecha_inscripcion DESC'
        );
        $stmt->execute([$usuarioId]);
        return $stmt->fetchAll();
    }

    /**
     * Equipos de un usuario: los que capitanea y aquellos con los que está
     * inscripto en algún torneo. Incluye el torneo al que pertenece cada uno.
     *
     * @param int $usuarioId
     * @return array
     */
    public function equiposDeUsuario(int $usuarioId): array {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT e.id, e.nombre, e.estado, e.created_at,
                    t.nombre AS torneo, t.disciplina, t.estado AS torneo_estado,
                    (e.capitan_id = ?) AS es_capitan
               FROM equipos e
               INNER JOIN torneos t ON t.id = e.torneo_id
               LEFT JOIN inscripciones i ON i.equipo_id = e.id
              WHERE e.capitan_id = ? OR i.usuario_id = ?
              ORDER BY e.created_at DESC'
        );
        $stmt->execute([$usuarioId, $usuarioId, $usuarioId]);
        return $stmt->fetchAll();
    }
}
