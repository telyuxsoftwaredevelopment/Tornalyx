-- ============================================================
-- Migración: roles por torneo en vez de rol global "organizador"
-- Cualquier usuario logueado puede crear torneos; quien lo crea es su
-- organizador (torneos.organizador_id), quien se inscribe es participante
-- de ESE torneo. El rol global "organizador" deja de tener uso: se
-- migran las cuentas existentes a 'participante' y se reduce el ENUM.
-- ============================================================

USE tornalyx_db;

UPDATE usuarios SET rol = 'participante' WHERE rol = 'organizador';
ALTER TABLE usuarios MODIFY COLUMN rol ENUM('participante','administrador') NOT NULL DEFAULT 'participante';

-- Se registra a sí misma (ver add_schema_migrations.sql). El CREATE TABLE
-- de acá abajo es defensivo por si esta migración se corre antes que esa.
CREATE TABLE IF NOT EXISTS schema_migrations (
    filename    VARCHAR(180) NOT NULL PRIMARY KEY,
    applied_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO schema_migrations (filename) VALUES ('add_rol_torneo_simplificado.sql');
