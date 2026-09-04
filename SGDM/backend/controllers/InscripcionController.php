<?php
require_once __DIR__ . '/../core/Controller.php';
require_once __DIR__ . '/../models/Inscripcion.php';
require_once __DIR__ . '/../models/Equipo.php';
require_once __DIR__ . '/../models/Torneo.php';
require_once __DIR__ . '/../models/Aviso.php';
require_once __DIR__ . '/../shared/Session.php';

/**
 * Controlador de inscripciones y equipos.
 *
 * Cubre el alta en línea del participante (solo o con equipo), la creación y
 * la unión a equipos, y la aprobación por parte del organizador.
 */
class InscripcionController extends Controller {

    private Inscripcion $inscripcionModel;
    private Equipo      $equipoModel;
    private Torneo      $torneoModel;

    public function __construct() {
        parent::__construct();
        $this->inscripcionModel = new Inscripcion();
        $this->equipoModel      = new Equipo();
        $this->torneoModel      = new Torneo();
    }

    /**
     * Inscribe al usuario autenticado en un torneo (POST /api/inscripcion).
     *
     * Acepta `equipo_id` para sumarse a un equipo existente o `equipo_nombre`
     * para crear uno nuevo y quedar como capitán. La confirmación es inmediata:
     * la respuesta indica si quedó aprobada o pendiente de revisión.
     */
    public function inscribirse(): void {
        if (!$this->requireApiLogin()) {
            return;
        }
        $usuarioId = (int) Session::getUserId();
        $torneoId  = filter_input(INPUT_POST, 'torneo_id', FILTER_VALIDATE_INT);
        if (!$torneoId) {
            $this->jsonError('Falta indicar el torneo.');
            return;
        }

        $torneo = $this->torneoModel->findById($torneoId);
        if (!$torneo || !$torneo['publico']) {
            $this->jsonError('Torneo no encontrado.', [], 404);
            return;
        }
        if ($torneo['estado'] !== 'inscripcion') {
            $this->jsonError('Este torneo no tiene las inscripciones abiertas.');
            return;
        }
        if ($this->inscripcionModel->deUsuario($usuarioId, $torneoId)) {
            $this->jsonError('Ya estás inscripto en este torneo.', [], 409);
            return;
        }
        if ($this->inscripcionModel->contarAprobadas($torneoId) >= (int) $torneo['max_participantes']) {
            $this->jsonError('El torneo ya cubrió todos sus cupos.');
            return;
        }

        // Resolver el equipo cuando el torneo se juega de a equipos.
        $equipoId = null;
        if (!empty($torneo['requiere_equipos'])) {
            $error = $this->resolverEquipo($usuarioId, $torneoId, $equipoId);
            if ($error !== null) {
                $this->jsonError($error);
                return;
            }
        }

        $id = $this->inscripcionModel->inscribir($usuarioId, $torneoId, $equipoId);
        $inscripcion = $this->inscripcionModel->findById($id);

        (new Aviso())->publicar(
            $torneoId,
            $usuarioId,
            'inscripcion',
            'Nueva inscripción en ' . $torneo['nombre'],
            'Se registró una nueva inscripción y está pendiente de aprobación.'
        );

        $this->jsonSuccess([
            'inscripcion' => $inscripcion,
            'mensaje'     => 'Inscripción registrada. Queda pendiente de aprobación del organizador.',
        ]);
    }

    /**
     * Cancela la propia inscripción (POST /api/inscripcion/cancelar).
     */
    public function cancelar(): void {
        if (!$this->requireApiLogin()) {
            return;
        }
        $torneoId = filter_input(INPUT_POST, 'torneo_id', FILTER_VALIDATE_INT);
        if (!$torneoId) {
            $this->jsonError('Falta indicar el torneo.');
            return;
        }

        $inscripcion = $this->inscripcionModel->deUsuario((int) Session::getUserId(), $torneoId);
        if (!$inscripcion) {
            $this->jsonError('No estás inscripto en este torneo.', [], 404);
            return;
        }
        // Con el torneo en marcha ya hay fixture armado: bajarse rompería el
        // calendario, así que solo se permite antes de que arranque.
        $torneo = $this->torneoModel->findById($torneoId);
        if ($torneo && $torneo['estado'] !== 'inscripcion') {
            $this->jsonError('El torneo ya comenzó: hablá con el organizador para darte de baja.');
            return;
        }

        $this->inscripcionModel->delete((int) $inscripcion['id']);
        $this->jsonSuccess(['mensaje' => 'Inscripción cancelada.']);
    }

