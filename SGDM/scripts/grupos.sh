#!/bin/bash
# ============================================================
# TORNALYX SGDM — grupos.sh
# Altas, bajas y modificaciones de grupos del servidor.
#
# La pertenencia de usuarios a grupos se maneja en registrar_grupo.sh;
# acá se administra el grupo en sí (crear, renombrar, cambiar GID, borrar).
#
# Uso:
#   sudo ./grupos.sh                        menú interactivo
#   sudo ./grupos.sh crear <grupo> [gid]
#   sudo ./grupos.sh eliminar <grupo> [--si]
#   sudo ./grupos.sh renombrar <viejo> <nuevo>
#   sudo ./grupos.sh gid <grupo> <nuevo_gid>
#   ./grupos.sh ver <grupo>                 no necesita root
#   ./grupos.sh listar                      no necesita root
# ============================================================

set -euo pipefail

DIR_SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/comun.sh
source "$DIR_SCRIPT/lib/comun.sh"

ayuda() {
    mostrar_ayuda "${BASH_SOURCE[0]}"
}

# ── Consulta ────────────────────────────────────────────────
ver_grupo() {
    local grupo=${1:-} gid miembros
    [[ -n $grupo ]] || abortar "Falta el nombre del grupo."
    existe_grupo "$grupo" || abortar "El grupo $grupo no existe."

    gid=$(getent group "$grupo" | cut -d: -f3)
    miembros=$(getent group "$grupo" | cut -d: -f4)

    titulo "Grupo: $grupo"
    printf '  GID       %s\n' "$gid"
    printf '  Miembros  %s\n' "${miembros:-—}"

    # Quien tiene este grupo como PRINCIPAL no aparece en el campo de
    # miembros de /etc/group: hay que buscarlo por el GID en /etc/passwd.
    local principales
    principales=$(getent passwd | awk -F: -v g="$gid" '$4 == g {printf "%s ", $1}')
    printf '  Principal %s\n' "${principales:-—}"
}

listar_grupos() {
    "$DIR_SCRIPT/listar.sh" grupos "$@"
}

# ── Alta ────────────────────────────────────────────────────
crear() {
    local grupo=${1:-} gid=${2:-}
    [[ -n $grupo ]] || abortar "Falta el nombre del grupo."
    validar_nombre_cuenta "$grupo" \
        || abortar "Nombre inválido: usá minúsculas, dígitos, '-' o '_', sin empezar con dígito."
    if existe_grupo "$grupo"; then
        msg_info "El grupo $grupo ya existe (GID $(getent group "$grupo" | cut -d: -f3))."
        return 0
    fi
    requiere_root

    if [[ -n $gid ]]; then
        [[ $gid =~ ^[0-9]+$ ]] || abortar "El GID debe ser numérico."
        getent group "$gid" >/dev/null 2>&1 \
            && abortar "El GID $gid ya lo usa el grupo $(getent group "$gid" | cut -d: -f1)."
        groupadd -g "$gid" "$grupo"
    else
        groupadd "$grupo"
    fi

    msg_ok "Grupo $grupo creado (GID $(getent group "$grupo" | cut -d: -f3))."
    registrar_log "crear grupo=$grupo gid=${gid:-auto}"
}

# ── Baja ────────────────────────────────────────────────────
eliminar() {
    local grupo=${1:-} parametro
    shift || true
    for parametro in "$@"; do
        [[ $parametro == --si ]] && TORNALYX_ASUMIR_SI=1
    done

    [[ -n $grupo ]] || abortar "Falta el nombre del grupo."
    existe_grupo "$grupo" || abortar "El grupo $grupo no existe."
    es_grupo_protegido "$grupo" \
        && abortar "$grupo es un grupo del sistema: no se elimina desde acá."
    requiere_root

    # groupdel se niega a borrar un grupo que sea el principal de alguien;
    # avisamos antes con un mensaje entendible en vez del error crudo.
    local gid principales
    gid=$(getent group "$grupo" | cut -d: -f3)
    principales=$(getent passwd | awk -F: -v g="$gid" '$4 == g {printf "%s ", $1}')
    if [[ -n $principales ]]; then
        abortar "No se puede borrar: $grupo es el grupo principal de: $principales"
    fi

    local miembros
    miembros=$(getent group "$grupo" | cut -d: -f4)
    [[ -n $miembros ]] && msg_warn "El grupo todavía tiene miembros: $miembros"

    confirmar "Eliminar el grupo $grupo" || { msg_info "Cancelado."; return 0; }
    groupdel "$grupo"
    msg_ok "Grupo $grupo eliminado."
    registrar_log "eliminar grupo=$grupo"
}

