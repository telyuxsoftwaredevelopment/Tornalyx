# Scripts de administración del servidor — Tornalyx SGDM

Suite en Bash para administrar el servidor donde corre la plataforma
(AlmaLinux 8.10 + Apache + MySQL 8), documentada en
`SGDM/vista/docs/sistemas-operativos.html`.

Todo pasa por `gestion.sh`, pero cada script funciona por separado —así se
pueden usar desde cron o desde otro script sin arrastrar el menú.

```bash
sudo ./gestion.sh            # menú principal
./gestion.sh doctor          # diagnóstico del entorno (no cambia nada)
./gestion.sh estado          # panorama rápido del servidor
```

## Los scripts

| Script | Para qué sirve | Necesita root |
|---|---|---|
| `gestion.sh` | Menú principal, diagnóstico y bitácora | Según la opción |
| `crear_roles.sh` | Crea las 4 cuentas y sus grupos, idempotente | Sí |
| `usuarios.sh` | Altas, bajas, bloqueo y contraseñas de cuentas | Sí (salvo `ver`) |
| `grupos.sh` | Crear, renombrar, cambiar GID y borrar grupos | Sí (salvo `ver`/`listar`) |
| `registrar_grupo.sh` | Sumar y quitar usuarios de grupos secundarios | Sí (salvo consultas) |
| `listar.sh` | Consultas sobre cuentas, grupos, sesiones y entorno | No |
| `servicios.sh` | Apache, MySQL y firewall vía systemctl | Sí (salvo `estado`/`probar`) |
| `permisos.sh` | Propiedad, permisos octales y contextos SELinux | Sí para `aplicar` |
| `desplegar.sh` | Traer código, migrar la base, permisos y reload | Sí |
| `respaldo.sh` | Respaldo, rotación y restauración | Sí |
| `monitoreo_bd.sh` | Estado de la base; modo alerta para cron | No |
| `cron.sh` | Instala y prueba las tareas programadas | Sí (salvo `ver`/`estado`) |

`lib/comun.sh` no se ejecuta: es la biblioteca que cargan todos (mensajes,
chequeo de root, confirmaciones, bitácora y consultas a `/etc/passwd`).

## Puesta en marcha en un servidor nuevo

```bash
sudo dnf install -y policycoreutils-python-utils mysql   # semanage y cliente mysql
cd /var/www/tornalyx/SGDM/scripts
chmod +x *.sh

sudo ./crear_roles.sh          # cuentas, grupos y /etc/sudoers.d/tornalyx
sudo ./permisos.sh aplicar     # propiedad, permisos y contextos SELinux
sudo ./cron.sh credenciales    # plantilla en /etc/tornalyx/credenciales.env
sudo ${EDITOR:-vi} /etc/tornalyx/credenciales.env   # completar contraseñas
sudo ./cron.sh instalar        # respaldo diario, monitoreo y auditoría
./gestion.sh doctor            # verificar que no quedó nada suelto
```

`crear_roles.sh` imprime **una sola vez** las contraseñas temporales que
generó: anotalas antes de cerrar la terminal. Cada cuenta tiene que
cambiarla en su primer inicio de sesión (`chage -d 0`).

Antes de aplicar nada, cualquier script destructivo acepta `--dry-run`:

```bash
sudo ./crear_roles.sh --dry-run
sudo ./permisos.sh aplicar --dry-run
sudo ./desplegar.sh --dry-run
```

## Cuentas del sistema

Las define `crear_roles.sh` siguiendo el estudio de mínimo privilegio:

| Cuenta | UID | Grupo principal | Grupos sec. | Shell | Función |
|---|---|---|---|---|---|
| `admin_tornalyx` | 1001 | `admin_tornalyx` | `wheel`, `apache` | `/bin/bash` | Administración general (sudo completo por `wheel`) |
| `operador_web` | 1002 | `apache` | — | `/bin/bash` | Despliegue; sudo acotado a reiniciar Apache y correr `desplegar.sh` |
| `dev_tornalyx` | 1003 | `dev_tornalyx` | `apache` | `/bin/bash` | Desarrollo y pruebas, sin acceso a producción |
| `backup_tornalyx` | 1004 | `backup_tornalyx` | — | `/sbin/nologin` | Cuenta de servicio para los respaldos por cron |

Las cuentas del sistema (UID < 1000) y los grupos `root`, `wheel` y
`apache` están protegidos: la suite se niega a borrarlos o renombrarlos.

