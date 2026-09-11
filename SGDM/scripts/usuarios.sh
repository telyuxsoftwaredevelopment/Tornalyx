#!/bin/bash
# ============================================================
# TORNALYX SGDM — usuarios.sh
# Altas, bajas y modificaciones de cuentas del servidor.
#
# Sirve de dos formas: como subcomando (para automatizar o para que lo
# llame gestion.sh) y como menú interactivo si se ejecuta sin argumentos.
#
# Uso:
#   sudo ./usuarios.sh                          menú interactivo
#   sudo ./usuarios.sh crear <usuario> [--grupo G] [--shell S] [--comentario "..."]
#   sudo ./usuarios.sh eliminar <usuario> [--con-home] [--si]
#   sudo ./usuarios.sh modificar <usuario> [--shell S] [--comentario "..."] [--grupo G]
#   sudo ./usuarios.sh bloquear <usuario>
#   sudo ./usuarios.sh desbloquear <usuario>
#   sudo ./usuarios.sh password <usuario>
#   sudo ./usuarios.sh caducar <usuario>        pide cambio en el próximo login
#   ./usuarios.sh ver <usuario>                 no necesita root
# ============================================================

set -euo pipefail

DIR_SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/comun.sh
source "$DIR_SCRIPT/lib/comun.sh"

ayuda() {
    mostrar_ayuda "${BASH_SOURCE[0]}"
}

# ── Detalle de una cuenta ───────────────────────────────────
ver_usuario() {
    local usuario=$1
    existe_usuario "$usuario" || abortar "El usuario $usuario no existe."

    titulo "Cuenta: $usuario"
    printf '  UID              %s\n' "$(uid_de "$usuario")"
    printf '  Grupo principal  %s (GID %s)\n' "$(grupo_principal_de "$usuario")" "$(gid_de "$usuario")"
    printf '  Grupos sec.      %s\n' "$(grupos_secundarios_de "$usuario" || echo '—')"
    printf '  Home             %s\n' "$(home_de "$usuario")"
    printf '  Shell            %s\n' "$(shell_de "$usuario")"
    printf '  Descripción      %s\n' "$(getent passwd "$usuario" | cut -d: -f5)"

    # El estado de la contraseña solo se puede leer como root.
    if [[ ${EUID:-$(id -u)} -eq 0 ]] && hay_comando passwd; then
        local estado
        estado=$(passwd -S "$usuario" 2>/dev/null | awk '{print $2}')
        case $estado in
            P|PS) printf '  Contraseña       definida\n' ;;
            L|LK) printf '  Contraseña       %sbloqueada%s\n' "$C_AMARILLO" "$C_RESET" ;;
            NP)   printf '  Contraseña       %ssin definir%s\n' "$C_ROJO" "$C_RESET" ;;
            *)    printf '  Contraseña       desconocida\n' ;;
        esac
        hay_comando chage && chage -l "$usuario" 2>/dev/null | sed 's/^/  /'
    fi
}

# ── Alta ────────────────────────────────────────────────────
crear() {
    local usuario=${1:-} grupo='' shell=/bin/bash comentario='' home=''
    shift || true
    while [[ $# -gt 0 ]]; do
        case $1 in
            --grupo)      grupo=${2:-};      shift 2 ;;
            --shell)      shell=${2:-};      shift 2 ;;
            --comentario) comentario=${2:-}; shift 2 ;;
            --home)       home=${2:-};       shift 2 ;;
            *) abortar "Opción desconocida para crear: $1" ;;
        esac
    done

    [[ -n $usuario ]] || abortar "Falta el nombre de usuario."
    validar_nombre_cuenta "$usuario" \
        || abortar "Nombre inválido: usá minúsculas, dígitos, '-' o '_', sin empezar con dígito."
    existe_usuario "$usuario" && abortar "El usuario $usuario ya existe."
    requiere_root

    local args=(-m -s "$shell")
    [[ -n $home       ]] && args+=(-d "$home")
    [[ -n $comentario ]] && args+=(-c "$comentario")
    if [[ -n $grupo ]]; then
        existe_grupo "$grupo" || abortar "El grupo $grupo no existe (creálo antes con grupos.sh)."
        args+=(-g "$grupo")
    fi

    useradd "${args[@]}" "$usuario"
    msg_ok "Usuario $usuario creado."

    if hay_comando chpasswd; then
        local password
        password=$(generar_password)
        printf '%s:%s\n' "$usuario" "$password" | chpasswd
        chage -d 0 "$usuario"
        printf '  Contraseña temporal: %s%s%s (se pedirá cambiarla al primer login)\n' \
            "$C_NEGRITA" "$password" "$C_RESET"
    else
        msg_warn "Falta 'chpasswd': la cuenta quedó sin contraseña. Asignala con: passwd $usuario"
    fi

    registrar_log "crear usuario=$usuario grupo=${grupo:-por-defecto} shell=$shell"
}

