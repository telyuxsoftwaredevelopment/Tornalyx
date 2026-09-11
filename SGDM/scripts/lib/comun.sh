#!/bin/bash
# ============================================================
# TORNALYX SGDM — lib/comun.sh
# Biblioteca compartida por toda la suite de administración.
#
# No se ejecuta sola: los demás scripts la cargan con `source`.
# Concentra acá lo que todos repiten (colores, mensajes, chequeo de
# root, confirmaciones, bitácora y consultas a /etc/passwd y
# /etc/group) para que cada script quede con su lógica y nada más.
# ============================================================

# Cargarla dos veces (gestion.sh la carga y además invoca scripts que
# también la cargan) redefiniría constantes de solo lectura y abortaría.
[[ -n "${TORNALYX_COMUN_CARGADO:-}" ]] && return 0
TORNALYX_COMUN_CARGADO=1

# ── Rutas del proyecto en el servidor ────────────────────────
# Se pueden pisar desde el entorno para probar la suite fuera de
# producción (por ejemplo TORNALYX_RAIZ=/tmp/prueba ./permisos.sh).
: "${TORNALYX_RAIZ:=/var/www/tornalyx}"
: "${TORNALYX_LOG:=/var/log/tornalyx/gestion.log}"
: "${TORNALYX_BACKUP_DIR:=/var/backups/tornalyx}"

# Cuentas y grupos definidos en el estudio de roles (sistemas-operativos.html).
TORNALYX_CUENTAS=(admin_tornalyx operador_web dev_tornalyx backup_tornalyx)

# UID a partir del cual una cuenta es "de persona" y no del sistema.
: "${TORNALYX_UID_MIN:=1000}"

# ── Colores (se apagan si la salida no es una terminal) ──────
if [[ -t 1 && -z "${TORNALYX_SIN_COLOR:-}" ]]; then
    C_ROJO=$'\033[0;31m';    C_VERDE=$'\033[0;32m'
    C_AMARILLO=$'\033[0;33m'; C_AZUL=$'\033[0;34m'
    C_GRIS=$'\033[0;90m';    C_NEGRITA=$'\033[1m'
    C_RESET=$'\033[0m'
else
    C_ROJO=''; C_VERDE=''; C_AMARILLO=''; C_AZUL=''
    C_GRIS=''; C_NEGRITA=''; C_RESET=''
fi

# ── Mensajes ────────────────────────────────────────────────
msg()       { printf '%s\n' "$*"; }
msg_info()  { printf '%s[i]%s %s\n' "$C_AZUL"     "$C_RESET" "$*"; }
msg_ok()    { printf '%s[+]%s %s\n' "$C_VERDE"    "$C_RESET" "$*"; }
msg_warn()  { printf '%s[!]%s %s\n' "$C_AMARILLO" "$C_RESET" "$*" >&2; }
msg_error() { printf '%s[x]%s %s\n' "$C_ROJO"     "$C_RESET" "$*" >&2; }

titulo() {
    printf '\n%s%s%s\n' "$C_NEGRITA" "$*" "$C_RESET"
    printf '%s%s%s\n' "$C_GRIS" "------------------------------------------------------------" "$C_RESET"
}

# Corta la ejecución con un mensaje de error y el código indicado (1 por defecto).
abortar() {
    msg_error "$1"
    exit "${2:-1}"
}

