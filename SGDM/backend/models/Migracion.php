<?php
require_once __DIR__ . '/Model.php';

/**
 * Registro de qué migraciones .sql de database/migrations/ ya se aplicaron a
 * esta base (ver add_schema_migrations.sql). Cada add_*.sql nuevo se inserta a
 * sí mismo con INSERT IGNORE al final; este modelo compara esa tabla contra los
 * archivos que existen en el repo para que el panel de admin avise si falta
 * correr alguno, y guarda el motivo cuando una quedó a medio aplicar.
 */
class Migracion extends Model {
    protected string $table = 'schema_migrations';
    protected string $primaryKey = 'filename';

    private const DIR = __DIR__ . '/../database/migrations';

    /**
     * Códigos de error de MySQL/TiDB que significan "esto ya estaba hecho":
     * tabla existente (1050), columna duplicada (1060), índice repetido (1061),
     * fila duplicada (1062) y DROP de algo inexistente (1091). Re-ejecutar una
     * migración contra una base que ya la tiene los produce en masa, así que no
     * cuentan como falla; cualquier otro código sí.
     */
    private const CODIGOS_YA_APLICADO = [1050, 1060, 1061, 1062, 1091];

    /**
     * Migraciones add_*.sql que no están aplicadas del todo: las que nunca
     * corrieron y las que quedaron registradas con error.
     *
     * @return string[]
     */
    public function faltantes(): array {
        $this->asegurarTabla();
        $archivos = array_map('basename', glob(self::DIR . '/add_*.sql') ?: []);

        $stmt = $this->db->query('SELECT filename, error FROM schema_migrations');
        $registradas = $conError = [];
        foreach ($stmt ? $stmt->fetchAll() : [] as $fila) {
            $registradas[] = $fila['filename'];
            if (($fila['error'] ?? null) !== null) {
                $conError[] = $fila['filename'] . ' (quedó a medio aplicar: ' . $fila['error'] . ')';
            }
        }

        return array_values(array_merge(array_diff($archivos, $registradas), $conError));
    }

    /**
     * Aplica automáticamente todas las migraciones add_*.sql pendientes.
     * Idempotente: los errores de "ya existe" se ignoran, pero cualquier otro
     * queda anotado en la fila de la migración en vez de darla por buena.
     */
    public function ejecutarFaltantes(): void {
        $this->asegurarTabla();
        $archivos = glob(self::DIR . '/add_*.sql') ?: [];
        sort($archivos);

        $stmt = $this->db->query('SELECT filename FROM schema_migrations');
        $registradas = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

        foreach ($archivos as $archivo) {
            $base = basename($archivo);
            if (in_array($base, $registradas, true)) {
                continue;
            }
            $sql = file_get_contents($archivo);
            if ($sql === false) {
                continue;
            }
            // Quitar sentencias USE para soportar cualquier nombre de BD configurado
            $sql = preg_replace('/^\s*USE\s+[^;]+;/mi', '', $sql);

            $errores = [];
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $sentencia) {
                if ($sentencia === '') {
                    continue;
                }
                try {
                    $this->db->exec($sentencia);
                } catch (Throwable $e) {
                    $yaAplicado = $this->esYaAplicado($e);
                    error_log(sprintf('Auto-migracion (%s) %s: %s',
                        $base, $yaAplicado ? 'aviso' : 'ERROR', $e->getMessage()));
                    if (!$yaAplicado) {
                        $errores[] = $e->getMessage();
                    }
                }
            }

            // Se registra siempre, incluso si falló: si no, un error permanente
            // haría reintentar el DDL completo en cada request. Lo que cambia es
            // que el motivo queda guardado y faltantes() —y con él el panel de
            // admin— la sigue reportando como pendiente en vez de darla por
            // aplicada. Para reintentarla, borrar su fila de schema_migrations.
            $this->registrar($base, $errores ? mb_substr(implode(' | ', $errores), 0, 500) : null);
        }
    }

    /**
     * Deja constancia de la migración y de su resultado (error = NULL si salió
     * limpia). Reescribe la fila si ya existía, para que un reintento exitoso
     * borre el error anterior.
     */
    private function registrar(string $archivo, ?string $error): void {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO schema_migrations (filename, error) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE error = VALUES(error)'
            );
            $stmt->execute([$archivo, $error]);
        } catch (Throwable $e) {
            error_log('Auto-migracion: no se pudo registrar ' . $archivo . ': ' . $e->getMessage());
        }
    }

    /** ¿El error solo dice que el cambio ya estaba hecho? */
    private function esYaAplicado(Throwable $e): bool {
        $codigo = ($e instanceof PDOException && isset($e->errorInfo[1])) ? (int) $e->errorInfo[1] : 0;
        return in_array($codigo, self::CODIGOS_YA_APLICADO, true);
    }

    private function asegurarTabla(): void {
        try {
            $this->db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
                filename    VARCHAR(180) NOT NULL PRIMARY KEY,
                applied_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                error       VARCHAR(500) NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        } catch (Throwable $e) {
            // Ignore
        }
        // Bases anteriores a la columna error: se agrega una sola vez. El SELECT
        // evita mandar un ALTER condenado a fallar en cada request.
        try {
            $this->db->query('SELECT error FROM schema_migrations LIMIT 1');
        } catch (Throwable $e) {
            try {
                $this->db->exec('ALTER TABLE schema_migrations ADD COLUMN error VARCHAR(500) NULL DEFAULT NULL');
            } catch (Throwable $e2) {
                error_log('Auto-migracion: no se pudo agregar schema_migrations.error: ' . $e2->getMessage());
            }
        }
    }
}
