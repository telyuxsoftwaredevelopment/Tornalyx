<?php
require_once __DIR__ . '/Model.php';

/**
 * Modelo de la tabla de posiciones.
 *
 * Las posiciones son un caché derivado de los resultados: en vez de ir
 * sumando de a poco (y arriesgar que queden desfasadas si se corrige un
 * marcador), se recalculan enteras a partir de los partidos finalizados cada
 * vez que se carga o edita un resultado.
 */
class Posicion extends Model {

    protected string $table = 'posiciones';

    /** Puntos por victoria y por empate (esquema clásico 3-1-0). */
    private const PTS_VICTORIA = 3;
    private const PTS_EMPATE   = 1;

    /**
     * Recalcula la tabla completa de un torneo desde sus resultados.
     */
    public function recalcular(int $torneoId): void {
        $stmt = $this->db->prepare(
            'SELECT p.local_id, p.visitante_id, p.tipo_contendiente,
                    r.goles_local, r.goles_visitante
               FROM partidos p
               INNER JOIN resultados r ON r.partido_id = p.id
              WHERE p.torneo_id = ?'
        );
        $stmt->execute([$torneoId]);
        $partidos = $stmt->fetchAll();

        /** @var array<int, array<string, int|string>> $tabla */
        $tabla = [];
        $fila = static function (array &$tabla, int $id, string $tipo): void {
            if (!isset($tabla[$id])) {
                $tabla[$id] = [
                    'tipo' => $tipo,
                    'pj' => 0, 'pg' => 0, 'pe' => 0, 'pp' => 0,
                    'gf' => 0, 'gc' => 0,
                ];
            }
        };

        foreach ($partidos as $p) {
            $local     = (int) $p['local_id'];
            $visitante = (int) $p['visitante_id'];
            $tipo      = (string) $p['tipo_contendiente'];
            $gl        = (int) $p['goles_local'];
            $gv        = (int) $p['goles_visitante'];

            $fila($tabla, $local, $tipo);
            $fila($tabla, $visitante, $tipo);

            $tabla[$local]['pj']++;
            $tabla[$visitante]['pj']++;
            $tabla[$local]['gf']     += $gl;
            $tabla[$local]['gc']     += $gv;
            $tabla[$visitante]['gf'] += $gv;
            $tabla[$visitante]['gc'] += $gl;

            if ($gl > $gv) {
                $tabla[$local]['pg']++;
                $tabla[$visitante]['pp']++;
            } elseif ($gv > $gl) {
                $tabla[$visitante]['pg']++;
                $tabla[$local]['pp']++;
            } else {
                $tabla[$local]['pe']++;
                $tabla[$visitante]['pe']++;
            }
        }

        // Reemplazo atómico: si algo falla, la tabla anterior queda intacta.
        $this->db->beginTransaction();
        try {
            $del = $this->db->prepare('DELETE FROM posiciones WHERE torneo_id = ?');
            $del->execute([$torneoId]);

            $ins = $this->db->prepare(
                'INSERT INTO posiciones
                    (torneo_id, contendiente_id, tipo, pj, pg, pe, pp, gf, gc, dg, pts)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($tabla as $id => $f) {
                $pts = $f['pg'] * self::PTS_VICTORIA + $f['pe'] * self::PTS_EMPATE;
                $ins->execute([
                    $torneoId, $id, $f['tipo'],
                    $f['pj'], $f['pg'], $f['pe'], $f['pp'],
                    $f['gf'], $f['gc'], $f['gf'] - $f['gc'], $pts,
                ]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Estadísticas agregadas de un contendiente a lo largo de todos sus
     * torneos (RF-15): partidos, victorias, empates, derrotas y goles.
     *
     * @return array<string, int>
     */
    public function resumenDeContendiente(int $contendienteId, string $tipo = 'usuario'): array {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(pj), 0) AS pj, COALESCE(SUM(pg), 0) AS pg,
                    COALESCE(SUM(pe), 0) AS pe, COALESCE(SUM(pp), 0) AS pp,
                    COALESCE(SUM(gf), 0) AS gf, COALESCE(SUM(gc), 0) AS gc,
                    COALESCE(SUM(pts), 0) AS pts
               FROM posiciones
              WHERE contendiente_id = ? AND tipo = ?'
        );
        $stmt->execute([$contendienteId, $tipo]);
        return array_map('intval', $stmt->fetch() ?: []);
    }
}