# ── Baja ────────────────────────────────────────────────────
eliminar() {
    local usuario=${1:-} borrar_home=0
    shift || true
    while [[ $# -gt 0 ]]; do
        case $1 in
            --con-home) borrar_home=1 ;;
            --si)       TORNALYX_ASUMIR_SI=1 ;;
            *) abortar "Opción desconocida para eliminar: $1" ;;
        esac
        shift
    done

    [[ -n $usuario ]] || abortar "Falta el nombre de usuario."
    existe_usuario "$usuario" || abortar "El usuario $usuario no existe."
    es_cuenta_protegida "$usuario" \
        && abortar "$usuario es una cuenta del sistema (UID < $TORNALYX_UID_MIN): no se elimina desde acá."
    requiere_root

    local aviso="Eliminar la cuenta $usuario"
    [[ $borrar_home -eq 1 ]] && aviso+=" y su home $(home_de "$usuario")"
    confirmar "$aviso" || { msg_info "Cancelado."; return 0; }

    # Sin esto, userdel falla si el usuario dejó procesos vivos.
    if pgrep -u "$usuario" >/dev/null 2>&1; then
        msg_warn "$usuario tiene procesos en ejecución; se terminan antes de borrar la cuenta."
        pkill -TERM -u "$usuario" 2>/dev/null || true
        sleep 2
        pkill -KILL -u "$usuario" 2>/dev/null || true
    fi

    if [[ $borrar_home -eq 1 ]]; then
        userdel -r "$usuario"
        msg_ok "Usuario $usuario y su home eliminados."
    else
        userdel "$usuario"
        msg_ok "Usuario $usuario eliminado (su home quedó en disco)."
    fi
    registrar_log "eliminar usuario=$usuario con_home=$borrar_home"
}

