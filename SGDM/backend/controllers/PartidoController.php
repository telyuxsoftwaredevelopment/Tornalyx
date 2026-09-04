<?php
require_once __DIR__ . '/../core/Controller.php';
require_once __DIR__ . '/../models/Partido.php';
require_once __DIR__ . '/../models/Posicion.php';
require_once __DIR__ . '/../models/Resultado.php';
require_once __DIR__ . '/../models/Inscripcion.php';
require_once __DIR__ . '/../models/Equipo.php';
require_once __DIR__ . '/../models/Torneo.php';
require_once __DIR__ . '/../models/Aviso.php';
require_once __DIR__ . '/../shared/Session.php';

/**
 * Controlador de enfrentamientos: generación del fixture, programación de
 * fecha/hora/lugar, estado del partido y confirmación de asistencia.
 */
class PartidoController extends Controller {

    /** Estados admitidos para un partido (coinciden con el ENUM). */
    private const ESTADOS = ['pendiente', 'en_curso', 'finalizado', 'aplazado'];

    private Partido     $partidoModel;
    private Torneo      $torneoModel;
    private Inscripcion $inscripcionModel;
    private Equipo      $equipoModel;

    public function __construct() {
        parent::__construct();
        $this->partidoModel     = new Partido();
        $this->torneoModel      = new Torneo();
        $this->inscripcionModel = new Inscripcion();
        $this->equipoModel      = new Equipo();
    }

    /**
     * Fixture completo de un torneo (GET /api/torneo/{id}/partidos).
     * Público: cualquiera puede consultar el calendario de un torneo publicado.
     */
    public function listar(int $torneoId): void {
        $torneo = $this->torneoModel->findById($torneoId);
        if (!$torneo) {
            $this->jsonError('Torneo no encontrado.', [], 404);
            return;
        }
        if (!$torneo['publico'] && !$this->esGestor($torneo)) {
            $this->jsonError('Torneo no encontrado.', [], 404);
            return;
        }
        $this->jsonSuccess(['partidos' => $this->partidoModel->listarPorTorneo($torneoId)]);
    }

    /**
     * Genera la siguiente ronda del torneo (POST /api/torneo/fixture).
     * En liga crea el calendario completo; en eliminación y suizo, una ronda.
     */
    public function generar(): void {
        if (!$this->requireApiLogin()) {
            return;
        }
        $torneoId = filter_input(INPUT_POST, 'torneo_id', FILTER_VALIDATE_INT);
        $torneo   = $torneoId ? $this->torneoModel->findById($torneoId) : null;
        if (!$torneo) {
            $this->jsonError('Torneo no encontrado.', [], 404);
            return;
        }
        if (!$this->esGestor($torneo)) {
            $this->jsonError('No tenés permiso para gestionar este torneo.', [], 403);
            return;
        }

        $contendientes = !empty($torneo['requiere_equipos'])
            ? $this->equipoModel->idsAprobados($torneoId)
            : $this->inscripcionModel->idsAprobados($torneoId);

        if (count($contendientes) < 2) {
            $this->jsonError('Hacen falta al menos 2 participantes aprobados para generar el fixture.');
            return;
        }

        $creados = $this->partidoModel->generarSiguienteRonda($torneo, $contendientes);
        if ($creados === 0) {
            $this->jsonError('No hay nada que generar: la ronda en curso todavía tiene partidos sin resultado, o el torneo ya terminó.');
            return;
        }

        // Publicado el fixture, el torneo pasa a estar en juego.
        if ($torneo['estado'] === 'inscripcion') {
            $this->torneoModel->cambiarEstado($torneoId, 'en_curso');
        }
        (new Aviso())->publicar(
            $torneoId,
            (int) Session::getUserId(),
            'fixture',
            'Nuevos emparejamientos en ' . $torneo['nombre'],
            sprintf('Se publicaron %d enfrentamiento(s). Consultá tu rival y horario.', $creados)
        );

        $this->jsonSuccess([
            'creados'  => $creados,
            'partidos' => $this->partidoModel->listarPorTorneo($torneoId),
            'mensaje'  => sprintf('Se generaron %d enfrentamiento(s).', $creados),
        ]);
    }