## Credenciales de base de datos

Los scripts nunca leen las credenciales de la app para tareas que no les
corresponden. Cada uno usa el usuario de `base_datos/dcl.sql` que le
toca, y todos caen al `.env` de la raíz para `DB_HOST`, `DB_PORT` y
`DB_NAME` si no vienen del entorno.

| Script | Variables | Usuario de `dcl.sql` |
|---|---|---|
| `desplegar.sh` | `DB_DDL_USER` / `DB_DDL_PASS` | `tornalyx_ddl` |
| `respaldo.sh` (respaldar) | `DB_BACKUP_USER` / `DB_BACKUP_PASS` | `tornalyx_backup` |
| `respaldo.sh` (restaurar) | `DB_DDL_USER` / `DB_DDL_PASS` | `tornalyx_ddl` |
| `monitoreo_bd.sh` | `DB_MONITOR_USER` / `DB_MONITOR_PASS` | `tornalyx_monitor` |

Nunca van en el `.env` de la app: el usuario que corre la web
(`tornalyx_dml`) no debe poder tocar el esquema ni leer los respaldos.

Cada script busca sus credenciales en tres lugares, en este orden:

1. **el entorno** — lo que exportaste antes de correrlo;
2. **`/etc/tornalyx/credenciales.env`** (600 `root:root`) — el archivo que
   crea `cron.sh credenciales`;
3. **el `.env` de la app** — último recurso, solo para `DB_HOST`, `DB_PORT`
   y `DB_NAME`.

Para una ejecución puntual a mano alcanza con el entorno:

```bash
export DB_DDL_USER=tornalyx_ddl
read -rs DB_DDL_PASS && export DB_DDL_PASS
sudo -E ./desplegar.sh solo-migrar
```

Para cron hace falta el archivo: una tarea programada arranca sin nadie que
exporte nada. Ver la sección de **Tareas programadas**.

## Despliegue

```bash
sudo ./desplegar.sh estado        # qué migraciones faltan, sin tocar nada
sudo -E ./desplegar.sh            # git pull + migraciones + permisos + reload + verificación
sudo -E ./desplegar.sh solo-migrar
sudo ./desplegar.sh solo-codigo   # sin tocar la base
```

Las migraciones se aplican con el mismo criterio que el migrador PHP
(`modelo/Migracion.php`): se leen los `add_*.sql` que no figuren en
`schema_migrations`, se ignoran los errores que solo dicen "esto ya estaba
hecho" (1050, 1060, 1061, 1062, 1091) y cualquier otro queda anotado en la
columna `error` de esa tabla. Correrlas por acá o dejar que las aplique la
app da el mismo resultado.

El despliegue termina probando que el sitio responda 200 y que
`/api/torneos` también: si la cadena Apache → PHP → MySQL quedó rota, el
script falla en vez de dar el despliegue por bueno.

## Respaldos

```bash
sudo -E ./respaldo.sh                      # base + archivos, con rotación
sudo -E ./respaldo.sh base --retencion 30
./respaldo.sh listar
sudo -E ./respaldo.sh restaurar tornalyx-db-20260908-031500.sql.gz
```

Los respaldos van a `/var/backups/tornalyx` con permisos 600 y su
`.sha256`. El volcado se verifica antes de darlo por bueno (gzip íntegro y
marca `Dump completed`), así un dump truncado no pasa por válido.

Restaurar pide confirmación, valida el checksum y guarda antes una copia
del estado actual (con sufijo `-previo`) por si hace falta volver atrás.

Para que el respaldo corra solo todas las noches, no hay que editar ningún
crontab a mano: lo instala `cron.sh` (sección siguiente).

## Monitoreo

```bash
./monitoreo_bd.sh                  # informe completo
./monitoreo_bd.sh conexiones
./monitoreo_bd.sh alerta           # modo cron
```

`alerta` no imprime nada si todo está bien, y devuelve `0` sin problemas,
`1` con alguno (conexiones o disco sobre el umbral, o sin respaldos en las
últimas 48 h) y `2` si la base no responde. `cron.sh` lo programa cada 15
minutos.

## Tareas programadas

```bash
sudo ./cron.sh credenciales   # plantilla en /etc/tornalyx/credenciales.env
sudo ./cron.sh instalar       # escribe /etc/cron.d/tornalyx y la rotación
./cron.sh ver                 # qué quedó instalado
./cron.sh estado              # ¿corre cron? ¿cuándo fue el último respaldo?
sudo ./cron.sh probar respaldo
sudo ./cron.sh quitar
```

