#!/bin/bash
# ============================================================
# TORNALYX SGDM — servicios.sh
# Control de los servicios de sistema que sostienen la plataforma:
# el servidor web, la base de datos y el firewall.
#
# El nombre real de cada servicio cambia según la distribución
# (httpd/apache2, mysqld/mariadb), así que se resuelve en tiempo de
# ejecución en vez de dejarlo escrito a mano.
#
# Uso:
#   sudo ./servicios.sh                          menú interactivo
#   ./servicios.sh estado [servicio]             no necesita root
#   sudo ./servicios.sh iniciar|detener|reiniciar|recargar [servicio]
#   sudo ./servicios.sh habilitar|deshabilitar [servicio]
#   ./servicios.sh logs <servicio> [lineas]
#   ./servicios.sh probar                        chequea que el sitio responda
#
# Sin <servicio> las acciones se aplican a web y base de datos.
# ============================================================

set -euo pipefail

DIR_SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/comun.sh
source "$DIR_SCRIPT/lib/comun.sh"

# URL contra la que se valida que el sitio esté vivo.
: "${TORNALYX_URL_SALUD:=http://localhost/}"

ayuda() {
    mostrar_ayuda "${BASH_SOURCE[0]}"
}

hay_systemd() { hay_comando systemctl && [[ -d /run/systemd/system ]]; }

# ── Resolución de nombres de servicio ───────────────────────
# Devuelve el primero de los candidatos que systemd conozca.
primer_servicio_existente() {
    local candidato
    for candidato in "$@"; do
        if systemctl list-unit-files "$candidato.service" >/dev/null 2>&1 \
           && systemctl cat "$candidato.service" >/dev/null 2>&1; then
            printf '%s' "$candidato"
            return 0
        fi
    done
    # Ninguno instalado: se devuelve el primero para que el mensaje de
    # error mencione el nombre esperado en AlmaLinux.
    printf '%s' "$1"
}

servicio_web() { primer_servicio_existente httpd apache2; }
servicio_bd()  { primer_servicio_existente mysqld mariadb mysql; }

# Traduce un alias amistoso al nombre real de la unidad.
resolver_servicio() {
    case ${1,,} in
        web|apache|httpd)          servicio_web ;;
        bd|db|mysql|mariadb|mysqld) servicio_bd ;;
        fw|firewall|firewalld)     printf 'firewalld' ;;
        *)                         printf '%s' "$1" ;;
    esac
}

# Servicios sobre los que actúan las acciones sin argumento.
servicios_por_defecto() {
    printf '%s\n%s\n' "$(servicio_web)" "$(servicio_bd)"
}

# ── Estado ──────────────────────────────────────────────────
estado_uno() {
    local servicio=$1 activo habilitado

    if ! hay_systemd; then
        msg_warn "Sin systemd en este equipo: no se puede consultar $servicio."
        return 0
    fi

    activo=$(systemctl is-active "$servicio" 2>/dev/null || true)
    habilitado=$(systemctl is-enabled "$servicio" 2>/dev/null || true)

    local color=$C_ROJO
    [[ $activo == active ]] && color=$C_VERDE

    printf '  %-14s %s%-10s%s  arranque: %-10s' \
        "$servicio" "$color" "${activo:-desconocido}" "$C_RESET" "${habilitado:-n/d}"

    if [[ $activo == active ]]; then
        printf ' desde %s' "$(systemctl show "$servicio" -p ActiveEnterTimestamp --value 2>/dev/null | cut -d' ' -f2-3)"
    fi
    printf '\n'
}