    /**
     * Inscripciones de un torneo (GET /api/torneo/{id}/inscripciones).
     * Solo el organizador dueño y los administradores.
     */
    public function listar(int $torneoId): void {
        if (!$this->requireApiLogin()) {
            return;
        }
        if (!$this->puedeGestionar($torneoId)) {
            return;
        }
        $this->jsonSuccess([
            'inscripciones' => $this->inscripcionModel->listarPorTorneo($torneoId),
            'equipos'       => $this->equipoModel->listarPorTorneo($torneoId),
        ]);
    }

    /**
     * Aprueba o rechaza una inscripción (POST /api/inscripcion/resolver).
     */
    public function resolver(): void {
        if (!$this->requireApiLogin()) {
            return;
        }
        $id     = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $accion = (string) (filter_input(INPUT_POST, 'accion', FILTER_DEFAULT) ?? '');
        if (!$id || !in_array($accion, ['aprobar', 'rechazar'], true)) {
            $this->jsonError('Datos inválidos.');
            return;
        }

        $inscripcion = $this->inscripcionModel->findById($id);
        if (!$inscripcion) {
            $this->jsonError('La inscripción no existe.', [], 404);
            return;
        }
        if (!$this->puedeGestionar((int) $inscripcion['torneo_id'])) {
            return;
        }

        $estado = $accion === 'aprobar' ? 'aprobada' : 'rechazada';
        $this->inscripcionModel->resolver($id, $estado);

        // Aprobar al capitán aprueba también a su equipo, para que entre al fixture.
        if ($estado === 'aprobada' && !empty($inscripcion['equipo_id'])) {
            $this->equipoModel->resolver((int) $inscripcion['equipo_id'], 'aprobado');
        }

        $this->jsonSuccess([
            'estado'  => $estado,
            'mensaje' => $estado === 'aprobada' ? 'Inscripción aprobada.' : 'Inscripción rechazada.',
        ]);
    }

    // ──────────────────────────────────────────────────────
    // Helpers privados
    // ──────────────────────────────────────────────────────

    /**
     * Resuelve con qué equipo se inscribe el usuario: uno existente
     * (`equipo_id`) o uno nuevo (`equipo_nombre`), del que queda como capitán.
     *
     * @param int|null $equipoId Salida: id del equipo resuelto.
     * @return string|null Mensaje de error, o null si salió bien.
     */
    private function resolverEquipo(int $usuarioId, int $torneoId, ?int &$equipoId): ?string {
        $existente = filter_input(INPUT_POST, 'equipo_id', FILTER_VALIDATE_INT);
        $nombre    = trim((string) (filter_input(INPUT_POST, 'equipo_nombre', FILTER_DEFAULT) ?? ''));

        if ($existente) {
            $equipo = $this->equipoModel->findById($existente);
            if (!$equipo || (int) $equipo['torneo_id'] !== $torneoId) {
                return 'El equipo elegido no pertenece a este torneo.';
            }
            $equipoId = $existente;
            return null;
        }

        if ($nombre === '') {
            return 'Este torneo es por equipos: elegí uno o creá el tuyo.';
        }
        if (mb_strlen($nombre) > 80) {
            return 'El nombre del equipo no puede superar los 80 caracteres.';
        }
        if ($this->equipoModel->existeNombre($torneoId, $nombre)) {
            return 'Ya existe un equipo con ese nombre en el torneo.';
        }

        $equipoId = $this->equipoModel->crear($usuarioId, $torneoId, $nombre);
        return null;
    }

    /**
     * Verifica que el usuario pueda gestionar el torneo (es su organizador o
     * es administrador). Responde el error correspondiente y devuelve false.
     */
    private function puedeGestionar(int $torneoId): bool {
        $torneo = $this->torneoModel->findById($torneoId);
        if (!$torneo) {
            $this->jsonError('Torneo no encontrado.', [], 404);
            return false;
        }
        if (Session::getUserRole() !== 'administrador'
            && (int) $torneo['organizador_id'] !== Session::getUserId()) {
            $this->jsonError('No tenés permiso para gestionar este torneo.', [], 403);
            return false;
        }
        return true;
    }
}
