-- ============================================================
-- TORNALYX SGDM – Migración: acceso restringido a documentación
-- Documentos sensibles (p. ej. Ciberseguridad) que exigen:
--   1) usuario registrado (sesión),
--   2) autorización aprobada por un administrador,
--   3) verificación con código OTP enviado por email.
-- Ejecutar sobre una base ya creada con schema.sql.
-- ============================================================

USE tornalyx_db;

-- ──────────────────────────────────────────────────────────────
-- TABLA: doc_acceso  (solicitud y estado de autorización)
-- Una fila por (usuario, materia). El admin la aprueba o rechaza.
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS doc_acceso (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id    INT UNSIGNED NOT NULL,
    materia       VARCHAR(40)  NOT NULL,                 -- slug de la materia, p. ej. 'ciberseguridad'
    estado        ENUM('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
    solicitado_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resuelto_at   TIMESTAMP    NULL DEFAULT NULL,
    resuelto_por  INT UNSIGNED NULL DEFAULT NULL,        -- admin que aprobó/rechazó
    -- Aprobación por email (estilo Google Docs): al solicitar acceso se genera un
    -- token de un solo uso; su hash SHA-256 viaja hasheado acá y el token en claro
    -- va en el enlace del correo al aprobador. Se limpia al resolver la solicitud.
    aprob_token_hash CHAR(64)  NULL DEFAULT NULL,
    aprob_token_exp  TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_docacceso_usuario
        FOREIGN KEY (usuario_id)  REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_docacceso_resuelto
        FOREIGN KEY (resuelto_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    UNIQUE KEY uq_usuario_materia (usuario_id, materia),
    INDEX idx_estado (estado),
    INDEX idx_aprob_token (aprob_token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- TABLA: doc_otps  (código de un solo uso para ver el documento)
-- Separada de login_otps para no interferir con el 2FA del login.
-- Un código activo por (usuario, materia).
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS doc_otps (
    usuario_id   INT UNSIGNED NOT NULL,
    materia      VARCHAR(40)  NOT NULL,
    code_hash    VARCHAR(255) NOT NULL,                  -- bcrypt del código de 6 dígitos
    -- Default fijo explícito: evita que MariaDB (con explicit_defaults_for_timestamp
    -- apagado) le agregue ON UPDATE CURRENT_TIMESTAMP al primer TIMESTAMP, lo que
    -- pisaría expires_at en cada UPDATE de attempts y rompería el reintento.
    expires_at   TIMESTAMP    NOT NULL DEFAULT '1970-01-01 00:00:01',
    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_sent_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (usuario_id, materia),
    CONSTRAINT fk_docotp_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- Corrección idempotente: si un entorno ya había creado doc_otps con el
-- esquema anterior (expires_at TIMESTAMP sin default), MariaDB le habrá puesto
-- ON UPDATE CURRENT_TIMESTAMP implícito. Re-ejecutar la migración lo normaliza.
-- ──────────────────────────────────────────────────────────────
ALTER TABLE doc_otps MODIFY expires_at TIMESTAMP NOT NULL DEFAULT '1970-01-01 00:00:01';

-- ──────────────────────────────────────────────────────────────
-- Columnas de aprobación por email (idempotente): si doc_acceso ya existía sin
-- ellas, se agregan. MariaDB soporta ADD COLUMN IF NOT EXISTS.
-- ──────────────────────────────────────────────────────────────
ALTER TABLE doc_acceso ADD COLUMN IF NOT EXISTS aprob_token_hash CHAR(64)  NULL DEFAULT NULL;
ALTER TABLE doc_acceso ADD COLUMN IF NOT EXISTS aprob_token_exp  TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE doc_acceso ADD INDEX IF NOT EXISTS idx_aprob_token (aprob_token_hash);