estado() {
    titulo "Estado de los servicios"
    if [[ $# -gt 0 ]]; then
        local nombre
        for nombre in "$@"; do estado_uno "$(resolver_servicio "$nombre")"; done
    else
        local servicio
        while IFS= read -r servicio; do estado_uno "$servicio"; done < <(servicios_por_defecto)
        systemctl list-unit-files firewalld.service >/dev/null 2>&1 && estado_uno firewalld
    fi
}

# ── Acciones sobre servicios ────────────────────────────────
accion_systemctl() {
    local accion=$1
    shift
    hay_systemd || abortar "Este equipo no usa systemd: administrá los servicios a mano."
    requiere_root

    local objetivos=()
    if [[ $# -gt 0 ]]; then
        local nombre
        for nombre in "$@"; do objetivos+=("$(resolver_servicio "$nombre")"); done
    else
        local servicio
        while IFS= read -r servicio; do objetivos+=("$servicio"); done < <(servicios_por_defecto)
    fi

    local objetivo fallos=0
    for objetivo in "${objetivos[@]}"; do
        # Recargar Apache sin validar la configuración deja el servicio
        # corriendo con la config vieja y el operador creyendo que aplicó.
        if [[ $accion == reload || $accion == restart ]]; then
            validar_config_web "$objetivo" || { fallos=$((fallos + 1)); continue; }
        fi

        if systemctl "$accion" "$objetivo"; then
            msg_ok "$objetivo: $accion aplicado."
            registrar_log "systemctl $accion $objetivo"
        else
            msg_error "$objetivo: falló '$accion'. Últimas líneas del journal:"
            journalctl -u "$objetivo" -n 15 --no-pager 2>/dev/null | sed 's/^/    /' || true
            fallos=$((fallos + 1))
        fi
    done

    [[ $fallos -eq 0 ]] || return 1
    [[ $accion =~ ^(start|restart|reload)$ ]] && sleep 1 && estado "${objetivos[@]}"
    return 0
}

# Antes de reiniciar o recargar Apache se corre su propio chequeo de
# sintaxis: si la config está rota, se avisa y no se toca el servicio.
validar_config_web() {
    local servicio=$1 binario=''
    case $servicio in
        httpd)   binario=httpd ;;
        apache2) binario=apache2ctl ;;
        *)       return 0 ;;
    esac
    hay_comando "$binario" || return 0

    if "$binario" -t >/dev/null 2>&1; then
        return 0
    fi
    msg_error "La configuración de Apache tiene errores; no se toca el servicio:"
    "$binario" -t 2>&1 | sed 's/^/    /'
    return 1
}

# ── Registros ───────────────────────────────────────────────
logs() {
    local servicio lineas=${2:-50}
    servicio=$(resolver_servicio "${1:-web}")
    titulo "Últimas $lineas líneas de $servicio"
    if hay_comando journalctl; then
        journalctl -u "$servicio" -n "$lineas" --no-pager || true
    else
        msg_warn "journalctl no está disponible; buscá los logs en /var/log/."
    fi
}

# ── Prueba de extremo a extremo ─────────────────────────────
# Que el servicio esté "active" no significa que el sitio responda: acá
# se comprueba lo que realmente le importa al usuario final.
probar() {
    titulo "Prueba de servicio"
    estado

    printf '\n'
    if ! hay_comando curl; then
        msg_warn "curl no está instalado: no se puede probar $TORNALYX_URL_SALUD"
        return 0
    fi

    local codigo
    codigo=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$TORNALYX_URL_SALUD" || echo '000')
    if [[ $codigo == 200 ]]; then
        msg_ok "El sitio responde 200 en $TORNALYX_URL_SALUD"
    elif [[ $codigo == 000 ]]; then
        msg_error "No hubo respuesta de $TORNALYX_URL_SALUD (¿Apache caído o firewall cerrado?)"
        return 1
    else
        msg_error "El sitio respondió HTTP $codigo en $TORNALYX_URL_SALUD"
        return 1
    fi

    # El endpoint de torneos es el primero que toca la base: si responde,
    # la cadena Apache -> PHP -> MySQL está entera.
    codigo=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "${TORNALYX_URL_SALUD%/}/api/torneos" || echo '000')
    if [[ $codigo == 200 ]]; then
        msg_ok "El API responde 200: la conexión con la base de datos funciona."
    else
        msg_error "El API /api/torneos respondió $codigo: revisá la base de datos y el .env."
        return 1
    fi
}

# ── Menú interactivo ────────────────────────────────────────
menu() {
    local opcion objetivo
    while true; do
        titulo "Control de servicios"
        cat <<'OPCIONES'
  1) Ver estado de todos los servicios
  2) Probar el sitio de punta a punta
  3) Reiniciar el servidor web
  4) Recargar el servidor web (sin cortar conexiones)
  5) Reiniciar la base de datos
  6) Iniciar un servicio
  7) Detener un servicio
  8) Habilitar un servicio en el arranque
  9) Ver registros de un servicio
  0) Volver
OPCIONES
        read -r -p "Opción: " opcion
        case $opcion in
            1) estado ;;
            2) probar || true ;;
            3) accion_systemctl restart web || true ;;
            4) accion_systemctl reload  web || true ;;
            5) accion_systemctl restart bd  || true ;;
            6) pedir_dato objetivo "Servicio (web/bd/firewall o nombre)"; accion_systemctl start   "$objetivo" || true ;;
            7) pedir_dato objetivo "Servicio (web/bd/firewall o nombre)"; accion_systemctl stop    "$objetivo" || true ;;
            8) pedir_dato objetivo "Servicio (web/bd/firewall o nombre)"; accion_systemctl enable  "$objetivo" || true ;;
            9) pedir_dato objetivo "Servicio (web/bd/firewall o nombre)"; logs "$objetivo" ;;
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
    menu)          menu ;;
    estado)        estado "$@" ;;
    iniciar)       accion_systemctl start   "$@" ;;
    detener)       accion_systemctl stop    "$@" ;;
    reiniciar)     accion_systemctl restart "$@" ;;
    recargar)      accion_systemctl reload  "$@" ;;
    habilitar)     accion_systemctl enable  "$@" ;;
    deshabilitar)  accion_systemctl disable "$@" ;;
    logs)          logs "$@" ;;
    probar)        probar ;;
    -h|--help)     ayuda ;;
    *)             abortar "Acción desconocida: $accion (probá --help)" ;;
esac
