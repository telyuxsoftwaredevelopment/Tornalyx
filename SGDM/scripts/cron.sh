#!/bin/bash
# ============================================================
# TORNALYX SGDM — cron.sh
# Tareas programadas del servidor: respaldo, monitoreo y auditoría.
#
# Las instala en /etc/cron.d/tornalyx en vez de en el crontab personal de
# una cuenta: así quedan declaradas en un archivo, con el usuario que las
# corre escrito al lado de cada línea, y se pueden revisar, versionar y
# borrar sin entrar a editar el spool de nadie.
#
# Uso:
#   sudo ./cron.sh instalar [--dry-run]  instala o actualiza las tareas
#   ./cron.sh ver                        muestra las tareas instaladas
#   ./cron.sh estado                     si cron corre y cómo viene cada tarea
#   sudo ./cron.sh probar [tarea]        corre una tarea ahora, como cron
#   sudo ./cron.sh credenciales          crea el archivo de credenciales
#   sudo ./cron.sh quitar                desinstala las tareas
#
# Tareas que instala:
#   03:15 todos los días   respaldo completo (base + archivos) y rotación
#   cada 15 minutos        monitoreo_bd.sh alerta (silencioso si todo va bien)
#   04:30 los domingos     permisos.sh verificar (auditoría del árbol)
#
# Antes de instalar hay que dejar las credenciales en su archivo:
#   sudo ./cron.sh credenciales
# ============================================================

set -euo pipefail

DIR_SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/comun.sh
source "$DIR_SCRIPT/lib/comun.sh"

# Ruta de los scripts TAL COMO LOS VA A VER CRON. No se usa $DIR_SCRIPT
# porque este script puede estar corriéndose desde un clon de pruebas, y lo
# que se escriba en /etc/cron.d tiene que apuntar a la copia de producción.
DIR_PRODUCCION="$TORNALYX_RAIZ/SGDM/scripts"

ARCHIVO_CRON=/etc/cron.d/tornalyx
ARCHIVO_LOGROTATE=/etc/logrotate.d/tornalyx
DIR_LOG="$(dirname "$TORNALYX_LOG")"

# Cuenta de servicio que corre el respaldo (ver crear_roles.sh). Si todavía
# no existe, la tarea se instala a nombre de root para que igual funcione.
USUARIO_RESPALDO=backup_tornalyx

ayuda() {
    mostrar_ayuda "${BASH_SOURCE[0]}"
}

# En AlmaLinux/RHEL el servicio se llama crond; en Debian/Ubuntu, cron.
# Se resuelve mirando qué unidad existe en disco: es lo único que funciona
# también cuando systemd no está corriendo como init (por ejemplo dentro de
# un contenedor), donde `systemctl list-unit-files` no es de fiar.
servicio_cron() {
    local unidad
    for unidad in /usr/lib/systemd/system /lib/systemd/system /etc/systemd/system; do
        [[ -f "$unidad/crond.service" ]] && { printf 'crond'; return 0; }
        [[ -f "$unidad/cron.service"  ]] && { printf 'cron';  return 0; }
    done
    # Sin unidades en disco, se cae a la convención de la familia de la distro.
    if [[ -f /etc/redhat-release ]]; then
        printf 'crond'
    else
        printf 'cron'
    fi
}

