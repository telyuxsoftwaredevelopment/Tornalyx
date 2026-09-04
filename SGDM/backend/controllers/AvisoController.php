<?php
require_once __DIR__ . '/../core/Controller.php';
require_once __DIR__ . '/../models/Aviso.php';
require_once __DIR__ . '/../models/Torneo.php';
require_once __DIR__ . '/../shared/Session.php';

/**
 * Controlador de avisos: el canal de comunicación entre organizadores y
 * participantes. Los avisos se generan solos ante cambios importantes
 * (fixture, horarios, resultados) y el organizador puede publicar novedades.
 */
class AvisoController extends Controller {

    private Aviso  $avisoModel;
    private Torneo $torneoModel;

    public function __construct() {
        parent::__construct();
        $this->avisoModel  = new Aviso();
        $this->torneoModel = new Torneo();
    }

    /**
     * Avisos públicos de un torneo (GET /api/torneo/{id}/avisos).
     */
    public function deTorneo(int $torneoId): void {
        $torneo = $this->torneoModel->findById($torneoId);
        if (!$torneo || !$torneo['publico']) {
            $this->jsonError('Torneo no encontrado.', [], 404);
            return;
        }
        $this->jsonSuccess(['avisos' => $this->avisoModel->listarPorTorneo($torneoId)]);
    }

    /**
     * El organizador publica una novedad (POST /api/aviso/publicar).
     */
    public function publicar(): void {
        if (!$this->requireApiLogin()) {
            return;
        }
        $torneoId = filter_input(INPUT_POST, 'torneo_id', FILTER_VALIDATE_INT);
        $titulo   = trim((string) (filter_input(INPUT_POST, 'titulo', FILTER_DEFAULT) ?? ''));
        $cuerpo   = trim((string) (filter_input(INPUT_POST, 'cuerpo', FILTER_DEFAULT) ?? ''));

        $torneo = $torneoId ? $this->torneoModel->findById($torneoId) : null;
        if (!$torneo) {
            $this->jsonError('Torneo no encontrado.', [], 404);
            return;
        }
        if (Session::getUserRole() !== 'administrador'
            && (int) $torneo['organizador_id'] !== Session::getUserId()) {
            $this->jsonError('No tenés permiso para publicar en este torneo.', [], 403);
            return;
        }
        if ($titulo === '' || mb_strlen($titulo) > 140) {
            $this->jsonError('El título es obligatorio y no puede superar los 140 caracteres.');
            return;
        }
        if (mb_strlen($cuerpo) > 2000) {
            $this->jsonError('El mensaje no puede superar los 2000 caracteres.');
            return;
        }

        $this->avisoModel->publicar(
            $torneoId,
            (int) Session::getUserId(),
            'novedad',
            $titulo,
            $cuerpo !== '' ? $cuerpo : null
        );

        $this->jsonSuccess([
            'avisos'  => $this->avisoModel->listarPorTorneo($torneoId),
            'mensaje' => 'Aviso publicado.',
        ]);
    }
}
