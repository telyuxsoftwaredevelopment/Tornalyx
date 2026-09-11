#!/bin/bash
# ============================================================
# TORNALYX SGDM — desplegar.sh
# Despliegue de la aplicación en el servidor: traer el código, aplicar
# las migraciones pendientes, reacomodar permisos y recargar Apache.
#
# Lo corre operador_web (tiene sudo acotado a este script, ver
# /etc/sudoers.d/tornalyx). Las credenciales de esquema NO salen del
# .env de la app: van en DB_DDL_USER/DB_DDL_PASS, exportadas a mano,
# porque la app en runtime no debe poder tocar la estructura de la base.
#
# Uso:
#   sudo ./desplegar.sh                 despliegue completo
#   sudo ./desplegar.sh solo-migrar     únicamente las migraciones .sql
#   sudo ./desplegar.sh solo-codigo     git pull + permisos + reload, sin SQL
#   ./desplegar.sh estado               qué migraciones faltan (no cambia nada)
#       --sin-git       no toca el repositorio (código ya copiado a mano)
#       --dry-run       muestra qué haría
#
# Variables de entorno:
#   DB_DDL_USER / DB_DDL_PASS   usuario DDL de dcl.sql (obligatorias para migrar)
#   DB_HOST / DB_PORT / DB_NAME se leen del .env si no están definidas
# ============================================================

set -euo pipefail

DIR_SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/comun.sh
source "$DIR_SCRIPT/lib/comun.sh"

APP="$TORNALYX_RAIZ/SGDM"
DIR_MIGRACIONES="$APP/base_datos/migrations"
ARCHIVO_ENV="$TORNALYX_RAIZ/.env"

USAR_GIT=1

# Códigos de error de MySQL que solo significan "esto ya estaba hecho".
# Son los mismos que ignora el migrador PHP (modelo/Migracion.php), para
# que correr las migraciones por acá o por la app dé el mismo resultado.
CODIGOS_YA_APLICADO='1050|1060|1061|1062|1091'

ayuda() {
    mostrar_ayuda "${BASH_SOURCE[0]}"
}

# ── Configuración de la base ────────────────────────────────
# Lee del .env solo lo que no venga ya del entorno, con el mismo criterio
# que loadDotEnv() en PHP: el entorno real tiene prioridad.
mysql_ddl() {
    mysql --host="${DB_HOST:-localhost}" --port="${DB_PORT:-3306}" \
          --user="$DB_DDL_USER" --password="$DB_DDL_PASS" \
          --default-character-set=utf8mb4 "$@"
}

verificar_credenciales_ddl() {
    hay_comando mysql || abortar "Falta el cliente 'mysql' (dnf install mysql)."
    [[ -n ${DB_DDL_USER:-} && -n ${DB_DDL_PASS:-} ]] || abortar \
        "Faltan DB_DDL_USER/DB_DDL_PASS. Exportalas antes de migrar:
    export DB_DDL_USER=tornalyx_ddl
    read -rs DB_DDL_PASS && export DB_DDL_PASS"
    [[ -n ${DB_NAME:-} ]] || abortar "No se pudo determinar DB_NAME (definila o completá el .env)."

    if ! mysql_ddl -e 'SELECT 1' >/dev/null 2>&1; then
        abortar "No se pudo conectar a ${DB_HOST:-localhost}:${DB_PORT:-3306} con el usuario $DB_DDL_USER."
    fi
}

