<?php
require_once __DIR__ . '/View.php';
require_once __DIR__ . '/../shared/Session.php';

/**
 * Controlador base — capa C (Controlador) del patrón MVC.
 *
 * Centraliza lo que antes estaba duplicado en cada controlador concreto:
 *  - el acceso a la capa de Vista ($this->view / $this->render),
 *  - las respuestas JSON estandarizadas para los endpoints del API.
 *
 * Todos los controladores de la aplicación deben extender esta clase.
 */
abstract class Controller {

    /** Renderizador de vistas (inyección de datos del controlador a la plantilla). */
    protected View $view;

    public function __construct() {
        $this->view = new View();
    }

    /**
     * Renderiza una vista pasándole datos (Controlador → Vista).
     *
     * @param string $view Ruta lógica de la plantilla, p. ej. 'publico/login'.
     * @param array  $data Datos disponibles dentro de la plantilla.
     */
    protected function render(string $view, array $data = []): void {
        $this->view->render($view, $data);
    }

    /**
     * Respuesta JSON genérica.
     *
     * El código de estado solo se fija si se pasa explícitamente; así los
     * llamadores que ya hicieron http_response_code(...) antes de responder
     * (p. ej. un 401 o 404) conservan ese código.
     *
     * @param array    $data
     * @param int|null $status
     */
    protected function json(array $data, ?int $status = null): void {
        if (!headers_sent()) {
            if ($status !== null) {
                http_response_code($status);
            }
            header('Content-Type: application/json');
        }
        echo json_encode($data);
    }

    /**
     * Respuesta JSON de éxito: { "success": true, ...$data }.
     */
    protected function jsonSuccess(array $data = []): void {
        $this->json(array_merge(['success' => true], $data));
    }

    /**
     * Respuesta JSON de error: { "success": false, "error": ..., ...$extra }.
     *
     * @param string   $mensaje
     * @param array    $extra   Datos adicionales (p. ej. intentos_restantes).
     * @param int|null $status  Código HTTP opcional.
     */
    protected function jsonError(string $mensaje, array $extra = [], ?int $status = null): void {
        $this->json(array_merge(['success' => false, 'error' => $mensaje], $extra), $status);
    }

    /**
     * Autorización para endpoints JSON.
     *
     * Session::requireRole() redirige a /login, lo que sirve para vistas pero
     * rompe a un cliente fetch (recibiría el HTML del login y fallaría al
     * parsear JSON). Acá se responde con 401/403 y un cuerpo JSON, para que el
     * front pueda avisar al usuario y mandarlo al login él mismo.
     *
     * @param string[] $roles
     * @return bool true si la petición puede continuar.
     */
    protected function requireApiRole(array $roles): bool {
        if (!Session::isLoggedIn()) {
            $this->jsonError('Tu sesión expiró. Iniciá sesión de nuevo.', ['login' => true], 401);
            return false;
        }
        if (!in_array(Session::getUserRole(), $roles, true)) {
            $this->jsonError('No tenés permisos para realizar esta acción.', [], 403);
            return false;
        }
        return true;
    }

    /**
     * Exige sesión activa, sin restricción de rol.
     *
     * Los roles de torneo (organizador/participante) ya no son un rol de
     * cuenta: se derivan de torneos.organizador_id y de inscripciones. Los
     * endpoints que dependen de esa pertenencia validan la propiedad ellos
     * mismos (p. ej. puedeGestionar()); acá solo se exige estar logueado.
     */
    protected function requireApiLogin(): bool {
        if (!Session::isLoggedIn()) {
            $this->jsonError('Tu sesión expiró. Iniciá sesión de nuevo.', ['login' => true], 401);
            return false;
        }
        return true;
    }

    /**
     * Valida una fecha de nacimiento: formato YYYY-MM-DD, existente, no
     * futura y con año dentro de un rango humano razonable. `Y` en
     * DateTime::createFromFormat acepta años de más de 4 dígitos (p. ej.
     * "20001-07-13" por un typo pasa el formato igual), y sin el piso de
     * 1900 el INSERT/UPDATE explota contra la columna DATE de la base.
     * Usado por el registro público y el alta/edición de usuarios del admin.
     */
    protected function esFechaNacValida(string $fecha): bool {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        if ($d === false || $d->format('Y-m-d') !== $fecha) {
            return false;
        }
        $anio = (int) $d->format('Y');
        return $anio >= 1900 && $d <= new DateTimeImmutable('today');
    }

