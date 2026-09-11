<?php
/**
 * Router minimalista — parte del Front Controller del patrón MVC.
 *
 * Registra rutas por método HTTP y las despacha hacia un handler (normalmente
 * una acción de un controlador). Soporta dos tipos de patrón:
 *  - Coincidencia exacta:  '/api/torneos'
 *  - Expresión regular:    '#^/api/torneo/(\d+)$#'  (los grupos capturados se
 *    pasan como argumentos al handler).
 *
 * Los handlers se registran como closures para que solo se construya el
 * controlador de la ruta que realmente coincide (instanciación perezosa): las
 * páginas públicas no abren conexión a la BD.
 */
class Router {

    /** @var array<string, array<int, array{pattern:string, handler:callable, isRegex:bool}>> */
    private array $routes = ['GET' => [], 'POST' => []];

    /** Handler para cuando ninguna ruta coincide (404). */
    private $fallback = null;

    public function get(string $pattern, callable $handler): void {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void {
        $this->add('POST', $pattern, $handler);
    }

    /**
     * Registra el mismo handler para varios métodos (p. ej. logout GET y POST).
     *
     * @param string[] $methods
     */
    public function map(array $methods, string $pattern, callable $handler): void {
        foreach ($methods as $method) {
            $this->add($method, $pattern, $handler);
        }
    }

    /** Define el handler de 404 (ninguna ruta coincidió). */
    public function fallback(callable $handler): void {
        $this->fallback = $handler;
    }

    private function add(string $method, string $pattern, callable $handler): void {
        // Convención: si el patrón empieza con '#', se trata como expresión
        // regular; si no, es una coincidencia exacta de la ruta.
        $isRegex = isset($pattern[0]) && $pattern[0] === '#';
        $this->routes[$method][] = [
            'pattern' => $pattern,
            'handler' => $handler,
            'isRegex' => $isRegex,
        ];
    }

    /**
     * Busca la primera ruta que coincida con la URI y el método, y ejecuta su
     * handler. Si ninguna coincide, ejecuta el fallback (404).
     */
    public function dispatch(string $uri, string $method): void {
        foreach ($this->routes[$method] ?? [] as $route) {
            if ($route['isRegex']) {
                if (preg_match($route['pattern'], $uri, $matches)) {
                    array_shift($matches); // descartamos la coincidencia completa
                    ($route['handler'])(...$matches);
                    return;
                }
            } elseif ($route['pattern'] === $uri) {
                ($route['handler'])();
                return;
            }
        }

        if ($this->fallback !== null) {
            ($this->fallback)();
        }
    }
}