# ── Modificación ────────────────────────────────────────────
renombrar() {
    local viejo=${1:-} nuevo=${2:-}
    [[ -n $viejo && -n $nuevo ]] || abortar "Uso: grupos.sh renombrar <viejo> <nuevo>"
    existe_grupo "$viejo" || abortar "El grupo $viejo no existe."
    existe_grupo "$nuevo" && abortar "Ya existe un grupo llamado $nuevo."
    validar_nombre_cuenta "$nuevo" || abortar "Nombre inválido: $nuevo"
    es_grupo_protegido "$viejo" && abortar "$viejo es un grupo del sistema: no se renombra desde acá."
    requiere_root

    groupmod -n "$nuevo" "$viejo"
    msg_ok "Grupo $viejo renombrado a $nuevo."
    registrar_log "renombrar grupo=$viejo -> $nuevo"
}

cambiar_gid() {
    local grupo=${1:-} gid=${2:-}
    [[ -n $grupo && -n $gid ]] || abortar "Uso: grupos.sh gid <grupo> <nuevo_gid>"
    existe_grupo "$grupo" || abortar "El grupo $grupo no existe."
    [[ $gid =~ ^[0-9]+$ ]] || abortar "El GID debe ser numérico."
    getent group "$gid" >/dev/null 2>&1 \
        && abortar "El GID $gid ya lo usa el grupo $(getent group "$gid" | cut -d: -f1)."
    es_grupo_protegido "$grupo" && abortar "$grupo es un grupo del sistema: no se le cambia el GID desde acá."
    requiere_root

    groupmod -g "$gid" "$grupo"
    msg_ok "Grupo $grupo ahora tiene GID $gid."
    msg_warn "Los archivos que pertenecían al GID anterior quedan huérfanos: revisá con permisos.sh verificar."
    registrar_log "gid grupo=$grupo nuevo_gid=$gid"
}

# ── Menú interactivo ────────────────────────────────────────
menu() {
    local opcion grupo extra
    while true; do
        titulo "Gestión de grupos"
        cat <<'OPCIONES'
  1) Listar grupos
  2) Ver detalle de un grupo
  3) Crear un grupo
  4) Renombrar un grupo
  5) Cambiar el GID de un grupo
  6) Eliminar un grupo
  0) Volver
OPCIONES
        read -r -p "Opción: " opcion
        case $opcion in
            1) listar_grupos ;;
            2) pedir_dato grupo "Grupo"; ver_grupo "$grupo" ;;
            3)
                pedir_dato grupo "Nombre del nuevo grupo"
                pedir_dato extra "GID (vacío = automático)" ""
                crear "$grupo" "$extra"
                ;;
            4)
                pedir_dato grupo "Grupo a renombrar"
                pedir_dato extra "Nuevo nombre"
                renombrar "$grupo" "$extra"
                ;;
            5)
                pedir_dato grupo "Grupo"
                pedir_dato extra "Nuevo GID"
                cambiar_gid "$grupo" "$extra"
                ;;
            6) pedir_dato grupo "Grupo a eliminar"; eliminar "$grupo" ;;
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
    menu)       menu ;;
    listar)     listar_grupos "$@" ;;
    ver)        ver_grupo "$@" ;;
    crear)      crear "$@" ;;
    eliminar)   eliminar "$@" ;;
    renombrar)  renombrar "$@" ;;
    gid)        cambiar_gid "$@" ;;
    -h|--help)  ayuda ;;
    *)          abortar "Acción desconocida: $accion (probá --help)" ;;
esac
