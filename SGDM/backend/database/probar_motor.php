<?php
/**
 * Prueba end-to-end del motor de torneos (fixture → resultados → posiciones).
 *
 * Siembra datos de demostración y despues ejercita los MODELOS REALES de la
 * aplicación —Partido::generarSiguienteRonda(), Resultado::cargar() y
 * Posicion::recalcular()— verificando que lo que producen sea coherente.
 * No pasa por los controladores: no valida permisos de rol, CSRF ni la
 * validación de entrada, que son responsabilidad de la capa HTTP.
 *
 * Cubre los dos motores de emparejamiento:
 *   - liga                 → calendario round-robin completo de una sola vez.
 *   - eliminacion_directa  → una ronda por vez, con los ganadores de la previa.
 *
 * Uso (desde la raíz del repositorio):
 *   php SGDM/backend/database/probar_motor.php
 *
 * Es re-ejecutable: borra los torneos de demostración de la corrida anterior
 * (por nombre) antes de volver a sembrarlos, así no se acumulan.
 *
 * Termina con código de salida 0 si todas las verificaciones pasan, 1 si
 * alguna falla, de modo que sirva como chequeo automatizable.
 */
declare(strict_types=1);

// ──────────────────────────────────────────────────────────────
// Guardas: este script escribe datos ficticios. Solo por linea de
// comandos y nunca contra una base de producción.
// ──────────────────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse por linea de comandos.\n");
}

require_once __DIR__ . '/../config/database.php';

if (strtolower(envOrDefault('APP_ENV', 'development')) === 'production') {
    fwrite(STDERR, "Abortado: APP_ENV=production. Este script siembra datos de prueba.\n");
    exit(1);
}

require_once __DIR__ . '/../models/Model.php';
require_once __DIR__ . '/../shared/Fixture.php';
foreach (['Torneo', 'Inscripcion', 'Equipo', 'Partido', 'Resultado', 'Posicion', 'Aviso'] as $modelo) {
    require_once __DIR__ . '/../models/' . $modelo . '.php';
}

$pdo = getDB();

/** Nombres de los torneos de demostración (se recrean en cada corrida). */
const TORNEO_LIGA        = 'Liga Apertura (demo)';
const TORNEO_ELIMINACION = 'Copa Eliminacion (demo)';

/** Dominio reservado para los participantes ficticios. */
const DOMINIO_DEMO = '@tornalyx.test';

$fallos = 0;

/** Registra una verificación; marca el script como fallido si no se cumple. */
function verificar(string $descripcion, bool $condicion, string $detalle = ''): void {
    global $fallos;
    if ($condicion) {
        echo "  [OK]    {$descripcion}\n";
        return;
    }
    $fallos++;
    echo "  [FALLA] {$descripcion}" . ($detalle !== '' ? " — {$detalle}" : '') . "\n";
}

function titulo(string $texto): void {
    echo "\n" . str_repeat('─', 62) . "\n{$texto}\n" . str_repeat('─', 62) . "\n";
}

/**
 * Devuelve el id de un usuario con el que crear los torneos como su
 * organizador (torneos.organizador_id: un rol por torneo, no de cuenta).
 * Si la base todavía no tiene ningún usuario (instalación limpia), crea uno
 * de demostración.
 */
function organizadorDemo(PDO $pdo): int {
    $id = $pdo->query('SELECT id FROM usuarios ORDER BY id LIMIT 1')->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    $stmt = $pdo->prepare(
        "INSERT INTO usuarios (nombre, apellido, email, password, fecha_nac, rol, estado)
         VALUES ('Organizador', 'Demo', ?, ?, '1990-01-01', 'participante', 'activo')"
    );
    $stmt->execute([
        'organizador' . DOMINIO_DEMO,
        password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
    ]);
    return (int) $pdo->lastInsertId();
}

