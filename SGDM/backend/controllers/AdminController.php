<?php
require_once __DIR__ . '/../core/Controller.php';
require_once __DIR__ . '/../models/Estadistica.php';
require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../models/DocAcceso.php';
require_once __DIR__ . '/../models/Migracion.php';
require_once __DIR__ . '/../shared/Session.php';
require_once __DIR__ . '/../shared/Mailer.php';

/**
 * Controlador del panel de administración.
 * Expone los agregados que alimentan sus tarjetas y feeds.
 */
class AdminController extends Controller {

    /** Roles válidos para filtrar el listado de usuarios. */
    private const ROLES = ['participante', 'administrador'];

    /** Estados válidos para una cuenta de usuario (coinciden con el ENUM). */
    private const ESTADOS_USUARIO = ['activo', 'suspendido', 'pendiente'];

    private Estadistica $estadisticaModel;
    private Usuario     $usuarioModel;

    public function __construct() {
        parent::__construct();
        $this->estadisticaModel = new Estadistica();
        $this->usuarioModel     = new Usuario();
    }

    /**
     * Métricas del sistema y actividad reciente (GET /api/admin/stats).
     * Solo para administradores.
     */
    public function stats(): void {
        if (!$this->requireApiRole(['administrador'])) {
            return;
        }

        $this->jsonSuccess([
            'stats'     => $this->estadisticaModel->resumenGeneral(),
            'actividad' => $this->estadisticaModel->actividadReciente(),
        ]);
    }

    /**
     * Chequeo de salud del esquema (GET /api/admin/salud): compara las
     * migraciones add_*.sql del repo contra schema_migrations y avisa si
     * falta correr alguna en esta base, o si alguna corrio pero quedo a medio
     * aplicar (ver add_schema_migrations.sql y Migracion::faltantes()).
     * Solo para administradores.
     */
    public function salud(): void {
        if (!$this->requireApiRole(['administrador'])) {
            return;
        }

        try {
            $faltantes = (new Migracion())->faltantes();
        } catch (Throwable $e) {
            // schema_migrations todavía no existe en esta base: es en sí
            // misma una migración pendiente (add_schema_migrations.sql).
            $faltantes = ['schema_migrations (correr add_schema_migrations.sql)'];
        }

        $this->jsonSuccess(['migraciones_faltantes' => $faltantes]);
    }

    /**
     * Listado de usuarios del sistema (GET /api/admin/usuarios).
     * Acepta ?rol= para filtrar. Solo para administradores.
     */
    public function usuarios(): void {
        if (!$this->requireApiRole(['administrador'])) {
            return;
        }

        $rol = (string) (filter_input(INPUT_GET, 'rol', FILTER_DEFAULT) ?? '');
        $rol = in_array($rol, self::ROLES, true) ? $rol : null;

        // Usuario::listar() ya excluye la columna password.
        $usuarios = array_map(static function (array $u): array {
            $u['id'] = (int) $u['id'];
            return $u;
        }, $this->usuarioModel->listar($rol));

        $this->jsonSuccess(['usuarios' => $usuarios]);
    }

    /**
     * Crea un usuario desde el panel (POST /api/admin/usuario/crear).
     */
    public function crearUsuario(): void {
        if (!$this->requireApiRole(['administrador'])) {
            return;
        }

        $datos = $this->leerCampos();
        $error = $this->validarUsuario($datos, null, true, $campo);
        if ($error !== null) {
            $this->jsonError($error, ['campo' => $campo]);
            return;
        }

        if ($this->usuarioModel->emailExiste($datos['email'])) {
            $this->jsonError('Ese correo ya está registrado.', ['campo' => 'email'], 409);
            return;
        }

        $id = $this->usuarioModel->crearComoAdmin($datos);
        $this->jsonSuccess([
            'usuario' => $this->usuarioPublico($id),
            'mensaje' => 'Usuario creado correctamente.',
        ]);
    }

