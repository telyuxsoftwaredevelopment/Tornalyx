<?php
require_once __DIR__ . '/Model.php';
require_once __DIR__ . '/../shared/Fixture.php';

/**
 * Modelo de Partido (enfrentamiento). Persiste el fixture que arma Fixture y
 * resuelve el avance de rondas según el formato del torneo.
 */
class Partido extends Model {

    protected string $table = 'partidos';

    /**
     * Partidos de un torneo con nombres resueltos y datos de la ronda.
     * Es la consulta que alimenta el fixture público y el del organizador.
     *
     * @return array
     */
    public function listarPorTorneo(int $torneoId): array {
        $stmt = $this->db->prepare(
            'SELECT p.id, p.local_id, p.visitante_id, p.tipo_contendiente,
                    p.estado, p.fecha_partido, p.lugar,
                    ro.numero AS ronda, ro.nombre AS ronda_nombre,
                    r.goles_local, r.goles_visitante,
                    COALESCE(el.nombre, TRIM(CONCAT(ul.nombre, \' \', COALESCE(ul.apellido, \'\')))) AS local_nombre,
                    COALESCE(ev.nombre, TRIM(CONCAT(uv.nombre, \' \', COALESCE(uv.apellido, \'\')))) AS visitante_nombre
               FROM partidos p
               INNER JOIN rondas ro ON ro.id = p.ronda_id
               LEFT JOIN resultados r ON r.partido_id = p.id
               LEFT JOIN equipos  el ON p.tipo_contendiente = \'equipo\'  AND el.id = p.local_id
               LEFT JOIN usuarios ul ON p.tipo_contendiente = \'usuario\' AND ul.id = p.local_id
               LEFT JOIN equipos  ev ON p.tipo_contendiente = \'equipo\'  AND ev.id = p.visitante_id
               LEFT JOIN usuarios uv ON p.tipo_contendiente = \'usuario\' AND uv.id = p.visitante_id
              WHERE p.torneo_id = ?
              ORDER BY ro.numero ASC, p.id ASC'
        );
        $stmt->execute([$torneoId]);
        return $stmt->fetchAll();
    }

    /**
     * Próximos partidos de un usuario (su rival siguiente y dónde juega).
     * Incluye los partidos de sus equipos y los suyos en torneos individuales.
     *
     * @return array
     */
    public function proximosDeUsuario(int $usuarioId, int $limite = 5): array {
        $stmt = $this->db->prepare(
            'SELECT p.id, p.estado, p.fecha_partido, p.lugar, p.local_id, p.visitante_id,
                    p.tipo_contendiente, t.id AS torneo_id, t.nombre AS torneo,
                    ro.numero AS ronda, ro.nombre AS ronda_nombre,
                    COALESCE(el.nombre, TRIM(CONCAT(ul.nombre, \' \', COALESCE(ul.apellido, \'\')))) AS local_nombre,
                    COALESCE(ev.nombre, TRIM(CONCAT(uv.nombre, \' \', COALESCE(uv.apellido, \'\')))) AS visitante_nombre
               FROM partidos p
               INNER JOIN torneos t  ON t.id = p.torneo_id
               INNER JOIN rondas  ro ON ro.id = p.ronda_id
               LEFT JOIN equipos  el ON p.tipo_contendiente = \'equipo\'  AND el.id = p.local_id
               LEFT JOIN usuarios ul ON p.tipo_contendiente = \'usuario\' AND ul.id = p.local_id
               LEFT JOIN equipos  ev ON p.tipo_contendiente = \'equipo\'  AND ev.id = p.visitante_id
               LEFT JOIN usuarios uv ON p.tipo_contendiente = \'usuario\' AND uv.id = p.visitante_id
              WHERE p.estado <> \'finalizado\'
                AND ( (p.tipo_contendiente = \'usuario\' AND ? IN (p.local_id, p.visitante_id))
                   OR (p.tipo_contendiente = \'equipo\'  AND EXISTS (
                          SELECT 1 FROM inscripciones i
                           WHERE i.usuario_id = ? AND i.equipo_id IN (p.local_id, p.visitante_id))) )
              ORDER BY (p.fecha_partido IS NULL), p.fecha_partido ASC, ro.numero ASC
              LIMIT ' . (int) $limite
        );
        $stmt->execute([$usuarioId, $usuarioId]);
        return $stmt->fetchAll();
    }

    /**
     * Genera la siguiente ronda del torneo y devuelve cuántos partidos creó.
     *
     * - Liga y grupos: se genera el calendario completo de una sola vez.
     * - Eliminación directa: una ronda por vez, con los ganadores de la previa.
     * - Suizo: una ronda por vez, emparejando por puntaje.
     *
     * @param array $torneo Fila de torneos (necesita id, formato, requiere_equipos).
     * @param int[] $contendientes IDs de los contendientes aprobados.
     * @return int Partidos creados (0 si no había nada que generar).
     */
    public function generarSiguienteRonda(array $torneo, array $contendientes): int {
        $torneoId = (int) $torneo['id'];
        $formato  = (string) $torneo['formato'];
        $tipo     = !empty($torneo['requiere_equipos']) ? 'equipo' : 'usuario';
        $ultima   = $this->ultimaRonda($torneoId);

        // Liga: el calendario es fijo, se crea entero en la primera generación.
        if ($formato === 'liga' || $formato === 'grupos_playoff') {
            if ($ultima > 0) {
                return 0;   // ya estaba generado
            }
            $creados = 0;
            foreach (Fixture::roundRobin($contendientes) as $i => $pares) {
                $rondaId = $this->crearRonda($torneoId, $i + 1, 'Fecha ' . ($i + 1));
                $creados += $this->crearPartidos($torneoId, $rondaId, $pares, $tipo);
            }
            return $creados;
        }

        // Eliminación y suizo avanzan de a una ronda.
        $numero = $ultima + 1;
        if ($ultima > 0 && !$this->rondaCompleta($torneoId, $ultima)) {
            return 0;   // falta cerrar la ronda en curso
        }

        if ($formato === 'eliminacion_directa') {
            $vivos = $this->vivosTras($torneoId, $ultima, $contendientes);
            if (count($vivos) < 2) {
                return 0;   // ya hay campeón
            }
            $pares  = Fixture::eliminacion($vivos);
            $nombre = Fixture::nombreRonda($formato, $numero, count($vivos));
        } else {
            // Suizo: se juega siempre con todos, emparejando por puntaje.
            if ($ultima === 0) {
                $orden = $contendientes;
            } else {
                $orden = $this->ordenPorPuntos($torneoId, $contendientes);
            }
            $pares  = Fixture::suizo($orden, $this->enfrentamientosPrevios($torneoId));
            $nombre = Fixture::nombreRonda($formato, $numero, count($orden));
        }

        if ($pares === []) {
            return 0;
        }
        $rondaId = $this->crearRonda($torneoId, $numero, $nombre);
        return $this->crearPartidos($torneoId, $rondaId, $pares, $tipo);
    }

    /**
     * Programa fecha, hora y lugar de un partido (RF-08).
     */
    public function programar(int $partidoId, ?string $fecha, ?string $lugar): bool {
        return $this->update($partidoId, [
            'fecha_partido' => $fecha !== '' ? $fecha : null,
            'lugar'         => $lugar !== '' ? $lugar : null,
        ]);
    }

    /**
     * Cambia el estado de un partido: pendiente | en_curso | finalizado | aplazado.
     */
    public function cambiarEstado(int $partidoId, string $estado): bool {
        return $this->update($partidoId, ['estado' => $estado]);
    }

    /**
     * Registra la confirmación de asistencia de un usuario a un partido
     * (RF-09). Reenviarla actualiza el estado en vez de duplicar.
     */
    public function confirmarAsistencia(int $partidoId, int $usuarioId, string $estado): void {
        $stmt = $this->db->prepare(
            'INSERT INTO asistencias (partido_id, usuario_id, estado) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE estado = VALUES(estado)'
        );
        $stmt->execute([$partidoId, $usuarioId, $estado]);
    }

    /**
     * Asistencias confirmadas de un partido, con el nombre de cada jugador.
     *
     * @return array
     */
    public function asistencias(int $partidoId): array {
        $stmt = $this->db->prepare(
            'SELECT a.usuario_id, a.estado,
                    TRIM(CONCAT(u.nombre, \' \', COALESCE(u.apellido, \'\'))) AS jugador
               FROM asistencias a
               INNER JOIN usuarios u ON u.id = a.usuario_id
              WHERE a.partido_id = ?'
        );
        $stmt->execute([$partidoId]);
        return $stmt->fetchAll();
    }

    /**
     * Torneo al que pertenece un partido (para validar permisos).
     */
    public function torneoDe(int $partidoId): ?int {
        $stmt = $this->db->prepare('SELECT torneo_id FROM partidos WHERE id = ?');
        $stmt->execute([$partidoId]);
        $row = $stmt->fetch();
        return $row ? (int) $row['torneo_id'] : null;
    }

    // ──────────────────────────────────────────────────────
    // Helpers internos
    // ──────────────────────────────────────────────────────

    /** Número de la última ronda creada (0 si no hay ninguna). */
    private function ultimaRonda(int $torneoId): int {
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(numero), 0) FROM rondas WHERE torneo_id = ?');
        $stmt->execute([$torneoId]);
        return (int) $stmt->fetchColumn();
    }

    /** True si todos los partidos de esa ronda están finalizados. */
    private function rondaCompleta(int $torneoId, int $numero): bool {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM partidos p
               INNER JOIN rondas ro ON ro.id = p.ronda_id
              WHERE ro.torneo_id = ? AND ro.numero = ? AND p.estado <> 'finalizado'"
        );
        $stmt->execute([$torneoId, $numero]);
        return (int) $stmt->fetchColumn() === 0;
    }

    /** Crea la ronda y devuelve su id. */
    private function crearRonda(int $torneoId, int $numero, string $nombre): int {
        $stmt = $this->db->prepare(
            "INSERT INTO rondas (torneo_id, numero, nombre, estado)
             VALUES (?, ?, ?, 'en_curso')"
        );
        $stmt->execute([$torneoId, $numero, $nombre]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Inserta los partidos de una ronda.
     *
     * @param array<int, array{0:int,1:int}> $pares
     */
    private function crearPartidos(int $torneoId, int $rondaId, array $pares, string $tipo): int {
        $stmt = $this->db->prepare(
            "INSERT INTO partidos (torneo_id, ronda_id, local_id, visitante_id, tipo_contendiente, estado)
             VALUES (?, ?, ?, ?, ?, 'pendiente')"
        );
        foreach ($pares as [$local, $visitante]) {
            $stmt->execute([$torneoId, $rondaId, $local, $visitante, $tipo]);
        }
        return count($pares);
    }

    /**
     * Quiénes siguen en carrera después de jugarse la ronda $numero.
     *
     * Se calcula recursivamente desde el plantel inicial: en cada ronda pasan
     * los ganadores de los partidos más quienes descansaron (quedaron sin rival
     * por ser un número impar). Así nadie se pierde por el camino aunque el
     * cuadro no sea una potencia de dos.
     *
     * @param int[] $contendientes Plantel aprobado del torneo.
     * @return int[]
     */
    private function vivosTras(int $torneoId, int $numero, array $contendientes): array {
        if ($numero === 0) {
            return array_values($contendientes);
        }

        $stmt = $this->db->prepare(
            'SELECT p.local_id, p.visitante_id, r.ganador_id, r.goles_local, r.goles_visitante
               FROM partidos p
               INNER JOIN rondas ro ON ro.id = p.ronda_id
               LEFT JOIN resultados r ON r.partido_id = p.id
              WHERE ro.torneo_id = ? AND ro.numero = ?
              ORDER BY p.id ASC'
        );
        $stmt->execute([$torneoId, $numero]);

        $ganadores = [];
        $jugaron   = [];
        foreach ($stmt->fetchAll() as $p) {
            $local     = (int) $p['local_id'];
            $visitante = (int) $p['visitante_id'];
            $jugaron[] = $local;
            $jugaron[] = $visitante;

            if ($p['ganador_id'] !== null) {
                $ganadores[] = (int) $p['ganador_id'];
                continue;
            }
            // Sin ganador explícito se deduce del marcador; ante empate pasa el
            // local (en eliminación directa no debería quedar ningún empate).
            $gl = (int) ($p['goles_local'] ?? 0);
            $gv = (int) ($p['goles_visitante'] ?? 0);
            $ganadores[] = $gv > $gl ? $visitante : $local;
        }

        $previos   = $this->vivosTras($torneoId, $numero - 1, $contendientes);
        $descansan = array_diff($previos, $jugaron);

        return array_values(array_merge($ganadores, $descansan));
    }

    /**
     * Contendientes ordenados por puntos (suizo). Los que aún no puntuaron
     * quedan al final, conservando su orden de inscripción.
     *
     * @param int[] $contendientes
     * @return int[]
     */
    private function ordenPorPuntos(int $torneoId, array $contendientes): array {
        $stmt = $this->db->prepare(
            'SELECT contendiente_id, pts FROM posiciones
              WHERE torneo_id = ? ORDER BY pts DESC, dg DESC'
        );
        $stmt->execute([$torneoId]);

        $puntos = [];
        foreach ($stmt->fetchAll() as $row) {
            $puntos[(int) $row['contendiente_id']] = (int) $row['pts'];
        }

        $orden = $contendientes;
        usort($orden, static fn($a, $b) => ($puntos[$b] ?? -1) <=> ($puntos[$a] ?? -1));
        return $orden;
    }

    /**
     * Mapa [a][b] de enfrentamientos ya disputados, para que el suizo no
     * repita rivales.
     *
     * @return array<int, array<int, bool>>
     */
    private function enfrentamientosPrevios(int $torneoId): array {
        $stmt = $this->db->prepare(
            'SELECT local_id, visitante_id FROM partidos WHERE torneo_id = ?'
        );
        $stmt->execute([$torneoId]);

        $mapa = [];
        foreach ($stmt->fetchAll() as $p) {
            $a = (int) $p['local_id'];
            $b = (int) $p['visitante_id'];
            $mapa[$a][$b] = true;
            $mapa[$b][$a] = true;
        }
        return $mapa;
    }
}