# ── Migraciones ─────────────────────────────────────────────
# La tabla schema_migrations la comparten este script, el migrador PHP y
# GET /api/admin/salud; se crea acá por si la base es nueva.
asegurar_tabla_migraciones() {
    mysql_ddl "$DB_NAME" <<'SQL'
CREATE TABLE IF NOT EXISTS schema_migrations (
    filename    VARCHAR(180) NOT NULL PRIMARY KEY,
    applied_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    error       VARCHAR(500) NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
}

migraciones_registradas() {
    mysql_ddl -N -B "$DB_NAME" -e 'SELECT filename FROM schema_migrations WHERE error IS NULL' 2>/dev/null || true
}

migraciones_pendientes() {
    local registradas archivo base
    registradas=$(migraciones_registradas)
    for archivo in "$DIR_MIGRACIONES"/add_*.sql; do
        [[ -e $archivo ]] || continue
        base=$(basename "$archivo")
        grep -qxF "$base" <<< "$registradas" || printf '%s\n' "$base"
    done
}

# Ejecuta un .sql y decide si los errores eran reales o solo "ya existe".
# Se quitan las sentencias USE para respetar el DB_NAME configurado, igual
# que hace Migracion.php.
aplicar_migracion() {
    local base=$1 archivo="$DIR_MIGRACIONES/$1" salida estado=0 errores_reales

    salida=$(sed -E 's/^[[:space:]]*USE[[:space:]]+[^;]+;//I' "$archivo" \
             | mysql_ddl --force "$DB_NAME" 2>&1) || estado=$?

    # --force sigue de largo ante un error y los lista en stderr; se filtran
    # los que solo dicen que el cambio ya estaba aplicado.
    errores_reales=$(grep -i '^ERROR' <<< "$salida" | grep -Ev "ERROR ($CODIGOS_YA_APLICADO) " || true)

    if [[ -n $errores_reales ]]; then
        msg_error "$base falló:"
        sed 's/^/    /' <<< "$errores_reales"
        registrar_migracion "$base" "$(head -c 400 <<< "$errores_reales")"
        return 1
    fi

    if [[ $estado -ne 0 && -z $salida ]]; then
        msg_error "$base: el cliente mysql terminó con código $estado."
        registrar_migracion "$base" "mysql salió con código $estado"
        return 1
    fi

    registrar_migracion "$base" ''
    msg_ok "$base aplicada."
    return 0
}

# error vacío = aplicada limpia. Se usa ON DUPLICATE KEY para que un
# reintento exitoso borre el error anterior (mismo criterio que el PHP).
registrar_migracion() {
    local base=$1 error=$2 valor
    if [[ -z $error ]]; then
        valor='NULL'
    else
        valor="'$(sed "s/'/''/g; s/\\\\/\\\\\\\\/g" <<< "$error")'"
    fi
    mysql_ddl "$DB_NAME" -e \
        "INSERT INTO schema_migrations (filename, error) VALUES ('$base', $valor)
         ON DUPLICATE KEY UPDATE error = VALUES(error)" 2>/dev/null || \
        msg_warn "No se pudo registrar $base en schema_migrations."
}

migrar() {
    titulo "Migraciones de base de datos"
    cargar_credenciales "$ARCHIVO_ENV"
    verificar_credenciales_ddl

    [[ -d $DIR_MIGRACIONES ]] || abortar "No existe $DIR_MIGRACIONES."
    asegurar_tabla_migraciones

    local pendientes fallidas=0 base
    pendientes=$(migraciones_pendientes)

    if [[ -z $pendientes ]]; then
        msg_ok "La base ya está al día: no hay migraciones pendientes."
        return 0
    fi

    msg_info "Pendientes: $(wc -l <<< "$pendientes")"
    sed 's/^/    /' <<< "$pendientes"

    if [[ -n "${TORNALYX_SIMULAR:-}" ]]; then
        msg_warn "(simulado) no se aplicó ninguna."
        return 0
    fi

    # sort para aplicarlas siempre en el mismo orden alfabético que el PHP.
    while IFS= read -r base; do
        [[ -z $base ]] && continue
        aplicar_migracion "$base" || fallidas=$((fallidas + 1))
    done < <(sort <<< "$pendientes")

    registrar_log "migraciones aplicadas=$(wc -l <<< "$pendientes") fallidas=$fallidas"
    [[ $fallidas -eq 0 ]] || abortar "$fallidas migración(es) fallaron. Revisá el detalle de arriba."
    msg_ok "Base de datos al día."
}

estado_migraciones() {
    cargar_credenciales "$ARCHIVO_ENV"
    verificar_credenciales_ddl
    titulo "Estado de las migraciones"

    local pendientes con_error
    pendientes=$(migraciones_pendientes)
    con_error=$(mysql_ddl -N -B "$DB_NAME" \
        -e 'SELECT CONCAT(filename, " -> ", error) FROM schema_migrations WHERE error IS NOT NULL' 2>/dev/null || true)

    if [[ -z $pendientes ]]; then
        msg_ok "Sin migraciones pendientes."
    else
        msg_warn "Pendientes:"
        sed 's/^/    /' <<< "$pendientes"
    fi

    if [[ -n $con_error ]]; then
        msg_error "Migraciones que quedaron a medio aplicar:"
        sed 's/^/    /' <<< "$con_error"
    fi
}

# ── Código ──────────────────────────────────────────────────
actualizar_codigo() {
    titulo "Actualización del código"
    if [[ $USAR_GIT -ne 1 ]]; then
        msg_info "Omitido por --sin-git."
        return 0
    fi
    if ! hay_comando git || [[ ! -d "$TORNALYX_RAIZ/.git" ]]; then
        msg_warn "$TORNALYX_RAIZ no es un repositorio git: se salta esta etapa."
        return 0
    fi

    local rama antes despues
    rama=$(git -C "$TORNALYX_RAIZ" rev-parse --abbrev-ref HEAD)
    antes=$(git -C "$TORNALYX_RAIZ" rev-parse --short HEAD)

    # Cambios locales sin commitear se perderían de vista tras el pull:
    # mejor frenar y que alguien decida, antes que pisarlos en silencio.
    if [[ -n "$(git -C "$TORNALYX_RAIZ" status --porcelain)" ]]; then
        msg_warn "Hay cambios locales sin commitear en $TORNALYX_RAIZ:"
        git -C "$TORNALYX_RAIZ" status --short | sed 's/^/    /'
        confirmar "Continuar igual con el pull sobre la rama $rama" \
            || abortar "Despliegue cancelado por cambios locales."
    fi

    ejecutar git -C "$TORNALYX_RAIZ" pull --ff-only origin "$rama"
    despues=$(git -C "$TORNALYX_RAIZ" rev-parse --short HEAD)

    if [[ $antes == "$despues" ]]; then
        msg_info "El código ya estaba actualizado ($antes)."
    else
        msg_ok "Código actualizado: $antes -> $despues"
        git -C "$TORNALYX_RAIZ" log --oneline "$antes..$despues" | sed 's/^/    /'
    fi
    registrar_log "git pull rama=$rama $antes -> $despues"
}

# ── Etapas finales ──────────────────────────────────────────
ajustar_permisos() {
    titulo "Permisos del árbol"
    if [[ -x "$DIR_SCRIPT/permisos.sh" ]]; then
        "$DIR_SCRIPT/permisos.sh" aplicar ${TORNALYX_SIMULAR:+--dry-run} || \
            msg_warn "permisos.sh terminó con observaciones."
    else
        msg_warn "No se encontró permisos.sh: revisá los permisos a mano."
    fi
}

recargar_web() {
    titulo "Recarga del servidor web"
    if [[ -x "$DIR_SCRIPT/servicios.sh" ]]; then
        "$DIR_SCRIPT/servicios.sh" recargar web || abortar "No se pudo recargar Apache."
    else
        msg_warn "No se encontró servicios.sh: recargá Apache a mano."
    fi
}

verificar_sitio() {
    titulo "Verificación posterior al despliegue"
    if [[ -x "$DIR_SCRIPT/servicios.sh" ]]; then
        "$DIR_SCRIPT/servicios.sh" probar || abortar "El sitio no responde correctamente tras el despliegue."
    fi
}

# ── Programa principal ──────────────────────────────────────
accion=completo
for parametro in "$@"; do
    case $parametro in
        solo-migrar|solo-codigo|estado) accion=$parametro ;;
        --sin-git)      USAR_GIT=0 ;;
        --dry-run|-n)   TORNALYX_SIMULAR=1 ;;
        --si)           TORNALYX_ASUMIR_SI=1 ;;
        -h|--help)      ayuda ;;
        *)              abortar "Argumento desconocido: $parametro (probá --help)" ;;
    esac
done

case $accion in
    estado)
        estado_migraciones
        ;;
    solo-migrar)
        [[ -n "${TORNALYX_SIMULAR:-}" ]] || requiere_root
        migrar
        ;;
    solo-codigo)
        requiere_root
        actualizar_codigo
        ajustar_permisos
        recargar_web
        verificar_sitio
        ;;
    *)
        [[ -n "${TORNALYX_SIMULAR:-}" ]] || requiere_root
        titulo "Despliegue de Tornalyx en $TORNALYX_RAIZ"
        [[ -n "${TORNALYX_SIMULAR:-}" ]] && msg_warn "MODO SIMULACIÓN: no se modifica nada."
        actualizar_codigo
        migrar
        ajustar_permisos
        recargar_web
        verificar_sitio
        printf '\n'
        msg_ok "Despliegue completo."
        registrar_log "despliegue completo OK"
        ;;
esac
