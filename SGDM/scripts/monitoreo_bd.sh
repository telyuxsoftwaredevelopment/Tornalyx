#!/bin/bash
# ============================================================
# TORNALYX SGDM — monitoreo_bd.sh
# Monitoreo de la base de datos con el usuario de solo lectura
# DB_MONITOR_USER definido en dcl.sql.
#
# Tiene dos modos: uno para mirar (informe legible en pantalla) y otro
# para cron (--alerta), que no imprime nada si está todo bien y devuelve
# un código de salida distinto de cero cuando algo se pasa de umbral.
#
# Uso:
#   ./monitoreo_bd.sh                    informe completo
#   ./monitoreo_bd.sh conexiones         quién está conectado ahora
#   ./monitoreo_bd.sh tablas             tamaño y filas por tabla
#   ./monitoreo_bd.sh lentas             consultas lentas registradas
#   ./monitoreo_bd.sh alerta             modo cron (silencioso si todo va bien)
#       --umbral-conexiones N   % de conexiones usadas que dispara alerta (80)
#       --umbral-disco N        % de disco usado que dispara alerta (85)
#
# Variables de entorno:
#   DB_MONITOR_USER / DB_MONITOR_PASS   usuario de monitoreo de dcl.sql
#   DB_HOST / DB_PORT / DB_NAME         se leen del .env si no están definidas
#
# El control periódico lo programa cron.sh (cada 15 minutos):
#   sudo ./cron.sh instalar
# ============================================================

set -euo pipefail

DIR_SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/comun.sh
source "$DIR_SCRIPT/lib/comun.sh"

ARCHIVO_ENV="$TORNALYX_RAIZ/.env"
UMBRAL_CONEXIONES=80
UMBRAL_DISCO=85

ayuda() {
    mostrar_ayuda "${BASH_SOURCE[0]}"
}

resolver_credenciales() {
    cargar_credenciales "$ARCHIVO_ENV"
    : "${DB_MONITOR_USER:=${DB_USER:-}}"
    : "${DB_MONITOR_PASS:=${DB_PASS:-}}"
    : "${DB_HOST:=localhost}"
    : "${DB_PORT:=3306}"
    : "${DB_NAME:=tornalyx_db}"

    hay_comando mysql || abortar "Falta el cliente 'mysql' (dnf install mysql)."
    [[ -n $DB_MONITOR_USER ]] || abortar "Faltan DB_MONITOR_USER/DB_MONITOR_PASS (o DB_USER/DB_PASS en el .env)."
}

# Consulta silenciosa, sin encabezados ni bordes: pensada para leer
# valores sueltos desde bash.
sql() {
    MYSQL_PWD="$DB_MONITOR_PASS" mysql -N -B \
        --host="$DB_HOST" --port="$DB_PORT" --user="$DB_MONITOR_USER" \
        --connect-timeout=10 -e "$1" 2>/dev/null
}

# Consulta con tabla formateada, para mostrar en pantalla.
sql_tabla() {
    MYSQL_PWD="$DB_MONITOR_PASS" mysql --table \
        --host="$DB_HOST" --port="$DB_PORT" --user="$DB_MONITOR_USER" \
        --connect-timeout=10 -e "$1" 2>/dev/null
}

# Lee una variable de estado del servidor (Threads_connected, etc.).
estado_global() {
    sql "SHOW GLOBAL STATUS LIKE '$1'" | awk '{print $2}'
}

variable_global() {
    sql "SHOW GLOBAL VARIABLES LIKE '$1'" | awk '{print $2}'
}

# ── Bloques del informe ─────────────────────────────────────
resumen_servidor() {
    titulo "Servidor"
    local version uptime_s dias horas
    version=$(sql 'SELECT VERSION()')
    uptime_s=$(estado_global Uptime)

    printf '  Host      %s:%s\n' "$DB_HOST" "$DB_PORT"
    printf '  Versión   %s\n' "${version:-desconocida}"

    if [[ -n ${uptime_s:-} ]]; then
        dias=$(( uptime_s / 86400 ))
        horas=$(( (uptime_s % 86400) / 3600 ))
        printf '  Encendida %s día(s) %s hora(s)\n' "$dias" "$horas"
    fi
    printf '  Base      %s\n' "$DB_NAME"
}

