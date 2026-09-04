-- ============================================================
-- Migración: gestión completa del torneo
-- Cubre reglamento (RF-18), canal oficial de comunicación
-- (RF-21), premios (RF-23), torneos por equipos (RF-06),
-- confirmación de asistencia (RF-09) y avisos del organizador
-- (RF-05, RF-10, RF-20). Las tablas de inscripciones, equipos,
-- rondas, partidos y posiciones ya existían en el esquema.
-- ============================================================

USE tornalyx_db;

-- TiDB no resuelve "AFTER <col>" contra una columna agregada en el mismo
-- ALTER TABLE (a diferencia de MySQL): valida los AFTER contra el estado
-- de la tabla antes del statement, no incrementalmente. Por eso van en
-- ALTER separados en vez de uno solo con las cuatro columnas.
ALTER TABLE torneos ADD COLUMN reglamento       TEXT         NULL DEFAULT NULL AFTER descripcion;
ALTER TABLE torneos ADD COLUMN premios          TEXT         NULL DEFAULT NULL AFTER reglamento;
ALTER TABLE torneos ADD COLUMN discord_url      VARCHAR(255) NULL DEFAULT NULL AFTER premios;
ALTER TABLE torneos ADD COLUMN requiere_equipos TINYINT(1)   NOT NULL DEFAULT 0 AFTER formato;

-- ──────────────────────────────────────────────────────────────
-- TABLA: asistencias  (confirmación previa a cada enfrentamiento)
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS asistencias (
    partido_id   INT UNSIGNED NOT NULL,
    usuario_id   INT UNSIGNED NOT NULL,
    estado       ENUM('confirmada','ausente') NOT NULL DEFAULT 'confirmada',
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (partido_id, usuario_id),
    CONSTRAINT fk_asistencia_partido
        FOREIGN KEY (partido_id) REFERENCES partidos(id) ON DELETE CASCADE,
    CONSTRAINT fk_asistencia_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- TABLA: avisos  (novedades del torneo y notificaciones al usuario)
-- torneo_id NULL = aviso global del sistema.
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS avisos (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    torneo_id   INT UNSIGNED NULL DEFAULT NULL,
    autor_id    INT UNSIGNED NULL DEFAULT NULL,
    tipo        ENUM('novedad','fixture','resultado','inscripcion','horario') NOT NULL DEFAULT 'novedad',
    titulo      VARCHAR(140) NOT NULL,
    cuerpo      TEXT         NULL DEFAULT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_aviso_torneo
        FOREIGN KEY (torneo_id) REFERENCES torneos(id) ON DELETE CASCADE,
    CONSTRAINT fk_aviso_autor
        FOREIGN KEY (autor_id)  REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_torneo_fecha (torneo_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Marca de lectura por usuario, para el contador de notificaciones.
CREATE TABLE IF NOT EXISTS avisos_leidos (
    aviso_id   INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    leido_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (aviso_id, usuario_id),
    CONSTRAINT fk_avisoleido_aviso
        FOREIGN KEY (aviso_id)   REFERENCES avisos(id)   ON DELETE CASCADE,
    CONSTRAINT fk_avisoleido_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