# ── Credenciales ────────────────────────────────────────────
# Una tarea programada arranca sin nadie que exporte variables de entorno,
# así que las credenciales tienen que estar en un archivo que solo root lea.
# El .env de la app no sirve: ahí vive tornalyx_dml, que por diseño no puede
# volcar la base entera ni tocar el esquema.
credenciales() {
    local archivo=$TORNALYX_CREDENCIALES
    titulo "Credenciales para las tareas programadas"

    if [[ -f $archivo ]]; then
        msg_info "Ya existe $archivo ($(stat -c '%a %U:%G' "$archivo" 2>/dev/null))."
        msg_info "Editalo con: sudo \${EDITOR:-vi} $archivo"
        printf '\n  Variables definidas:\n'
        # Solo los nombres: las contraseñas no se imprimen nunca.
        grep -oE '^[A-Za-z_][A-Za-z0-9_]*' "$archivo" 2>/dev/null | sed 's/^/    /' || true
        return 0
    fi

    requiere_root
    ejecutar mkdir -p "$(dirname "$archivo")"
    ejecutar chmod 700 "$(dirname "$archivo")"

    if [[ -n "${TORNALYX_SIMULAR:-}" ]]; then
        msg_info "(simulado) se crearía la plantilla $archivo"
        return 0
    fi

    umask 077
    cat > "$archivo" <<'PLANTILLA'
# Credenciales de las tareas programadas de Tornalyx (cron.sh).
# Las cuentas son las de SGDM/base_datos/dcl.sql. Este archivo lo lee root
# y nadie más: NO poner acá el usuario de la app (tornalyx_dml), que no debe
# poder volcar la base ni modificar el esquema.
#
# Completar y volver a correr:  sudo ./cron.sh probar respaldo

# Respaldo (solo lectura sobre tornalyx_db)
DB_BACKUP_USER=tornalyx_backup
DB_BACKUP_PASS=

# Monitoreo (solo lectura de estado del servidor)
DB_MONITOR_USER=tornalyx_monitor
DB_MONITOR_PASS=

# DDL: solo hace falta para restaurar un respaldo o migrar la base.
DB_DDL_USER=tornalyx_ddl
DB_DDL_PASS=

# Conexión (si se omiten, se toman del .env de la aplicación).
#DB_HOST=localhost
#DB_PORT=3306
#DB_NAME=tornalyx_db
PLANTILLA
    chown root:root "$archivo" 2>/dev/null || true
    chmod 600 "$archivo"

    msg_ok "Plantilla creada en $archivo (600 root:root)."
    msg_warn "Completá las contraseñas antes de instalar el cron:"
    printf '    sudo ${EDITOR:-vi} %s\n' "$archivo"
    registrar_log "credenciales plantilla creada en $archivo"
}

# Avisa (sin cortar) si las credenciales todavía no están listas: instalar
# el cron con el archivo vacío deja un respaldo que falla en silencio todas
# las noches, que es la peor forma de no tener respaldos.
revisar_credenciales() {
    local archivo=$TORNALYX_CREDENCIALES
    if [[ ! -f $archivo ]]; then
        msg_warn "No existe $archivo: el respaldo va a intentar usar el usuario de la app."
        msg_warn "Crealo con: sudo $0 credenciales"
        return 1
    fi

    local modo
    modo=$(stat -c '%a' "$archivo" 2>/dev/null || printf '')
    [[ $modo == 600 ]] || msg_warn "$archivo tiene permisos $modo: debería ser 600."

    if grep -qE '^DB_BACKUP_PASS=.+' "$archivo"; then
        msg_ok "Credenciales de respaldo cargadas en $archivo."
        return 0
    fi
    msg_warn "DB_BACKUP_PASS está vacío en $archivo: el respaldo por cron va a fallar."
    return 1
}

# ── Instalar ────────────────────────────────────────────────
instalar() {
    [[ ${1:-} == --dry-run || ${1:-} == -n ]] && TORNALYX_SIMULAR=1
    [[ -n "${TORNALYX_SIMULAR:-}" ]] || requiere_root
    [[ -n "${TORNALYX_SIMULAR:-}" ]] && msg_warn "MODO SIMULACIÓN: no se modifica nada."

    titulo "1. Comprobaciones previas"
    [[ -d $DIR_PRODUCCION ]] \
        || msg_warn "No existe $DIR_PRODUCCION: las tareas van a apuntar ahí igual (ajustá TORNALYX_RAIZ si el proyecto está en otro lado)."
    hay_comando crontab || msg_warn "Falta el paquete de cron (dnf install cronie)."
    revisar_credenciales || true

    # La cuenta de servicio corre el respaldo por sudo (ver crear_roles.sh).
    # Sin ella, la tarea se instala como root: peor en privilegios, pero
    # funciona igual y el doctor lo va a señalar.
    local usuario=$USUARIO_RESPALDO
    if ! existe_usuario "$usuario"; then
        msg_warn "No existe la cuenta $usuario: el respaldo se instala como root (corré crear_roles.sh)."
        usuario=root
    fi

    titulo "2. Directorio de registros"
    preparar_logs "$usuario"

    titulo "3. Tareas en $ARCHIVO_CRON"
    escribir_cron "$usuario"

    titulo "4. Rotación de registros"
    escribir_logrotate

    titulo "5. Servicio de cron"
    local servicio
    servicio=$(servicio_cron)
    if hay_comando systemctl; then
        if systemctl is-enabled "$servicio" >/dev/null 2>&1; then
            msg_ok "$servicio está habilitado al arranque."
        elif ejecutar systemctl enable --now "$servicio" >/dev/null 2>&1; then
            msg_ok "$servicio habilitado e iniciado."
        else
            msg_warn "No se pudo habilitar $servicio (¿systemd no está corriendo?): arrancalo a mano con 'systemctl enable --now $servicio'."
        fi
    else
        msg_warn "No hay systemctl en este equipo: comprobá a mano que cron esté corriendo."
    fi

    printf '\n'
    msg_ok "Tareas programadas instaladas."
    msg_info "Probalas sin esperar al horario: sudo $0 probar respaldo"
}