resumen_conexiones() {
    titulo "Conexiones"
    local activas maximas rechazadas porcentaje=0
    activas=$(estado_global Threads_connected)
    maximas=$(variable_global max_connections)
    rechazadas=$(estado_global Aborted_connects)

    [[ ${maximas:-0} -gt 0 ]] && porcentaje=$(( activas * 100 / maximas ))

    printf '  Activas          %s de %s (%s%%)\n' "${activas:-?}" "${maximas:-?}" "$porcentaje"
    printf '  Pico histórico   %s\n' "$(estado_global Max_used_connections)"
    printf '  Rechazadas       %s\n' "${rechazadas:-0}"

    [[ $porcentaje -ge $UMBRAL_CONEXIONES ]] && \
        msg_warn "El uso de conexiones superó el $UMBRAL_CONEXIONES%."
    return 0
}

listar_conexiones() {
    resolver_credenciales
    titulo "Procesos conectados ahora"
    sql_tabla "SELECT id, user, host, db, command, time, LEFT(COALESCE(info,''), 60) AS consulta
                 FROM information_schema.processlist
                ORDER BY time DESC" || msg_warn "Se necesita el privilegio PROCESS para ver esto."
}

resumen_tablas() {
    resolver_credenciales
    titulo "Tablas de $DB_NAME"
    sql_tabla "SELECT table_name AS tabla,
                      table_rows AS filas,
                      ROUND((data_length + index_length) / 1024 / 1024, 2) AS mb
                 FROM information_schema.tables
                WHERE table_schema = '$DB_NAME'
                ORDER BY (data_length + index_length) DESC"

    local total
    total=$(sql "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2)
                   FROM information_schema.tables WHERE table_schema = '$DB_NAME'")
    printf '\n  Tamaño total: %s MB\n' "${total:-0}"
}

resumen_lentas() {
    resolver_credenciales
    titulo "Consultas lentas"
    local activo umbral cantidad
    activo=$(variable_global slow_query_log)
    umbral=$(variable_global long_query_time)
    cantidad=$(estado_global Slow_queries)

    printf '  Registro activo  %s\n' "${activo:-OFF}"
    printf '  Umbral           %s s\n' "${umbral:-?}"
    printf '  Acumuladas       %s\n' "${cantidad:-0}"

    if [[ ${activo:-OFF} == OFF ]]; then
        msg_info "Para habilitarlo: SET GLOBAL slow_query_log = 'ON';"
        return 0
    fi

    # mysql.slow_log solo se puede leer si log_output incluye TABLE y el
    # usuario tiene el SELECT que le da dcl.sql.
    sql_tabla "SELECT start_time, user_host, query_time, LEFT(sql_text, 70) AS consulta
                 FROM mysql.slow_log ORDER BY start_time DESC LIMIT 10" 2>/dev/null || \
        msg_info "El detalle está en el archivo de log (log_output=FILE), no en mysql.slow_log."
}

resumen_datos() {
    resolver_credenciales
    titulo "Datos de la plataforma"
    # Las tablas se califican con la base: sql_tabla no ejecuta un USE.
    sql_tabla "SELECT
        (SELECT COUNT(*) FROM \`$DB_NAME\`.usuarios)                          AS usuarios,
        (SELECT COUNT(*) FROM \`$DB_NAME\`.torneos)                           AS torneos,
        (SELECT COUNT(*) FROM \`$DB_NAME\`.torneos WHERE estado = 'en_curso') AS en_curso,
        (SELECT COUNT(*) FROM \`$DB_NAME\`.inscripciones)                     AS inscripciones,
        (SELECT COUNT(*) FROM \`$DB_NAME\`.partidos)                          AS partidos" \
        || msg_warn "No se pudieron leer las tablas de la aplicación (¿la base todavía no tiene el esquema?)."
}

resumen_disco() {
    titulo "Disco"
    local datadir uso
    datadir=$(variable_global datadir)
    [[ -z ${datadir:-} ]] && datadir=/var/lib/mysql

    if [[ -d $datadir ]]; then
        df -h "$datadir" | sed 's/^/  /'
        uso=$(df --output=pcent "$datadir" 2>/dev/null | tail -1 | tr -dc '0-9')
        [[ -n ${uso:-} && $uso -ge $UMBRAL_DISCO ]] && \
            msg_warn "El disco de datos está al $uso% (umbral $UMBRAL_DISCO%)."
    else
        msg_info "El directorio de datos ($datadir) no es local a este equipo."
    fi
    return 0
}

# ── Modo alerta (para cron) ─────────────────────────────────
# Silencioso mientras todo esté bien; imprime solo los problemas y sale
# con código 1 para que cron lo mande por correo o lo levante el monitor.
alerta() {
    resolver_credenciales
    local problemas=()

    if ! sql 'SELECT 1' >/dev/null 2>&1; then
        printf 'CRITICO: la base %s:%s no responde.\n' "$DB_HOST" "$DB_PORT"
        registrar_log "ALERTA base sin respuesta en $DB_HOST:$DB_PORT"
        return 2
    fi

    local activas maximas porcentaje=0
    activas=$(estado_global Threads_connected)
    maximas=$(variable_global max_connections)
    [[ ${maximas:-0} -gt 0 ]] && porcentaje=$(( activas * 100 / maximas ))
    [[ $porcentaje -ge $UMBRAL_CONEXIONES ]] && \
        problemas+=("Conexiones al $porcentaje% ($activas/$maximas, umbral $UMBRAL_CONEXIONES%).")

    local datadir uso
    datadir=$(variable_global datadir)
    [[ -z ${datadir:-} ]] && datadir=/var/lib/mysql
    if [[ -d $datadir ]]; then
        uso=$(df --output=pcent "$datadir" 2>/dev/null | tail -1 | tr -dc '0-9')
        [[ -n ${uso:-} && $uso -ge $UMBRAL_DISCO ]] && \
            problemas+=("Disco de datos al $uso% (umbral $UMBRAL_DISCO%).")
    fi

    # Un respaldo viejo es tan grave como uno que no existe. El `|| true` es
    # necesario: si el directorio todavía no existe, find sale con error y
    # bajo `set -e` el script moría acá, justo antes de poder avisarlo.
    local ultimo=''
    if [[ -d $TORNALYX_BACKUP_DIR ]]; then
        ultimo=$(primeras_lineas 1 find "$TORNALYX_BACKUP_DIR" -maxdepth 1 -name 'tornalyx-db-*.sql.gz' -mtime -2)
    fi
    [[ -z $ultimo ]] && problemas+=("No hay respaldos de la base de las últimas 48 horas en $TORNALYX_BACKUP_DIR.")

    if [[ ${#problemas[@]} -eq 0 ]]; then
        return 0
    fi

    printf 'ALERTA Tornalyx (%s):\n' "$(date '+%Y-%m-%d %H:%M')"
    printf '  - %s\n' "${problemas[@]}"
    registrar_log "ALERTA ${problemas[*]}"
    return 1
}

# ── Informe completo ────────────────────────────────────────
informe() {
    resolver_credenciales
    if ! sql 'SELECT 1' >/dev/null 2>&1; then
        abortar "No se pudo conectar a $DB_HOST:$DB_PORT con el usuario $DB_MONITOR_USER."
    fi
    resumen_servidor
    resumen_conexiones
    resumen_datos
    resumen_tablas
    resumen_lentas
    resumen_disco
    printf '\n'
    msg_ok "Informe generado el $(date '+%Y-%m-%d %H:%M:%S')."
}

# ── Programa principal ──────────────────────────────────────
accion=''
while [[ $# -gt 0 ]]; do
    case $1 in
        informe|conexiones|tablas|lentas|alerta) accion=${accion:-$1} ;;
        --umbral-conexiones) UMBRAL_CONEXIONES=${2:-80}; shift ;;
        --umbral-disco)      UMBRAL_DISCO=${2:-85};      shift ;;
        -h|--help)           ayuda ;;
        *)                   abortar "Argumento desconocido: $1 (probá --help)" ;;
    esac
    shift
done

case ${accion:-informe} in
    informe)    informe ;;
    conexiones) listar_conexiones ;;
    tablas)     resumen_tablas ;;
    lentas)     resumen_lentas ;;
    alerta)     alerta ;;
esac