# ── Modificación ────────────────────────────────────────────
modificar() {
    local usuario=${1:-} cambios=() detalle=''
    shift || true
    while [[ $# -gt 0 ]]; do
        case $1 in
            --shell)      cambios+=(-s "${2:-}"); detalle+=" shell=${2:-}";      shift 2 ;;
            --comentario) cambios+=(-c "${2:-}"); detalle+=" comentario";        shift 2 ;;
            --grupo)      cambios+=(-g "${2:-}"); detalle+=" grupo=${2:-}";      shift 2 ;;
            --home)       cambios+=(-d "${2:-}" -m); detalle+=" home=${2:-}";    shift 2 ;;
            --nombre)     cambios+=(-l "${2:-}"); detalle+=" renombrado=${2:-}"; shift 2 ;;
            *) abortar "Opción desconocida para modificar: $1" ;;
        esac
    done

    [[ -n $usuario ]] || abortar "Falta el nombre de usuario."
    existe_usuario "$usuario" || abortar "El usuario $usuario no existe."
    [[ ${#cambios[@]} -gt 0 ]] || abortar "No indicaste ningún cambio (--shell, --comentario, --grupo, --home, --nombre)."
    requiere_root

    usermod "${cambios[@]}" "$usuario"
    msg_ok "Usuario $usuario actualizado:$detalle"
    registrar_log "modificar usuario=$usuario$detalle"
}

# ── Contraseña y bloqueo ────────────────────────────────────
bloquear() {
    local usuario=${1:-}
    [[ -n $usuario ]] || abortar "Falta el nombre de usuario."
    existe_usuario "$usuario" || abortar "El usuario $usuario no existe."
    es_cuenta_protegida "$usuario" && abortar "$usuario es una cuenta del sistema: no se bloquea desde acá."
    requiere_root

    # -l bloquea la contraseña; expirar la cuenta corta también las sesiones
    # que entran por clave SSH, que passwd -l por sí solo no detiene.
    usermod -L -e 1 "$usuario"
    msg_ok "Cuenta $usuario bloqueada (contraseña y acceso deshabilitados)."
    registrar_log "bloquear usuario=$usuario"
}

desbloquear() {
    local usuario=${1:-}
    [[ -n $usuario ]] || abortar "Falta el nombre de usuario."
    existe_usuario "$usuario" || abortar "El usuario $usuario no existe."
    requiere_root

    usermod -U -e '' "$usuario"
    msg_ok "Cuenta $usuario desbloqueada."
    registrar_log "desbloquear usuario=$usuario"
}

cambiar_password() {
    local usuario=${1:-}
    [[ -n $usuario ]] || abortar "Falta el nombre de usuario."
    existe_usuario "$usuario" || abortar "El usuario $usuario no existe."
    requiere_root

    # passwd pide la contraseña dos veces y no la muestra: es preferible
    # a leerla desde acá y arriesgarse a que quede en el historial.
    passwd "$usuario"
    registrar_log "password usuario=$usuario"
}

caducar() {
    local usuario=${1:-}
    [[ -n $usuario ]] || abortar "Falta el nombre de usuario."
    existe_usuario "$usuario" || abortar "El usuario $usuario no existe."
    requiere_root

    chage -d 0 "$usuario"
    msg_ok "$usuario deberá cambiar su contraseña en el próximo inicio de sesión."
    registrar_log "caducar usuario=$usuario"
}

# ── Menú interactivo ────────────────────────────────────────
menu() {
    local opcion usuario extra
    while true; do
        titulo "Gestión de usuarios"
        cat <<'OPCIONES'
  1) Listar cuentas del servidor
  2) Ver detalle de una cuenta
  3) Crear una cuenta
  4) Modificar una cuenta
  5) Bloquear una cuenta
  6) Desbloquear una cuenta
  7) Cambiar la contraseña de una cuenta
  8) Forzar cambio de contraseña en el próximo login
  9) Eliminar una cuenta
  0) Volver
OPCIONES
        read -r -p "Opción: " opcion
        case $opcion in
            1) "$DIR_SCRIPT/listar.sh" usuarios ;;
            2) pedir_dato usuario "Usuario"; ver_usuario "$usuario" ;;
            3)
                pedir_dato usuario "Nombre de la nueva cuenta"
                pedir_dato extra "Descripción" "Cuenta creada por gestion.sh"
                crear "$usuario" --comentario "$extra"
                ;;
            4)
                pedir_dato usuario "Usuario a modificar"
                pedir_dato extra "Nuevo shell" "$(shell_de "$usuario" 2>/dev/null || echo /bin/bash)"
                modificar "$usuario" --shell "$extra"
                ;;
            5) pedir_dato usuario "Usuario a bloquear";    bloquear "$usuario" ;;
            6) pedir_dato usuario "Usuario a desbloquear"; desbloquear "$usuario" ;;
            7) pedir_dato usuario "Usuario";               cambiar_password "$usuario" ;;
            8) pedir_dato usuario "Usuario";               caducar "$usuario" ;;
            9)
                pedir_dato usuario "Usuario a eliminar"
                pedir_dato extra "¿Borrar también su home? (s/n)" "n"
                if [[ ${extra,,} == s ]]; then eliminar "$usuario" --con-home; else eliminar "$usuario"; fi
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
    menu)        menu ;;
    ver)         ver_usuario "$@" ;;
    crear)       crear "$@" ;;
    eliminar)    eliminar "$@" ;;
    modificar)   modificar "$@" ;;
    bloquear)    bloquear "$@" ;;
    desbloquear) desbloquear "$@" ;;
    password)    cambiar_password "$@" ;;
    caducar)     caducar "$@" ;;
    -h|--help)   ayuda ;;
    *)           abortar "Acción desconocida: $accion (probá --help)" ;;
esac
