<?php
/**
 * Generación de emparejamientos (fixture) según el formato del torneo.
 *
 * Lógica pura: recibe IDs de contendientes y devuelve pares, sin tocar la
 * base de datos. Eso la hace fácil de razonar y de probar por separado del
 * resto del sistema (el guardado vive en el modelo Partido).
 *
 * Un "par" es [localId, visitanteId]; una "ronda" es una lista de pares.
 */
class Fixture {

    /**
     * Liga: todos contra todos a una rueda (método del círculo).
     *
     * Con un número impar de contendientes se agrega un descanso (bye): en esa
     * ronda quien lo recibe no juega. La localía se alterna por ronda para que
     * el calendario quede equilibrado.
     *
     * @param int[] $ids
     * @return array<int, array<int, array{0:int,1:int}>> Lista de rondas.
     */
    public static function roundRobin(array $ids): array {
        $ids = array_values($ids);
        $n   = count($ids);
        if ($n < 2) {
            return [];
        }

        // Con impares se agrega un hueco: quien queda emparejado con él descansa.
        if ($n % 2 !== 0) {
            $ids[] = null;
            $n++;
        }

        $rondas = [];
        $mitad  = intdiv($n, 2);
        $rot    = $ids;

        for ($r = 0; $r < $n - 1; $r++) {
            $pares = [];
            for ($i = 0; $i < $mitad; $i++) {
                $a = $rot[$i];
                $b = $rot[$n - 1 - $i];
                if ($a === null || $b === null) {
                    continue;   // descanso
                }
                // Alternar localía ronda a ronda.
                $pares[] = ($r % 2 === 0) ? [$a, $b] : [$b, $a];
            }
            if ($pares !== []) {
                $rondas[] = $pares;
            }

            // Rotación: el primero queda fijo, el resto gira una posición.
            $fijo  = array_shift($rot);
            $ultimo = array_pop($rot);
            array_unshift($rot, $ultimo);
            array_unshift($rot, $fijo);
        }

        return $rondas;
    }

    /**
     * Eliminación directa: empareja la lista de una ronda de a dos, en orden.
     *
     * Si sobra uno (cantidad impar), avanza sin jugar: no se le crea partido y
     * el modelo lo detecta al calcular quiénes pasan a la ronda siguiente.
     *
     * @param int[] $ids
     * @return array<int, array{0:int,1:int}> Pares de una sola ronda.
     */
    public static function eliminacion(array $ids): array {
        $ids   = array_values($ids);
        $pares = [];
        for ($i = 0; $i + 1 < count($ids); $i += 2) {
            $pares[] = [$ids[$i], $ids[$i + 1]];
        }
        return $pares;
    }

    /**
     * Sistema suizo: empareja por cercanía de puntaje evitando repetir rivales.
     *
     * Recorre la tabla ordenada de mayor a menor puntaje y, para cada
     * contendiente libre, elige al siguiente disponible con el que todavía no
     * haya jugado. Si no queda ninguno sin repetir, acepta la revancha antes de
     * dejar a alguien sin partido.
     *
     * @param int[]                       $ordenados IDs ordenados por puntaje desc.
     * @param array<int, array<int, bool>> $jugados   [a][b] => true si ya se enfrentaron.
     * @return array<int, array{0:int,1:int}> Pares de una sola ronda.
     */
    public static function suizo(array $ordenados, array $jugados = []): array {
        $libres = array_values($ordenados);
        $pares  = [];

        while (count($libres) >= 2) {
            $a = array_shift($libres);

            // Primer rival con el que no haya jugado; si no hay, el primero libre.
            $elegido = null;
            foreach ($libres as $i => $b) {
                if (empty($jugados[$a][$b])) {
                    $elegido = $i;
                    break;
                }
            }
            if ($elegido === null) {
                $elegido = 0;
            }

            $b = $libres[$elegido];
            array_splice($libres, $elegido, 1);
            $pares[] = [$a, $b];
        }

        return $pares;   // Si sobra uno, descansa esta ronda.
    }

    /**
     * Nombre legible de una ronda según el formato y cuántos contendientes
     * quedan en ella (en eliminación directa: Final, Semifinal, etc.).
     *
     * @param string $formato
     * @param int    $numero      Número de ronda (1-based).
     * @param int    $contendientes Cuántos entran a esa ronda.
     */
    public static function nombreRonda(string $formato, int $numero, int $contendientes): string {
        if ($formato !== 'eliminacion_directa') {
            return 'Ronda ' . $numero;
        }
        return match (true) {
            $contendientes <= 2 => 'Final',
            $contendientes <= 4 => 'Semifinal',
            $contendientes <= 8 => 'Cuartos de final',
            $contendientes <= 16 => 'Octavos de final',
            default => 'Ronda ' . $numero,
        };
    }
}
