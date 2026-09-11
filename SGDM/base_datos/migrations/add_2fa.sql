-- ============================================================
-- TORNALYX SGDM – Migración: doble factor (2FA) por código OTP
-- Ejecutar sobre una base ya creada con schema.sql.
-- ============================================================

USE tornalyx_db;

-- ──────────────────────────────────────────────────────────────
-- TABLA: login_otps  (código de verificación de un solo uso)
-- Un código activo por usuario; se reemplaza en cada solicitud.
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS login_otps (
    usuario_id   INT UNSIGNED PRIMARY KEY,
    code_hash    VARCHAR(255) NOT NULL,            -- bcrypt del código de 6 dígitos
    expires_at   TIMESTAMP    NOT NULL,
    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_sent_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_otp_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
