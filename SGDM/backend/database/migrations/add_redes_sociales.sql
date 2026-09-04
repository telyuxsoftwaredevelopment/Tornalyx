-- ============================================================
-- Migración: redes sociales del perfil (Twitter/X, Facebook, Instagram)
-- Cada una es un link opcional que se muestra como ícono clickeable en
-- el perfil propio y en la ficha pública de jugador. Se valida como
-- URL http(s) al guardar (mismo criterio que discord_url en torneos:
-- bloquea esquemas javascript:/data: en TorneoController/validarCampos).
-- ============================================================

USE tornalyx_db;

ALTER TABLE usuarios ADD COLUMN twitter_url   VARCHAR(255) NULL DEFAULT NULL AFTER ubicacion;
ALTER TABLE usuarios ADD COLUMN facebook_url  VARCHAR(255) NULL DEFAULT NULL AFTER twitter_url;
ALTER TABLE usuarios ADD COLUMN instagram_url VARCHAR(255) NULL DEFAULT NULL AFTER facebook_url;

-- Se registra a sí misma (ver add_schema_migrations.sql). El CREATE TABLE
-- de acá abajo es defensivo por si esta migración se corre antes que esa.
CREATE TABLE IF NOT EXISTS schema_migrations (
    filename    VARCHAR(180) NOT NULL PRIMARY KEY,
    applied_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO schema_migrations (filename) VALUES ('add_redes_sociales.sql');
