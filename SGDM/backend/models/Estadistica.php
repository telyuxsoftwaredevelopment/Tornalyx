<?php
require_once __DIR__ . '/Model.php';

/**
 * Modelo de estadísticas del sistema.
 * Provee los agregados que alimentan el panel de administración.
 */
class Estadistica extends Model {

    // Model exige una tabla; usamos usuarios como ancla aunque las consultas
    // de esta clase abarcan varias tablas.
    protected string $table = 'usuarios';

    /**
     * Contadores globales para las tarjetas KPI del panel de administración.
     *
     * @return array{
     *   usuarios:int, usuarios_nuevos_semana:int,
     *   torneos_total:int, torneos_activos:int,
     *   equipos:int, partidos:int, partidos_semana:int
     * }
     */
    public function resumenGeneral(): array {
        // Un solo viaje a la BD: cada métrica es una subconsulta escalar.
        $sql = "
            SELECT
              (SELECT COUNT(*) FROM usuarios)                                   AS usuarios,
              (SELECT COUNT(*) FROM usuarios
                 WHERE created_at >= NOW() - INTERVAL 7 DAY)                    AS usuarios_nuevos_semana,
              (SELECT COUNT(*) FROM torneos)                                    AS torneos_total,
              (SELECT COUNT(*) FROM torneos
                 WHERE estado IN ('inscripcion','en_curso'))                   AS torneos_activos,
              (SELECT COUNT(*) FROM equipos WHERE estado = 'aprobado')         AS equipos,
              (SELECT COUNT(*) FROM partidos)                                   AS partidos,
              (SELECT COUNT(*) FROM partidos
                 WHERE created_at >= NOW() - INTERVAL 7 DAY)                    AS partidos_semana
        ";
        $row = $this->db->query($sql)->fetch() ?: [];

        // MySQL devuelve los COUNT como string; el front hace comparaciones
        // y sumas, así que se normalizan a entero.
        return array_map('intval', $row);
    }

    /**
     * Últimos eventos del sistema (torneos creados, usuarios registrados y
     * resultados cargados), mezclados y ordenados del más reciente al más
     * antiguo, para el feed "Actividad reciente".
     *
     * @param int $limite Cantidad máxima de eventos a devolver.
     * @return array<int, array{tipo:string, texto:string, detalle:string, created_at:string}>
     */
    public function actividadReciente(int $limite = 6): array {
        $eventos = [];

        // Torneos creados.
        $stmt = $this->db->query(
            'SELECT nombre, created_at FROM torneos ORDER BY created_at DESC LIMIT 5'
        );
        foreach ($stmt->fetchAll() as $t) {
            $eventos[] = [
                'tipo'       => 'torneo',
                'texto'      => 'Nuevo torneo',
                'detalle'    => $t['nombre'],
                'created_at' => $t['created_at'],
            ];
        }

        // Usuarios registrados.
        $stmt = $this->db->query(
            'SELECT email, created_at FROM usuarios ORDER BY created_at DESC LIMIT 5'
        );
        foreach ($stmt->fetchAll() as $u) {
            $eventos[] = [
                'tipo'       => 'usuario',
                'texto'      => 'Usuario registrado',
                'detalle'    => $u['email'],
                'created_at' => $u['created_at'],
            ];
        }

        // Resultados cargados (con el nombre del torneo al que pertenecen).
        $stmt = $this->db->query(
            'SELECT t.nombre AS torneo, r.goles_local, r.goles_visitante, r.created_at
               FROM resultados r
               INNER JOIN partidos p ON p.id = r.partido_id
               INNER JOIN torneos  t ON t.id = p.torneo_id
              ORDER BY r.created_at DESC LIMIT 5'
        );
        foreach ($stmt->fetchAll() as $r) {
            $eventos[] = [
                'tipo'       => 'resultado',
                'texto'      => 'Resultado cargado',
                'detalle'    => sprintf('%s (%d · %d)', $r['torneo'], $r['goles_local'], $r['goles_visitante']),
                'created_at' => $r['created_at'],
            ];
        }

        // Orden global por fecha descendente y recorte al límite pedido.
        usort($eventos, static fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return array_slice($eventos, 0, $limite);
    }
}