# Prepara el directorio y los archivos de registro.
#
# Acá hay un detalle que es fácil pasar por alto y deja el cron mudo: el
# `>> archivo.log` de una línea de cron lo abre el USUARIO DE LA TAREA antes
# de ejecutar nada. En el respaldo ese usuario es backup_tornalyx, no root:
# si no puede abrir su registro, la tarea falla entera sin llegar a correr y
# sin dejar rastro en ningún lado.
#
# Por eso el directorio queda en 751 (root:wheel): el bit de ejecución para
# "otros" permite ATRAVESARLO hacia un archivo de nombre conocido, pero no
# listarlo. Y cada registro se crea de antemano a nombre de quien lo escribe,
# en 640, así el contenido lo leen solo su dueño y los administradores.
preparar_logs() {
    local usuario_respaldo=$1

    if [[ ! -d $DIR_LOG ]]; then
        ejecutar mkdir -p "$DIR_LOG"
        msg_info "Creado $DIR_LOG"
    fi

    local grupo=root
    existe_grupo wheel && grupo=wheel

    ejecutar chown "root:$grupo" "$DIR_LOG"
    ejecutar chmod 751 "$DIR_LOG"

    # gestion.log lo escribe cualquier script que corra como root.
    crear_log gestion.log  root              "$grupo"
    crear_log respaldo.log "$usuario_respaldo" "$grupo"
    crear_log monitoreo.log root             "$grupo"
    crear_log permisos.log  root             "$grupo"

    msg_ok "$DIR_LOG listo (751 root:$grupo, registros 640)."
}

# Crea un archivo de registro vacío con dueño y permisos, sin pisar el que
# ya exista (solo le corrige la propiedad, para no perder el historial).
crear_log() {
    local archivo="$DIR_LOG/$1" duenio=$2 grupo=$3

    if [[ -n "${TORNALYX_SIMULAR:-}" ]]; then
        msg_info "(simulado) $archivo -> $duenio:$grupo 640"
        return 0
    fi

    [[ -f $archivo ]] || : > "$archivo"
    chown "$duenio:$grupo" "$archivo" 2>/dev/null || true
    chmod 640 "$archivo"
}

# El archivo de cron.d lleva el usuario en la sexta columna, cosa que el
# crontab personal no tiene. Por eso cada tarea dice con qué cuenta corre.
escribir_cron() {
    local usuario=$1 temporal
    local invocacion_respaldo

    # El respaldo necesita root (mysqldump al destino 600 de /var/backups).
    # La cuenta de servicio llega ahí por la regla NOPASSWD de sudoers que
    # instala crear_roles.sh, acotada a este único script.
    if [[ $usuario == root ]]; then
        invocacion_respaldo="$DIR_PRODUCCION/respaldo.sh"
    else
        invocacion_respaldo="sudo -n $DIR_PRODUCCION/respaldo.sh"
    fi

    temporal=$(mktemp)
    cat > "$temporal" <<CRON
# Generado por cron.sh (Tornalyx SGDM). No editar a mano:
# volver a correr \`sudo ./cron.sh instalar\` regenera este archivo.
#
# Formato de /etc/cron.d: min hora día mes día-semana USUARIO comando.

SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
# Sin MAILTO cron intenta mandar por correo la salida de cada tarea; como
# todo queda registrado en $DIR_LOG, se apaga para no llenar la casilla
# local de un servidor que probablemente no tenga MTA configurado.
MAILTO=""

# Respaldo completo (base + archivos) con rotación, todos los días a las 03:15.
15 3 * * *   $usuario  $invocacion_respaldo >> $DIR_LOG/respaldo.log 2>&1

# Control de la base cada 15 minutos. En modo alerta no imprime nada si está
# todo bien, así que el registro solo crece cuando hay algo para contar.
*/15 * * * * root      $DIR_PRODUCCION/monitoreo_bd.sh alerta >> $DIR_LOG/monitoreo.log 2>&1

# Auditoría de permisos del árbol, los domingos a las 04:30.
30 4 * * 0   root      $DIR_PRODUCCION/permisos.sh verificar >> $DIR_LOG/permisos.log 2>&1
CRON

    if [[ -n "${TORNALYX_SIMULAR:-}" ]]; then
        msg_info "(simulado) se instalaría $ARCHIVO_CRON con:"
        sed 's/^/    /' "$temporal"
        rm -f "$temporal"
        return 0
    fi

    # cron ignora los archivos de /etc/cron.d que no sean 644 de root, y no
    # avisa: simplemente no corre nada. Por eso se instala con install(1).
    install -o root -g root -m 0644 "$temporal" "$ARCHIVO_CRON"
    rm -f "$temporal"
    msg_ok "Tareas instaladas en $ARCHIVO_CRON (respaldo como $usuario)."
    registrar_log "cron instalado en $ARCHIVO_CRON (respaldo=$usuario)"
}