    /**
     * Programa fecha, hora y lugar de un partido (POST /api/partido/programar).
     */
    public function programar(): void {
        if (!$this->requireApiLogin()) {
            return;
        }
        $partidoId = filter_input(INPUT_POST, 'partido_id', FILTER_VALIDATE_INT);
        $fecha     = trim((string) (filter_input(INPUT_POST, 'fecha_partido', FILTER_DEFAULT) ?? ''));
        $lugar     = trim((string) (filter_input(INPUT_POST, 'lugar',         FILTER_DEFAULT) ?? ''));

        $torneo = $this->torneoDePartido($partidoId);
        if ($torneo === null) {
            return;
        }
        if ($fecha !== '' && !$this->esFechaHoraValida($fecha)) {
            $this->jsonError('La fecha y hora del partido no son válidas.');
            return;
        }
        if (mb_strlen($lugar) > 120) {
            $this->jsonError('El lugar no puede superar los 120 caracteres.');
            return;
        }

        // El formato de <input type="datetime-local"> es YYYY-MM-DDTHH:MM.
        $this->partidoModel->programar($partidoId, str_replace('T', ' ', $fecha), $lugar);

        (new Aviso())->publicar(
            (int) $torneo['id'],
            (int) Session::getUserId(),
            'horario',
            'Cambio de horario en ' . $torneo['nombre'],
            'Se actualizó la fecha o el lugar de un enfrentamiento.'
        );

        $this->jsonSuccess([
            'partidos' => $this->partidoModel->listarPorTorneo((int) $torneo['id']),
            'mensaje'  => 'Enfrentamiento programado.',
        ]);
    }

    /**
     * Cambia el estado de un partido (POST /api/partido/estado):
     * pendiente, en juego, finalizado o aplazado.
     */
    public function estado(): void {
        if (!$this->requireApiLogin()) {
            return;
        }
        $partidoId = filter_input(INPUT_POST, 'partido_id', FILTER_VALIDATE_INT);
        $estado    = (string) (filter_input(INPUT_POST, 'estado', FILTER_DEFAULT) ?? '');

        $torneo = $this->torneoDePartido($partidoId);
        if ($torneo === null) {
            return;
        }
        if (!in_array($estado, self::ESTADOS, true)) {
            $this->jsonError('Estado de partido inválido.');
            return;
        }

        $this->partidoModel->cambiarEstado($partidoId, $estado);
        $this->jsonSuccess([
            'partidos' => $this->partidoModel->listarPorTorneo((int) $torneo['id']),
            'mensaje'  => 'Estado actualizado.',
        ]);
    }

    /**
     * Carga o corrige el resultado de un partido (POST /api/partido/resultado)
     * y recalcula la tabla de posiciones del torneo.
     */
    public function resultado(): void {
        if (!$this->requireApiLogin()) {
            return;
        }
        $partidoId = filter_input(INPUT_POST, 'partido_id',      FILTER_VALIDATE_INT);
        $golesL    = filter_input(INPUT_POST, 'goles_local',     FILTER_VALIDATE_INT);
        $golesV    = filter_input(INPUT_POST, 'goles_visitante', FILTER_VALIDATE_INT);

        $torneo = $this->torneoDePartido($partidoId);
        if ($torneo === null) {
            return;
        }
        if ($golesL === null || $golesV === null || $golesL === false || $golesV === false
            || $golesL < 0 || $golesV < 0) {
            $this->jsonError('Los marcadores deben ser números enteros no negativos.');
            return;
        }

        $partido = $this->partidoModel->findById($partidoId);
        $ganador = null;
        if ($golesL > $golesV) {
            $ganador = (int) $partido['local_id'];
        } elseif ($golesV > $golesL) {
            $ganador = (int) $partido['visitante_id'];
        }

        (new Resultado())->cargar($partidoId, $golesL, $golesV, $ganador, (int) Session::getUserId());
        // La tabla es un derivado de los resultados: se rehace entera.
        (new Posicion())->recalcular((int) $torneo['id']);

        (new Aviso())->publicar(
            (int) $torneo['id'],
            (int) Session::getUserId(),
            'resultado',
            'Resultado cargado en ' . $torneo['nombre'],
            sprintf('Se registró un marcador %d - %d. La tabla de posiciones ya está actualizada.', $golesL, $golesV)
        );

        $this->jsonSuccess([
            'partidos'   => $this->partidoModel->listarPorTorneo((int) $torneo['id']),
            'posiciones' => $this->torneoModel->getPosiciones((int) $torneo['id']),
            'mensaje'    => 'Resultado guardado y posiciones actualizadas.',
        ]);
    }

