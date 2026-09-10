#!/usr/bin/env bash
set -euo pipefail

SCRIPT_NAME="$(basename "$0")"
DEFAULT_CONTAINER="prestashop-prestashop-1"
DEFAULT_PS_ROOT="/var/www/html"
DEFAULT_INTERVAL_MINUTES=5
MARKER_BEGIN="# >>> NeuroCheckout cron >>>"
MARKER_END="# <<< NeuroCheckout cron <<<"

RUNNER_MODE="auto"          # auto | docker | host
CONTAINER_NAME="$DEFAULT_CONTAINER"
PS_ROOT=""
INTERVAL_MINUTES="$DEFAULT_INTERVAL_MINUTES"
CRON_USER=""
REMOVE_ONLY=0
DRY_RUN=0
NO_CONFIG_UPDATE=0

usage() {
    cat <<USAGE
Usage: $SCRIPT_NAME [options]

Configure la tache cron NeuroCheckout (local + production) de facon idempotente.

Options:
  --runner <auto|docker|host>   Mode d'execution (defaut: auto)
  --container <name>            Nom du conteneur PrestaShop (defaut: $DEFAULT_CONTAINER)
  --ps-root <path>              Racine PrestaShop (defaut: $DEFAULT_PS_ROOT)
  --interval-minutes <n>        Intervalle cron en minutes (defaut: $DEFAULT_INTERVAL_MINUTES)
  --user <unix_user>            Installe le cron pour cet utilisateur (crontab -u)
  --remove                      Supprime le bloc cron NeuroCheckout
  --no-config-update            N'ecrit pas NC_EXECUTION_MODE / NC_CRON_INTERVAL_SECONDS
  --dry-run                     Affiche le cron final sans l'installer
  -h, --help                    Affiche cette aide

Exemples:
  $SCRIPT_NAME
  $SCRIPT_NAME --runner docker --container prestashop-prestashop-1
  $SCRIPT_NAME --runner host --ps-root /var/www/html --interval-minutes 5
  $SCRIPT_NAME --remove
USAGE
}

log() {
    printf '[NC-CRON] %s\n' "$*"
}

err() {
    printf '[NC-CRON][ERROR] %s\n' "$*" >&2
}

require_cmd() {
    if ! command -v "$1" >/dev/null 2>&1; then
        err "Commande requise manquante: $1"
        exit 1
    fi
}

trim() {
    local value="$1"
    value="${value#${value%%[![:space:]]*}}"
    value="${value%${value##*[![:space:]]}}"
    printf '%s' "$value"
}

php_set_cron_config_code() {
    cat <<'CODE'
$root = rtrim($argv[1], '/');
$intervalSeconds = (int) ($argv[2] ?? 300);
if ($intervalSeconds < 60) {
    $intervalSeconds = 300;
}
require $root.'/config/config.inc.php';
require $root.'/init.php';
Configuration::updateValue('NC_EXECUTION_MODE', 'cron_module');
Configuration::updateValue('NC_CRON_INTERVAL_SECONDS', $intervalSeconds);
echo 'ok';
CODE
}

remove_managed_block() {
    awk -v begin="$MARKER_BEGIN" -v end="$MARKER_END" '
        $0 == begin { skip = 1; next }
        $0 == end { skip = 0; next }
        skip == 0 { print }
    '
}

is_positive_int() {
    [[ "$1" =~ ^[0-9]+$ ]] && [ "$1" -gt 0 ]
}

get_current_crontab() {
    local output
    if [ -n "$CRON_USER" ]; then
        output="$(crontab -u "$CRON_USER" -l 2>/dev/null || true)"
    else
        output="$(crontab -l 2>/dev/null || true)"
    fi
    printf '%s' "$output"
}

install_crontab() {
    local content="$1"
    if [ "$DRY_RUN" -eq 1 ]; then
        log "Dry-run active: aucun changement applique."
        printf '\n----- CRONTAB RESULT -----\n%s\n--------------------------\n' "$content"
        return
    fi

    if [ -n "$CRON_USER" ]; then
        printf '%s\n' "$content" | crontab -u "$CRON_USER" -
    else
        printf '%s\n' "$content" | crontab -
    fi
}

runner_exists_docker() {
    command -v docker >/dev/null 2>&1 || return 1
    docker ps --format '{{.Names}}' 2>/dev/null | grep -Fx "$CONTAINER_NAME" >/dev/null 2>&1
}

resolve_ps_root_host() {
    if [ -n "$PS_ROOT" ]; then
        printf '%s' "$PS_ROOT"
        return
    fi

    local script_dir candidate
    script_dir="$(cd "$(dirname "$0")" && pwd)"
    candidate="$(realpath "$script_dir/../../.." 2>/dev/null || true)"

    if [ -n "$candidate" ] && [ -f "$candidate/config/config.inc.php" ]; then
        printf '%s' "$candidate"
        return
    fi

    if [ -f "$DEFAULT_PS_ROOT/config/config.inc.php" ]; then
        printf '%s' "$DEFAULT_PS_ROOT"
        return
    fi

    printf ''
}

parse_args() {
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --runner)
                RUNNER_MODE="${2:-}"
                shift 2
                ;;
            --container)
                CONTAINER_NAME="${2:-}"
                shift 2
                ;;
            --ps-root)
                PS_ROOT="${2:-}"
                shift 2
                ;;
            --interval-minutes)
                INTERVAL_MINUTES="${2:-}"
                shift 2
                ;;
            --user)
                CRON_USER="${2:-}"
                shift 2
                ;;
            --remove)
                REMOVE_ONLY=1
                shift
                ;;
            --dry-run)
                DRY_RUN=1
                shift
                ;;
            --no-config-update)
                NO_CONFIG_UPDATE=1
                shift
                ;;
            -h|--help)
                usage
                exit 0
                ;;
            *)
                err "Option inconnue: $1"
                usage
                exit 1
                ;;
        esac
    done
}

