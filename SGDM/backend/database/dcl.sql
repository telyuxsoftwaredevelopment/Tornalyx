-- ============================================================
-- TORNALYX SGDM — DCL (Data Control Language)
-- Usuarios de MySQL con privilegios mínimos por función.
--
-- Requisito: Fullstack segunda entrega ("Configuración de usuarios de BD
-- con las restricciones pertinentes" / "DCL implementado").
--
-- Separación por lenguaje SQL (además de por función): los tres usuarios
-- de aplicación quedan divididos exactamente en lo que cada uno de DDL,
-- DCL y DML necesita, sin superposición:
--   tornalyx_ddl  — solo Data Definition Language (CREATE/ALTER/DROP/INDEX).
--                   No puede leer ni escribir una sola fila de datos.
--   tornalyx_dcl  — solo Data Control Language (GRANT/REVOKE/CREATE USER).
--                   No puede tocar estructura ni datos directamente; su
--                   trabajo es administrar los permisos de los demás.
--   tornalyx_dml  — solo Data Manipulation Language (SELECT/INSERT/UPDATE/
--                   DELETE). Es el usuario que usa la app en runtime.
--
-- Corresponde al SERVIDOR REAL (bare-metal / VM administrado por
-- admin_tornalyx), no al docker-compose.yml de desarrollo: ahí
-- MYSQL_USER/MYSQL_PASSWORD reciben privilegios completos sobre la base
-- por defecto de la imagen oficial de MySQL, y eso es intencional
-- únicamente para desarrollo local (ver comentario en docker-compose.yml).
--
-- Uso:
--   1. Reemplazar los placeholders CAMBIAR_* por contraseñas fuertes
--      generadas (ver política de contraseñas: mínimo 16 caracteres para
--      cuentas de administración, nunca reutilizadas).
--   2. Ejecutar como root de MySQL:
--        mysql -u root -p < dcl.sql
--   3. Cargar cada contraseña en la variable de entorno que le corresponde
--      (nunca en el código, nunca todas en el mismo .env):
--        - tornalyx_dml -> DB_PASS del .env de la app (SGDM/config/database.php).
--        - tornalyx_ddl -> DB_DDL_PASS, exportada a mano antes de correr
--          'desplegar.sh' o 'desplegar.sh solo-migrar' (nunca en el .env
--          de la app: la app en runtime no debe poder tocar el esquema).
--        - tornalyx_dcl -> uso exclusivamente manual por admin_tornalyx,
--          igual que root hoy. No vive en ningún .env ni la usa ningún
--          script; es la cuenta para dar de alta o ajustar el resto de
--          los usuarios sin repartir la contraseña de root.
--
-- Cada usuario está atado a 'localhost' porque la app, el backup y el
-- monitoreo corren en el mismo host que MySQL. Si en algún despliegue la
-- base vive en otro host, reemplazar 'localhost' por la IP del cliente
-- (nunca usar '%' salvo necesidad justificada).
-- ============================================================

-- ──────────────────────────────────────────────────────────────
-- tornalyx_ddl — usuario DDL: aplica el esquema y las migraciones
-- (SGDM/database/migrations/*.sql) sobre tornalyx_db. Lo usa únicamente
-- desplegar.sh al desplegar (variables DB_DDL_USER/DB_DDL_PASS, jamás las
-- credenciales de la app). Solo estructura: sin SELECT/INSERT/UPDATE/
-- DELETE, no puede leer ni escribir datos de negocio.
-- ──────────────────────────────────────────────────────────────
CREATE USER IF NOT EXISTS 'tornalyx_ddl'@'localhost' IDENTIFIED BY 'CAMBIAR_PASSWORD_DDL';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'tornalyx_ddl'@'localhost';
GRANT CREATE, ALTER, DROP, INDEX, REFERENCES ON tornalyx_db.* TO 'tornalyx_ddl'@'localhost';

-- ──────────────────────────────────────────────────────────────
-- tornalyx_dcl — usuario DCL: administra permisos y cuentas del resto de
-- los usuarios de la aplicación (GRANT/REVOKE/CREATE USER). Uso
-- EXCLUSIVAMENTE manual por admin_tornalyx, igual que root hoy: sus
-- credenciales no viven en ningún .env ni las usa ningún script.
--
-- Solo puede otorgar (WITH GRANT OPTION) exactamente los privilegios que
-- él mismo tiene: nada sobre otras bases, sin SUPER/FILE/SHUTDOWN/RELOAD.
-- CREATE USER y PROCESS son privilegios globales en MySQL (no se pueden
-- acotar a una base), por eso van sobre *.*; el resto queda limitado a
-- tornalyx_db.
-- ──────────────────────────────────────────────────────────────
CREATE USER IF NOT EXISTS 'tornalyx_dcl'@'localhost' IDENTIFIED BY 'CAMBIAR_PASSWORD_DCL';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'tornalyx_dcl'@'localhost';
GRANT CREATE USER ON *.* TO 'tornalyx_dcl'@'localhost' WITH GRANT OPTION;
GRANT PROCESS ON *.* TO 'tornalyx_dcl'@'localhost' WITH GRANT OPTION;
GRANT SELECT, INSERT, UPDATE, DELETE,
      CREATE, ALTER, DROP, INDEX, REFERENCES,
      LOCK TABLES, SHOW VIEW, EVENT, TRIGGER
  ON tornalyx_db.* TO 'tornalyx_dcl'@'localhost' WITH GRANT OPTION;

-- ──────────────────────────────────────────────────────────────
-- tornalyx_dml — usuario DML: la aplicación en runtime
-- (SGDM/config/database.php). Solo CRUD sobre las tablas de negocio, sin
-- DDL (CREATE/ALTER/DROP) ni acceso a otras bases o tablas de sistema.
-- ──────────────────────────────────────────────────────────────
CREATE USER IF NOT EXISTS 'tornalyx_dml'@'localhost' IDENTIFIED BY 'CAMBIAR_PASSWORD_DML';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'tornalyx_dml'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON tornalyx_db.* TO 'tornalyx_dml'@'localhost';

-- ──────────────────────────────────────────────────────────────
-- tornalyx_monitor — usuario de solo lectura para monitoreo_bd.sh
-- (variables DB_MONITOR_USER/DB_MONITOR_PASS). PROCESS es un privilegio
-- global (no se puede limitar a una base), pero solo permite VER los
-- procesos en ejecución, no matarlos ni modificarlos.
-- ──────────────────────────────────────────────────────────────
CREATE USER IF NOT EXISTS 'tornalyx_monitor'@'localhost' IDENTIFIED BY 'CAMBIAR_PASSWORD_MONITOR';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'tornalyx_monitor'@'localhost';
GRANT SELECT ON tornalyx_db.* TO 'tornalyx_monitor'@'localhost';
GRANT PROCESS ON *.* TO 'tornalyx_monitor'@'localhost';
GRANT SELECT ON mysql.slow_log TO 'tornalyx_monitor'@'localhost';

-- ──────────────────────────────────────────────────────────────
-- tornalyx_backup — usuario para mysqldump (variables DB_BACKUP_USER/
-- DB_BACKUP_PASS), usado por el usuario de sistema backup_tornalyx (ver
-- analisis-usuarios-sistema.md y respaldo.sh). Solo lectura + LOCK
-- TABLES/SHOW VIEW, imprescindibles para un dump consistente; sin
-- permisos de escritura.
-- ──────────────────────────────────────────────────────────────
CREATE USER IF NOT EXISTS 'tornalyx_backup'@'localhost' IDENTIFIED BY 'CAMBIAR_PASSWORD_BACKUP';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'tornalyx_backup'@'localhost';
GRANT SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER ON tornalyx_db.* TO 'tornalyx_backup'@'localhost';

-- ──────────────────────────────────────────────────────────────
-- tornalyx_dev — usuario de desarrollo, acotado a la base tornalyx_dev
-- (nunca a tornalyx_db de producción). Usado por dev_tornalyx (ver
-- analisis-usuarios-sistema.md: "Acceso a MySQL de desarrollo").
-- Sí puede crear/alterar tablas dentro de SU base para poder iterar el
-- esquema y correr migraciones/seeders de prueba.
-- ──────────────────────────────────────────────────────────────
CREATE DATABASE IF NOT EXISTS tornalyx_dev
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'tornalyx_dev'@'localhost' IDENTIFIED BY 'CAMBIAR_PASSWORD_DEV';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'tornalyx_dev'@'localhost';
GRANT ALL PRIVILEGES ON tornalyx_dev.* TO 'tornalyx_dev'@'localhost';

FLUSH PRIVILEGES;

-- ──────────────────────────────────────────────────────────────
-- Verificación rápida de lo aplicado:
--   SHOW GRANTS FOR 'tornalyx_ddl'@'localhost';
--   SHOW GRANTS FOR 'tornalyx_dcl'@'localhost';
--   SHOW GRANTS FOR 'tornalyx_dml'@'localhost';
--   SHOW GRANTS FOR 'tornalyx_monitor'@'localhost';
--   SHOW GRANTS FOR 'tornalyx_backup'@'localhost';
--   SHOW GRANTS FOR 'tornalyx_dev'@'localhost';
-- ──────────────────────────────────────────────────────────────