    /**
     * Edita un usuario existente (POST /api/admin/usuario/actualizar).
     */
    public function actualizarUsuario(): void {
        if (!$this->requireApiRole(['administrador'])) {
            return;
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            $this->jsonError('Falta el identificador del usuario.');
            return;
        }

        $actual = $this->usuarioModel->findById($id);
        if (!$actual) {
            $this->jsonError('El usuario no existe.', [], 404);
            return;
        }

        // En edición la contraseña es opcional: solo se valida/actualiza si el
        // admin escribió una nueva.
        $datos    = $this->leerCampos();
        $cambiaPw = $datos['password'] !== '';
        $error = $this->validarUsuario($datos, $id, $cambiaPw, $campo);
        if ($error !== null) {
            $this->jsonError($error, ['campo' => $campo]);
            return;
        }

        if ($this->usuarioModel->emailExiste($datos['email'], $id)) {
            $this->jsonError('Ese correo ya está en uso por otra cuenta.', ['campo' => 'email'], 409);
            return;
        }

        $adminId = (int) Session::getUserId();

        // Salvaguarda 1: un admin no puede quitarse a sí mismo el rol ni
        // suspender su propia cuenta (se dejaría fuera del panel).
        if ($id === $adminId && ($datos['rol'] !== 'administrador' || $datos['estado'] !== 'activo')) {
            $this->jsonError(
                'No podés cambiar tu propio rol ni suspender tu cuenta.',
                ['campo' => 'rol']
            );
            return;
        }

        // Salvaguarda 2: nunca dejar el sistema sin administradores activos.
        $eraAdminActivo = $actual['rol'] === 'administrador' && $actual['estado'] === 'activo';
        $dejaDeSerlo    = $datos['rol'] !== 'administrador' || $datos['estado'] !== 'activo';
        if ($eraAdminActivo && $dejaDeSerlo && $this->usuarioModel->contarAdministradoresActivos() <= 1) {
            $this->jsonError('Debe quedar al menos un administrador activo.', ['campo' => 'rol']);
            return;
        }

        $campos = [
            'nombre'    => $datos['nombre'],
            'apellido'  => $datos['apellido'],
            'email'     => $datos['email'],
            'fecha_nac' => $datos['fecha_nac'],
            'rol'       => $datos['rol'],
            'estado'    => $datos['estado'],
        ];
        if ($cambiaPw) {
            $campos['password'] = $datos['password'];
        }

        $this->usuarioModel->actualizar($id, $campos);
        $this->jsonSuccess([
            'usuario' => $this->usuarioPublico($id),
            'mensaje' => 'Usuario actualizado correctamente.',
        ]);
    }

    /** Nombres legibles de materias restringidas (para el correo de aviso). */
    private const MATERIA_NOMBRES = [
        'ciberseguridad'      => 'Ciberseguridad',
        'ingenieria-software' => 'Ingeniería de Software',
        'sistemas-operativos' => 'Sistemas Operativos',
        'tutoria'             => 'Tutoría de Proyecto UTULAB',
    ];

    /**
     * Lista las solicitudes de acceso a documentación restringida
     * (GET /api/admin/doc-solicitudes). Solo para administradores.
     */
    public function docSolicitudes(): void {
        if (!$this->requireApiRole(['administrador'])) {
            return;
        }

        $solicitudes = array_map(static function (array $s): array {
            $s['id']         = (int) $s['id'];
            $s['usuario_id'] = (int) $s['usuario_id'];
            return $s;
        }, (new DocAcceso())->listarSolicitudes());

        $this->jsonSuccess(['solicitudes' => $solicitudes]);
    }

    /**
     * Aprueba o rechaza una solicitud de acceso
     * (POST /api/admin/doc-solicitud/resolver). Solo para administradores.
     */
    public function resolverDocSolicitud(): void {
        if (!$this->requireApiRole(['administrador'])) {
            return;
        }

        $id     = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $accion = (string) (filter_input(INPUT_POST, 'accion', FILTER_DEFAULT) ?? '');

        if (!$id) {
            $this->jsonError('Falta el identificador de la solicitud.');
            return;
        }
        if (!in_array($accion, ['aprobar', 'rechazar'], true)) {
            $this->jsonError('Acción inválida.');
            return;
        }

        $acceso    = new DocAcceso();
        $solicitud = $acceso->findById($id);
        if (!$solicitud) {
            $this->jsonError('La solicitud no existe.', [], 404);
            return;
        }

        $nuevoEstado = $acceso->resolverPorId($id, $accion, (int) Session::getUserId());

        // Aviso al usuario (best-effort: un fallo de correo no frena la acción).
        try {
            $this->notificarResolucionDoc((int) $solicitud['usuario_id'], (string) $solicitud['materia'], $accion);
        } catch (Throwable $e) {
            error_log('Fallo al notificar resolución de acceso a documentación: ' . $e->getMessage());
        }

        $this->jsonSuccess([
            'estado'  => $nuevoEstado,
            'mensaje' => $accion === 'aprobar' ? 'Solicitud aprobada.' : 'Solicitud rechazada.',
        ]);
    }

