<?php
/**
 * Configuración y conexión a la base de datos.
 * Usa PDO con prepared statements para prevenir inyección SQL.
 *
 * Las credenciales se leen de variables de entorno para no exponerlas
 * en el código fuente ni en el control de versiones. Definí estas
 * variables en el entorno del servidor (o en un archivo .env cargado
 * por el SAPI). Los valores por defecto solo sirven para desarrollo local.
 */

/**
 * Carga variables desde un archivo .env (si existe) en la raíz del proyecto.
 * No sobreescribe variables ya definidas en el entorno real del servidor,
 * de modo que en producción el entorno tiene prioridad sobre el archivo.
 *
 * PHP plano no lee .env automáticamente; este cargador minimalista lo suple
 * sin dependencias externas. El archivo .env está en .gitignore (no se versiona).
 */
function loadDotEnv(string $path): void {
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        // Quitar comillas envolventes si las hubiera (el valor se toma literal).
        if (strlen($val) >= 2
            && (($val[0] === '"' && $val[-1] === '"') || ($val[0] === "'" && $val[-1] === "'"))) {
            $val = substr($val, 1, -1);
        }
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $val);
            $_ENV[$key] = $val;
        }
    }
}

loadDotEnv(__DIR__ . '/../../../.env');

/**
 * Lee una variable de entorno con valor por defecto para desarrollo local.
 */
function envOrDefault(string $key, string $default): string {
    $value = getenv($key);
    return ($value !== false && $value !== '') ? $value : $default;
}

define('DB_HOST',    envOrDefault('DB_HOST', 'localhost'));
define('DB_PORT',    envOrDefault('DB_PORT', '3306'));
define('DB_NAME',    envOrDefault('DB_NAME', 'tornalyx_db'));
define('DB_USER',    envOrDefault('DB_USER', 'root'));
define('DB_PASS',    envOrDefault('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

/**
 * Retorna la conexión PDO (singleton).
 *
 * @return PDO
 * @throws RuntimeException Si la conexión falla.
 */
function getDB(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        // ── TLS opcional para MySQL gestionado (Aiven, TiDB, etc.) ──
        // Los proveedores cloud suelen exigir conexión cifrada. Se activa
        // con DB_SSL=1; en desarrollo local queda desactivado por defecto.
        if (filter_var(getenv('DB_SSL') ?: '0', FILTER_VALIDATE_BOOLEAN)) {
            $ca = getenv('DB_SSL_CA') ?: '';
            // El CA puede pasarse como CONTENIDO PEM (no como ruta), práctico
            // para configurarlo via variable de entorno sin versionar archivos.
            if (strncmp($ca, '-----BEGIN', 10) === 0) {
                $tmp = tempnam(sys_get_temp_dir(), 'dbca');
                file_put_contents($tmp, $ca);
                $ca = $tmp;
            }
            // Sin CA explícito, usar el bundle del sistema: sirve para
            // proveedores con CA pública (p. ej. TiDB Serverless).
            if ($ca === '') {
                $ca = '/etc/ssl/certs/ca-certificates.crt';
            }
            $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
            // DB_SSL_VERIFY=0 desactiva la verificación del cert del servidor
            // (último recurso, no recomendado en producción).
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] =
                    filter_var(getenv('DB_SSL_VERIFY') ?: '1', FILTER_VALIDATE_BOOLEAN);
            }
        }

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // Sincronizar automáticamente cualquier columna o tabla faltante
            require_once __DIR__ . '/../models/Migracion.php';
            (new Migracion())->ejecutarFaltantes();
        } catch (PDOException $e) {
            // Reason: Never exponer detalles de BD al cliente en producción
            error_log('DB connection error: ' . $e->getMessage());
            throw new RuntimeException('No se pudo conectar a la base de datos.');
        } catch (Throwable $e) {
            error_log('Auto-migration error: ' . $e->getMessage());
        }
    }

    return $pdo;
}

