#!/bin/bash
# ============================================================
# TORNALYX SGDM — listar.sh
# Consultas de solo lectura sobre cuentas, grupos y sesiones.
#
# Lee directamente /etc/passwd y /etc/group (vía getent, que además
# cubre usuarios de LDAP/SSSD si algún día se suman) y los parsea con
# cut/awk. No modifica nada: es el único script de la suite que se
# puede correr sin privilegios.
#
# Uso:
#   ./listar.sh                    resumen general
#   ./listar.sh usuarios [--todos] cuentas de persona (UID >= 1000) o todas
#   ./listar.sh grupos   [--todos]
#   ./listar.sh proyecto           solo las cuentas del estudio de roles
#   ./listar.sh sesiones           quién está conectado y últimos accesos
#   ./listar.sh sistema            SO, kernel y versiones de la pila LAMP
# ============================================================

set -euo pipefail

DIR_SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/comun.sh
source "$DIR_SCRIPT/lib/comun.sh"

ayuda() {
    mostrar_ayuda "${BASH_SOURCE[0]}"
}

# ── Usuarios ────────────────────────────────────────────────
listar_usuarios() {
    local todos=0 umin=$TORNALYX_UID_MIN
    [[ ${1:-} == --todos ]] && { todos=1; umin=0; }

    if [[ $todos -eq 1 ]]; then
        titulo "Todas las cuentas de /etc/passwd"
    else
        titulo "Cuentas de persona (UID >= $TORNALYX_UID_MIN)"
    fi

    printf '  %-18s %-7s %-18s %-26s %s\n' USUARIO UID GRUPO HOME SHELL
    printf '  %s\n' "-----------------------------------------------------------------------------------------"

    # getent devuelve las mismas 7 columnas que /etc/passwd:
    # usuario:x:uid:gid:comentario:home:shell
    local linea usuario uid gid home shell grupo
    while IFS=: read -r usuario _ uid gid _ home shell; do
        [[ $uid -lt $umin ]] && continue
        grupo=$(getent group "$gid" | cut -d: -f1)
        printf '  %-18s %-7s %-18s %-26s %s\n' \
            "$usuario" "$uid" "${grupo:-$gid}" "$home" "$shell"
    done < <(getent passwd | sort -t: -k3 -n)

    printf '\n  Total: %s cuenta(s)\n' \
        "$(getent passwd | awk -F: -v m="$umin" '$3 >= m' | wc -l)"
}

# ── Grupos ──────────────────────────────────────────────────
listar_grupos() {
    local todos=0 gmin=$TORNALYX_UID_MIN
    [[ ${1:-} == --todos ]] && { todos=1; gmin=0; }

    if [[ $todos -eq 1 ]]; then
        titulo "Todos los grupos de /etc/group"
    else
        titulo "Grupos de trabajo (GID >= $TORNALYX_UID_MIN) y grupos del proyecto"
    fi

    printf '  %-20s %-8s %s\n' GRUPO GID MIEMBROS
    printf '  %s\n' "-------------------------------------------------------------------------"

    local grupo gid miembros
    while IFS=: read -r grupo _ gid miembros; do
        # Los grupos del sistema se ocultan salvo --todos, con la excepción
        # de wheel y apache: son parte del diseño de roles del proyecto.
        if [[ $gid -lt $gmin && $grupo != wheel && $grupo != apache ]]; then
            continue
        fi
        printf '  %-20s %-8s %s\n' "$grupo" "$gid" "${miembros:-—}"
    done < <(getent group | sort -t: -k3 -n)
}

