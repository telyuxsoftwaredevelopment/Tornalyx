<?php
require_once __DIR__ . '/../core/Controller.php';
require_once __DIR__ . '/../models/Torneo.php';
require_once __DIR__ . '/../models/Resultado.php';
require_once __DIR__ . '/../models/Posicion.php';
require_once __DIR__ . '/../models/Partido.php';
require_once __DIR__ . '/../models/Aviso.php';
require_once __DIR__ . '/../models/Inscripcion.php';
require_once __DIR__ . '/../shared/Session.php';

/**
 * Controlador de torneos.
 * Maneja listado, creación, edición, resultados y posiciones.
 */
class TorneoController extends Controller {

    /** Formatos de torneo aceptados (coinciden con el ENUM de la tabla). */
    private const FORMATOS = ['liga', 'eliminacion_directa', 'suizo', 'grupos_playoff'];

    /** Estados aceptados (coinciden con el ENUM de la tabla). */
    private const ESTADOS = ['borrador', 'inscripcion', 'en_curso', 'finalizado', 'cancelado'];

    /** Límites de participantes admitidos al crear un torneo. */
    private const MIN_PARTICIPANTES = 2;
    private const MAX_PARTICIPANTES = 512;

    /** Tamaño máximo de la foto de fondo en bytes (4 MB: más margen que el
     *  avatar porque acá cubre toda una tarjeta/hero, no un círculo chico). */
    private const BANNER_MAX_BYTES = 4 * 1024 * 1024;

    /** MIME reales admitidos para la foto de fondo. */
    private const BANNER_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /** Ruta pública donde se sirve la foto de un torneo (ver servirBanner). */
    private const BANNER_URL = '/api/torneo';

    private Torneo    $torneoModel;
    private Resultado $resultadoModel;

    public function __construct() {
        parent::__construct();
        $this->torneoModel    = new Torneo();
        $this->resultadoModel = new Resultado();
    }

    /**
     * Lista torneos públicos con filtros (GET /api/torneos).
     */
    public function index(): void {
        $estado  = (string) (filter_input(INPUT_GET, 'estado',  FILTER_DEFAULT) ?? '');
        $formato = (string) (filter_input(INPUT_GET, 'formato', FILTER_DEFAULT) ?? '');

        $filtros = [
            // Solo se aceptan valores del ENUM; cualquier otra cosa se ignora
            // en vez de devolver un listado vacío sin explicación.
            'estado'     => in_array($estado,  self::ESTADOS,  true) ? $estado  : '',
            'formato'    => in_array($formato, self::FORMATOS, true) ? $formato : '',
            'disciplina' => trim((string) (filter_input(INPUT_GET, 'disciplina', FILTER_DEFAULT) ?? '')),
        ];
        $torneos = $this->torneoModel->listarPublicos(array_filter($filtros));
        $this->jsonSuccess(['torneos' => array_map([$this, 'normalizar'], $torneos)]);
    }

    /**
     * Torneos del usuario autenticado (GET /api/torneos/mios).
     * El organizador ve los suyos; el administrador ve todos.
     */
    public function mios(): void {
        if (!$this->requireApiLogin()) {
            return;
        }

        $torneos = Session::getUserRole() === 'administrador'
            ? $this->torneoModel->listarTodosConMetricas()
            : $this->torneoModel->listarDeOrganizador((int) Session::getUserId());

        $this->jsonSuccess(['torneos' => array_map([$this, 'normalizar'], $torneos)]);
    }

