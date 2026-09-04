-- ============================================================
-- Migración: foto de fondo del torneo guardada en la base
-- Mismo patrón que add_avatar_blob.sql: Render free no tiene disco
-- persistente, así que la imagen vive como BLOB en la propia fila del
-- torneo y se sirve dinámicamente desde GET /api/torneo/{id}/banner
-- (TorneoController::servirBanner). banner_url ya existía en el schema
-- pero no se usaba en ningún lado; ahora guarda esa URL servida.
-- ============================================================

USE tornalyx_db;

-- TiDB no resuelve "AFTER <col>" contra una columna agregada en el mismo
-- ALTER TABLE (a diferencia de MySQL): valida los AFTER contra el estado
-- de la tabla antes del statement, no incrementalmente. Por eso van en dos
-- ALTER separados en vez de uno solo con ambas columnas.
ALTER TABLE torneos ADD COLUMN banner_data MEDIUMBLOB  NULL DEFAULT NULL AFTER banner_url;
ALTER TABLE torneos ADD COLUMN banner_mime VARCHAR(30) NULL DEFAULT NULL AFTER banner_data;

-- Se registra a sí misma (ver add_schema_migrations.sql). El CREATE TABLE
-- de acá abajo es defensivo por si esta migración se corre antes que esa.
CREATE TABLE IF NOT EXISTS schema_migrations (
    filename    VARCHAR(180) NOT NULL PRIMARY KEY,
    applied_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO schema_migrations (filename) VALUES ('add_torneo_banner.sql');
