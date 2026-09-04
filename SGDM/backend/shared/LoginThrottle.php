<?php
/**
 * Limitador de intentos para mitigar fuerza bruta (login) y enumeración
 * masiva de usuarios (registro).
 *
 * Persiste los intentos en archivos JSON (uno por clave) bajo
 * SGDM/backend/storage/throttle. No requiere cambios de esquema en la base de datos.
 */
class LoginThrottle {

    /** Login: máximo de intentos fallidos por IP+email dentro de la ventana. */
    private const LOGIN_MAX = 5;

    /** Registro: máximo de altas por IP dentro de la ventana (anti-enumeración). */
    private const REGISTER_MAX = 10;

    /** Ventana de tiempo en segundos (15 minutos) para ambos casos. */
    private const WINDOW_SECONDS = 900;

    // ──────────────────────────────────────────────────────
    // Núcleo genérico (keyed por un identificador arbitrario)
    // ──────────────────────────────────────────────────────

    /**
     * Directorio donde se almacenan los contadores.
     */
    private static function dir(): string {
        $dir = __DIR__ . '/../storage/throttle';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    /**
     * IP del cliente (best-effort).
     */
    private static function ip(): string {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Ruta del archivo de contador para una clave lógica.
     */
    private static function fileFor(string $key): string {
        return self::dir() . '/' . hash('sha256', $key) . '.json';
    }

    /**
     * Lee los timestamps vigentes (dentro de la ventana) de una clave.
     *
     * @return int[]
     */
    private static function read(string $key): array {
        $file = self::fileFor($key);
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data)) {
            return [];
        }
        $cutoff = time() - self::WINDOW_SECONDS;
        return array_values(array_filter($data, static fn($ts) => is_int($ts) && $ts > $cutoff));
    }

    /**
     * Registra un evento (timestamp) para una clave.
     */
    private static function hit(string $key): void {
        $attempts   = self::read($key);
        $attempts[] = time();
        @file_put_contents(self::fileFor($key), json_encode($attempts), LOCK_EX);
    }

    /**
     * Cuenta eventos vigentes de una clave.
     */
    private static function count(string $key): int {
        return count(self::read($key));
    }

    // ──────────────────────────────────────────────────────
    // Login (clave: IP + email)
    // ──────────────────────────────────────────────────────

    private static function loginKey(string $email): string {
        return 'login|' . strtolower(self::ip() . '|' . trim($email));
    }

    public static function isBlocked(string $email): bool {
        return self::count(self::loginKey($email)) >= self::LOGIN_MAX;
    }

    public static function remaining(string $email): int {
        return max(0, self::LOGIN_MAX - self::count(self::loginKey($email)));
    }

    public static function registerFailure(string $email): void {
        self::hit(self::loginKey($email));
    }

    public static function clear(string $email): void {
        $file = self::fileFor(self::loginKey($email));
        if (is_file($file)) {
            @unlink($file);
        }
    }

    // ──────────────────────────────────────────────────────
    // Registro (clave: solo IP, para frenar barridos de enumeración)
    // ──────────────────────────────────────────────────────

    private static function registerKey(): string {
        return 'register|' . strtolower(self::ip());
    }

    /**
     * Indica si la IP superó el máximo de altas permitidas en la ventana.
     */
    public static function isRegistrationBlocked(): bool {
        return self::count(self::registerKey()) >= self::REGISTER_MAX;
    }

    /**
     * Registra un intento de alta (exitoso o no) para la IP actual.
     */
    public static function registerSignupAttempt(): void {
        self::hit(self::registerKey());
    }
}
