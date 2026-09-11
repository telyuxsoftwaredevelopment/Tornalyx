#!/bin/bash
# ============================================================
# TORNALYX SGDM — gestion.sh
# Menú principal de administración del servidor.
#
# Centraliza el resto de la suite: no implementa lógica propia, delega
# en el script que corresponde a cada tarea. Así cada script sigue
# sirviendo por separado (para cron o para automatizar) y este es solo
# la puerta de entrada cómoda.
#
# Uso:
#   sudo ./gestion.sh              menú interactivo
#   ./gestion.sh estado            panorama rápido del servidor
#   ./gestion.sh bitacora [n]      últimas n líneas de la bitácora
#   ./gestion.sh doctor            diagnóstico completo del entorno
# ============================================================

set -euo pipefail

DIR_SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/comun.sh
source "$DIR_SCRIPT/lib/comun.sh"

ayuda() {
    mostrar_ayuda "${BASH_SOURCE[0]}"
}

# Llama a un script hermano avisando con claridad si falta o no es
# ejecutable, en vez de morir con un "command not found".
invocar() {
    local script="$DIR_SCRIPT/$1"
    shift
    if [[ ! -f $script ]]; then
        msg_error "No se encuentra $script"
        return 1
    fi
    if [[ ! -x $script ]]; then
        # Un clone de git en Windows o un unzip pueden perder el bit +x.
        msg_warn "$(basename "$script") no era ejecutable; se corre con bash."
        bash "$script" "$@"
        return $?
    fi
    "$script" "$@"
}

banner() {
    printf '\n%s' "$C_ROJO"
    cat <<'ARTE'
  ┌────────────────────────────────────────────────┐
  │   TORNALYX · SGDM                              │
  │   Administración del servidor                  │
  └────────────────────────────────────────────────┘
ARTE
    printf '%s' "$C_RESET"
    printf '  %s · %s · %s\n' \
        "$(hostname)" \
        "$(date '+%Y-%m-%d %H:%M')" \
        "$([[ ${EUID:-$(id -u)} -eq 0 ]] && printf 'root' || printf '%s (sin privilegios)' "${USER:-?}")"
}

# ── Panorama rápido ─────────────────────────────────────────
estado_general() {
    invocar listar.sh proyecto || true
    invocar servicios.sh estado || true

    titulo "Permisos del proyecto"
    invocar permisos.sh verificar || true

    titulo "Últimos respaldos"
    invocar respaldo.sh listar || true

    titulo "Tareas programadas"
    invocar cron.sh estado || true
}

bitacora() {
    local lineas=${1:-30}
    titulo "Bitácora ($TORNALYX_LOG)"
    if [[ -r $TORNALYX_LOG ]]; then
        tail -n "$lineas" "$TORNALYX_LOG"
    elif [[ -f $TORNALYX_LOG ]]; then
        msg_warn "La bitácora existe pero no tenés permiso para leerla (probá con sudo)."
    else
        msg_info "Todavía no hay bitácora: se crea con la primera operación."
    fi
}

