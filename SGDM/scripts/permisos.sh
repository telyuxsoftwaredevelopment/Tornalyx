#!/bin/bash
# ============================================================
# TORNALYX SGDM — permisos.sh
# Propiedad y permisos octales sobre el árbol del proyecto.
#
# Modelo aplicado:
#   dueño   operador_web  (despliega y edita)
#   grupo   apache        (el servidor web solo necesita LEER)
#   dirs    750  · archivos 640   -> nada es legible por "otros"
#   almacenamiento 2770           -> única ruta escribible por apache
#   .env    640  root:apache      -> credenciales fuera del alcance del resto
#   *.sh    750                   -> los scripts siguen siendo ejecutables
#
# Las tres capas del MVC (controlador/, modelo/, vista/) viven fuera del
# DocumentRoot (SGDM/publico), así que aunque Apache pueda leerlas nunca las
# sirve; el chequeo de más abajo lo verifica.
#
# Uso:
#   sudo ./permisos.sh aplicar [--dry-run]   deja el árbol como corresponde
#   ./permisos.sh verificar                  audita sin cambiar nada
#   ./permisos.sh mostrar                    muestra el estado actual
# ============================================================

set -euo pipefail

DIR_SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/comun.sh
source "$DIR_SCRIPT/lib/comun.sh"

# Dueño y grupo del árbol. Se pueden pisar por entorno para probar la
# suite en un equipo donde esas cuentas todavía no existen.
: "${TORNALYX_DUENIO:=operador_web}"
: "${TORNALYX_GRUPO:=apache}"

APP="$TORNALYX_RAIZ/SGDM"
DOCUMENT_ROOT="$APP/publico"
DIRS_ESCRIBIBLES=("$APP/almacenamiento")
ARCHIVO_ENV="$TORNALYX_RAIZ/.env"

ayuda() {
    mostrar_ayuda "${BASH_SOURCE[0]}"
}

verificar_raiz() {
    [[ -d $TORNALYX_RAIZ ]] || abortar "No existe $TORNALYX_RAIZ (ajustá TORNALYX_RAIZ si el proyecto está en otro lado)."
    [[ -d $APP ]] || abortar "No se encuentra $APP: ¿es realmente la raíz del proyecto?"
}

# ── Aplicar ─────────────────────────────────────────────────
aplicar() {
    [[ ${1:-} == --dry-run || ${1:-} == -n ]] && TORNALYX_SIMULAR=1
    verificar_raiz
    [[ -n "${TORNALYX_SIMULAR:-}" ]] || requiere_root
    [[ -n "${TORNALYX_SIMULAR:-}" ]] && msg_warn "MODO SIMULACIÓN: no se modifica nada."

    local duenio=$TORNALYX_DUENIO grupo=$TORNALYX_GRUPO

    # Sin las cuentas creadas (crear_roles.sh) no se puede aplicar el
    # modelo; se cae a root:apache antes que dejar el árbol sin dueño.
    if ! existe_usuario "$duenio"; then
        msg_warn "El usuario $duenio no existe: se usa root como dueño (corré crear_roles.sh primero)."
        duenio=root
    fi
    if ! existe_grupo "$grupo"; then
        msg_warn "El grupo $grupo no existe: se usa root como grupo."
        grupo=root
    fi

    titulo "1. Propiedad de $TORNALYX_RAIZ"
    ejecutar chown -R "$duenio:$grupo" "$TORNALYX_RAIZ"
    msg_ok "Todo el árbol pertenece a $duenio:$grupo."

    titulo "2. Permisos base (directorios 750, archivos 640)"
    ejecutar find "$TORNALYX_RAIZ" -type d -exec chmod 750 {} +
    ejecutar find "$TORNALYX_RAIZ" -type f -exec chmod 640 {} +
    msg_ok "Nada del proyecto queda legible para 'otros'."

    titulo "3. Rutas escribibles por el servidor web"
    local dir
    for dir in "${DIRS_ESCRIBIBLES[@]}"; do
        if [[ ! -d $dir ]]; then
            ejecutar mkdir -p "$dir"
            msg_info "Creado $dir"
        fi
        ejecutar chown -R "$duenio:$grupo" "$dir"
        # setgid (2770): todo lo que Apache cree adentro hereda el grupo
        # apache, así el operador puede seguir administrándolo.
        ejecutar chmod 2770 "$dir"
        ejecutar find "$dir" -type d -exec chmod 2770 {} +
        ejecutar find "$dir" -type f -exec chmod 660 {} +
        msg_ok "$dir escribible por $grupo (2770)."
    done

    titulo "4. Scripts ejecutables"
    ejecutar find "$APP/scripts" -type f -name '*.sh' -exec chmod 750 {} +
    msg_ok "Los scripts de $APP/scripts quedaron ejecutables (750)."

    titulo "5. Archivo de credenciales"
    if [[ -f $ARCHIVO_ENV ]]; then
        ejecutar chown root:"$grupo" "$ARCHIVO_ENV"
        ejecutar chmod 640 "$ARCHIVO_ENV"
        msg_ok "$ARCHIVO_ENV solo lo leen root y $grupo."
    else
        msg_warn "No existe $ARCHIVO_ENV: la app va a usar los valores por defecto."
    fi

    aplicar_selinux "$duenio"

    printf '\n'
    registrar_log "permisos aplicados en $TORNALYX_RAIZ (dueño $duenio:$grupo)"
    msg_ok "Permisos aplicados."
    [[ -n "${TORNALYX_SIMULAR:-}" ]] || verificar
}

