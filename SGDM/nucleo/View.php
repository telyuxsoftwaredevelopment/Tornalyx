<?php
/**
 * Renderizador de vistas — capa V (Vista) del patrón MVC.
 *
 * Carga una plantilla desde SGDM/vista (la capa V vive entera ahí, fuera del
 * DocumentRoot), le inyecta los datos que le pasa el controlador y devuelve (o
 * imprime) el resultado. Aísla el scope de la plantilla para que solo vea las
 * variables que el controlador decidió pasarle.
 *
 * Maneja los dos tipos de vista del proyecto:
 *   - plantillas .php dinámicas  -> render() / capture()
 *   - páginas .html estáticas    -> html()
 * Ninguna de las dos es alcanzable por Apache: pasan siempre por un
 * controlador, que es el que decide si el visitante puede verlas.
 */

if (!function_exists('e')) {
    /**
     * Escapa un valor para insertarlo de forma segura dentro de HTML (anti-XSS).
     * Usar SIEMPRE en las plantillas al imprimir datos que vengan de la BD o del
     * usuario: <?= e($variable) ?>.
     */
    function e(?string $value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

class View {

    /** Carpeta raíz donde viven las plantillas .php. */
    private string $viewsPath;

    public function __construct(?string $viewsPath = null) {
        $this->viewsPath = $viewsPath ?? (__DIR__ . '/../vista');
    }

    /**
     * Renderiza una plantilla e imprime el resultado al cliente.
     *
     * @param string $view Ruta relativa sin extensión, p. ej. 'documentacion'.
     * @param array  $data Variables expuestas dentro de la plantilla.
     */
    public function render(string $view, array $data = []): void {
        echo $this->capture($view, $data);
    }

    /**
     * Renderiza una plantilla y devuelve el HTML como string. Útil para componer
     * vistas dentro de otras (layouts, parciales) sin imprimir todavía.
     *
     * @param string $view
     * @param array  $data
     * @return string
     */
    public function capture(string $view, array $data = []): string {
        $__file = $this->resolve($view);
        if (!is_file($__file)) {
            throw new RuntimeException("Vista no encontrada: {$view}");
        }

        // Composición de vistas: expone una función $partial() dentro de la
        // plantilla para incrustar parciales reutilizables (navbar, footer).
        // En la plantilla se usa con echo corto, pasándole el nombre del parcial
        // y, opcionalmente, los datos que necesite. Cada parcial se renderiza
        // con su propio scope (no hereda los datos del padre): hay que pasarle
        // explícitamente lo que use.
        if (!isset($data['partial'])) {
            $self = $this;
            $data['partial'] = static function (string $name, array $vars = []) use ($self): string {
                return $self->capture($name, $vars);
            };
        }

        // Closure aislada: la plantilla solo ve $data (extraído) y las funciones
        // globales (como e()); no tiene acceso a $this ni al estado del View.
        // El "use" captura por valor al definir el closure, así que $__file y
        // $data deben existir antes de esta línea.
        $renderer = static function () use ($__file, $data): string {
            extract($data, EXTR_SKIP);
            ob_start();
            include $__file;
            return (string) ob_get_clean();
        };
        return $renderer();
    }

    /**
     * Imprime una página .html de la capa Vista tal cual.
     *
     * Las páginas sin lógica de servidor (home, login, paneles) son .html
     * planos, pero viven en SGDM/vista igual que las plantillas: Apache no
     * llega a ellas, así que la única forma de servirlas es por acá, después
     * de que el controlador haya decidido que el visitante puede verlas.
     *
     * @param string $view Ruta lógica sin extensión, p. ej. 'paneles/perfil'.
     */
    public function html(string $view): void {
        $file = $this->resolve($view, 'html');
        if (!is_file($file)) {
            throw new RuntimeException("Vista no encontrada: {$view}.html");
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
        }
        readfile($file);
    }

    /**
     * Convierte el nombre lógico de la vista en una ruta de archivo segura,
     * evitando el path traversal (no se permite salir de SGDM/vista).
     */
    private function resolve(string $view, string $ext = 'php'): string {
        $view = str_replace(['..', "\0"], '', $view);
        $view = ltrim($view, '/');
        return $this->viewsPath . '/' . $view . '.' . $ext;
    }
}