# ── Diagnóstico ─────────────────────────────────────────────
# Chequea de una sola pasada todo lo que suele fallar en un servidor
# recién armado, para no tener que ir script por script.
doctor() {
    local fallos=0

    titulo "1. Scripts de la suite"
    local script
    for script in gestion.sh crear_roles.sh usuarios.sh grupos.sh registrar_grupo.sh \
                  listar.sh servicios.sh permisos.sh desplegar.sh respaldo.sh monitoreo_bd.sh; do
        if [[ ! -f "$DIR_SCRIPT/$script" ]]; then
            msg_error "Falta $script"
            fallos=$((fallos + 1))
        elif ! bash -n "$DIR_SCRIPT/$script" 2>/dev/null; then
            msg_error "$script tiene errores de sintaxis"
            fallos=$((fallos + 1))
        elif [[ ! -x "$DIR_SCRIPT/$script" ]]; then
            msg_warn "$script no es ejecutable (corregí con: chmod +x $DIR_SCRIPT/*.sh)"
        else
            msg_ok "$script"
        fi
    done

    titulo "2. Cuentas del estudio de roles"
    local usuario
    for usuario in "${TORNALYX_CUENTAS[@]}"; do
        if existe_usuario "$usuario"; then
            msg_ok "$usuario (UID $(uid_de "$usuario"))"
        else
            msg_error "Falta la cuenta $usuario — corré: sudo ./crear_roles.sh"
            fallos=$((fallos + 1))
        fi
    done

    titulo "3. Árbol del proyecto"
    if [[ -d $TORNALYX_RAIZ ]]; then
        msg_ok "$TORNALYX_RAIZ existe"
        invocar permisos.sh verificar >/dev/null 2>&1 \
            && msg_ok "Permisos correctos" \
            || { msg_warn "Los permisos tienen observaciones (mirá: ./permisos.sh verificar)"; fallos=$((fallos + 1)); }
    else
        msg_error "No existe $TORNALYX_RAIZ"
        fallos=$((fallos + 1))
    fi

    titulo "4. Herramientas necesarias"
    local herramienta
    for herramienta in useradd usermod groupadd gpasswd chage getent find; do
        hay_comando "$herramienta" && msg_ok "$herramienta" || { msg_error "Falta $herramienta"; fallos=$((fallos + 1)); }
    done
    for herramienta in systemctl passwd chpasswd mysql mysqldump curl; do
        hay_comando "$herramienta" && msg_ok "$herramienta" || msg_warn "Falta $herramienta (algunas funciones quedan limitadas)"
    done

    titulo "5. Tareas programadas"
    if [[ -f /etc/cron.d/tornalyx ]]; then
        msg_ok "Las tareas de cron están instaladas."
        # Un respaldo que no corre es peor que no tener respaldo: da
        # falsa tranquilidad. Por eso el doctor mira la fecha del último.
        invocar cron.sh estado 2>/dev/null | grep -E "respaldo diario|último respaldo|No hay ningún respaldo" || true
    else
        msg_warn "No hay tareas programadas (instalalas con: sudo ./cron.sh instalar)"
    fi

    titulo "6. Servicios"
    invocar servicios.sh estado || true

    printf '\n'
    if [[ $fallos -eq 0 ]]; then
        msg_ok "Diagnóstico sin problemas críticos."
        return 0
    fi
    msg_error "$fallos problema(s) crítico(s). Revisá el detalle de arriba."
    return 1
}

# ── Menú principal ──────────────────────────────────────────
menu() {
    local opcion
    while true; do
        banner
        titulo "Menú principal"
        cat <<'OPCIONES'
  --- Cuentas y grupos ---
  1) Usuarios (altas, bajas, modificaciones)
  2) Grupos
  3) Pertenencia a grupos
  4) Crear/sincronizar las cuentas del estudio de roles

  --- Servidor ---
  5) Servicios (Apache, MySQL, firewall)
  6) Permisos del proyecto
  7) Listados y consultas

  --- Aplicación y datos ---
  8) Desplegar la aplicación
  9) Respaldos
 10) Monitoreo de la base de datos
 11) Tareas programadas (cron)

  --- Otros ---
 12) Estado general del servidor
 13) Diagnóstico del entorno (doctor)
 14) Ver la bitácora
  0) Salir
OPCIONES
        read -r -p "Opción: " opcion
        case $opcion in
            1)  invocar usuarios.sh        || true ;;
            2)  invocar grupos.sh          || true ;;
            3)  invocar registrar_grupo.sh || true ;;
            4)  invocar crear_roles.sh     || true; pausa ;;
            5)  invocar servicios.sh       || true ;;
            6)  submenu_permisos ;;
            7)  submenu_listados ;;
            8)  submenu_despliegue ;;
            9)  submenu_respaldos ;;
            10) submenu_monitoreo ;;
            11) submenu_cron ;;
            12) estado_general; pausa ;;
            13) doctor || true; pausa ;;
            14) bitacora 40; pausa ;;
            0)  msg_info "Hasta luego."; return 0 ;;
            *)  msg_warn "Opción inválida."; pausa ;;
        esac
    done
}

submenu_permisos() {
    local opcion
    titulo "Permisos del proyecto"
    cat <<'OPCIONES'
  1) Verificar (no cambia nada)
  2) Mostrar el estado actual
  3) Aplicar el modelo de permisos
  4) Simular la aplicación (--dry-run)
  0) Volver
OPCIONES
    read -r -p "Opción: " opcion
    case $opcion in
        1) invocar permisos.sh verificar || true ;;
        2) invocar permisos.sh mostrar   || true ;;
        3) invocar permisos.sh aplicar   || true ;;
        4) invocar permisos.sh aplicar --dry-run || true ;;
        0) return 0 ;;
        *) msg_warn "Opción inválida." ;;
    esac
    pausa
}

submenu_listados() {
    local opcion
    titulo "Listados y consultas"
    cat <<'OPCIONES'
  1) Cuentas del proyecto
  2) Todas las cuentas de persona
  3) Grupos
  4) Sesiones y últimos accesos
  5) Entorno (SO, kernel, pila LAMP)
  0) Volver