parse_args "$@"

RUNNER_MODE="$(trim "$RUNNER_MODE")"
if [ -z "$RUNNER_MODE" ]; then
    RUNNER_MODE="auto"
fi

case "$RUNNER_MODE" in
    auto|docker|host) ;;
    *)
        err "Valeur --runner invalide: $RUNNER_MODE"
        exit 1
        ;;
esac

if ! is_positive_int "$INTERVAL_MINUTES"; then
    err "--interval-minutes doit etre un entier positif"
    exit 1
fi
if [ "$INTERVAL_MINUTES" -gt 59 ]; then
    err "--interval-minutes doit etre <= 59 (limite cron sur le champ minute)"
    exit 1
fi

require_cmd crontab

FINAL_RUNNER="$RUNNER_MODE"
if [ "$RUNNER_MODE" = "auto" ]; then
    if runner_exists_docker; then
        FINAL_RUNNER="docker"
    else
        FINAL_RUNNER="host"
    fi
fi

if [ "$FINAL_RUNNER" = "docker" ]; then
    require_cmd docker
    if ! runner_exists_docker; then
        err "Conteneur docker introuvable ou arrete: $CONTAINER_NAME"
        exit 1
    fi

    if [ -z "$PS_ROOT" ]; then
        PS_ROOT="$DEFAULT_PS_ROOT"
    fi

    if ! docker exec "$CONTAINER_NAME" test -f "$PS_ROOT/config/config.inc.php"; then
        err "Racine PrestaShop invalide dans le conteneur: $PS_ROOT"
        exit 1
    fi

    if [ "$NO_CONFIG_UPDATE" -eq 0 ] && [ "$REMOVE_ONLY" -eq 0 ] && [ "$DRY_RUN" -eq 0 ]; then
        php_code="$(php_set_cron_config_code)"
        interval_seconds=$((INTERVAL_MINUTES * 60))
        set_result="$(docker exec "$CONTAINER_NAME" php -r "$php_code" "$PS_ROOT" "$interval_seconds" 2>/dev/null || true)"
        if [ "$(trim "$set_result")" != "ok" ]; then
            err "Impossible de mettre a jour NC_EXECUTION_MODE/NC_CRON_INTERVAL_SECONDS (docker)."
            exit 1
        fi
    fi

    dispatch_path="$PS_ROOT/modules/neurocheckoutconnector/scripts/cron_env_dispatch.php"
    if ! docker exec "$CONTAINER_NAME" test -f "$dispatch_path"; then
        err "Script introuvable dans le conteneur: $dispatch_path"
        exit 1
    fi

    docker_bin="$(command -v docker)"
    cron_command="$docker_bin exec $CONTAINER_NAME php $dispatch_path >/dev/null 2>&1"
else
    require_cmd php

    PS_ROOT="$(resolve_ps_root_host)"
    if [ -z "$PS_ROOT" ] || [ ! -f "$PS_ROOT/config/config.inc.php" ]; then
        err "Impossible de detecter la racine PrestaShop en mode host. Utilisez --ps-root <path>."
        exit 1
    fi

    if [ "$NO_CONFIG_UPDATE" -eq 0 ] && [ "$REMOVE_ONLY" -eq 0 ] && [ "$DRY_RUN" -eq 0 ]; then
        php_code="$(php_set_cron_config_code)"
        interval_seconds=$((INTERVAL_MINUTES * 60))
        set_result="$(php -r "$php_code" "$PS_ROOT" "$interval_seconds" 2>/dev/null || true)"
        if [ "$(trim "$set_result")" != "ok" ]; then
            err "Impossible de mettre a jour NC_EXECUTION_MODE/NC_CRON_INTERVAL_SECONDS (host)."
            exit 1
        fi
    fi

    dispatch_path="$PS_ROOT/modules/neurocheckoutconnector/scripts/cron_env_dispatch.php"
    if [ ! -f "$dispatch_path" ]; then
        err "Script introuvable: $dispatch_path"
        exit 1
    fi

    php_bin="$(command -v php)"
    cron_command="$php_bin $dispatch_path >/dev/null 2>&1"
fi

current_crontab="$(get_current_crontab)"
clean_crontab="$(printf '%s\n' "$current_crontab" | remove_managed_block)"
clean_crontab="$(printf '%s' "$clean_crontab" | sed '/^[[:space:]]*$/N;/^\n$/D')"

if [ "$REMOVE_ONLY" -eq 1 ]; then
    final_crontab="$clean_crontab"
    install_crontab "$final_crontab"
    log "Bloc cron NeuroCheckout supprime."
    exit 0
fi

cron_line="*/$INTERVAL_MINUTES * * * * $cron_command"
managed_block="$MARKER_BEGIN
# Managed by neurocheckoutconnector/scripts/setup_cron.sh
$cron_line
$MARKER_END"

if [ -n "$clean_crontab" ]; then
    final_crontab="$clean_crontab
$managed_block"
else
    final_crontab="$managed_block"
fi

install_crontab "$final_crontab"

log "Cron NeuroCheckout configure avec succes."
log "Runner: $FINAL_RUNNER"
log "Intervalle: ${INTERVAL_MINUTES} minute(s)"
log "Commande: $cron_line"
if [ "$NO_CONFIG_UPDATE" -eq 0 ] && [ "$DRY_RUN" -eq 0 ]; then
    log "Configuration module mise a jour: NC_EXECUTION_MODE=cron_module, NC_CRON_INTERVAL_SECONDS=$((INTERVAL_MINUTES * 60))"
fi
