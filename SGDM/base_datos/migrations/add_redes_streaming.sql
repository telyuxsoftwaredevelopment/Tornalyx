-- ============================================================
-- Migración: redes de streaming y video en el perfil
-- Suma YouTube, TikTok, Kick y Twitch a las tres redes que ya
-- existían (X, Facebook, Instagram). Mismo criterio de validación:
-- PerfilController::normalizarRedSocial acepta URL completa, dominio
-- sin protocolo o solo el usuario, y guarda siempre una URL http(s).
-- ============================================================

USE tornalyx_db;

-- TiDB no resuelve "AFTER <col>" contra una columna agregada en el mismo
-- ALTER TABLE (a diferencia de MySQL): valida los AFTER contra el estado
-- de la tabla antes del statement, no incrementalmente. Por eso van en
-- ALTER separados en vez de uno solo con las cuatro columnas.
ALTER TABLE usuarios ADD COLUMN youtube_url VARCHAR(255) NULL DEFAULT NULL AFTER instagram_url;
ALTER TABLE usuarios ADD COLUMN tiktok_url  VARCHAR(255) NULL DEFAULT NULL AFTER youtube_url;
ALTER TABLE usuarios ADD COLUMN kick_url    VARCHAR(255) NULL DEFAULT NULL AFTER tiktok_url;
ALTER TABLE usuarios ADD COLUMN twitch_url  VARCHAR(255) NULL DEFAULT NULL AFTER kick_url;

-- Se registra a sí misma (ver add_schema_migrations.sql). El CREATE TABLE
-- de acá abajo es defensivo por si esta migración se corre antes que esa.
CREATE TABLE IF NOT EXISTS schema_migrations (
    filename    VARCHAR(180) NOT NULL PRIMARY KEY,
    applied_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    error       VARCHAR(500) NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO schema_migrations (filename) VALUES ('add_redes_streaming.sql');
