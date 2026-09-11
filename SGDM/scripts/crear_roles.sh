#!/bin/bash
# ============================================================
# TORNALYX SGDM — crear_roles.sh
# Creación masiva e idempotente de las cuentas y grupos del servidor
# definidos en el estudio de mínimo privilegio (sistemas-operativos.html).
#
# Idempotente: se puede correr todas las veces que haga falta. Lo que ya
# existe se deja como está (o se ajusta si quedó desalineado), lo que
# falta se crea. Nunca borra cuentas.
#
# Uso:
#   sudo ./crear_roles.sh                 # crea/ajusta todo
#   sudo ./crear_roles.sh --dry-run       # muestra qué haría, sin tocar nada
#   sudo ./crear_roles.sh --estricto      # además quita grupos secundarios no declarados
#   sudo ./crear_roles.sh --sin-password  # no asigna contraseñas temporales
#   sudo ./crear_roles.sh --sin-sudoers   # no instala /etc/sudoers.d/tornalyx
# ============================================================

set -euo pipefail

DIR_SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/comun.sh
source "$DIR_SCRIPT/lib/comun.sh"

# ── Definición de roles ──────────────────────────────────────
# usuario | uid | grupo_principal | grupos_secundarios | shell | home | comentario
USUARIOS=(
"admin_tornalyx|1001|admin_tornalyx|wheel,apache|/bin/bash|/home/admin_tornalyx|Administrador del servidor"
"operador_web|1002|apache||/bin/bash|/home/operador_web|Operador de la aplicacion web"
"dev_tornalyx|1003|dev_tornalyx|apache|/bin/bash|/home/dev_tornalyx|Desarrollo y pruebas"
"backup_tornalyx|1004|backup_tornalyx||/sbin/nologin|/var/backups/tornalyx|Cuenta de servicio para respaldos"
)

# Grupos que deben existir aunque no sean el grupo principal de nadie.
GRUPOS_REQUERIDOS=(wheel apache)

ESTRICTO=0
ASIGNAR_PASSWORD=1
INSTALAR_SUDOERS=1

# Contraseñas temporales generadas en esta corrida, para el resumen final.
declare -a CREDENCIALES_NUEVAS=()

ayuda() {
    mostrar_ayuda "${BASH_SOURCE[0]}"
}

while [[ $# -gt 0 ]]; do
    case $1 in
        --dry-run|-n)   TORNALYX_SIMULAR=1 ;;
        --estricto)     ESTRICTO=1 ;;
        --sin-password) ASIGNAR_PASSWORD=0 ;;
        --sin-sudoers)  INSTALAR_SUDOERS=0 ;;
        -h|--help)      ayuda ;;
        *)              abortar "Opción desconocida: $1 (probá --help)" ;;
    esac
    shift
done

[[ -n "${TORNALYX_SIMULAR:-}" ]] || requiere_root

# ── 1. Grupos requeridos ─────────────────────────────────────
crear_grupos_requeridos() {
    titulo "1. Grupos requeridos del sistema"
    local grupo
    for grupo in "${GRUPOS_REQUERIDOS[@]}"; do
        if existe_grupo "$grupo"; then
            msg_info "El grupo $grupo ya existe (GID $(getent group "$grupo" | cut -d: -f3))."
        else
            ejecutar groupadd "$grupo"
            msg_ok "Grupo $grupo creado."
            registrar_log "groupadd $grupo"
        fi
    done
}

# ── 2. Grupo principal de cada cuenta ────────────────────────
# Convención del estudio de roles: cuando el grupo principal se llama
# igual que el usuario, se crea con el mismo número que el UID para que
# la dupla UID/GID sea fácil de leer en un `ls -n`. Si ese GID ya está
# tomado por otro grupo, se crea igual con el que asigne el sistema.
asegurar_grupo_principal() {
    local usuario=$1 uid=$2 grupo=$3
    existe_grupo "$grupo" && return 0

    if [[ $grupo == "$usuario" ]] && ! getent group "$uid" >/dev/null 2>&1; then
        ejecutar groupadd -g "$uid" "$grupo"
        msg_ok "Grupo principal $grupo creado con GID $uid."
    else
        ejecutar groupadd "$grupo"
        msg_ok "Grupo principal $grupo creado."
    fi
    registrar_log "groupadd $grupo (principal de $usuario)"
}