# ── SELinux ─────────────────────────────────────────────────
# En AlmaLinux SELinux viene activo: sin los contextos correctos Apache
# devuelve 403 aunque los permisos POSIX estén perfectos.
aplicar_selinux() {
    titulo "6. Contextos de SELinux"

    if ! hay_comando getenforce || [[ "$(getenforce 2>/dev/null)" == Disabled ]]; then
        msg_info "SELinux no está activo en este equipo: no hay contextos que aplicar."
        return 0
    fi

    if ! hay_comando semanage; then
        msg_warn "Falta 'semanage' (dnf install policycoreutils-python-utils): se omiten los contextos."
        return 0
    fi

    # Todo el árbol es contenido de solo lectura para httpd...
    ejecutar semanage fcontext -a -t httpd_sys_content_t "${TORNALYX_RAIZ}(/.*)?" 2>/dev/null || \
        ejecutar semanage fcontext -m -t httpd_sys_content_t "${TORNALYX_RAIZ}(/.*)?"

    # ...salvo la ruta donde la app necesita escribir.
    local dir
    for dir in "${DIRS_ESCRIBIBLES[@]}"; do
        ejecutar semanage fcontext -a -t httpd_sys_rw_content_t "${dir}(/.*)?" 2>/dev/null || \
            ejecutar semanage fcontext -m -t httpd_sys_rw_content_t "${dir}(/.*)?"
    done

    ejecutar restorecon -R "$TORNALYX_RAIZ"
    msg_ok "Contextos de SELinux aplicados."

    # PHP abre una conexión TCP a MySQL: sin este booleano SELinux la corta.
    if hay_comando getsebool && [[ "$(getsebool httpd_can_network_connect_db 2>/dev/null)" == *off ]]; then
        ejecutar setsebool -P httpd_can_network_connect_db on
        msg_ok "Habilitado httpd_can_network_connect_db (PHP puede conectarse a MySQL)."
    fi
}

