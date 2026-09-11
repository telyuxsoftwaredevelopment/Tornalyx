-- ============================================================
-- Migración: Nickname y Tag (Estilo Riot) para el usuario
-- Agrega las columnas nickname (máx. 30 chars) y tag (máx. 5 chars)
-- ============================================================

USE tornalyx_db;

CREATE TABLE IF NOT EXISTS schema_migrations (
    filename    VARCHAR(180) NOT NULL PRIMARY KEY,
    applied_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE usuarios ADD COLUMN nickname VARCHAR(30) NULL DEFAULT NULL AFTER apellido;
ALTER TABLE usuarios ADD COLUMN tag      VARCHAR(5)  NULL DEFAULT NULL AFTER nickname;

INSERT IGNORE INTO schema_migrations (filename) VALUES ('add_nickname_tag.sql');