OPCIONES
    read -r -p "Opción: " opcion
    case $opcion in
        1) invocar listar.sh proyecto ;;
        2) invocar listar.sh usuarios ;;
        3) invocar listar.sh grupos ;;
        4) invocar listar.sh sesiones ;;
        5) invocar listar.sh sistema ;;
        0) return 0 ;;
        *) msg_warn "Opción inválida." ;;
    esac
    pausa
}

submenu_despliegue() {
    local opcion
    titulo "Despliegue"
    cat <<'OPCIONES'
  1) Ver qué migraciones faltan
  2) Despliegue completo (código + migraciones + permisos + reload)
  3) Solo migraciones
  4) Solo código (sin tocar la base)
  5) Simular el despliegue completo
  0) Volver
OPCIONES
    read -r -p "Opción: " opcion
    case $opcion in
        1) invocar desplegar.sh estado      || true ;;
        2) invocar desplegar.sh             || true ;;
        3) invocar desplegar.sh solo-migrar || true ;;
        4) invocar desplegar.sh solo-codigo || true ;;
        5) invocar desplegar.sh --dry-run   || true ;;
        0) return 0 ;;
        *) msg_warn "Opción inválida." ;;
    esac
    pausa
}

submenu_respaldos() {
    local opcion archivo
    titulo "Respaldos"
    cat <<'OPCIONES'
  1) Listar respaldos existentes
  2) Respaldo completo (base + archivos)
  3) Respaldo solo de la base
  4) Restaurar desde un respaldo
  0) Volver
OPCIONES
    read -r -p "Opción: " opcion
    case $opcion in
        1) invocar respaldo.sh listar || true ;;
        2) invocar respaldo.sh        || true ;;
        3) invocar respaldo.sh base   || true ;;
        4)
            invocar respaldo.sh listar || true
            pedir_dato archivo "Nombre del archivo a restaurar"
            [[ -n $archivo ]] && { invocar respaldo.sh restaurar "$archivo" || true; }
            ;;
        0) return 0 ;;
        *) msg_warn "Opción inválida." ;;
    esac
    pausa
}

submenu_monitoreo() {
    local opcion
    titulo "Monitoreo de la base de datos"
    cat <<'OPCIONES'
  1) Informe completo
  2) Conexiones abiertas
  3) Tamaño de las tablas
  4) Consultas lentas
  5) Chequeo de alertas (modo cron)
  0) Volver
OPCIONES
    read -r -p "Opción: " opcion
    case $opcion in
        1) invocar monitoreo_bd.sh            || true ;;
        2) invocar monitoreo_bd.sh conexiones || true ;;
        3) invocar monitoreo_bd.sh tablas     || true ;;
        4) invocar monitoreo_bd.sh lentas     || true ;;
        5) invocar monitoreo_bd.sh alerta && msg_ok "Sin alertas." || true ;;
        0) return 0 ;;
        *) msg_warn "Opción inválida." ;;
    esac
    pausa
}

submenu_cron() {
    local opcion
    titulo "Tareas programadas (cron)"
    cat <<'OPCIONES'
  1) Ver las tareas instaladas
  2) Estado (servicio, último respaldo, registros)
  3) Instalar o actualizar las tareas
  4) Simular la instalación (--dry-run)
  5) Probar una tarea ahora, con el entorno de cron
  6) Credenciales de las tareas
  7) Desinstalar las tareas
  0) Volver
OPCIONES
    read -r -p "Opción: " opcion
    case $opcion in
        1) invocar cron.sh ver    || true ;;
        2) invocar cron.sh estado || true ;;
        3) invocar cron.sh instalar || true ;;
        4) invocar cron.sh instalar --dry-run || true ;;
        5)
            local tarea
            pedir_dato tarea "Tarea a probar (respaldo, monitoreo o permisos)" respaldo
            invocar cron.sh probar "$tarea" || true
            ;;
        6) invocar cron.sh credenciales || true ;;
        7) invocar cron.sh quitar || true ;;
        0) return 0 ;;
        *) msg_warn "Opción inválida." ;;
    esac
    pausa
}

# ── Despacho ────────────────────────────────────────────────
accion=${1:-menu}
shift || true
case $accion in
    menu)      menu ;;
    estado)    estado_general ;;
    bitacora)  bitacora "$@" ;;
    doctor)    doctor ;;
    -h|--help) ayuda ;;
    *)         abortar "Acción desconocida: $accion (probá --help)" ;;
esac