# ── Cuentas del proyecto ────────────────────────────────────
listar_proyecto() {
    titulo "Cuentas del estudio de roles de Tornalyx"
    printf '  %-18s %-7s %-17s %-24s %s\n' USUARIO UID GRUPO GRUPOS_SEC ESTADO
    printf '  %s\n' "----------------------------------------------------------------------------------"

    local usuario estado
    for usuario in "${TORNALYX_CUENTAS[@]}"; do
        if ! existe_usuario "$usuario"; then
            printf '  %-18s %sfalta crearla (corré crear_roles.sh)%s\n' "$usuario" "$C_ROJO" "$C_RESET"
            continue
        fi

        estado='activa'
        if [[ ${EUID:-$(id -u)} -eq 0 ]] && hay_comando passwd; then
            case "$(passwd -S "$usuario" 2>/dev/null | awk '{print $2}')" in
                L|LK) estado='bloqueada' ;;
                NP)   estado='sin contraseña' ;;
            esac
        fi
        [[ "$(shell_de "$usuario")" == */nologin ]] && estado='servicio (nologin)'

        printf '  %-18s %-7s %-17s %-24s %s\n' \
            "$usuario" \
            "$(uid_de "$usuario")" \
            "$(grupo_principal_de "$usuario")" \
            "$(grupos_secundarios_de "$usuario" || echo '—')" \
            "$estado"
    done

    [[ ${EUID:-$(id -u)} -eq 0 ]] || printf '\n  %s(el estado de las contraseñas solo se ve como root)%s\n' "$C_GRIS" "$C_RESET"
}

# ── Sesiones ────────────────────────────────────────────────
listar_sesiones() {
    titulo "Sesiones abiertas"
    if hay_comando who; then
        who || true
        [[ -z "$(who 2>/dev/null)" ]] && msg_info "No hay sesiones interactivas abiertas."
    else
        msg_warn "El comando 'who' no está disponible en este sistema."
    fi

    titulo "Últimos accesos de las cuentas del proyecto"
    if hay_comando lastlog; then
        local usuario
        for usuario in "${TORNALYX_CUENTAS[@]}"; do
            existe_usuario "$usuario" && lastlog -u "$usuario" 2>/dev/null | tail -n +2
        done
    else
        msg_warn "El comando 'lastlog' no está disponible en este sistema."
    fi
}

# ── Entorno ─────────────────────────────────────────────────
listar_sistema() {
    titulo "Sistema operativo"
    if [[ -r /etc/os-release ]]; then
        # shellcheck disable=SC1091
        printf '  Distribución  %s\n' "$(. /etc/os-release && printf '%s' "${PRETTY_NAME:-desconocida}")"
    fi
    printf '  Kernel        %s\n' "$(uname -r)"
    printf '  Arquitectura  %s\n' "$(uname -m)"
    printf '  Hostname      %s\n' "$(hostname)"

    titulo "Pila LAMP"
    local ver
    if hay_comando httpd;   then ver=$(primeras_lineas 1 httpd -v);   else ver=''; fi
    [[ -z $ver ]] && hay_comando apache2 && ver=$(primeras_lineas 1 apache2 -v)
    printf '  Apache        %s\n' "${ver:-no instalado}"

    if hay_comando php; then ver=$(php -r 'echo PHP_VERSION;' 2>/dev/null); else ver=''; fi
    printf '  PHP           %s\n' "${ver:-no instalado}"
    if [[ -n $ver ]] && [[ "${ver%%.*}" -lt 8 ]]; then
        msg_warn "Tornalyx necesita PHP 8.0+. En AlmaLinux: sudo dnf module reset php && sudo dnf module enable php:8.2"
    fi

    if hay_comando mysql; then ver=$(mysql --version 2>/dev/null); else ver=''; fi
    [[ -z $ver ]] && hay_comando mariadb && ver=$(mariadb --version 2>/dev/null)
    printf '  MySQL         %s\n' "${ver:-no instalado}"

    titulo "Raíz del proyecto"
    if [[ -d $TORNALYX_RAIZ ]]; then
        printf '  %s\n' "$TORNALYX_RAIZ"
        ls -ld "$TORNALYX_RAIZ" | sed 's/^/  /'
        printf '  Tamaño        %s\n' "$(du -sh "$TORNALYX_RAIZ" 2>/dev/null | cut -f1)"
    else
        msg_warn "$TORNALYX_RAIZ no existe en este equipo."
    fi
}

# ── Resumen general ─────────────────────────────────────────
resumen() {
    listar_proyecto
    listar_grupos
    listar_sistema
}

# ── Despacho ────────────────────────────────────────────────
accion=${1:-resumen}
shift || true
case $accion in
    resumen)   resumen ;;
    usuarios)  listar_usuarios "$@" ;;
    grupos)    listar_grupos "$@" ;;
    proyecto)  listar_proyecto ;;
    sesiones)  listar_sesiones ;;
    sistema)   listar_sistema ;;
    -h|--help) ayuda ;;
    *)         abortar "Acción desconocida: $accion (probá --help)" ;;
esac