    /**
     * Política de robustez de contraseña, compartida por el registro público,
     * el cambio de contraseña del perfil y el alta/edición de usuarios del
     * admin: al menos 8 caracteres con mayúscula, minúscula y número. Se valida
     * en el servidor porque el medidor del cliente es solo visual y se evade.
     */
    protected function passwordEsFuerte(string $password): bool {
        return strlen($password) >= 8
            && preg_match('/[A-Z]/', $password)
            && preg_match('/[a-z]/', $password)
            && preg_match('/[0-9]/', $password);
    }

    /**
     * Enmascara un correo para mostrarlo sin revelarlo por completo.
     * Ej.: "rodrigo@gmail.com" → "r******@gmail.com".
     */
    protected function maskEmail(string $email): string {
        $parts = explode('@', $email);
        if (count($parts) !== 2 || $parts[0] === '') {
            return '***';
        }
        $name = $parts[0];
        return substr($name, 0, 1)
             . str_repeat('*', max(1, strlen($name) - 1))
             . '@' . $parts[1];
    }

    /**
     * True si $url es una URL http(s) sintácticamente válida. FILTER_VALIDATE_URL
     * por sí solo acepta esquemas como "javascript:" o "data:" como válidos, así
     * que sin el chequeo de esquema alguien podría guardar "javascript:alert(1)"
     * como enlace (canal de un torneo, red social) y el front lo volcaría tal
     * cual en un href. Usado por discord_url (torneos) y las redes del perfil.
     */
    protected function esUrlHttpValida(string $url): bool {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && preg_match('#^https?://#i', $url) === 1;
    }

    /**
     * True si el usuario en sesión puede gestionar el torneo: es su organizador
     * dueño (torneos.organizador_id) o es administrador. El rol de organizador
     * es una pertenencia por torneo, no un rol de cuenta; por eso se resuelve
     * contra la fila del torneo y no contra el rol global.
     *
     * @param array $torneo Fila de torneos (necesita organizador_id).
     */
    protected function esGestorDe(array $torneo): bool {
        return Session::getUserRole() === 'administrador'
            || (int) $torneo['organizador_id'] === Session::getUserId();
    }

    /**
     * Valida y lee una imagen subida por formulario, con el mismo criterio para
     * el avatar del perfil y la foto de fondo del torneo: exige que el archivo
     * haya llegado bien, no supere el tope de tamaño y sea realmente JPG/PNG/WebP
     * (tipo real por finfo + getimagesize, no el que declara el cliente). Ante
     * cualquier problema responde el error JSON y devuelve null; si todo va bien
     * devuelve ['data' => <bytes>, 'mime' => <mime real>]. El llamador persiste
     * el BLOB y arma su propia URL con cache-busting.
     *
     * @param string              $campo    Nombre del input de archivo ('avatar', 'banner').
     * @param array<string,string> $mimes   Mapa de MIME real admitido → extensión.
     * @param int                 $maxBytes Tamaño máximo permitido, en bytes.
     * @param string              $maxLabel Tope legible para el mensaje (p. ej. "2 MB").
     * @return array{data:string,mime:string}|null
     */
    protected function procesarImagenSubida(string $campo, array $mimes, int $maxBytes, string $maxLabel): ?array {
        $file = $_FILES[$campo] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->jsonError('No se recibió ninguna imagen.');
            return null;
        }
        if ((int) $file['size'] > $maxBytes) {
            $this->jsonError("La imagen no puede superar los {$maxLabel}.");
            return null;
        }

        // Tipo real del archivo, no el que declara el cliente.
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset($mimes[$mime]) || getimagesize($file['tmp_name']) === false) {
            $this->jsonError('Formato no admitido. Usá JPG, PNG o WebP.');
            return null;
        }

        $datos = file_get_contents($file['tmp_name']);
        if ($datos === false) {
            $this->jsonError('No se pudo leer la imagen. Intentá más tarde.', [], 500);
            return null;
        }

        return ['data' => $datos, 'mime' => $mime];
    }
}