    /** Envía al usuario el resultado de su solicitud de acceso. */
    private function notificarResolucionDoc(int $usuarioId, string $materia, string $accion): void {
        $usuario = $this->usuarioModel->findById($usuarioId);
        if (!$usuario || empty($usuario['email'])) {
            return;
        }

        $nombreMateria = self::MATERIA_NOMBRES[$materia] ?? $materia;
        $nombre        = trim((string) $usuario['nombre']);
        $aprobado      = $accion === 'aprobar';

        $subject = 'Tornalyx · Acceso a la documentación de ' . $nombreMateria;
        if ($aprobado) {
            $textoCuerpo = "Tu solicitud de acceso a la documentación de {$nombreMateria} fue APROBADA. "
                         . 'Entrá a la sección Documentación y pedí el código de verificación para verla.';
        } else {
            $textoCuerpo = "Tu solicitud de acceso a la documentación de {$nombreMateria} fue rechazada.";
        }

        $text = "Hola {$nombre},\n\n{$textoCuerpo}\n\nTornalyx";
        $html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto">'
              . '<h2 style="color:#ec1c24">Tornalyx</h2>'
              . '<p>Hola ' . htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') . ',</p>'
              . '<p>' . htmlspecialchars($textoCuerpo, ENT_QUOTES, 'UTF-8') . '</p>'
              . '</div>';

        (new Mailer())->send((string) $usuario['email'], $nombre, $subject, $html, $text);
    }

    // ──────────────────────────────────────────────────────
    // Helpers privados
    // ──────────────────────────────────────────────────────

    /**
     * Lee y normaliza los campos del formulario de usuario.
     *
     * @return array{nombre:string,apellido:string,email:string,password:string,fecha_nac:string,rol:string,estado:string}
     */
    private function leerCampos(): array {
        return [
            'nombre'    => trim((string) (filter_input(INPUT_POST, 'nombre',    FILTER_DEFAULT) ?? '')),
            'apellido'  => trim((string) (filter_input(INPUT_POST, 'apellido',  FILTER_DEFAULT) ?? '')),
            'email'     => trim((string) (filter_input(INPUT_POST, 'email',     FILTER_DEFAULT) ?? '')),
            'password'  => (string) (filter_input(INPUT_POST, 'password',  FILTER_DEFAULT) ?? ''),
            'fecha_nac' => trim((string) (filter_input(INPUT_POST, 'fecha_nac', FILTER_DEFAULT) ?? '')),
            'rol'       => trim((string) (filter_input(INPUT_POST, 'rol',       FILTER_DEFAULT) ?? '')),
            'estado'    => trim((string) (filter_input(INPUT_POST, 'estado',    FILTER_DEFAULT) ?? '')),
        ];
    }

    /**
     * Valida los datos de un usuario. Devuelve el mensaje de error (y el campo
     * culpable por referencia) o null si todo es válido.
     *
     * @param array       $d
     * @param int|null    $id           Null en alta; el id en edición.
     * @param bool        $exigePassword Si la contraseña es obligatoria.
     * @param string|null $campo         Campo con error (salida).
     * @return string|null
     */
    private function validarUsuario(array $d, ?int $id, bool $exigePassword, ?string &$campo = null): ?string {
        if ($d['nombre'] === '' || $d['apellido'] === '') {
            $campo = 'nombre';
            return 'Nombre y apellido son obligatorios.';
        }
        if (mb_strlen($d['nombre']) > 60 || mb_strlen($d['apellido']) > 60) {
            $campo = 'nombre';
            return 'Nombre y apellido no pueden superar los 60 caracteres.';
        }
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($d['email']) > 120) {
            $campo = 'email';
            return 'Correo electrónico inválido.';
        }
        if (!in_array($d['rol'], self::ROLES, true)) {
            $campo = 'rol';
            return 'Rol inválido.';
        }
        if (!in_array($d['estado'], self::ESTADOS_USUARIO, true)) {
            $campo = 'estado';
            return 'Estado inválido.';
        }
        if ($d['fecha_nac'] === '' || !$this->esFechaNacValida($d['fecha_nac'])) {
            $campo = 'fecha_nac';
            return 'La fecha de nacimiento no es válida.';
        }
        if ($exigePassword && !$this->passwordEsFuerte($d['password'])) {
            $campo = 'password';
            return 'La contraseña debe tener al menos 8 caracteres e incluir mayúsculas, minúsculas y números.';
        }
        return null;
    }

    /**
     * Misma política de contraseña que el registro público.
     */
    private function passwordEsFuerte(string $password): bool {
        return strlen($password) >= 8
            && preg_match('/[A-Z]/', $password)
            && preg_match('/[a-z]/', $password)
            && preg_match('/[0-9]/', $password);
    }

    /**
     * Devuelve la fila pública de un usuario (sin password) para responder al
     * cliente tras crear o editar.
     *
     * @param int $id
     * @return array|null
     */
    private function usuarioPublico(int $id): ?array {
        $u = $this->usuarioModel->findById($id);
        if (!$u) {
            return null;
        }
        unset($u['password']);
        $u['id'] = (int) $u['id'];
        return $u;
    }
}
