<?php
/**
 * Gestión de sesiones PHP para Tornalyx.
 * Inicia la sesión, almacena usuario autenticado y provee helpers
 * de autorización, expiración por inactividad y protección CSRF.
 */
class Session {

    /** Minutos de inactividad antes de cerrar la sesión automáticamente. */
    private const IDLE_TIMEOUT_MINUTES = 30;

    /** Nombre de la cookie legible por JS que transporta el token CSRF. */
    private const CSRF_COOKIE = 'XSRF-TOKEN';

    /** Nombre del header en el que el cliente reenvía el token CSRF. */
    private const CSRF_HEADER = 'HTTP_X_CSRF_TOKEN';

    /**
     * Indica si la petición llega por HTTPS (para marcar cookies como secure).
     */
    private static function isHttps(): bool {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (($_SERVER['SERVER_PORT'] ?? null) == 443) {
            return true;
        }
        // Detrás de un proxy / balanceador.
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        return getenv('APP_ENV') === 'production';
    }

    /**
     * Inicia la sesión con configuración segura y aplica el timeout de
     * inactividad y la inicialización del token CSRF.
     */
    public static function start(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => self::isHttps(),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            session_start();
            self::enforceIdleTimeout();
        }
        self::ensureCsrfToken();
    }

    /**
     * Cierra la sesión si superó el tiempo de inactividad permitido.
     */
    private static function enforceIdleTimeout(): void {
        $now    = time();
        $maxIdle = self::IDLE_TIMEOUT_MINUTES * 60;

        if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > $maxIdle) {
            // Sesión expirada por inactividad: limpiarla por completo.
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['last_activity'] = $now;
    }

    /**
     * Garantiza que exista un token CSRF en sesión y lo expone al cliente
     * mediante una cookie legible por JavaScript (patrón double-submit + sesión).
     */
    private static function ensureCsrfToken(): void {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        // Re-emitimos la cookie en cada request para mantenerla sincronizada
        // con la sesión (p. ej. tras session_regenerate_id).
        $current = $_COOKIE[self::CSRF_COOKIE] ?? null;
        if ($current !== $_SESSION['csrf_token'] && !headers_sent()) {
            setcookie(self::CSRF_COOKIE, $_SESSION['csrf_token'], [
                'expires'  => 0,
                'path'     => '/',
                'secure'   => self::isHttps(),
                'httponly' => false,   // El JS debe poder leerla para reenviarla.
                'samesite' => 'Strict',
            ]);
            $_COOKIE[self::CSRF_COOKIE] = $_SESSION['csrf_token'];
        }
    }

    /**
     * Retorna el token CSRF actual de la sesión.
     */
    public static function csrfToken(): string {
        self::start();
        return $_SESSION['csrf_token'] ?? '';
    }

    /**
     * Verifica el token CSRF enviado por el cliente (header X-CSRF-Token o
     * campo csrf_token del formulario) contra el almacenado en sesión.
     */
    public static function verifyCsrf(): bool {
        $expected = $_SESSION['csrf_token'] ?? '';
        if ($expected === '') {
            return false;
        }
        $provided = $_SERVER[self::CSRF_HEADER]
            ?? ($_POST['csrf_token'] ?? '');
        return is_string($provided) && hash_equals($expected, $provided);
    }

    /**
     * Exige un token CSRF válido; corta la petición con 419 si no lo es.
     */
    public static function requireCsrf(): void {
        self::start();
        if (!self::verifyCsrf()) {
            http_response_code(419);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Token de seguridad inválido o expirado. Recargá la página.']);
            exit;
        }
    }

    /**
     * Almacena los datos del usuario en la sesión.
     *
     * @param array $usuario Fila de la tabla usuarios.
     */
    public static function login(array $usuario): void {
        session_regenerate_id(true);
        $_SESSION['usuario_id']     = $usuario['id'];
        $_SESSION['usuario_rol']    = $usuario['rol'];
        $_SESSION['usuario_nombre'] = $usuario['nombre'];
        $_SESSION['last_activity']  = time();
        // Nuevo id de sesión: renovamos también el token CSRF.
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        self::ensureCsrfToken();
    }

    /**
     * Destruye la sesión activa (logout).
     */
    public static function logout(): void {
        $_SESSION = [];
        session_unset();
        session_destroy();
    }

    /**
     * Verifica si hay un usuario autenticado.
     */
    public static function isLoggedIn(): bool {
        return isset($_SESSION['usuario_id']);
    }

    /**
     * Retorna el ID del usuario autenticado.
     */
    public static function getUserId(): ?int {
        return isset($_SESSION['usuario_id']) ? (int) $_SESSION['usuario_id'] : null;
    }

    /**
     * Retorna el rol del usuario autenticado.
     */
    public static function getUserRole(): ?string {
        return $_SESSION['usuario_rol'] ?? null;
    }

    /**
     * Verifica que el usuario tenga el rol requerido; redirige si no.
     *
     * @param string|array $rolesPermitidos
     * @param string       $redirect URL de redirección si no tiene permiso.
     */
    public static function requireRole(string|array $rolesPermitidos, string $redirect = '/login'): void {
        self::start();
        if (!self::isLoggedIn()) {
            header('Location: ' . $redirect);
            exit;
        }
        $roles = (array) $rolesPermitidos;
        if (!in_array(self::getUserRole(), $roles, true)) {
            http_response_code(403);
            echo 'Acceso denegado.';
            exit;
        }
    }
}
