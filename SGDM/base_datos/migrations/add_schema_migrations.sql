-- ============================================================
-- Migración: seguimiento de qué migraciones ya se corrieron
-- Sin esto, un archivo .sql nuevo en esta carpeta puede quedar sin correr
-- en la base real sin que nadie lo note — fue justo lo que pasó con la
-- tabla avisos y la columna ubicacion, que existían en el código pero no
-- en la base de Render hasta que alguien los corrió a mano.
--
-- Convención a partir de ahora: toda migración nueva (add_*.sql) termina
-- con un INSERT IGNORE que se registra a sí misma acá abajo. AdminController
-- expone GET /api/admin/salud, que compara los archivos add_*.sql del repo
-- contra esta tabla y avisa en el panel de admin si falta correr alguno.
-- ============================================================

USE tornalyx_db;

CREATE TABLE IF NOT EXISTS schema_migrations (
    filename    VARCHAR(180) NOT NULL PRIMARY KEY,
    applied_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: las migraciones anteriores a esta ya están aplicadas (si no lo
-- estuvieran, la app ya estaría rota antes de llegar a esta línea).
INSERT IGNORE INTO schema_migrations (filename) VALUES
    ('add_2fa.sql'),
    ('add_doc_acceso.sql'),
    ('add_perfil.sql'),
    ('add_torneo_gestion.sql'),
    ('add_avatar_blob.sql'),
    ('add_schema_migrations.sql');
