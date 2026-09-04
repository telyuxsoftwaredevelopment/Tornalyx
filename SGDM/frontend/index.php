<?php
/**
 * Punto de entrada del backend Tornalyx (Front Controller del patrón MVC).
 * Todas las peticiones llegan aquí via .htaccess, se enrutan con Router y se
 * resuelven contra un Controlador (capa C) o una Vista (capa V).
 */

declare(strict_types=1);

// ──────────────────────────────────────────────────────────────
// Manejador global de errores: evita filtrar stack traces y rutas
// del servidor al cliente (information disclosure). Los detalles se
// registran en el log; al cliente solo le llega un mensaje genérico.
// ──────────────────────────────────────────────────────────────
set_exception_handler(static function (Throwable $e): void {
    error_log('Unhandled exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'error' => 'Ocurrió un error en el servidor. Intentá más tarde.']);
});

require_once __DIR__ . '/../backend/core/Router.php';
require_once __DIR__ . '/../backend/core/View.php';
require_once __DIR__ . '/../backend/shared/Session.php';
require_once __DIR__ . '/../backend/controllers/AuthController.php';
require_once __DIR__ . '/../backend/controllers/TorneoController.php';
require_once __DIR__ . '/../backend/controllers/AdminController.php';
require_once __DIR__ . '/../backend/controllers/DocsController.php';
require_once __DIR__ . '/../backend/controllers/PerfilController.php';
require_once __DIR__ . '/../backend/controllers/InscripcionController.php';
require_once __DIR__ . '/../backend/controllers/PartidoController.php';
require_once __DIR__ . '/../backend/controllers/AvisoController.php';

Session::start();

// Obtener la ruta y el método HTTP
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = strtoupper($_SERVER['REQUEST_METHOD']);

// Eliminar trailing slash
$uri = rtrim($uri, '/') ?: '/';

// ──────────────────────────────────────────────────────────────
// Protección CSRF: toda petición que cambia estado (POST) debe
// presentar un token válido. El logout queda exento porque es
// idempotente y accesible también por GET.
// ──────────────────────────────────────────────────────────────
if ($method === 'POST' && $uri !== '/logout') {
    Session::requireCsrf();
}

// ──────────────────────────────────────────────────────────────
// Tabla de rutas (Front Controller → Router)
// ──────────────────────────────────────────────────────────────
// Los controladores se instancian dentro de los closures (de forma perezosa):
// solo las rutas que realmente acceden a datos abren conexión a la BD. Así las
// páginas públicas (home, registro, listado) se sirven aunque la BD esté caída.
$router = new Router();

// ─── Home ───────────────────────────────────────────────────────
// La raíz (/) sirve el index.html estático del frontend. Se registra de forma
// explícita porque Apache está priorizando index.php sobre index.html como
// DirectoryIndex; sin esta ruta, / no matcheaba ninguna y caía en el fallback
// (404 "Ruta no encontrada.").
$router->get('/', static function () {
    readfile(__DIR__ . '/index.html');
});

// ─── Páginas públicas ───────────────────────────────────────────
// Todas las vistas simples (sin lógica server-side propia) son archivos
// .html estáticos servidos directamente por Apache (ver .htaccess); acá
// solo quedan las rutas de guardia de sesión y el flujo de Documentación,
// que sigue siendo un flujo server-side genuino (OTP por email).
$router->get('/documentacion',  static fn() => (new DocsController())->show());
$router->post('/documentacion/solicitar', static fn() => (new DocsController())->solicitarAcceso());
$router->post('/documentacion/codigo',    static fn() => (new DocsController())->enviarCodigo());
$router->post('/documentacion/verificar', static fn() => (new DocsController())->verificarCodigo());
$router->get('/documentacion/aprobar',    static fn() => (new DocsController())->revisarSolicitud());
$router->post('/documentacion/resolver',  static fn() => (new DocsController())->resolverSolicitud());

// ─── AUTH ──────────────────────────────────────────────────────
$router->post('/login',           static fn() => (new AuthController())->processLogin());
$router->post('/login/verificar', static fn() => (new AuthController())->verify2fa());
$router->post('/login/reenviar',  static fn() => (new AuthController())->resend2fa());
$router->get('/api/2fa/estado',   static fn() => (new AuthController())->estado2fa());
$router->post('/registro',        static fn() => (new AuthController())->processRegistro());
$router->map(['GET', 'POST'], '/logout', static fn() => (new AuthController())->logout());
$router->get('/api/me',           static fn() => (new AuthController())->me());

// ─── Paneles privados (guardia de sesión) ──────────────────────
// El .html vive fuera de frontend/ (backend/vistas/), fuera del alcance de
// Apache: solo se sirve pasando por acá, después de validar el rol. Si
// estuviera dentro de frontend/, la regla de URLs bonitas del .htaccess lo
// serviría como estático sin pasar por ningún control de acceso.
$router->get('/perfil', static function () {
    Session::requireRole(['participante', 'administrador']);
    readfile(__DIR__ . '/../backend/vistas/perfil.html');
});
$router->get('/organizador/dashboard', static function () {
    // "Mis torneos": cualquier usuario logueado puede crear y gestionar
    // torneos, el rol de organizador ya no es una cuenta global sino una
    // pertenencia por torneo (torneos.organizador_id).
    Session::requireRole(['participante', 'administrador']);
    readfile(__DIR__ . '/../backend/vistas/organizador-dashboard.html');
});
$router->get('/admin/dashboard', static function () {
    Session::requireRole('administrador');
    readfile(__DIR__ . '/../backend/vistas/admin-dashboard.html');
});

// ─── PERFIL (API JSON) ─────────────────────────────────────────
$router->get('/api/perfil',             static fn() => (new PerfilController())->datos());
$router->post('/api/perfil/actualizar', static fn() => (new PerfilController())->actualizar());
$router->post('/api/perfil/password',   static fn() => (new PerfilController())->password());
$router->post('/api/perfil/avatar',     static fn() => (new PerfilController())->avatar());
$router->get('#^/api/perfil/avatar/(\d+)$#', static fn($id) => (new PerfilController())->servirAvatar((int) $id));

// ─── TORNEOS (API JSON) ────────────────────────────────────────
$router->get('/api/torneos',           static fn() => (new TorneoController())->index());
$router->get('/api/torneos/mios',      static fn() => (new TorneoController())->mios());
$router->post('/api/torneo/crear',     static fn() => (new TorneoController())->store());

// Torneo por ID: /api/torneo/42 (la restricción \d+ evita que "abc" matchee).
$router->get('#^/api/torneo/(\d+)$#',               static fn($id) => (new TorneoController())->show((int) $id));
$router->get('#^/api/torneo/(\d+)/partidos$#',      static fn($id) => (new PartidoController())->listar((int) $id));
$router->get('#^/api/torneo/(\d+)/inscripciones$#', static fn($id) => (new InscripcionController())->listar((int) $id));
$router->get('#^/api/torneo/(\d+)/avisos$#',        static fn($id) => (new AvisoController())->deTorneo((int) $id));
$router->post('#^/api/torneo/(\d+)/editar$#',       static fn($id) => (new TorneoController())->actualizar((int) $id));
$router->post('#^/api/torneo/(\d+)/eliminar$#',     static fn($id) => (new TorneoController())->eliminar((int) $id));
$router->post('#^/api/torneo/(\d+)/cancelar$#',     static fn($id) => (new TorneoController())->cancelar((int) $id));
$router->post('#^/api/torneo/(\d+)/banner$#',       static fn($id) => (new TorneoController())->imagen((int) $id));
$router->get('#^/api/torneo/(\d+)/banner$#',        static fn($id) => (new TorneoController())->servirBanner((int) $id));

// ─── INSCRIPCIONES Y EQUIPOS (API JSON) ────────────────────────
$router->post('/api/inscripcion',          static fn() => (new InscripcionController())->inscribirse());
$router->post('/api/inscripcion/cancelar', static fn() => (new InscripcionController())->cancelar());
$router->post('/api/inscripcion/resolver', static fn() => (new InscripcionController())->resolver());

// ─── ENFRENTAMIENTOS (API JSON) ────────────────────────────────
$router->post('/api/torneo/fixture',      static fn() => (new PartidoController())->generar());
$router->post('/api/partido/programar',   static fn() => (new PartidoController())->programar());
$router->post('/api/partido/estado',      static fn() => (new PartidoController())->estado());
$router->post('/api/partido/resultado',   static fn() => (new PartidoController())->resultado());
$router->post('/api/partido/asistencia',  static fn() => (new PartidoController())->asistencia());

// ─── AVISOS (API JSON) ─────────────────────────────────────────
$router->post('/api/aviso/publicar', static fn() => (new AvisoController())->publicar());

// ─── JUGADORES (API JSON pública) ──────────────────────────────
$router->get('/api/jugadores',            static fn() => (new PerfilController())->buscar());
$router->get('#^/api/jugador/(\d+)$#',    static fn($id) => (new PerfilController())->publico((int) $id));

// ─── ADMIN (API JSON) ──────────────────────────────────────────
$router->get('/api/admin/stats',    static fn() => (new AdminController())->stats());
$router->get('/api/admin/salud',    static fn() => (new AdminController())->salud());
$router->get('/api/admin/usuarios', static fn() => (new AdminController())->usuarios());
$router->post('/api/admin/usuario/crear',      static fn() => (new AdminController())->crearUsuario());
$router->post('/api/admin/usuario/actualizar', static fn() => (new AdminController())->actualizarUsuario());
$router->get('/api/admin/doc-solicitudes',         static fn() => (new AdminController())->docSolicitudes());
$router->post('/api/admin/doc-solicitud/resolver', static fn() => (new AdminController())->resolverDocSolicitud());

// ─── 404 por defecto ───────────────────────────────────────────
$router->fallback(static function (): void {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Ruta no encontrada.']);
});

$router->dispatch($uri, $method);