    /**
     * Confirma (o desmarca) la asistencia del usuario a un partido
     * (POST /api/partido/asistencia).
     */
    public function asistencia(): void {
        if (!$this->requireApiLogin()) {
            return;
        }
        $partidoId = filter_input(INPUT_POST, 'partido_id', FILTER_VALIDATE_INT);
        $estado    = (string) (filter_input(INPUT_POST, 'estado', FILTER_DEFAULT) ?? 'confirmada');
        if (!$partidoId || !in_array($estado, ['confirmada', 'ausente'], true)) {
            $this->jsonError('Datos inválidos.');
            return;
        }

        $usuarioId = (int) Session::getUserId();
        if (!in_array($usuarioId, $this->inscripcionModel->usuariosDePartido($partidoId), true)) {
            $this->jsonError('No jugás este enfrentamiento.', [], 403);
            return;
        }

        $this->partidoModel->confirmarAsistencia($partidoId, $usuarioId, $estado);

        $this->jsonSuccess([
            'estado'  => $estado,
            'mensaje' => $estado === 'confirmada' ? 'Asistencia confirmada.' : 'Marcaste que no vas a asistir.',
        ]);
    }

    // ──────────────────────────────────────────────────────
    // Helpers privados
    // ──────────────────────────────────────────────────────

    /** True si el usuario en sesión organiza el torneo o es administrador. */
    private function esGestor(array $torneo): bool {
        return Session::getUserRole() === 'administrador'
            || (int) $torneo['organizador_id'] === Session::getUserId();
    }

    /**
     * Devuelve el torneo del partido validando permisos. Si algo falla,
     * ya respondió el error y devuelve null.
     */
    private function torneoDePartido(int|false|null $partidoId): ?array {
        if (!$partidoId) {
            $this->jsonError('Falta indicar el enfrentamiento.');
            return null;
        }
        $torneoId = $this->partidoModel->torneoDe($partidoId);
        if ($torneoId === null) {
            $this->jsonError('Enfrentamiento no encontrado.', [], 404);
            return null;
        }
        $torneo = $this->torneoModel->findById($torneoId);
        if (!$torneo || !$this->esGestor($torneo)) {
            $this->jsonError('No tenés permiso para gestionar este torneo.', [], 403);
            return null;
        }
        return $torneo;
    }

    /**
     * Valida 'YYYY-MM-DDTHH:MM' o 'YYYY-MM-DD HH:MM(:SS)', con año dentro de
     * un rango razonable. `Y` acepta años de más de 4 dígitos (un typo pasa
     * el formato igual) y sin este piso el UPDATE explota contra la columna
     * DATETIME de la base recién al guardar.
     */
    private function esFechaHoraValida(string $valor): bool {
        $valor = str_replace('T', ' ', $valor);
        foreach (['!Y-m-d H:i', '!Y-m-d H:i:s'] as $formato) {
            $d = DateTimeImmutable::createFromFormat($formato, $valor);
            if ($d !== false) {
                $anio = (int) $d->format('Y');
                return $anio >= 1900 && $anio <= 2200;
            }
        }
        return false;
    }
}