    /**
     * Detalle de un torneo (GET /api/torneo/{id}).
     *
     * @param int $id
     */
    public function show(int $id): void {
        $torneo = $this->torneoModel->findConOrganizador($id);
        if (!$torneo) {
            http_response_code(404);
            $this->jsonError('Torneo no encontrado.');
            return;
        }

        // Los torneos no públicos (borradores) solo son visibles para su
        // organizador dueño y para administradores. Para cualquier otro se
        // responde 404 (no 403) para no revelar siquiera su existencia.
        if (!$torneo['publico']) {
            $esDueno = Session::getUserId() === (int) $torneo['organizador_id'];
            $esAdmin = Session::getUserRole() === 'administrador';
            if (!$esDueno && !$esAdmin) {
                http_response_code(404);
                $this->jsonError('Torneo no encontrado.');
                return;
            }
        }
        $this->jsonSuccess([
            'torneo'     => $this->normalizar($torneo),
            'posiciones' => $this->torneoModel->getPosiciones($id),
            'resultados' => $this->resultadoModel->listarPorTorneo($id),
            'equipos'    => $this->torneoModel->getEquipos($id),
            // Fixture completo (con o sin resultado) y novedades del torneo:
            // así el detalle muestra calendario, rival, horario, lugar y estado.
            'partidos'   => (new Partido())->listarPorTorneo($id),
            'avisos'     => (new Aviso())->listarPorTorneo($id, 10),
            // Inscripción del usuario en sesión, para saber qué botón mostrar.
            'mi_inscripcion' => Session::isLoggedIn()
                ? (new Inscripcion())->deUsuario((int) Session::getUserId(), $id)
                : null,
        ]);
    }

    /**
     * Crea un nuevo torneo (POST /api/torneo/crear).
     * Cualquier usuario logueado puede crear torneos: queda como su
     * organizador (organizador_id), un rol por torneo, no de cuenta.
     */
    public function store(): void {
        if (!$this->requireApiLogin()) {
            return;
        }

        $organizadorId = (int) Session::getUserId();
        $campos = $this->validarCampos($organizadorId);
        if ($campos === null) {
            return;
        }

        // Un borrador queda privado hasta que el organizador lo publique; un
        // torneo publicado abre inscripciones y aparece en el listado público.
        $id = $this->torneoModel->insert([
            'organizador_id'    => $organizadorId,
            'nombre'            => $campos['nombre'],
            'descripcion'       => $campos['descripcion'],
            'reglamento'        => $campos['reglamento'],
            'premios'           => $campos['premios'],
            'discord_url'       => $campos['discord'],
            'disciplina'        => $campos['disciplina'],
            'formato'           => $campos['formato'],
            'requiere_equipos'  => $campos['porEquipos'] ? 1 : 0,
            'max_participantes' => $campos['maxPart'],
            'fecha_inicio'      => $campos['fechaInicio'],
            'fecha_fin'         => $campos['fechaFin'],
            'estado'            => $campos['publicar'] ? 'inscripcion' : 'borrador',
            'publico'           => $campos['publicar'] ? 1 : 0,
        ]);

        // Inscripciones abiertas: se avisa a la comunidad (RF-05).
        if ($campos['publicar']) {
            (new Aviso())->publicar(
                $id,
                $organizadorId,
                'inscripcion',
                'Inscripciones abiertas: ' . $campos['nombre'],
                sprintf('Nuevo torneo de %s. Ya podés inscribirte.', $campos['disciplina'])
            );
        }

        // Se devuelve el torneo completo para que el panel lo pinte sin recargar.
        $torneo = $this->torneoModel->findConOrganizador($id);
        $this->jsonSuccess([
            'id'      => $id,
            'torneo'  => $torneo ? $this->normalizar($torneo) : null,
            'mensaje' => $campos['publicar']
                ? 'Torneo creado y publicado. Ya acepta inscripciones.'
                : 'Torneo guardado como borrador.',
        ]);
    }