Instala tres tareas:

| Cuándo | Tarea | Corre como |
|---|---|---|
| 03:15 todos los días | `respaldo.sh` (base + archivos + rotación) | `backup_tornalyx` |
| cada 15 minutos | `monitoreo_bd.sh alerta` | `root` |
| 04:30 los domingos | `permisos.sh verificar` | `root` |

Van a `/etc/cron.d/tornalyx` y no al crontab personal de una cuenta: así
quedan en un archivo que se puede leer, versionar y borrar, con el usuario
de cada tarea escrito al lado. El respaldo corre con la cuenta de servicio
`backup_tornalyx`, que llega a root por la regla `NOPASSWD` acotada a ese
único script que instala `crear_roles.sh`.

**`cron.sh probar` es el comando importante.** Corre la tarea ahora, pero con
el entorno pelado que le da cron (`env -i`), no con el de tu sesión. Es la
única forma de descubrir hoy que faltan credenciales, en vez de enterarte
dentro de dos semanas cuando necesites un respaldo que nunca se hizo:

```bash
sudo ./cron.sh probar respaldo     # 0 = así va a correr por cron
sudo ./cron.sh probar monitoreo
```

Los registros van a `/var/log/tornalyx/` (directorio 751 `root:wheel`, cada
archivo 640 a nombre de quien lo escribe) y `logrotate` los rota semanal,
conservando 8 semanas. El `751` no es un descuido: el `>>` de una línea de
cron lo abre el usuario de la tarea *antes* de ejecutar nada, así que
`backup_tornalyx` necesita poder atravesar el directorio hasta su archivo —
pero no listarlo.

## Permisos del proyecto

`permisos.sh aplicar` deja el árbol así: dueño `operador_web`, grupo
`apache`, directorios 750 y archivos 640 (nada legible por "otros"),
`SGDM/almacenamiento` en 2770 —la única ruta donde Apache escribe—, el
`.env` en 640 `root:apache` y los `*.sh` en 750.

`permisos.sh verificar` además comprueba que la reorganización MVC siga en
pie: que ninguna de las capas (`controlador/`, `modelo/`, `vista/`,
`nucleo/`, `comun/`, `base_datos/`) haya quedado dentro del DocumentRoot, y
que no haya ninguna vista `.html` suelta en `publico/`. Una vista ahí adentro
se descargaría por su nombre de archivo, salteándose la guardia de sesión que
aplica `PaginaController` antes de imprimirla.

En AlmaLinux aplica además los contextos de SELinux (`httpd_sys_content_t`,
`httpd_sys_rw_content_t` y el booleano `httpd_can_network_connect_db`), sin
los cuales Apache devuelve 403 y PHP no puede conectarse a MySQL aunque los
permisos POSIX estén perfectos.

`permisos.sh verificar` audita sin tocar nada y devuelve distinto de cero
si encuentra algo: sirve para encadenarlo en el despliegue o en un cron.

## Bitácora

Toda operación que cambia el sistema deja registro en
`/var/log/tornalyx/gestion.log` con fecha, quién la hizo (`SUDO_USER`),
script y detalle. Las contraseñas generadas nunca se escriben ahí.

```bash
./gestion.sh bitacora 50
```

## Variables de entorno de la suite

| Variable | Por defecto | Para qué |
|---|---|---|
| `TORNALYX_RAIZ` | `/var/www/tornalyx` | Raíz del proyecto |
| `TORNALYX_LOG` | `/var/log/tornalyx/gestion.log` | Bitácora |
| `TORNALYX_BACKUP_DIR` | `/var/backups/tornalyx` | Destino de los respaldos |
| `TORNALYX_DUENIO` | `operador_web` | Dueño del árbol (`permisos.sh`) |
| `TORNALYX_GRUPO` | `apache` | Grupo del árbol (`permisos.sh`) |
| `TORNALYX_URL_SALUD` | `http://localhost/` | URL que prueba `servicios.sh probar` |
| `TORNALYX_SIN_COLOR` | — | Con cualquier valor, apaga los colores |

Sirven para probar la suite fuera de producción sin tocar nada del sistema:

```bash
TORNALYX_RAIZ=/tmp/tornalyx ./permisos.sh verificar
```
