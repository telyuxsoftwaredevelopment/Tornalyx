#!/bin/bash
# ============================================================
# TORNALYX SGDM — registrar_grupo.sh
# Asignación y desvinculación de usuarios a grupos secundarios.
#
# Se separa de grupos.sh a propósito: acá no se crean ni se borran
# grupos, solo se maneja QUIÉN pertenece a cuál. Es la operación
# cotidiana (dar acceso a apache, sumar a alguien a wheel) y la que
# conviene tener acotada y auditada.
#
# Uso:
#   sudo ./registrar_grupo.sh                          menú interactivo
#   sudo ./registrar_grupo.sh agregar <usuario> <grupo> [grupo2 ...]
#   sudo ./registrar_grupo.sh quitar  <usuario> <grupo> [grupo2 ...]
#   sudo ./registrar_grupo.sh fijar   <usuario> <g1,g2> reemplaza TODOS los secundarios
#   ./registrar_grupo.sh ver <usuario>                 no necesita root
#   ./registrar_grupo.sh miembros <grupo>              no necesita root
# ============================================================

set -euo pipefail

DIR_SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/comun.sh
source "$DIR_SCRIPT/lib/comun.sh"

ayuda() {
    mostrar_ayuda "${BASH_SOURCE[0]}"
}

# ¿El usuario ya pertenece al grupo (principal o secundario)?
# La resuelve comun.sh sin pipeline: bajo `pipefail`, un `grep -q` que corta
# el pipe hace fallar el pipeline y devuelve lo contrario de lo correcto.
pertenece() {
    esta_en_grupo "$1" "$2"
}

# ── Consultas ───────────────────────────────────────────────
ver() {
    local usuario=${1:-}
    [[ -n $usuario ]] || abortar "Falta el nombre de usuario."
    existe_usuario "$usuario" || abortar "El usuario $usuario no existe."

    titulo "Grupos de $usuario"
    printf '  Principal   %s\n' "$(grupo_principal_de "$usuario")"
    printf '  Secundarios %s\n' "$(grupos_secundarios_de "$usuario" || echo '—')"
}

miembros() {
    local grupo=${1:-} gid
    [[ -n $grupo ]] || abortar "Falta el nombre del grupo."
    existe_grupo "$grupo" || abortar "El grupo $grupo no existe."
    gid=$(getent group "$grupo" | cut -d: -f3)

    titulo "Miembros de $grupo (GID $gid)"
    printf '  Por grupo principal   %s\n' \
        "$(getent passwd | awk -F: -v g="$gid" '$4 == g {printf "%s ", $1}' | sed 's/ $//' || true)"
    printf '  Como grupo secundario %s\n' \
        "$(getent group "$grupo" | cut -d: -f4 | tr ',' ' ')"
}

