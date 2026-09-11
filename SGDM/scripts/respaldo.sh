#!/bin/bash
# ============================================================
# TORNALYX SGDM — respaldo.sh
# Respaldo de la base de datos y de los archivos del proyecto.
#
# Pensado para correr por cron con la cuenta backup_tornalyx (ver
# /etc/sudoers.d/tornalyx). Usa el usuario de solo lectura DB_BACKUP_USER
# definido en dcl.sql: un respaldo nunca necesita permisos de escritura.
#
# Uso:
#   sudo ./respaldo.sh                  base de datos + archivos
#   sudo ./respaldo.sh base             solo la base de datos
#   sudo ./respaldo.sh archivos         solo el árbol del proyecto
#   ./respaldo.sh listar                respaldos existentes
#   sudo ./respaldo.sh restaurar <archivo.sql.gz>   (usa DB_DDL_USER para escribir)
#       --retencion N   días que se conservan los respaldos (por defecto 14)
#       --destino DIR   dónde guardarlos (por defecto /var/backups/tornalyx)
#
# Variables de entorno:
#   DB_BACKUP_USER / DB_BACKUP_PASS   usuario de respaldo de dcl.sql (solo lectura)
#   DB_DDL_USER / DB_DDL_PASS         usuario DDL, necesario solo para restaurar
#   DB_HOST / DB_PORT / DB_NAME       se leen del .env si no están definidas
#
# Para que corra solo todas las noches no hace falta editar ningún crontab:
#   sudo ./cron.sh instalar
# lo programa a las 03:15 con la cuenta backup_tornalyx y deja el registro
# en /var/log/tornalyx/respaldo.log. Las credenciales salen de
# /etc/tornalyx/credenciales.env (sudo ./cron.sh credenciales).
# ============================================================

set -euo pipefail

DIR_SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/comun.sh
source "$DIR_SCRIPT/lib/comun.sh"

ARCHIVO_ENV="$TORNALYX_RAIZ/.env"
RETENCION_DIAS=14
DESTINO=$TORNALYX_BACKUP_DIR
MARCA=$(date '+%Y%m%d-%H%M%S')

# Ruta del último archivo que generó respaldar_base (la usa la restauración
# para comprobar que su copia previa no pisó el archivo de origen).
RESPALDO_GENERADO=''

ayuda() {
    mostrar_ayuda "${BASH_SOURCE[0]}"
}

# Si no hay usuario de respaldo dedicado se cae al de la app, que también
# tiene SELECT. Es preferible un respaldo hecho con el usuario equivocado
# que ningún respaldo.
resolver_credenciales() {
    cargar_credenciales "$ARCHIVO_ENV"
    : "${DB_BACKUP_USER:=${DB_USER:-}}"
    : "${DB_BACKUP_PASS:=${DB_PASS:-}}"
    : "${DB_HOST:=localhost}"
    : "${DB_PORT:=3306}"
    : "${DB_NAME:=tornalyx_db}"

    [[ -n $DB_BACKUP_USER ]] || abortar "Faltan DB_BACKUP_USER/DB_BACKUP_PASS (o DB_USER/DB_PASS en el .env)."
    hay_comando mysqldump || abortar "Falta 'mysqldump' (dnf install mysql)."
}

preparar_destino() {
    if [[ ! -d $DESTINO ]]; then
        mkdir -p "$DESTINO" || abortar "No se pudo crear $DESTINO."
        msg_info "Creado $DESTINO"
    fi
    # Los respaldos contienen TODOS los datos de la plataforma: nadie más
    # que el dueño del directorio debería poder leerlos.
    chmod 700 "$DESTINO" 2>/dev/null || true
    [[ -w $DESTINO ]] || abortar "No hay permiso de escritura en $DESTINO."
}