/** Crea (o reutiliza, por email) un participante ficticio. */
function participante(PDO $pdo, string $nombre, string $apellido, string $usuario): int {
    $email = $usuario . DOMINIO_DEMO;
    $stmt  = $pdo->prepare('SELECT id FROM usuarios WHERE email = ?');
    $stmt->execute([$email]);
    if ($id = $stmt->fetchColumn()) {
        return (int) $id;
    }
    // La contraseña es aleatoria y se descarta: estas cuentas no se usan para
    // iniciar sesión, solo para poblar inscripciones.
    $stmt = $pdo->prepare(
        "INSERT INTO usuarios (nombre, apellido, email, password, fecha_nac, rol, estado)
         VALUES (?, ?, ?, ?, '1998-05-10', 'participante', 'activo')"
    );
    $stmt->execute([$nombre, $apellido, $email, password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT)]);
    return (int) $pdo->lastInsertId();
}

/** Borra el torneo de demostración anterior; las tablas hijas caen por cascada. */
function limpiarTorneo(PDO $pdo, string $nombre): void {
    $stmt = $pdo->prepare('DELETE FROM torneos WHERE nombre = ?');
    $stmt->execute([$nombre]);
}

/** Crea un torneo e inscribe (ya aprobados) a los participantes indicados. */
function torneoCon(PDO $pdo, int $organizador, string $nombre, string $formato, array $usuarios): int {
    $stmt = $pdo->prepare(
        "INSERT INTO torneos (organizador_id, nombre, descripcion, disciplina, formato,
                              requiere_equipos, estado, max_participantes, publico, fecha_inicio)
         VALUES (?, ?, 'Torneo de demostracion para validar el motor.', 'Futbol 5', ?, 0,
                 'inscripcion', 32, 1, CURDATE())"
    );
    $stmt->execute([$organizador, $nombre, $formato]);
    $torneoId = (int) $pdo->lastInsertId();

    $inscribir = $pdo->prepare(
        "INSERT INTO inscripciones (usuario_id, torneo_id, estado) VALUES (?, ?, 'aprobada')"
    );
    foreach ($usuarios as $usuarioId) {
        $inscribir->execute([$usuarioId, $torneoId]);
    }
    return $torneoId;
}

$partidoM = new Partido();
$torneoM  = new Torneo();
$inscM    = new Inscripcion();
$resM     = new Resultado();
$posM     = new Posicion();
$avisoM   = new Aviso();

$organizador = organizadorDemo($pdo);

// ══════════════════════════════════════════════════════════════
// TORNEO 1 — LIGA (todos contra todos), 6 participantes
// ══════════════════════════════════════════════════════════════
titulo('TORNEO 1 — liga (round-robin), 6 participantes');

limpiarTorneo($pdo, TORNEO_LIGA);

$plantel = [
    ['Lucia', 'Ferreyra', 'jugador1'], ['Mateo', 'Godoy',   'jugador2'],
    ['Sofia', 'Quiroga',  'jugador3'], ['Bruno', 'Alcaraz', 'jugador4'],
    ['Nadia', 'Sosa',     'jugador5'], ['Ivan',  'Peralta', 'jugador6'],
];
$jugadores = [];
foreach ($plantel as [$nombre, $apellido, $usuario]) {
    $jugadores[] = participante($pdo, $nombre, $apellido, $usuario);
}

$ligaId = torneoCon($pdo, $organizador, TORNEO_LIGA, 'liga', $jugadores);
$n      = count($jugadores);
echo "Torneo id={$ligaId}, participantes aprobados: " . count($inscM->idsAprobados($ligaId)) . "\n\n";

$creados = $partidoM->generarSiguienteRonda($torneoM->findById($ligaId), $inscM->idsAprobados($ligaId));
$rondas  = (int) $pdo->query("SELECT COUNT(*) FROM rondas WHERE torneo_id = {$ligaId}")->fetchColumn();

// En un round-robin de n participantes (n par) hay n-1 fechas y n(n-1)/2 partidos.
$partidosEsperados = intdiv($n * ($n - 1), 2);
verificar("Genera {$partidosEsperados} partidos", $creados === $partidosEsperados, "generó {$creados}");
verificar('Genera ' . ($n - 1) . ' rondas', $rondas === $n - 1, "creó {$rondas}");

