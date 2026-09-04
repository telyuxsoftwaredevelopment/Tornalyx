<?php
/**
 * Renderizador de vistas — capa V (Vista) del patrón MVC.
 *
 * Carga una plantilla PHP desde backend/vistas, le inyecta los datos que le
 * pasa el controlador y devuelve (o imprime) el HTML resultante. Aísla el
 * scope de la plantilla para que solo vea las variables que el controlador
 * decidió pasarle.
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
        $this->viewsPath = $viewsPath ?? (__DIR__ . '/../vistas');
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
     * Convierte el nombre lógico de la vista en una ruta de archivo segura,
     * evitando el path traversal (no se permite salir de backend/vistas).
     */
    private function resolve(string $view): string {
        $view = str_replace(['..', "\0"], '', $view);
        $view = ltrim($view, '/');
        return $this->viewsPath . '/' . $view . '.php';
    }
}
