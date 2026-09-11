-- ============================================================
-- Migración: campos de perfil público del usuario
-- Agrega la biografía breve (máx. 500 palabras, validado en el
-- backend) y la ubicación que se muestran en /perfil.
-- avatar_url ya existía en el esquema original.
-- ============================================================

USE tornalyx_db;

-- TiDB no resuelve "AFTER <col>" contra una columna agregada en el mismo
-- ALTER TABLE (a diferencia de MySQL): valida los AFTER contra el estado
-- de la tabla antes del statement, no incrementalmente. Por eso van en dos
-- ALTER separados en vez de uno solo con ambas columnas.
ALTER TABLE usuarios ADD COLUMN bio       TEXT         NULL DEFAULT NULL AFTER avatar_url;
ALTER TABLE usuarios ADD COLUMN ubicacion VARCHAR(120) NULL DEFAULT NULL AFTER bio;