# Sin rotación, respaldo.log crece para siempre: el respaldo escribe el
# listado completo cada noche.
escribir_logrotate() {
    if ! hay_comando logrotate; then
        msg_warn "logrotate no está instalado: los registros de $DIR_LOG no se van a rotar."
        return 0
    fi

    local temporal
    temporal=$(mktemp)
    cat > "$temporal" <<LOGROTATE
# Generado por cron.sh (Tornalyx SGDM).
$DIR_LOG/*.log {
    weekly
    rotate 8
    missingok
    notifempty
    compress
    delaycompress
    # copytruncate en vez de create: el archivo NO se recrea, se copia y se
    # vacía en el lugar. Así conserva su dueño (respaldo.log es de la cuenta
    # de respaldo, no de root) y la tarea de cron puede seguir escribiéndolo
    # después de cada rotación.
    copytruncate
    su root root
}
LOGROTATE

    if [[ -n "${TORNALYX_SIMULAR:-}" ]]; then
        msg_info "(simulado) se instalaría $ARCHIVO_LOGROTATE"
        rm -f "$temporal"
        return 0
    fi

    install -o root -g root -m 0644 "$temporal" "$ARCHIVO_LOGROTATE"
    rm -f "$temporal"
    msg_ok "Rotación semanal configurada en $ARCHIVO_LOGROTATE (8 semanas)."
}

# ── Quitar ──────────────────────────────────────────────────
quitar() {
    requiere_root
    titulo "Desinstalar las tareas programadas"

    if [[ ! -f $ARCHIVO_CRON && ! -f $ARCHIVO_LOGROTATE ]]; then
        msg_info "No hay nada instalado."
        return 0
    fi

    confirmar "Borrar $ARCHIVO_CRON y $ARCHIVO_LOGROTATE (los respaldos ya hechos no se tocan)" \
        || { msg_info "Cancelado."; return 0; }

    rm -f "$ARCHIVO_CRON" "$ARCHIVO_LOGROTATE"
    msg_ok "Tareas programadas desinstaladas."
    msg_info "Los respaldos de $TORNALYX_BACKUP_DIR siguen donde estaban."
    registrar_log "cron desinstalado"
}

# ── Ver ─────────────────────────────────────────────────────
ver() {
    titulo "Tareas programadas ($ARCHIVO_CRON)"
    if [[ ! -f $ARCHIVO_CRON ]]; then
        msg_info "No hay tareas instaladas. Instalalas con: sudo $0 instalar"
        return 0
    fi
    if [[ ! -r $ARCHIVO_CRON ]]; then
        msg_warn "El archivo existe pero no tenés permiso para leerlo (probá con sudo)."
        return 0
    fi
    sed 's/^/  /' "$ARCHIVO_CRON"

    printf '\n'
    local modo
    modo=$(stat -c '%a %U:%G' "$ARCHIVO_CRON" 2>/dev/null || printf '?')
    if [[ $modo == "644 root:root" ]]; then
        msg_ok "Permisos correctos ($modo): cron va a leerlo."
    else
        msg_error "Permisos $modo: cron ignora los archivos de /etc/cron.d que no sean 644 root:root."
    fi
}

# ── Estado ──────────────────────────────────────────────────
estado() {
    titulo "Servicio de cron"
    local servicio
    servicio=$(servicio_cron)
    if hay_comando systemctl; then
        if systemctl is-active "$servicio" >/dev/null 2>&1; then
            msg_ok "$servicio está corriendo."
        else
            msg_error "$servicio NO está corriendo: ninguna tarea se va a ejecutar."
        fi
    else
        msg_info "Sin systemctl: no se puede consultar el estado del servicio."
    fi

    if [[ -f $ARCHIVO_CRON ]]; then
        msg_ok "Tareas instaladas en $ARCHIVO_CRON."
    else
        msg_error "No hay tareas instaladas (falta $ARCHIVO_CRON)."
    fi

    titulo "Último respaldo"
    # Se ordena por fecha de modificación (%T@) y se toma el más reciente.
    local ultimo
    ultimo=$(primeras_lineas 1 \
                 sh -c "find '$TORNALYX_BACKUP_DIR' -maxdepth 1 -name 'tornalyx-db-*.sql.gz' -printf '%T@ %p\n' 2>/dev/null | sort -rn" \
             | cut -d' ' -f2-)
    if [[ -n ${ultimo:-} ]]; then
        local edad_horas
        edad_horas=$(( ( $(date +%s) - $(stat -c %Y "$ultimo") ) / 3600 ))
        printf '  %s\n  %s · hace %s h\n' \
            "$(basename "$ultimo")" "$(du -h "$ultimo" | cut -f1)" "$edad_horas"
        if [[ $edad_horas -le 26 ]]; then
            msg_ok "El respaldo diario está al día."
        else
            msg_error "El último respaldo tiene $edad_horas horas: la tarea no está corriendo bien."
        fi
    else
        msg_error "No hay ningún respaldo en $TORNALYX_BACKUP_DIR."
    fi

    titulo "Registros de las tareas"
    local log
    for log in respaldo monitoreo permisos; do
        local archivo="$DIR_LOG/$log.log"
        if [[ -r $archivo ]]; then
            printf '  %-14s %8s  última escritura: %s\n' \
                "$log.log" "$(du -h "$archivo" | cut -f1)" \
                "$(date -r "$archivo" '+%Y-%m-%d %H:%M')"
        elif [[ -f $archivo ]]; then
            printf '  %-14s (sin permiso de lectura, probá con sudo)\n' "$log.log"
        else
            printf '  %-14s todavía no se escribió\n' "$log.log"
        fi
    done
}

# ── Probar ──────────────────────────────────────────────────
# Corre una tarea ahora, con el mismo entorno pelado que le da cron: sin
# las variables que exportó la sesión interactiva. Es la única forma de
# descubrir hoy que faltan credenciales, en vez de a las 3 de la mañana.
probar() {
    local tarea=${1:-respaldo}
    requiere_root

    local comando
    case $tarea in
        respaldo)  comando="$DIR_SCRIPT/respaldo.sh" ;;
        monitoreo) comando="$DIR_SCRIPT/monitoreo_bd.sh alerta" ;;
        permisos)  comando="$DIR_SCRIPT/permisos.sh verificar" ;;
        *) abortar "Tarea desconocida: $tarea (usá respaldo, monitoreo o permisos)" ;;
    esac

    titulo "Prueba de la tarea '$tarea' con el entorno de cron"
    msg_info "Comando: $comando"
    printf '\n'

    # env -i borra el entorno heredado; se dejan solo las variables que cron
    # define en /etc/cron.d, para que la prueba valga de verdad.
    local salida=0
    env -i \
        SHELL=/bin/bash \
        PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin \
        HOME=/root \
        TORNALYX_RAIZ="$TORNALYX_RAIZ" \
        bash -c "$comando" || salida=$?

    printf '\n'
    case $salida in
        0) msg_ok "La tarea terminó bien (código 0): así se va a comportar por cron." ;;
        1) msg_warn "La tarea terminó con código 1. En 'monitoreo' eso significa que hay alertas; en el respaldo, que algo falló." ;;
        2) msg_error "Código 2: la base no respondió." ;;
        *) msg_error "La tarea falló con código $salida." ;;
    esac
    registrar_log "prueba de cron tarea=$tarea salida=$salida"
    return 0
}

# ── Despacho ────────────────────────────────────────────────
accion=${1:-ver}
shift || true
case $accion in
    instalar)     instalar "$@" ;;
    quitar)       quitar ;;
    ver)          ver ;;
    estado)       estado ;;
    probar)       probar "$@" ;;
    credenciales) credenciales ;;
    -h|--help)    ayuda ;;
    *)            abortar "Acción desconocida: $accion (probá --help)" ;;
esac