// Regenerar no debe duplicar: el calendario de liga se crea una sola vez.
$repetido = $partidoM->generarSiguienteRonda($torneoM->findById($ligaId), $inscM->idsAprobados($ligaId));
verificar('Regenerar el fixture no duplica partidos', $repetido === 0, "creó {$repetido} de más");

$torneoM->cambiarEstado($ligaId, 'en_curso');
$avisoM->publicar($ligaId, $organizador, 'fixture', 'Fixture publicado',
    "Se publicaron {$creados} enfrentamientos.");

// Cargar un marcador en cada partido, con valores variados (incluye empates).
$partidos = $pdo->query(
    "SELECT id, local_id, visitante_id FROM partidos WHERE torneo_id = {$ligaId} ORDER BY id"
)->fetchAll();
foreach ($partidos as $i => $partido) {
    $golesLocal     = $i % 4;
    $golesVisitante = ($i * 3) % 4;
    $ganador = $golesLocal > $golesVisitante
        ? (int) $partido['local_id']
        : ($golesVisitante > $golesLocal ? (int) $partido['visitante_id'] : null);
    $resM->cargar((int) $partido['id'], $golesLocal, $golesVisitante, $ganador, $organizador);
    $partidoM->cambiarEstado((int) $partido['id'], 'finalizado');
}
$posM->recalcular($ligaId);

echo "\nTabla de posiciones:\n";
printf("  %-20s %3s %3s %3s %3s %3s %3s %4s %4s\n",
    'CONTENDIENTE', 'PJ', 'PG', 'PE', 'PP', 'GF', 'GC', 'DG', 'PTS');

$tabla       = $torneoM->getPosiciones($ligaId);
$puntosOk    = true;
$difOk       = true;
$ptsPrevios  = PHP_INT_MAX;
$ordenOk     = true;
foreach ($tabla as $fila) {
    printf("  %-20s %3d %3d %3d %3d %3d %3d %4d %4d\n",
        substr((string) ($fila['contendiente'] ?? '?'), 0, 20),
        $fila['pj'], $fila['pg'], $fila['pe'], $fila['pp'],
        $fila['gf'], $fila['gc'], $fila['dg'], $fila['pts']);

    // Puntaje 3-1-0 y diferencia de gol derivada de goles a favor/en contra.
    if ((int) $fila['pts'] !== 3 * (int) $fila['pg'] + (int) $fila['pe']) { $puntosOk = false; }
    if ((int) $fila['dg'] !== (int) $fila['gf'] - (int) $fila['gc'])      { $difOk    = false; }
    if ((int) $fila['pts'] > $ptsPrevios) { $ordenOk = false; }
    $ptsPrevios = (int) $fila['pts'];
}

echo "\n";
verificar('Todos los participantes figuran en la tabla', count($tabla) === $n, 'hay ' . count($tabla));
verificar('Los puntos respetan 3 por victoria y 1 por empate', $puntosOk);
verificar('La diferencia de gol es GF - GC', $difOk);
verificar('La tabla viene ordenada por puntos descendente', $ordenOk);

// Identidades que deben cumplirse en cualquier torneo cerrado.
$suma = $pdo->query(
    "SELECT SUM(pj) pj, SUM(pg) pg, SUM(pe) pe, SUM(pp) pp, SUM(gf) gf, SUM(gc) gc
       FROM posiciones WHERE torneo_id = {$ligaId}"
)->fetch();
verificar('Cada partido suma 2 al total de PJ',
    (int) $suma['pj'] === 2 * count($partidos), "SUM(PJ)={$suma['pj']}");
verificar('Hay tantas victorias como derrotas',
    (int) $suma['pg'] === (int) $suma['pp'], "PG={$suma['pg']} PP={$suma['pp']}");
verificar('Los goles a favor igualan a los goles en contra',
    (int) $suma['gf'] === (int) $suma['gc'], "GF={$suma['gf']} GC={$suma['gc']}");