# ── Respaldo de la base ─────────────────────────────────────
# Acepta un sufijo opcional para el nombre del archivo (lo usa la
# restauración para su copia de seguridad previa).
respaldar_base() {
    titulo "Respaldo de la base de datos"
    resolver_credenciales
    preparar_destino

    local sufijo=${1:-}
    local salida="$DESTINO/tornalyx-db-$MARCA$sufijo.sql.gz"

    # MARCA se calcula una sola vez al arrancar el script y tiene precisión
    # de segundos: dos respaldos lanzados en el mismo segundo caerían en el
    # mismo nombre y el segundo pisaría al primero. Peor todavía durante una
    # restauración, donde el archivo pisado puede ser justo el que se está
    # por leer. Ante colisión se numera en vez de sobrescribir.
    local n=2
    while [[ -e $salida ]]; do
        salida="$DESTINO/tornalyx-db-$MARCA$sufijo-$n.sql.gz"
        n=$((n + 1))
    done
    RESPALDO_GENERADO=$salida

    local temporal="$salida.parcial"

    # --single-transaction toma una foto consistente sin bloquear escrituras
    # (InnoDB); sin él, un respaldo en caliente puede salir a mitad de camino.
    #
    # Se vuelca la base por nombre y NO con --databases: esa opción mete un
    # CREATE DATABASE + USE en el archivo, y restaurarlo exigiría el permiso
    # global de crear bases, que ninguna de las cuentas de dcl.sql tiene (ni
    # debería). Sin esas dos líneas, el volcado se restaura sobre la base que
    # se le indique con las credenciales de tornalyx_ddl y nada más.
    # Tampoco se usa --events: el esquema no define ninguno y pedirlo obliga
    # a que quien restaura tenga el privilegio EVENT.
    # --skip-add-locks va en la misma línea: sin él el volcado trae un
    # LOCK TABLES ... WRITE por tabla y restaurarlo exige el privilegio
    # LOCK TABLES, que tampoco tiene la cuenta que restaura.
    if MYSQL_PWD="$DB_BACKUP_PASS" mysqldump \
            --host="$DB_HOST" --port="$DB_PORT" --user="$DB_BACKUP_USER" \
            --single-transaction --quick --routines --triggers --skip-add-locks \
            --default-character-set=utf8mb4 \
            "$DB_NAME" 2>"$DESTINO/.error-$MARCA" | gzip -9 > "$temporal"; then
        mv "$temporal" "$salida"
        rm -f "$DESTINO/.error-$MARCA"
    else
        rm -f "$temporal"
        msg_error "mysqldump falló:"
        sed 's/^/    /' "$DESTINO/.error-$MARCA" 2>/dev/null || true
        rm -f "$DESTINO/.error-$MARCA"
        return 1
    fi

    chmod 600 "$salida"

    # Un dump truncado pesa poco y parece válido: se verifica que el gzip
    # esté entero y que el volcado llegue hasta su marca de cierre.
    if ! gzip -t "$salida" 2>/dev/null; then
        msg_error "El archivo generado está corrupto: $salida"
        return 1
    fi
    # El cierre se busca sobre las últimas líneas ya materializadas, y no con
    # `gzip -dc | tail | grep -q`: bajo `pipefail`, el grep que corta el pipe
    # apenas encuentra la marca daría por truncado un volcado perfectamente sano.
    local cola
    cola=$(gzip -dc "$salida" 2>/dev/null | tail -5 || true)
    if [[ $cola != *'Dump completed'* ]]; then
        msg_error "El volcado quedó incompleto (falta la marca 'Dump completed'): $salida"
        return 1
    fi

    ( cd "$DESTINO" && sha256sum "$(basename "$salida")" > "$(basename "$salida").sha256" )

    msg_ok "Base respaldada en $salida ($(du -h "$salida" | cut -f1))"
    registrar_log "respaldo base -> $salida"
}