# ── 3. Alta o ajuste de la cuenta ────────────────────────────
crear_usuario() {
    local usuario=$1 uid=$2 grupo=$3 secundarios=$4 shell=$5 home=$6 comentario=$7
    local args=(-u "$uid" -g "$grupo" -s "$shell" -d "$home" -m -c "$comentario")

    if getent passwd "$uid" >/dev/null 2>&1; then
        msg_warn "El UID $uid ya lo usa $(usuario_por_uid "$uid"); $usuario se crea con el UID que asigne el sistema."
        args=(-g "$grupo" -s "$shell" -d "$home" -m -c "$comentario")
    fi
    [[ -n $secundarios ]] && args+=(-G "$secundarios")

    # useradd -m no crea directorios intermedios: para un home fuera de
    # /home (backup_tornalyx vive en /var/backups/tornalyx) falla con
    # "cannot create directory" y deja la cuenta a medio hacer.
    ejecutar mkdir -p "$(dirname "$home")"

    ejecutar useradd "${args[@]}" "$usuario"
    msg_ok "Cuenta $usuario creada (grupo $grupo, shell $shell)."
    registrar_log "useradd $usuario uid=$uid grupo=$grupo shell=$shell"
}

ajustar_usuario() {
    local usuario=$1 grupo=$3 secundarios=$4 shell=$5 home=$6 comentario=$7
    local cambios=()

    [[ "$(shell_de "$usuario")" == "$shell" ]] || cambios+=(-s "$shell")
    [[ "$(home_de  "$usuario")" == "$home"  ]] || cambios+=(-d "$home")
    [[ "$(grupo_principal_de "$usuario")" == "$grupo" ]] || cambios+=(-g "$grupo")

    if [[ ${#cambios[@]} -gt 0 ]]; then
        ejecutar usermod "${cambios[@]}" -c "$comentario" "$usuario"
        msg_ok "Cuenta $usuario ajustada a lo declarado (${cambios[*]})."
        registrar_log "usermod $usuario ${cambios[*]}"
    else
        msg_info "La cuenta $usuario ya coincide con el estudio de roles."
    fi

    sincronizar_secundarios "$usuario" "$secundarios"
}

# Suma los grupos secundarios declarados que falten. En modo --estricto
# además quita los que no estén declarados; por defecto solo los reporta,
# porque un grupo agregado a mano puede ser deliberado.
sincronizar_secundarios() {
    local usuario=$1 declarados=$2 grupo actuales
    actuales=$(grupos_secundarios_de "$usuario")

    if [[ -n $declarados ]]; then
        while IFS= read -r grupo; do
            [[ -z $grupo ]] && continue
            if esta_en_grupo "$usuario" "$grupo"; then
                continue
            fi
            ejecutar usermod -aG "$grupo" "$usuario"
            msg_ok "$usuario agregado al grupo secundario $grupo."
            registrar_log "usermod -aG $grupo $usuario"
        done < <(tr ',' '\n' <<< "$declarados")
    fi

    [[ -z $actuales ]] && return 0
    while IFS= read -r grupo; do
        [[ -z $grupo ]] && continue
        if [[ ",$declarados," == *",$grupo,"* ]]; then
            continue
        fi
        if [[ $ESTRICTO -eq 1 ]]; then
            ejecutar gpasswd -d "$usuario" "$grupo"
            msg_ok "$usuario quitado del grupo no declarado $grupo (modo estricto)."
            registrar_log "gpasswd -d $usuario $grupo"
        else
            msg_warn "$usuario pertenece a $grupo, que no está en el estudio de roles (usá --estricto para quitarlo)."
        fi
    done < <(tr ',' '\n' <<< "$actuales")
}

# ── 4. Contraseña inicial y caducidad ────────────────────────
# Las cuentas de servicio (nologin) quedan bloqueadas: no se loguean.
# Las interactivas reciben una contraseña temporal que caduca en el
# primer inicio de sesión (chage -d 0), como pide el estudio.
configurar_password() {
    local usuario=$1 shell=$2 estado password

    if [[ $shell == */nologin || $shell == */false ]]; then
        # usermod -L funciona aunque falte el paquete `passwd`, que en una
        # instalación mínima puede no estar.
        ejecutar usermod -L "$usuario"
        msg_info "$usuario es cuenta de servicio: login bloqueado."
        return 0
    fi

    if [[ $ASIGNAR_PASSWORD -ne 1 ]]; then
        msg_info "$usuario: se omite la contraseña (--sin-password)."
        return 0
    fi

    if [[ -n "${TORNALYX_SIMULAR:-}" ]]; then
        ejecutar chpasswd "(contraseña temporal para $usuario)"
        ejecutar chage -d 0 "$usuario"
        return 0
    fi

    if ! hay_comando chpasswd; then
        msg_warn "$usuario: falta 'chpasswd' (dnf install passwd shadow-utils); asignale la contraseña a mano."
        return 0
    fi

    # Solo se toca la contraseña si la cuenta todavía no tiene una: de lo
    # contrario cada corrida del script le cambiaría la clave al admin.
    # El `|| true` evita que un `passwd` ausente aborte todo el script.
    estado=$(passwd -S "$usuario" 2>/dev/null | awk '{print $2}' || true)
    if [[ $estado == P || $estado == PS ]]; then
        msg_info "$usuario ya tiene contraseña definida: no se toca."
        return 0
    fi

    password=$(generar_password)
    printf '%s:%s\n' "$usuario" "$password" | chpasswd
    chage -d 0 "$usuario"
    CREDENCIALES_NUEVAS+=("$usuario|$password")
    msg_ok "$usuario: contraseña temporal asignada, se pedirá cambiarla en el primer login."
    registrar_log "chpasswd+chage $usuario (contraseña temporal)"
}

# ── 5. Permisos del home ─────────────────────────────────────
asegurar_home() {
    local usuario=$1 grupo=$2 home=$3
    if [[ -n "${TORNALYX_SIMULAR:-}" ]]; then
        ejecutar chmod 750 "$home"
        return 0
    fi
    [[ -d $home ]] || mkdir -p "$home"
    chown "$usuario:$grupo" "$home"
    chmod 750 "$home"
}

# ── 6. Reglas de sudo ────────────────────────────────────────
# admin_tornalyx hereda sudo completo por pertenecer a wheel. operador_web
# necesita exactamente dos cosas: reiniciar el servidor web y correr el
# despliegue/migraciones. Se instala como archivo aparte en sudoers.d y se
# valida con visudo ANTES de dejarlo en su lugar: un sudoers roto deja el
# servidor sin forma de escalar privilegios.
instalar_sudoers() {
    titulo "3. Reglas de sudo (mínimo privilegio)"
    if [[ $INSTALAR_SUDOERS -ne 1 ]]; then
        msg_info "Omitido por --sin-sudoers."
        return 0
    fi

    local destino=/etc/sudoers.d/tornalyx temporal
    if [[ ! -d /etc/sudoers.d ]]; then
        if ! hay_comando sudo; then
            msg_warn "sudo no está instalado (dnf install sudo): no hay dónde instalar las reglas."
            return 0
        fi
        ejecutar mkdir -p /etc/sudoers.d
    fi
    temporal=$(mktemp)
    cat > "$temporal" <<'SUDOERS'
# Generado por crear_roles.sh (Tornalyx SGDM). No editar a mano:
# volver a correr `sudo ./crear_roles.sh` regenera este archivo.

# Comandos que el operador del sitio necesita para desplegar y para
# recuperar el servicio, y nada más.
Cmnd_Alias TORNALYX_WEB = /usr/bin/systemctl restart httpd, \
                          /usr/bin/systemctl reload httpd, \
                          /usr/bin/systemctl status httpd, \
                          /usr/bin/systemctl start httpd, \
                          /usr/bin/systemctl stop httpd
Cmnd_Alias TORNALYX_DEPLOY = /var/www/tornalyx/SGDM/scripts/desplegar.sh, \
                             /var/www/tornalyx/SGDM/scripts/permisos.sh

operador_web ALL=(root) TORNALYX_WEB, TORNALYX_DEPLOY

# La cuenta de respaldo corre por cron; solo necesita poder ejecutar su
# propio script sin que le pidan contraseña.
backup_tornalyx ALL=(root) NOPASSWD: /var/www/tornalyx/SGDM/scripts/respaldo.sh
SUDOERS

    if [[ -n "${TORNALYX_SIMULAR:-}" ]]; then
        msg_info "(simulado) se instalaría $destino"
        rm -f "$temporal"
        return 0
    fi

    if hay_comando visudo && ! visudo -cqf "$temporal"; then
        rm -f "$temporal"
        msg_error "El archivo de sudoers generado no es válido: no se instaló nada."
        return 1
    fi

    install -o root -g root -m 0440 "$temporal" "$destino"
    rm -f "$temporal"
    msg_ok "Reglas instaladas en $destino."
    registrar_log "sudoers instalado en $destino"
}

# ── Programa principal ───────────────────────────────────────
main() {
    titulo "Tornalyx — creación de cuentas y grupos del servidor"
    [[ -n "${TORNALYX_SIMULAR:-}" ]] && msg_warn "MODO SIMULACIÓN: no se modifica nada."

    crear_grupos_requeridos

    titulo "2. Cuentas del estudio de roles"
    local linea usuario uid grupo secundarios shell home comentario
    for linea in "${USUARIOS[@]}"; do
        IFS='|' read -r usuario uid grupo secundarios shell home comentario <<< "$linea"

        printf '\n%s%s%s (UID %s)\n' "$C_NEGRITA" "$usuario" "$C_RESET" "$uid"
        asegurar_grupo_principal "$usuario" "$uid" "$grupo"

        if existe_usuario "$usuario"; then
            ajustar_usuario "$usuario" "$uid" "$grupo" "$secundarios" "$shell" "$home" "$comentario"
        else
            crear_usuario "$usuario" "$uid" "$grupo" "$secundarios" "$shell" "$home" "$comentario"
        fi

        configurar_password "$usuario" "$shell"
        asegurar_home "$usuario" "$grupo" "$home"
    done

    instalar_sudoers

    titulo "Resultado"
    for linea in "${USUARIOS[@]}"; do
        IFS='|' read -r usuario _ _ _ _ _ _ <<< "$linea"
        if existe_usuario "$usuario"; then
            printf '  %-18s UID %-6s grupos: %s\n' \
                "$usuario" "$(uid_de "$usuario")" "$(id -nG "$usuario" 2>/dev/null | tr ' ' ',')"
        else
            printf '  %-18s %sno existe%s\n' "$usuario" "$C_ROJO" "$C_RESET"
        fi
    done

    if [[ ${#CREDENCIALES_NUEVAS[@]} -gt 0 ]]; then
        titulo "Contraseñas temporales (se piden cambiar en el primer login)"
        msg_warn "Anotalas ahora: no se vuelven a mostrar ni quedan en la bitácora."
        for linea in "${CREDENCIALES_NUEVAS[@]}"; do
            printf '  %-18s %s\n' "${linea%%|*}" "${linea#*|}"
        done
    fi

    printf '\n'
    msg_ok "Listo. Bitácora en $TORNALYX_LOG"
}

main "$@"