verificar('Los empates se cuentan de a pares',
    (int) $suma['pe'] % 2 === 0, "SUM(PE)={$suma['pe']}");

// ══════════════════════════════════════════════════════════════
// TORNEO 2 — ELIMINACION DIRECTA, 8 participantes
// ══════════════════════════════════════════════════════════════
titulo('TORNEO 2 — eliminacion directa, 8 participantes');

limpiarTorneo($pdo, TORNEO_ELIMINACION);

$copa = [];
for ($i = 1; $i <= 8; $i++) {
    $copa[] = participante($pdo, "Copa{$i}", 'Jugador', "copa{$i}");
}
$copaId = torneoCon($pdo, $organizador, TORNEO_ELIMINACION, 'eliminacion_directa', $copa);
echo "Torneo id={$copaId}, participantes: " . count($copa) . "\n\n";

$torneoCopa = $torneoM->findById($copaId);
$rondaNro   = 0;
$partidosPorRonda = [];

while (true) {
    $creados = $partidoM->generarSiguienteRonda($torneoCopa, $inscM->idsAprobados($copaId));
    if ($creados === 0) {
        break;   // ya hay campeón: no quedan cruces por generar
    }
    $rondaNro++;
    $partidosPorRonda[] = $creados;
    $nombreRonda = $pdo->query(
        "SELECT nombre FROM rondas WHERE torneo_id = {$copaId} ORDER BY numero DESC LIMIT 1"
    )->fetchColumn();
    echo "  Ronda {$rondaNro} ('{$nombreRonda}'): {$creados} partidos\n";

    // Resolver la ronda sin empates, para que siempre haya quien avance.
    $pendientes = $pdo->query(
        "SELECT p.id, p.local_id FROM partidos p
           LEFT JOIN resultados r ON r.partido_id = p.id
          WHERE p.torneo_id = {$copaId} AND r.id IS NULL ORDER BY p.id"
    )->fetchAll();
    foreach ($pendientes as $partido) {
        $resM->cargar((int) $partido['id'], 2, 1, (int) $partido['local_id'], $organizador);
        $partidoM->cambiarEstado((int) $partido['id'], 'finalizado');
    }

    if ($rondaNro > 6) {
        echo "  !! corte de seguridad: el motor no converge\n";
        break;
    }
}
$posM->recalcular($copaId);
$torneoM->cambiarEstado($copaId, 'finalizado');

echo "\n";
// 8 participantes → cuartos (4), semifinal (2), final (1).
verificar('Recorre 3 rondas hasta la final', $rondaNro === 3, "recorrió {$rondaNro}");
verificar('Cada ronda tiene la mitad de partidos que la anterior',
    $partidosPorRonda === [4, 2, 1], 'fue ' . implode('-', $partidosPorRonda));

$campeon = $pdo->query(
    "SELECT TRIM(CONCAT(u.nombre, ' ', u.apellido)) FROM resultados r
       INNER JOIN partidos p ON p.id = r.partido_id
       INNER JOIN usuarios u ON u.id = r.ganador_id
      WHERE p.torneo_id = {$copaId} ORDER BY p.ronda_id DESC, p.id DESC LIMIT 1"
)->fetchColumn();
verificar('Queda un campeon definido', $campeon !== false && $campeon !== null, '');
echo "  Campeon: {$campeon}\n";

// ══════════════════════════════════════════════════════════════
titulo('RESUMEN');

foreach (['usuarios', 'torneos', 'inscripciones', 'rondas', 'partidos', 'resultados', 'posiciones', 'avisos'] as $tabla) {
    printf("  %-14s %d\n", $tabla, $pdo->query("SELECT COUNT(*) FROM {$tabla}")->fetchColumn());
}

echo "\n";
if ($fallos === 0) {
    echo "Todas las verificaciones pasaron.\n";
    exit(0);
}
echo "Verificaciones fallidas: {$fallos}\n";
exit(1);