# ── Respaldo de archivos ────────────────────────────────────
# El código está en git; lo irremplazable es lo que suben los usuarios y
# la configuración con credenciales.
respaldar_archivos() {
    titulo "Respaldo de archivos del proyecto"
    preparar_destino
    [[ -d $TORNALYX_RAIZ ]] || { msg_warn "No existe $TORNALYX_RAIZ: no hay archivos que respaldar."; return 0; }

    local salida="$DESTINO/tornalyx-archivos-$MARCA.tar.gz"
    local rutas=()
    [[ -d "$TORNALYX_RAIZ/SGDM/almacenamiento" ]] && rutas+=(SGDM/almacenamiento)
    [[ -f "$ARCHIVO_ENV"                       ]] && rutas+=(.env)

    if [[ ${#rutas[@]} -eq 0 ]]; then
        msg_info "No hay archivos de usuario ni configuración que respaldar todavía."
        return 0
    fi

    tar -czf "$salida" -C "$TORNALYX_RAIZ" "${rutas[@]}"
    chmod 600 "$salida"
    ( cd "$DESTINO" && sha256sum "$(basename "$salida")" > "$(basename "$salida").sha256" )

    msg_ok "Archivos respaldados en $salida ($(du -h "$salida" | cut -f1))"
    registrar_log "respaldo archivos -> $salida (${rutas[*]})"
}

# ── Rotación ────────────────────────────────────────────────
rotar() {
    titulo "Rotación (retención: $RETENCION_DIAS días)"
    [[ -d $DESTINO ]] || return 0

    local borrados=0 archivo
    while IFS= read -r archivo; do
        rm -f "$archivo" "$archivo.sha256"
        borrados=$((borrados + 1))
    done < <(find "$DESTINO" -maxdepth 1 -type f \
                \( -name 'tornalyx-db-*.sql.gz' -o -name 'tornalyx-archivos-*.tar.gz' \) \
                -mtime +"$RETENCION_DIAS" 2>/dev/null)

    if [[ $borrados -gt 0 ]]; then
        msg_ok "$borrados respaldo(s) con más de $RETENCION_DIAS días eliminados."
        registrar_log "rotacion borrados=$borrados retencion=$RETENCION_DIAS"
    else
        msg_info "No había respaldos vencidos."
    fi
}

# ── Consulta ────────────────────────────────────────────────
listar() {
    titulo "Respaldos en $DESTINO"
    if [[ ! -d $DESTINO ]]; then
        msg_warn "El directorio $DESTINO todavía no existe."
        return 0
    fi

    local encontrados=0 archivo
    while IFS= read -r archivo; do
        printf '  %-46s %8s  %s\n' \
            "$(basename "$archivo")" \
            "$(du -h "$archivo" | cut -f1)" \
            "$(date -r "$archivo" '+%Y-%m-%d %H:%M')"
        encontrados=$((encontrados + 1))
    done < <(find "$DESTINO" -maxdepth 1 -type f \
                \( -name 'tornalyx-db-*.sql.gz' -o -name 'tornalyx-archivos-*.tar.gz' \) \
                2>/dev/null | sort -r)

    [[ $encontrados -eq 0 ]] && msg_info "Todavía no hay respaldos."
    printf '\n  Espacio ocupado: %s\n' "$(du -sh "$DESTINO" 2>/dev/null | cut -f1)"
}

# ── Restauración ────────────────────────────────────────────
restaurar() {
    local archivo=${1:-}
    [[ -n $archivo ]] || abortar "Uso: respaldo.sh restaurar <archivo.sql.gz>"
    [[ -f $archivo ]] || archivo="$DESTINO/$archivo"
    [[ -f $archivo ]] || abortar "No se encuentra el respaldo: ${1}"

    resolver_credenciales
    requiere_root

    # Restaurar PISA la base entera: es la operación más destructiva de la
    # suite, así que se pide confirmación explícita aunque sea tedioso.
    msg_warn "Vas a reemplazar TODO el contenido de la base $DB_NAME en $DB_HOST."
    msg_warn "Origen: $archivo"
    confirmar "Confirmás la restauración (se pierden los datos actuales)" \
        || { msg_info "Cancelado."; return 0; }

    if [[ -f "$archivo.sha256" ]]; then
        if ( cd "$(dirname "$archivo")" && sha256sum -c "$(basename "$archivo").sha256" >/dev/null 2>&1 ); then
            msg_ok "Checksum verificado."
        else
            abortar "El checksum no coincide: el respaldo está dañado, no se restaura."
        fi
    else
        msg_warn "El respaldo no tiene archivo .sha256: no se pudo verificar su integridad."
    fi

    # El usuario de respaldo es de solo lectura por diseño (dcl.sql), así que
    # escribir la base restaurada le toca a la cuenta DDL, que sí tiene
    # CREATE/DROP e INSERT sobre tornalyx_db.
    local usuario=${DB_RESTORE_USER:-${DB_DDL_USER:-}}
    local clave=${DB_RESTORE_PASS:-${DB_DDL_PASS:-}}
    if [[ -z $usuario ]]; then
        abortar "Restaurar necesita una cuenta con permisos de escritura; el usuario de respaldo no los tiene.
Exportá las credenciales del usuario DDL antes de reintentar:
    export DB_DDL_USER=tornalyx_ddl
    read -rs DB_DDL_PASS && export DB_DDL_PASS"
    fi

    # Red de seguridad: antes de pisar nada se guarda el estado actual, con
    # sufijo propio para que no pueda coincidir con el archivo de origen.
    msg_info "Guardando un respaldo previo del estado actual..."
    RESPALDO_GENERADO=''
    respaldar_base '-previo' || msg_warn "No se pudo respaldar el estado previo; se continúa igual."
    if [[ -n $RESPALDO_GENERADO && "$(readlink -f "$RESPALDO_GENERADO")" == "$(readlink -f "$archivo")" ]]; then
        abortar "El respaldo previo se escribió sobre el archivo de origen: se aborta para no perderlo."
    fi

    if gzip -dc "$archivo" | MYSQL_PWD="$clave" mysql \
            --host="$DB_HOST" --port="$DB_PORT" --user="$usuario" "$DB_NAME"; then
        msg_ok "Base $DB_NAME restaurada desde $archivo (con el usuario $usuario)."
        registrar_log "restaurar desde=$archivo usuario=$usuario"
    else
        abortar "La restauración falló con el usuario $usuario.
Verificá que tenga CREATE, DROP, INSERT y ALTER sobre $DB_NAME (ver dcl.sql)."
    fi
}

# ── Programa principal ──────────────────────────────────────
accion=''
posicionales=()
while [[ $# -gt 0 ]]; do
    case $1 in
        base|archivos|listar|restaurar|todo) accion=${accion:-$1} ;;
        --retencion) RETENCION_DIAS=${2:-14}; shift ;;
        --destino)   DESTINO=${2:-$DESTINO};  shift ;;
        --si)        TORNALYX_ASUMIR_SI=1 ;;
        -h|--help)   ayuda ;;
        *)           posicionales+=("$1") ;;
    esac
    shift
done

case ${accion:-todo} in
    base)
        requiere_root
        respaldar_base
        rotar
        ;;
    archivos)
        requiere_root
        respaldar_archivos
        rotar
        ;;
    listar)
        listar
        ;;
    restaurar)
        restaurar "${posicionales[0]:-}"
        ;;
    todo)
        requiere_root
        titulo "Respaldo completo de Tornalyx"
        respaldar_base
        respaldar_archivos
        rotar
        printf '\n'
        listar
        ;;
esac