    /**
     * Edita los ajustes de un torneo existente (POST /api/torneo/{id}/editar).
     * Solo el organizador dueño o un administrador.
     */
    public function actualizar(int $id): void {
        if (!$this->requireApiLogin()) {
            return;
        }
        $torneo = $this->torneoModel->findById($id);
        if (!$torneo) {
            $this->jsonError('Torneo no encontrado.', [], 404);
            return;
        }
        if (!$this->esDueno($torneo)) {
            $this->jsonError('No tenés permiso para editar este torneo.', [], 403);
            return;
        }

        $campos = $this->validarCampos((int) $torneo['organizador_id'], $id);
        if ($campos === null) {
            return;
        }

        // El cupo no puede bajar de lo ya inscripto: rompería el fixture armado.
        $inscriptos = (new Inscripcion())->contarAprobadas($id);
        if ($campos['maxPart'] < $inscriptos) {
            $this->jsonError(sprintf(
                'Ya hay %d inscriptos aprobados: el cupo no puede ser menor a esa cantidad.',
                $inscriptos
            ), ['campo' => 'max_participantes']);
            return;
        }

        $tieneActividad = $this->tieneActividad($id);

        // El formato define cómo se arma el fixture: no se puede tocar una vez
        // generado, porque los partidos ya creados quedarían inconsistentes.
        $tieneFixture = count((new Partido())->listarPorTorneo($id)) > 0;
        if ($tieneFixture && $campos['formato'] !== $torneo['formato']) {
            $this->jsonError(
                'El torneo ya tiene un fixture generado: no se puede cambiar el formato.',
                ['campo' => 'formato']
            );
            return;
        }

        // "Por equipos" define si las inscripciones llevan equipo_id: cambiar
        // la modalidad con inscripciones ya cargadas las dejaría huérfanas.
        // Solo aplica si el formulario que llamó realmente envió este campo
        // (el panel de admin usa una versión simplificada del formulario que
        // no lo incluye, y en ese caso se preserva el valor actual más abajo).
        $requiereEquiposEnviado = filter_input(INPUT_POST, 'requiere_equipos', FILTER_DEFAULT) !== null;
        if ($requiereEquiposEnviado && $tieneActividad
            && ((int) $campos['porEquipos'] !== (int) $torneo['requiere_equipos'])) {
            $this->jsonError(
                'El torneo ya tiene inscripciones o partidos: no se puede cambiar la modalidad (individual/equipos).',
                ['campo' => 'requiere_equipos']
            );
            return;
        }

        // Los formularios simplificados (p. ej. el del panel de admin) no
        // incluyen todos los campos del torneo. Si un campo no vino en el
        // POST, se conserva el valor actual en vez de vaciarlo/resetearlo.
        $datos = [
            'nombre'            => $campos['nombre'],
            'descripcion'       => $campos['descripcion'],
            'disciplina'        => $campos['disciplina'],
            'formato'           => $campos['formato'],
            'max_participantes' => $campos['maxPart'],
            'fecha_inicio'      => $campos['fechaInicio'],
            'fecha_fin'         => $campos['fechaFin'],
        ];
        foreach ([
            'reglamento'  => 'reglamento',
            'premios'     => 'premios',
            'discord_url' => 'discord',
        ] as $columna => $clave) {
            if (filter_input(INPUT_POST, $columna, FILTER_DEFAULT) !== null) {
                $datos[$columna] = $campos[$clave];
            }
        }
        if ($requiereEquiposEnviado) {
            $datos['requiere_equipos'] = $campos['porEquipos'] ? 1 : 0;
        }

        $this->torneoModel->update($id, $datos);

        $torneoActualizado = $this->torneoModel->findConOrganizador($id);
        $this->jsonSuccess([
            'torneo'  => $torneoActualizado ? $this->normalizar($torneoActualizado) : null,
            'mensaje' => 'Ajustes del torneo guardados.',
        ]);
    }

    /**
     * Elimina un torneo (POST /api/torneo/{id}/eliminar).
     * Solo si todavía no tiene actividad (inscripciones, aunque estén
     * pendientes de revisión, o partidos); si ya la tiene, se sugiere
     * cancelarlo en vez de borrarlo.
     */
    public function eliminar(int $id): void {
        if (!$this->requireApiLogin()) {
            return;
        }
        $torneo = $this->torneoModel->findById($id);
        if (!$torneo) {
            $this->jsonError('Torneo no encontrado.', [], 404);
            return;
        }
        if (!$this->esDueno($torneo)) {
            $this->jsonError('No tenés permiso para eliminar este torneo.', [], 403);
            return;
        }

        if ($this->tieneActividad($id)) {
            $this->jsonError(
                'Este torneo ya tiene actividad (inscripciones o partidos): no se puede eliminar. Cancelalo en vez de borrarlo.',
                ['sugerir_cancelar' => true],
                409
            );
            return;
        }

        $this->torneoModel->delete($id);
        $this->jsonSuccess(['mensaje' => 'Torneo eliminado.']);
    }

