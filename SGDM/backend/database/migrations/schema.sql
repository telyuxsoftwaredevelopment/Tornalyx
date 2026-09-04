-- ============================================================
-- TORNALYX SGDM – Esquema de Base de Datos
-- Motor: MySQL 8.x
-- Codificación: utf8mb4
-- ============================================================

CREATE DATABASE IF NOT EXISTS tornalyx_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE tornalyx_db;

-- ──────────────────────────────────────────────────────────────
-- TABLA: usuarios
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS usuarios (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre       VARCHAR(60)  NOT NULL,
    apellido     VARCHAR(60)  NOT NULL,
    nickname     VARCHAR(30)  DEFAULT NULL,
    tag          VARCHAR(5)   DEFAULT NULL,
    email        VARCHAR(120) NOT NULL UNIQUE,
    password     VARCHAR(255) NOT NULL,          -- bcrypt hash
    fecha_nac    DATE         NOT NULL,
    -- 'organizador' ya no es un rol de cuenta: se es organizador de un torneo
    -- puntual vía torneos.organizador_id (ver add_rol_torneo_simplificado.sql).
    rol          ENUM('participante','administrador') NOT NULL DEFAULT 'participante',
    estado       ENUM('activo','suspendido','pendiente') NOT NULL DEFAULT 'activo',
    avatar_url   VARCHAR(255) DEFAULT NULL,
    -- Foto de perfil guardada en la propia fila (ver add_avatar_blob.sql;
    -- mantener en sincronía). Render free no tiene disco persistente, así
    -- que el archivo no puede vivir en frontend/uploads: se sirve desde acá
    -- vía GET /api/perfil/avatar/{id}, y avatar_url solo guarda esa URL.
    avatar_data  MEDIUMBLOB   DEFAULT NULL,
    avatar_mime  VARCHAR(30)  DEFAULT NULL,
    -- Perfil público (ver add_perfil.sql; mantener en sincronía)
    bio          TEXT         DEFAULT NULL,
    ubicacion    VARCHAR(120) DEFAULT NULL,
    -- Redes sociales, opcionales (ver add_redes_sociales.sql; mantener en
    -- sincronía). Se muestran como íconos clickeables en el perfil.
    twitter_url   VARCHAR(255) DEFAULT NULL,
    facebook_url  VARCHAR(255) DEFAULT NULL,
    instagram_url VARCHAR(255) DEFAULT NULL,
    youtube_url   VARCHAR(255) DEFAULT NULL,
    tiktok_url    VARCHAR(255) DEFAULT NULL,
    kick_url      VARCHAR(255) DEFAULT NULL,
    twitch_url    VARCHAR(255) DEFAULT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email  (email),
    INDEX idx_rol    (rol),
    INDEX idx_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- TABLA: torneos
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS torneos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organizador_id  INT UNSIGNED NOT NULL,
    nombre          VARCHAR(120) NOT NULL,
    descripcion     TEXT         DEFAULT NULL,
    -- Gestión del torneo (ver add_torneo_gestion.sql; mantener en sincronía)
    reglamento      TEXT         DEFAULT NULL,
    premios         TEXT         DEFAULT NULL,
    discord_url     VARCHAR(255) DEFAULT NULL,
    disciplina      VARCHAR(60)  NOT NULL,
    formato         ENUM('liga','eliminacion_directa','suizo','grupos_playoff') NOT NULL,
    requiere_equipos TINYINT(1)  NOT NULL DEFAULT 0,
    estado          ENUM('borrador','inscripcion','en_curso','finalizado','cancelado') NOT NULL DEFAULT 'borrador',
    max_participantes INT UNSIGNED NOT NULL DEFAULT 16,
    publico         TINYINT(1)   NOT NULL DEFAULT 1,
    -- Igual que avatar_data/avatar_mime en usuarios: Render free no tiene
    -- disco persistente, así que la foto de fondo se guarda en la propia
    -- fila y se sirve vía GET /api/torneo/{id}/banner; banner_url solo
    -- guarda esa URL (ver add_torneo_banner.sql).
    banner_url      VARCHAR(255) DEFAULT NULL,
    banner_data     MEDIUMBLOB   DEFAULT NULL,
    banner_mime     VARCHAR(30)  DEFAULT NULL,
    fecha_inicio    DATE         DEFAULT NULL,
    fecha_fin       DATE         DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_torneo_organizador
        FOREIGN KEY (organizador_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
    INDEX idx_estado     (estado),
    INDEX idx_disciplina (disciplina),
    INDEX idx_org        (organizador_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- TABLA: equipos
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS equipos (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    capitan_id  INT UNSIGNED NOT NULL,
    torneo_id   INT UNSIGNED NOT NULL,
    nombre      VARCHAR(80)  NOT NULL,
    estado      ENUM('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_equipo_capitan
        FOREIGN KEY (capitan_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
    CONSTRAINT fk_equipo_torneo
        FOREIGN KEY (torneo_id) REFERENCES torneos(id) ON DELETE CASCADE,
    UNIQUE KEY uq_nombre_torneo (nombre, torneo_id),
    INDEX idx_torneo (torneo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- TABLA: inscripciones  (participante individual ↔ torneo)
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS inscripciones (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id      INT UNSIGNED NOT NULL,
    torneo_id       INT UNSIGNED NOT NULL,
    equipo_id       INT UNSIGNED DEFAULT NULL,
    estado          ENUM('pendiente','aprobada','rechazada') NOT NULL DEFAULT 'pendiente',
    fecha_inscripcion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_inscripcion_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_inscripcion_torneo
        FOREIGN KEY (torneo_id) REFERENCES torneos(id) ON DELETE CASCADE,
    CONSTRAINT fk_inscripcion_equipo
        FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE SET NULL,
    UNIQUE KEY uq_usuario_torneo (usuario_id, torneo_id),
    INDEX idx_torneo (torneo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- TABLA: rondas
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS rondas (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    torneo_id   INT UNSIGNED NOT NULL,
    numero      TINYINT UNSIGNED NOT NULL,
    nombre      VARCHAR(60) DEFAULT NULL,          -- "Semifinal", "Ronda 3", etc.
    estado      ENUM('pendiente','en_curso','finalizada') NOT NULL DEFAULT 'pendiente',
    CONSTRAINT fk_ronda_torneo
        FOREIGN KEY (torneo_id) REFERENCES torneos(id) ON DELETE CASCADE,
    UNIQUE KEY uq_torneo_ronda (torneo_id, numero)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- TABLA: partidos
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS partidos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    torneo_id       INT UNSIGNED NOT NULL,
    ronda_id        INT UNSIGNED NOT NULL,
    local_id        INT UNSIGNED NOT NULL,          -- equipo o usuario
    visitante_id    INT UNSIGNED NOT NULL,
    tipo_contendiente ENUM('usuario','equipo') NOT NULL DEFAULT 'equipo',
    estado          ENUM('pendiente','en_curso','finalizado','aplazado') NOT NULL DEFAULT 'pendiente',
    fecha_partido   DATETIME DEFAULT NULL,
    lugar           VARCHAR(120) DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_partido_torneo
        FOREIGN KEY (torneo_id) REFERENCES torneos(id) ON DELETE CASCADE,
    CONSTRAINT fk_partido_ronda
        FOREIGN KEY (ronda_id) REFERENCES rondas(id) ON DELETE CASCADE,
    INDEX idx_torneo (torneo_id),
    INDEX idx_ronda  (ronda_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- TABLA: resultados
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS resultados (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    partido_id      INT UNSIGNED NOT NULL UNIQUE,
    goles_local     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    goles_visitante TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ganador_id      INT UNSIGNED DEFAULT NULL,      -- NULL = empate
    observaciones   TEXT DEFAULT NULL,
    cargado_por     INT UNSIGNED NOT NULL,          -- usuario_id del que cargó
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_resultado_partido
        FOREIGN KEY (partido_id) REFERENCES partidos(id) ON DELETE CASCADE,
    CONSTRAINT fk_resultado_cargado
        FOREIGN KEY (cargado_por) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- TABLA: posiciones  (caché de tabla de posiciones)
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS posiciones (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    torneo_id       INT UNSIGNED NOT NULL,
    contendiente_id INT UNSIGNED NOT NULL,
    tipo            ENUM('usuario','equipo') NOT NULL,
    pj              SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- partidos jugados
    pg              SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- ganados
    pe              SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- empatados
    pp              SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- perdidos
    gf              SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- goles/puntos a favor
    gc              SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- goles/puntos en contra
    dg              SMALLINT          NOT NULL DEFAULT 0,  -- diferencia
    pts             SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_posicion_torneo
        FOREIGN KEY (torneo_id) REFERENCES torneos(id) ON DELETE CASCADE,
    UNIQUE KEY uq_torneo_contendiente (torneo_id, contendiente_id, tipo),
    INDEX idx_torneo_pts (torneo_id, pts DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- TABLA: sesiones  (PHP session store manual)
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sesiones (
    id          VARCHAR(128) PRIMARY KEY,
    usuario_id  INT UNSIGNED NOT NULL,
    ip          VARCHAR(45)  NOT NULL,
    user_agent  VARCHAR(255) DEFAULT NULL,
    expires_at  TIMESTAMP    NOT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sesion_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_usuario (usuario_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- TABLA: login_otps  (códigos de verificación 2FA por email)
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

-- ──────────────────────────────────────────────────────────────
-- TABLA: doc_acceso  (autorización a documentación restringida)
-- Solicitud/estado por (usuario, materia); el admin aprueba/rechaza.
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS doc_acceso (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id    INT UNSIGNED NOT NULL,
    materia       VARCHAR(40)  NOT NULL,
    estado        ENUM('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
    solicitado_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resuelto_at   TIMESTAMP    NULL DEFAULT NULL,
    resuelto_por  INT UNSIGNED NULL DEFAULT NULL,
    -- Aprobación por email (magic link estilo Google Docs): token de un solo uso
    -- hasheado, con vencimiento. Ver add_doc_acceso.sql. Mantener en sincronía.
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
-- TABLA: doc_otps  (código OTP para ver un documento restringido)
-- Separada de login_otps; un código activo por (usuario, materia).
-- ──────────────────────────────────────────────────────────────
-- ──────────────────────────────────────────────────────────────
-- TABLA: asistencias  (confirmación previa a cada enfrentamiento)
-- Ver add_torneo_gestion.sql; mantener en sincronía.
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
-- TABLA: avisos  (novedades del torneo / notificaciones)
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

CREATE TABLE IF NOT EXISTS doc_otps (
    usuario_id   INT UNSIGNED NOT NULL,
    materia      VARCHAR(40)  NOT NULL,
    code_hash    VARCHAR(255) NOT NULL,
    -- Default fijo explícito: evita que MariaDB (con explicit_defaults_for_timestamp
    -- apagado) le agregue ON UPDATE CURRENT_TIMESTAMP al primer TIMESTAMP, lo que
    -- pisaría expires_at en cada UPDATE de attempts y rompería el reintento del código.
    expires_at   TIMESTAMP    NOT NULL DEFAULT '1970-01-01 00:00:01',
    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_sent_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (usuario_id, materia),
    CONSTRAINT fk_docotp_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- TABLA: schema_migrations  (registro de migraciones aplicadas)
-- La crea también add_schema_migrations.sql, pero tiene que estar acá:
-- sin ella, una base recién creada desde este archivo arranca con el
-- registro vacío y la auto-migración de Migracion::ejecutarFaltantes()
-- vuelve a correr las diez add_*.sql contra un esquema que ya las trae,
-- llenando el log de errores de columna duplicada.
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS schema_migrations (
    filename    VARCHAR(180) NOT NULL PRIMARY KEY,
    applied_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- NULL = aplicada sin errores. Si trae texto, la migracion corrio pero
    -- alguna sentencia fallo: Migracion::faltantes() la sigue reportando
    -- como pendiente y el panel de admin la muestra (ver Migracion.php).
    error       VARCHAR(500) NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: este archivo ya trae plegado el efecto de todas las migraciones
-- de abajo, así que se dan por aplicadas. IMPORTANTE: al plegar una migración
-- nueva en este esquema, agregar su nombre también a esta lista; si no, se va
-- a re-ejecutar de más en cada base nueva.
INSERT IGNORE INTO schema_migrations (filename) VALUES
    ('add_2fa.sql'),
    ('add_avatar_blob.sql'),
    ('add_doc_acceso.sql'),
    ('add_nickname_tag.sql'),
    ('add_perfil.sql'),
    ('add_redes_sociales.sql'),
    ('add_redes_streaming.sql'),
    ('add_rol_torneo_simplificado.sql'),
    ('add_schema_migrations.sql'),
    ('add_torneo_banner.sql'),
    ('add_torneo_gestion.sql');