# ── Verificar ───────────────────────────────────────────────
# Audita el árbol sin tocarlo. Devuelve != 0 si encuentra problemas, para
# poder encadenarlo en el despliegue o en un cron de control.
verificar() {
    verificar_raiz
    titulo "Auditoría de permisos"
    local problemas=0

    # 1. Nada del proyecto debería ser legible o escribible por "otros".
    local legibles escribibles
    legibles=$(find "$TORNALYX_RAIZ" -perm /o=r ! -type l 2>/dev/null | wc -l)
    escribibles=$(find "$TORNALYX_RAIZ" -perm /o=w ! -type l 2>/dev/null | wc -l)

    if [[ $legibles -gt 0 ]]; then
        msg_warn "$legibles archivo(s) legibles por cualquier usuario del servidor."
        primeras_lineas 5 find "$TORNALYX_RAIZ" -perm /o=r ! -type l | sed 's/^/    /'
        problemas=$((problemas + 1))
    else
        msg_ok "Ningún archivo es legible por 'otros'."
    fi

    if [[ $escribibles -gt 0 ]]; then
        msg_error "$escribibles archivo(s) ESCRIBIBLES por cualquier usuario: riesgo grave."
        primeras_lineas 5 find "$TORNALYX_RAIZ" -perm /o=w ! -type l | sed 's/^/    /'
        problemas=$((problemas + 1))
    else
        msg_ok "Ningún archivo es escribible por 'otros'."
    fi

    # 2. Las rutas que la app necesita escribir tienen que serlo de verdad.
    local dir
    for dir in "${DIRS_ESCRIBIBLES[@]}"; do
        if [[ ! -d $dir ]]; then
            msg_warn "Falta el directorio $dir (lo crea 'permisos.sh aplicar')."
            problemas=$((problemas + 1))
        elif [[ "$(stat -c '%A' "$dir" 2>/dev/null)" == ?rw?rw* ]]; then
            msg_ok "$dir es escribible por su grupo."
        else
            msg_error "$dir NO es escribible por el grupo ($(stat -c '%A %U:%G' "$dir" 2>/dev/null))."
            problemas=$((problemas + 1))
        fi
    done

    # 3. El .env nunca debe quedar al alcance del resto del servidor.
    if [[ -f $ARCHIVO_ENV ]]; then
        local modo_env
        modo_env=$(stat -c '%a' "$ARCHIVO_ENV" 2>/dev/null)
        if [[ $modo_env =~ [0-7][0-7][1-7]$ ]]; then
            msg_error "$ARCHIVO_ENV tiene permisos $modo_env: las credenciales están expuestas."
            problemas=$((problemas + 1))
        else
            msg_ok "$ARCHIVO_ENV protegido ($modo_env)."
        fi
    fi

    # 4. Ninguna capa del MVC puede quedar dentro del DocumentRoot: si
    #    estuviera, un fallo de mod_php serviría los .php como texto plano
    #    y quedarían a la vista las consultas y la configuración.
    local filtrados
    filtrados=$(find "$DOCUMENT_ROOT" -maxdepth 2 -type d \
                     \( -name controlador -o -name modelo -o -name vista \
                        -o -name nucleo -o -name comun -o -name base_datos \
                        -o -name migrations \) 2>/dev/null)
    if [[ -n $filtrados ]]; then
        msg_error "Hay código de servidor DENTRO del DocumentRoot:"
        sed 's/^/    /' <<< "$filtrados"
        problemas=$((problemas + 1))
    else
        msg_ok "El MVC está fuera del DocumentRoot ($DOCUMENT_ROOT)."
    fi

    # Las vistas .html importan por el mismo motivo pero al revés: adentro del
    # DocumentRoot se descargarían por su nombre de archivo, salteándose la
    # guardia de sesión que aplica PaginaController antes de imprimirlas.
    local html_sueltos
    html_sueltos=$(primeras_lineas 5 find "$DOCUMENT_ROOT" -name '*.html')
    if [[ -n $html_sueltos ]]; then
        msg_warn "Hay vistas .html dentro del DocumentRoot (se servirían sin pasar por el controlador):"
        sed 's/^/    /' <<< "$html_sueltos"
        problemas=$((problemas + 1))
    else
        msg_ok "Ninguna vista .html es alcanzable por Apache directamente."
    fi

    # El .env tampoco: si cae adentro, se descarga por HTTP con las credenciales.
    if [[ -f "$DOCUMENT_ROOT/.env" ]]; then
        msg_error "Hay un .env dentro del DocumentRoot: se puede descargar por HTTP."
        problemas=$((problemas + 1))
    fi

    # 5. Los scripts tienen que poder ejecutarse.
    local sin_ejecucion
    sin_ejecucion=$(find "$APP/scripts" -type f -name '*.sh' ! -perm -u=x 2>/dev/null | wc -l)
    if [[ $sin_ejecucion -gt 0 ]]; then
        msg_warn "$sin_ejecucion script(s) sin permiso de ejecución."
        problemas=$((problemas + 1))
    else
        msg_ok "Todos los scripts son ejecutables."
    fi

    printf '\n'
    if [[ $problemas -eq 0 ]]; then
        msg_ok "Auditoría sin observaciones."
        return 0
    fi
    msg_warn "$problemas observación(es). Corregilas con: sudo $0 aplicar"
    return 1
}

# ── Mostrar ─────────────────────────────────────────────────
mostrar() {
    verificar_raiz
    titulo "Estado actual de $TORNALYX_RAIZ"
    ls -ld "$TORNALYX_RAIZ" "$APP" "$DOCUMENT_ROOT" \
           "$APP/controlador" "$APP/modelo" "$APP/vista" \
           "$APP/nucleo" "$APP/comun" 2>/dev/null | sed 's/^/  /'

    printf '\n'
    local dir
    for dir in "${DIRS_ESCRIBIBLES[@]}"; do
        [[ -d $dir ]] && ls -ld "$dir" | sed 's/^/  /'
    done

    [[ -f $ARCHIVO_ENV ]] && { printf '\n'; ls -l "$ARCHIVO_ENV" | sed 's/^/  /'; }

    titulo "Scripts de administración"
    ls -l "$APP/scripts"/*.sh 2>/dev/null | sed 's/^/  /' || msg_warn "No hay scripts en $APP/scripts."
}

# ── Despacho ────────────────────────────────────────────────
accion=${1:-verificar}
shift || true
case $accion in
    aplicar)   aplicar "$@" ;;
    verificar) verificar ;;
    mostrar)   mostrar ;;
    -h|--help) ayuda ;;
    *)         abortar "Acción desconocida: $accion (usá aplicar, verificar o mostrar)" ;;
esac