    /**
     * True si el torneo ya tiene inscripciones (en cualquier estado, no solo
     * aprobadas: una solicitud pendiente ya es actividad real de alguien) o
     * partidos generados. Se usa para bloquear el borrado y el cambio de
     * modalidad (individual/equipos) una vez que hay gente involucrada.
     */
    private function tieneActividad(int $torneoId): bool {
        return count((new Inscripcion())->listarPorTorneo($torneoId)) > 0
            || count((new Partido())->listarPorTorneo($torneoId)) > 0;
    }

    /**
     * Cancela un torneo (POST /api/torneo/{id}/cancelar): lo saca de juego
     * sin borrar su historial. Solo el organizador dueño o un administrador.
     */
    public function cancelar(int $id): void {
        if (!$this->requireApiLogin()) {
            return;
        }
        $torneo = $this->torneoModel->findById($id);
        if (!$torneo) {
            $this->jsonError('Torneo no encontrado.', [], 404);
            return;
        }
        if (!$this->esDueno($torneo)) {
            $this->jsonError('No tenés permiso para cancelar este torneo.', [], 403);
            return;
        }

        $this->torneoModel->cambiarEstado($id, 'cancelado');
        $this->jsonSuccess(['mensaje' => 'Torneo cancelado.']);
    }

    /**
     * Sube o reemplaza la foto de fondo del torneo (POST /api/torneo/{id}/banner).
     * Mismo criterio que PerfilController::avatar(): valida tipo real
     * (finfo + getimagesize) y tamaño, se guarda como BLOB en la propia fila
     * (Render free no tiene disco persistente) y se sirve vía servirBanner().
     * Solo el organizador dueño del torneo o un administrador.
     */
    public function imagen(int $id): void {
        if (!$this->requireApiLogin()) {
            return;
        }
        $torneo = $this->torneoModel->findById($id);
        if (!$torneo) {
            $this->jsonError('Torneo no encontrado.', [], 404);
            return;
        }
        if (!$this->esDueno($torneo)) {
            $this->jsonError('No tenés permiso para editar este torneo.', [], 403);
            return;
        }

        $file = $_FILES['banner'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->jsonError('No se recibió ninguna imagen.');
            return;
        }
        if ((int) $file['size'] > self::BANNER_MAX_BYTES) {
            $this->jsonError('La imagen no puede superar los 4 MB.');
            return;
        }

        // Tipo real del archivo, no el que declara el cliente.
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(self::BANNER_MIMES[$mime]) || getimagesize($file['tmp_name']) === false) {
            $this->jsonError('Formato no admitido. Usá JPG, PNG o WebP.');
            return;
        }

        $datos = file_get_contents($file['tmp_name']);
        if ($datos === false) {
            $this->jsonError('No se pudo leer la imagen. Intentá más tarde.', [], 500);
            return;
        }