# ── Ayuda ───────────────────────────────────────────────────
# Imprime como texto de ayuda la cabecera del script: el bloque de
# comentarios que va entre las dos líneas de '='.
#
# Antes cada script recortaba su propia cabecera con un rango de líneas fijo
# (`sed -n '3,24p'`), y bastaba agregar un párrafo arriba para que --help
# empezara a escupir el código de abajo. Acá los límites se buscan en el
# archivo, así que la ayuda no se puede desfasar.
mostrar_ayuda() {
    awk '
        /^# =+$/ { bloque++; if (bloque == 2) exit; next }
        bloque == 1 { sub(/^# ?/, ""); print }
    ' "${1:-$0}"
    exit 0
}

# ── Bitácora ────────────────────────────────────────────────
# Toda operación que cambia el sistema deja rastro de quién la hizo.
# Si no se puede escribir el log (por ejemplo una consulta de listar.sh
# corriendo sin root) la operación NO falla: la bitácora es un extra.
registrar_log() {
    local dir
    dir=$(dirname "$TORNALYX_LOG")
    [[ -d $dir ]] || mkdir -p "$dir" 2>/dev/null || return 0
    printf '%s | %-16s | %-18s | %s\n' \
        "$(date '+%Y-%m-%d %H:%M:%S')" \
        "${SUDO_USER:-${USER:-$(id -un 2>/dev/null || echo desconocido)}}" \
        "$(basename "${0:-bash}")" \
        "$*" >> "$TORNALYX_LOG" 2>/dev/null || true
}

# ── Privilegios ─────────────────────────────────────────────
requiere_root() {
    if [[ ${EUID:-$(id -u)} -ne 0 ]]; then
        abortar "Esta operación necesita privilegios de root. Volvé a ejecutarla con sudo."
    fi
}

# ── Interacción ─────────────────────────────────────────────
# Pide confirmación antes de algo irreversible. En modo desatendido
# (TORNALYX_ASUMIR_SI=1, que activan los flags --si) responde que sí;
# sin terminal y sin ese flag responde que no, para que un cron nunca
# borre nada por accidente.
confirmar() {
    local respuesta
    [[ -n "${TORNALYX_ASUMIR_SI:-}" ]] && return 0
    if [[ ! -t 0 ]]; then
        msg_warn "Sin terminal interactiva: se cancela \"$1\" (usá --si para confirmarlo de antemano)."
        return 1
    fi
    read -r -p "$(printf '%s?%s %s [s/N]: ' "$C_AMARILLO" "$C_RESET" "$1")" respuesta
    [[ ${respuesta,,} == s || ${respuesta,,} == si ]]
}

# Lee un dato con valor por defecto opcional: pedir_dato VARIABLE "Etiqueta" ["default"]
pedir_dato() {
    local -n _destino=$1
    local etiqueta=$2 defecto=${3:-} entrada
    if [[ -n $defecto ]]; then
        read -r -p "$etiqueta [$defecto]: " entrada
        _destino=${entrada:-$defecto}
    else
        read -r -p "$etiqueta: " entrada
        _destino=$entrada
    fi
}

pausa() {
    [[ -t 0 ]] || return 0
    read -r -p "$(printf '\n%sEnter para continuar...%s' "$C_GRIS" "$C_RESET")" _
}

# ── Credenciales ────────────────────────────────────────────
# Los scripts que hablan con MySQL (respaldo, monitoreo, despliegue) leen
# sus credenciales de tres lugares, en este orden de prioridad:
#
#   1. el entorno              -> lo que exportó quien ejecuta el script
#   2. TORNALYX_CREDENCIALES   -> /etc/tornalyx/credenciales.env (600, root)
#   3. el .env de la app       -> solo como último recurso
#
# El punto 2 es el que hace posible el cron: una tarea programada no tiene
# a nadie que exporte variables antes de arrancar, y el .env de la app SOLO
# tiene al usuario web (tornalyx_dml), que no debe poder volcar la base ni
# tocar el esquema. El archivo de credenciales guarda las cuentas de
# dcl.sql (respaldo, monitoreo, DDL) donde únicamente root las lee.
: "${TORNALYX_CREDENCIALES:=/etc/tornalyx/credenciales.env}"

# Carga pares CLAVE=valor de un archivo sin pisar lo que ya esté definido
# en el entorno. Mismo criterio que el cargador de PHP (loadDotEnv): se
# ignoran comentarios y líneas sueltas, y se quitan las comillas envolventes.
cargar_variables_de() {
    local archivo=$1
    [[ -f $archivo && -r $archivo ]] || return 0
    local linea clave valor
    while IFS= read -r linea; do
        linea=${linea%$'\r'}
        [[ -z $linea || $linea == \#* ]] && continue
        [[ $linea != *=* ]] && continue
        clave=${linea%%=*}
        valor=${linea#*=}
        clave=${clave// /}
        valor=${valor#[\"\']}
        valor=${valor%[\"\']}
        # Ya definida en el entorno: el entorno manda.
        [[ -n ${!clave:-} ]] && continue
        printf -v "$clave" '%s' "$valor"
        export "${clave?}"
    done < "$archivo"
}

# Carga las credenciales de los dos archivos, en orden de prioridad.
cargar_credenciales() {
    cargar_variables_de "$TORNALYX_CREDENCIALES"
    cargar_variables_de "${1:-$TORNALYX_RAIZ/.env}"
}

# ── Consultas al sistema ────────────────────────────────────
existe_usuario() { getent passwd "$1" >/dev/null 2>&1; }
existe_grupo()   { getent group  "$1" >/dev/null 2>&1; }

uid_de()   { getent passwd "$1" | cut -d: -f3; }
gid_de()   { getent passwd "$1" | cut -d: -f4; }
home_de()  { getent passwd "$1" | cut -d: -f6; }
shell_de() { getent passwd "$1" | cut -d: -f7; }

# Grupo principal (por nombre) de un usuario.
grupo_principal_de() {
    local gid
    gid=$(gid_de "$1") || return 1
    getent group "$gid" | cut -d: -f1
}

# Grupos secundarios de un usuario, separados por coma (vacío si no tiene).
# El `|| true` no es decorativo: cuando el usuario solo pertenece a su grupo
# principal, grep no encuentra nada y sale con 1, y bajo `set -o pipefail`
# eso hacía abortar al script que llamara a esta función.
grupos_secundarios_de() {
    local usuario=$1 principal salida
    principal=$(grupo_principal_de "$usuario") || return 0
    salida=$(id -nG "$usuario" 2>/dev/null | tr ' ' '\n' | grep -vx "$principal" | paste -sd, - || true)
    printf '%s' "$salida"
}

# Nombre del usuario a partir de un UID (vacío si no existe).
usuario_por_uid() { getent passwd "$1" | cut -d: -f1; }

# ¿El usuario pertenece al grupo (principal o secundario)? Se resuelve con
# una comparación de bash y no con `id -nG | grep`, que bajo `pipefail`
# devuelve lo contrario de lo que debería cuando grep corta el pipe.
esta_en_grupo() {
    local grupos
    grupos=" $(id -nG "$1" 2>/dev/null || true) "
    [[ $grupos == *" $2 "* ]]
}

# ── Validaciones ────────────────────────────────────────────
# Misma regla que useradd: minúsculas, dígitos, guion y guion bajo,
# sin empezar con dígito y hasta 32 caracteres.
validar_nombre_cuenta() {
    [[ $1 =~ ^[a-z_][a-z0-9_-]{0,31}$ ]]
}

# Las cuentas del sistema (UID < TORNALYX_UID_MIN) y root no se tocan
# desde esta suite: un borrado ahí deja el servidor inutilizable.
es_cuenta_protegida() {
    local usuario=$1 uid
    [[ $usuario == root ]] && return 0
    existe_usuario "$usuario" || return 1
    uid=$(uid_de "$usuario")
    [[ -n $uid && $uid -lt $TORNALYX_UID_MIN ]]
}

# Igual para los grupos del sistema.
es_grupo_protegido() {
    local grupo=$1 gid
    [[ $grupo == root || $grupo == wheel || $grupo == apache ]] && return 0
    existe_grupo "$grupo" || return 1
    gid=$(getent group "$grupo" | cut -d: -f3)
    [[ -n $gid && $gid -lt $TORNALYX_UID_MIN ]]
}

# ── Ejecución con soporte de simulación (--dry-run) ─────────
# Los scripts que aceptan --dry-run ponen TORNALYX_SIMULAR=1 y llaman
# a todo lo que modifica el sistema a través de esta función.
ejecutar() {
    if [[ -n "${TORNALYX_SIMULAR:-}" ]]; then
        printf '    %s(simulado)%s %s\n' "$C_GRIS" "$C_RESET" "$*"
        return 0
    fi
    "$@"
}

# ── Pipelines seguros bajo `set -o pipefail` ────────────────
# Toda la suite corre con `set -euo pipefail`, y eso convierte un patrón
# común en una trampa: `comando | head -5` y `comando | grep -q ...` hacen
# que el lector CORTE el pipe apenas tiene lo que buscaba, el escritor de la
# izquierda muera con SIGPIPE, y `pipefail` marque el pipeline entero como
# fallido aunque haya funcionado perfecto. El resultado es un `if` que da
# justo al revés, o un script que aborta a mitad de camino.
#
# Estas dos funciones hacen lo mismo dentro de un subshell con `pipefail`
# apagado, que es donde corresponde apagarlo: el pipeline no falló.

# Primeras N líneas de la salida de un comando.
#   primeras_lineas 5 find /ruta -type f
primeras_lineas() {
    local n=$1
    shift
    ( set +o pipefail; "$@" 2>/dev/null | head -n "$n" )
}

# ¿La salida del comando contiene el patrón? (equivalente a `cmd | grep -q`)
#   salida_contiene '^crond' systemctl list-unit-files
salida_contiene() {
    local patron=$1
    shift
    ( set +o pipefail; "$@" 2>/dev/null | grep -q -- "$patron" )
}

# ── Utilidades varias ───────────────────────────────────────
# Contraseña temporal razonable para una cuenta recién creada: siempre
# incluye mayúscula, minúscula y dígito, así pasa cualquier política PAM.
generar_password() {
    local aleatorio=''
    if command -v openssl >/dev/null 2>&1; then
        aleatorio=$(openssl rand -base64 24 2>/dev/null | tr -dc 'A-Za-z0-9' | cut -c1-14)
    fi
    if [[ -z $aleatorio ]]; then
        aleatorio=$(LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom 2>/dev/null | head -c 14 || true)
    fi
    printf 'Tx%s7' "$aleatorio"
}

# ¿El comando existe en este sistema?
hay_comando() { command -v "$1" >/dev/null 2>&1; }
