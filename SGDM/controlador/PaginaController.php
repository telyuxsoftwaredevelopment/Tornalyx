<?php
require_once __DIR__ . '/../nucleo/Controller.php';
require_once __DIR__ . '/../comun/Session.php';

/**
 * Controlador de páginas — sirve las vistas .html de la capa V.
 *
 * Toda la capa Vista vive en SGDM/vista, fuera del DocumentRoot: Apache no
 * puede servir ni una sola de esas páginas por su cuenta. Ese es justamente el
 * punto del patrón: la única puerta de entrada es el front controller, y quien
 * decide si el visitante puede ver una página es un controlador, no el
 * servidor web. Antes los .html públicos se servían como archivos estáticos y
 * los privados con readfile() desde el front controller; ahora las dos cosas
 * pasan por acá.
 *
 * Las páginas públicas no consultan la base de datos: el HTML es una cáscara
 * que el JavaScript del navegador rellena pegándole al API JSON. Por eso el
 * sitio público sigue de pie aunque MySQL esté caído.
 */
class PaginaController extends Controller {

    // ─── Páginas públicas ───────────────────────────────────

    /** Home (/). */
    public function inicio(): void {
        $this->html('paginas/index');
    }

    /**
     * Login y registro (/login y /registro).
     *
     * Es una sola tarjeta con giro 3D entre "Iniciar sesión" y "Crear cuenta"
     * (ver publico/js/auth-flip.js), así que las dos rutas sirven la misma
     * vista; ?tab=registro la abre del lado del registro.
     */
    public function login(): void {
        $this->html('paginas/login');
    }

    /** Listado público de torneos (/torneos). */
    public function torneos(): void {
        $this->html('paginas/torneos');
    }

    /** Detalle de un torneo (/torneo-detalle?id=N). */
    public function torneoDetalle(): void {
        $this->html('paginas/torneo-detalle');
    }

    /** Buscador público de jugadores (/jugadores). */
    public function jugadores(): void {
        $this->html('paginas/jugadores');
    }

    /** Quiénes somos (/nosotros). */
    public function nosotros(): void {
        $this->html('paginas/nosotros');
    }

    /** Política de privacidad (/privacidad). */
    public function privacidad(): void {
        $this->html('paginas/privacidad');
    }

    /** Términos y condiciones (/terminos). */
    public function terminos(): void {
        $this->html('paginas/terminos');
    }

    // ─── Paneles privados (guardia de sesión) ───────────────
    // Session::requireRole() redirige a /login si el visitante no tiene el rol
    // pedido, así que la vista solo se llega a imprimir cuando corresponde.

    /** Perfil del usuario en sesión (/perfil). */
    public function perfil(): void {
        Session::requireRole(['participante', 'administrador']);
        $this->html('paneles/perfil');
    }

    /**
     * Panel "Mis torneos" (/organizador/dashboard).
     *
     * Cualquier usuario logueado puede crear y gestionar torneos: ser
     * organizador no es un rol de cuenta sino una pertenencia por torneo
     * (torneos.organizador_id), que cada endpoint valida por su lado.
     */
    public function dashboardOrganizador(): void {
        Session::requireRole(['participante', 'administrador']);
        $this->html('paneles/organizador-dashboard');
    }

    /** Panel de administración (/admin/dashboard). */
    public function dashboardAdmin(): void {
        Session::requireRole('administrador');
        $this->html('paneles/admin-dashboard');
    }
}