# ── Alta de pertenencia ─────────────────────────────────────
agregar() {
    local usuario=${1:-}
    shift || true
    [[ -n $usuario && $# -gt 0 ]] || abortar "Uso: registrar_grupo.sh agregar <usuario> <grupo> [grupo2 ...]"
    existe_usuario "$usuario" || abortar "El usuario $usuario no existe."
    requiere_root

    local grupo
    for grupo in "$@"; do
        if ! existe_grupo "$grupo"; then
            msg_error "El grupo $grupo no existe: se omite (creálo con grupos.sh crear $grupo)."
            continue
        fi
        if pertenece "$usuario" "$grupo"; then
            msg_info "$usuario ya pertenece a $grupo."
            continue
        fi
        # -a es imprescindible: sin él, -G REEMPLAZA la lista completa de
        # grupos secundarios y el usuario pierde todos los demás de golpe.
        usermod -aG "$grupo" "$usuario"
        msg_ok "$usuario agregado a $grupo."
        registrar_log "agregar usuario=$usuario grupo=$grupo"
    done

    ver "$usuario"
    msg_warn "Los grupos nuevos recién aplican en la próxima sesión del usuario."
}

# ── Baja de pertenencia ─────────────────────────────────────
quitar() {
    local usuario=${1:-}
    shift || true
    [[ -n $usuario && $# -gt 0 ]] || abortar "Uso: registrar_grupo.sh quitar <usuario> <grupo> [grupo2 ...]"
    existe_usuario "$usuario" || abortar "El usuario $usuario no existe."
    requiere_root

    local grupo principal
    principal=$(grupo_principal_de "$usuario")
    for grupo in "$@"; do
        if [[ $grupo == "$principal" ]]; then
            msg_error "$grupo es el grupo PRINCIPAL de $usuario: cambialo con usuarios.sh modificar --grupo."
            continue
        fi
        if ! pertenece "$usuario" "$grupo"; then
            msg_info "$usuario no pertenece a $grupo."
            continue
        fi
        gpasswd -d "$usuario" "$grupo" >/dev/null
        msg_ok "$usuario quitado de $grupo."
        registrar_log "quitar usuario=$usuario grupo=$grupo"
    done

    ver "$usuario"
}

# ── Reemplazo completo ──────────────────────────────────────
# Deja al usuario exactamente en los grupos indicados (además del
# principal). Útil para volver una cuenta al estado declarado en el
# estudio de roles después de cambios manuales.
fijar() {
    local usuario=${1:-} lista=${2:-}
    [[ -n $usuario ]] || abortar "Uso: registrar_grupo.sh fijar <usuario> <g1,g2,...>"
    existe_usuario "$usuario" || abortar "El usuario $usuario no existe."
    requiere_root

    local grupo faltantes=()
    if [[ -n $lista ]]; then
        while IFS= read -r grupo; do
            [[ -z $grupo ]] && continue
            existe_grupo "$grupo" || faltantes+=("$grupo")
        done < <(tr ',' '\n' <<< "$lista")
    fi
    [[ ${#faltantes[@]} -gt 0 ]] && abortar "Estos grupos no existen: ${faltantes[*]}"

    confirmar "Reemplazar TODOS los grupos secundarios de $usuario por: ${lista:-(ninguno)}" \
        || { msg_info "Cancelado."; return 0; }

    usermod -G "$lista" "$usuario"
    msg_ok "Grupos secundarios de $usuario fijados a: ${lista:-(ninguno)}"
    registrar_log "fijar usuario=$usuario grupos=${lista:-vacio}"
    ver "$usuario"
}

# ── Menú interactivo ────────────────────────────────────────
menu() {
    local opcion usuario grupo
    while true; do
        titulo "Pertenencia a grupos"
        cat <<'OPCIONES'
  1) Ver los grupos de un usuario
  2) Ver los miembros de un grupo
  3) Agregar un usuario a un grupo
  4) Quitar un usuario de un grupo
  5) Fijar la lista completa de grupos secundarios
  0) Volver
OPCIONES
        read -r -p "Opción: " opcion
        case $opcion in
            1) pedir_dato usuario "Usuario"; ver "$usuario" ;;
            2) pedir_dato grupo "Grupo";     miembros "$grupo" ;;
            3)
                pedir_dato usuario "Usuario"
                pedir_dato grupo "Grupo al que agregarlo"
                agregar "$usuario" "$grupo"
                ;;
            4)
                pedir_dato usuario "Usuario"
                pedir_dato grupo "Grupo del que quitarlo"
                quitar "$usuario" "$grupo"
                ;;
            5)
                pedir_dato usuario "Usuario"
                pedir_dato grupo "Grupos secundarios separados por coma (vacío = ninguno)" ""
                fijar "$usuario" "$grupo"
                ;;
            0) return 0 ;;
            *) msg_warn "Opción inválida." ;;
        esac
        pausa
    done
}

# ── Despacho ────────────────────────────────────────────────
accion=${1:-menu}
shift || true
case $accion in
    menu)      menu ;;
    ver)       ver "$@" ;;
    miembros)  miembros "$@" ;;
    agregar)   agregar "$@" ;;
    quitar)    quitar "$@" ;;
    fijar)     fijar "$@" ;;
    -h|--help) ayuda ;;
    *)         abortar "Acción desconocida: $accion (probá --help)" ;;
esac