        // La URL cambia con cada subida (cache-busting): el navegador cachea
        // agresivo las imágenes, y sin esto seguiría mostrando la vieja
        // aunque la fila ya tenga la nueva.
        $url = self::BANNER_URL . '/' . $id . '/banner?v=' . time();
        $this->torneoModel->update($id, [
            'banner_data' => $datos,
            'banner_mime' => $mime,
            'banner_url'  => $url,
        ]);
        $this->jsonSuccess(['banner_url' => $url]);
    }

    /**
     * Sirve la foto de fondo de un torneo guardada en la base (GET
     * /api/torneo/{id}/banner). Pública y sin autenticación: es la misma
     * imagen que ya se ve en el listado público de torneos.
     */
    public function servirBanner(int $id): void {
        $torneo = $this->torneoModel->findById($id);
        if (!$torneo || $torneo['banner_data'] === null || $torneo['banner_mime'] === null) {
            http_response_code(404);
            return;
        }
        header('Content-Type: ' . $torneo['banner_mime']);
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: ' . strlen($torneo['banner_data']));
        echo $torneo['banner_data'];
    }

    /** True si el usuario en sesión organiza el torneo o es administrador. */
    private function esDueno(array $torneo): bool {
        return Session::getUserRole() === 'administrador'
            || (int) $torneo['organizador_id'] === Session::getUserId();
    }

    /**
     * Lee y valida los campos del formulario de torneo (crear o editar). El
     * front necesita saber QUÉ campo falló, por eso cada error trae 'campo'.
     *
     * @param int      $organizadorId    Dueño contra el que se chequea nombre duplicado.
     * @param int|null $excluirTorneoId  Al editar, el propio torneo (no choca contra sí mismo).
     * @return array|null Campos normalizados, o null si ya se respondió un error.
     */
    private function validarCampos(int $organizadorId, ?int $excluirTorneoId = null): ?array {
        // Los textos se guardan crudos (ya validados) y se escapan al mostrarlos.
        // Sanitizarlos acá guardaría entidades HTML en la BD y el nombre saldría
        // como "Copa &#34;A&#34;" en cada vista.
        $nombre      = trim((string) (filter_input(INPUT_POST, 'nombre',       FILTER_DEFAULT) ?? ''));
        $descripcion = trim((string) (filter_input(INPUT_POST, 'descripcion',  FILTER_DEFAULT) ?? ''));
        $reglamento  = trim((string) (filter_input(INPUT_POST, 'reglamento',   FILTER_DEFAULT) ?? ''));
        $premios     = trim((string) (filter_input(INPUT_POST, 'premios',      FILTER_DEFAULT) ?? ''));
        $discord     = trim((string) (filter_input(INPUT_POST, 'discord_url',  FILTER_DEFAULT) ?? ''));
        $disciplina  = trim((string) (filter_input(INPUT_POST, 'disciplina',   FILTER_DEFAULT) ?? ''));
        $formato     = trim((string) (filter_input(INPUT_POST, 'formato',      FILTER_DEFAULT) ?? ''));
        $fechaInicio = trim((string) (filter_input(INPUT_POST, 'fecha_inicio', FILTER_DEFAULT) ?? ''));
        $fechaFin    = trim((string) (filter_input(INPUT_POST, 'fecha_fin',    FILTER_DEFAULT) ?? ''));
        $maxPart     = filter_input(INPUT_POST, 'max_participantes', FILTER_VALIDATE_INT);
        $publicar    = filter_var(
            filter_input(INPUT_POST, 'publicar', FILTER_DEFAULT) ?? '1',
            FILTER_VALIDATE_BOOLEAN
        );
        $porEquipos  = filter_var(
            filter_input(INPUT_POST, 'requiere_equipos', FILTER_DEFAULT) ?? '0',
            FILTER_VALIDATE_BOOLEAN
        );

        // ── Validación campo por campo: el front necesita saber QUÉ falló ──
        if ($nombre === '' || $disciplina === '' || $formato === '') {
            $this->jsonError('Nombre, disciplina y formato son obligatorios.', ['campo' => 'nombre']);
            return null;
        }
        if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 120) {
            $this->jsonError('El nombre debe tener entre 3 y 120 caracteres.', ['campo' => 'nombre']);
            return null;
        }
        if (mb_strlen($disciplina) > 60) {
            $this->jsonError('La disciplina no puede superar los 60 caracteres.', ['campo' => 'disciplina']);
            return null;
        }
        if (mb_strlen($descripcion) > 2000) {
            $this->jsonError('La descripción no puede superar los 2000 caracteres.', ['campo' => 'descripcion']);
            return null;
        }
        if (mb_strlen($reglamento) > 8000) {
            $this->jsonError('El reglamento no puede superar los 8000 caracteres.', ['campo' => 'reglamento']);
            return null;
        }
        if (mb_strlen($premios) > 2000) {
            $this->jsonError('Los premios no pueden superar los 2000 caracteres.', ['campo' => 'premios']);
            return null;
        }
        if ($discord !== '' && mb_strlen($discord) > 255) {
            $this->jsonError('El enlace del canal oficial no puede superar los 255 caracteres.', ['campo' => 'discord_url']);
            return null;
        }
        // FILTER_VALIDATE_URL solo exige sintaxis de URI: acepta esquemas como
        // "javascript:" o "data:" como válidos. Sin este chequeo de esquema, un
        // organizador podría guardar "javascript:alert(1)" como canal oficial y
        // el front lo vuelca tal cual en el href del botón "Entrar al canal".
        if ($discord !== '' && (!filter_var($discord, FILTER_VALIDATE_URL)
            || !preg_match('#^https?://#i', $discord))) {
            $this->jsonError('El enlace del canal oficial debe ser una URL http(s) válida.', ['campo' => 'discord_url']);
            return null;
        }
        if (!in_array($formato, self::FORMATOS, true)) {
            $this->jsonError('Formato de torneo inválido.', ['campo' => 'formato']);
            return null;
        }

        $maxPart = $maxPart ?: 16;
        if ($maxPart < self::MIN_PARTICIPANTES || $maxPart > self::MAX_PARTICIPANTES) {
            $this->jsonError(sprintf(
                'La cantidad de participantes debe estar entre %d y %d.',
                self::MIN_PARTICIPANTES, self::MAX_PARTICIPANTES
            ), ['campo' => 'max_participantes']);
            return null;
        }

        if ($fechaInicio !== '' && !$this->esFechaValida($fechaInicio)) {
            $this->jsonError('La fecha de inicio no es válida.', ['campo' => 'fecha_inicio']);
            return null;
        }
        if ($fechaFin !== '' && !$this->esFechaValida($fechaFin)) {
            $this->jsonError('La fecha de fin no es válida.', ['campo' => 'fecha_fin']);
            return null;
        }
        if ($fechaInicio !== '' && $fechaFin !== '' && $fechaFin < $fechaInicio) {
            $this->jsonError('La fecha de fin no puede ser anterior a la de inicio.', ['campo' => 'fecha_fin']);
            return null;
        }

        if ($this->torneoModel->existeNombreDeOrganizador($organizadorId, $nombre, $excluirTorneoId)) {
            $this->jsonError('Ya tenés un torneo con ese nombre.', ['campo' => 'nombre'], 409);
            return null;
        }

        return [
            'nombre'      => $nombre,
            'descripcion' => $descripcion !== '' ? $descripcion : null,
            'reglamento'  => $reglamento  !== '' ? $reglamento  : null,
            'premios'     => $premios     !== '' ? $premios     : null,
            'discord'     => $discord     !== '' ? $discord     : null,
            'disciplina'  => $disciplina,
            'formato'     => $formato,
            'porEquipos'  => $porEquipos,
            'maxPart'     => $maxPart,
            'fechaInicio' => $fechaInicio !== '' ? $fechaInicio : null,
            'fechaFin'    => $fechaFin    !== '' ? $fechaFin    : null,
            'publicar'    => $publicar,
        ];
    }

    /**
     * Valida una fecha en formato YYYY-MM-DD (el que envía <input type="date">),
     * rechazando fechas inexistentes como 2026-02-31.
     */
    private function esFechaValida(string $fecha): bool {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        if ($d === false || $d->format('Y-m-d') !== $fecha) {
            return false;
        }
        // `Y` acepta años de más de 4 dígitos (p. ej. "20260-01-01" pasa el
        // formato igual): sin este rango, un typo explota contra la columna
        // DATE de la base recién al guardar. A diferencia de una fecha de
        // nacimiento, un torneo sí puede planificarse a futuro.
        $anio = (int) $d->format('Y');
        return $anio >= 1900 && $anio <= 2200;
    }

    /**
     * Normaliza una fila de torneo para el cliente: los contadores y flags
     * llegan de MySQL como strings según cómo se haya ejecutado la consulta,
     * y el front hace comparaciones estrictas con ellos.
     *
     * @param array $torneo
     * @return array
     */
    private function normalizar(array $torneo): array {
        foreach (['id', 'organizador_id', 'max_participantes', 'publico', 'requiere_equipos',
                  'inscriptos', 'equipos', 'partidos', 'partidos_jugados'] as $campo) {
            if (isset($torneo[$campo])) {
                $torneo[$campo] = (int) $torneo[$campo];
            }
        }
        return $torneo;
    }
}
