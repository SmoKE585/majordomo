#!/usr/bin/env bash
#
# MajorDoMo · deploy.sh — обновление системы из терминала.
#
# Делает то же, что кнопка «Обновить систему» в панели, но без веб-сессии:
# проверяет окружение, снимает дамп базы, скачивает архив нужного коммита,
# распаковывает его, ставит циклы на паузу, заменяет файлы, запускает
# Composer/npm/миграции и перезапускает службу.
#
# Вся работа выполняется командой `php majordomo deploy:update`; этот скрипт
# отвечает за оформление, журнал, проверку запуска и понятные сообщения об
# ошибках. Логику обновления здесь дублировать нельзя.
#
# Примеры:
#   ./deploy.sh                  проверки, подтверждение, обновление
#   ./deploy.sh --yes            без подтверждения (cron, CI)
#   ./deploy.sh --check          только проверки окружения
#   ./deploy.sh --plan           показать, какой коммит будет установлен
#   ./deploy.sh --branch=alpha   обновиться с ветки alpha
#   ./deploy.sh --no-restart     обновить файлы без перезапуска службы
#
# Коды выхода: 0 — успех, 1 — обновление не выполнено, 2 — ошибка запуска,
# 3 — отменено пользователем.
#
# 2026-10-23 — добавлено, чтобы обновление не зависело от веб-панели.

set -Eeuo pipefail

if ! APP_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"; then
    echo "deploy.sh: не удалось определить каталог скрипта" >&2
    exit 2
fi
if ! cd -- "$APP_ROOT"; then
    echo "deploy.sh: не удалось перейти в $APP_ROOT" >&2
    exit 2
fi

ORIGINAL_ARGS=("$@")
MODE="update"
ASSUME_YES=0
ALLOW_ROOT=0
PHP_BIN="${PHP_BIN:-}"
LOG_FILE=""
FINISHED=0

# ── Оформление ───────────────────────────────────────────────────────────────

setup_style() {
    if [[ -t 1 && -z "${NO_COLOR:-}" && "${TERM:-}" != "dumb" ]]; then
        C_RESET=$'\033[0m'
        C_BOLD=$'\033[1m'
        C_DIM=$'\033[2m'
        C_RED=$'\033[31m'
        C_GREEN=$'\033[32m'
        C_YELLOW=$'\033[33m'
        C_CYAN=$'\033[36m'
    else
        C_RESET='' C_BOLD='' C_DIM='' C_RED='' C_GREEN='' C_YELLOW='' C_CYAN=''
    fi

    case "${LC_ALL:-${LC_CTYPE:-${LANG:-}}}" in
        ''|*[Uu][Tt][Ff]*) SYM_OK='✓' SYM_FAIL='✗' SYM_WARN='!' SYM_ARROW='→' ;;
        *) SYM_OK='+' SYM_FAIL='x' SYM_WARN='!' SYM_ARROW='->' ;;
    esac

    if [[ -z "${COLUMNS:-}" ]] && command -v tput >/dev/null 2>&1; then
        COLUMNS="$(tput cols 2>/dev/null || true)"
    fi
    if ! [[ "${COLUMNS:-}" =~ ^[0-9]+$ ]]; then
        COLUMNS=100
    fi
    export COLUMNS
}

rule() {
    local width="${COLUMNS:-100}"
    if (( width > 100 )); then width=100; fi
    if (( width < 40 )); then width=40; fi
    printf '%s%s%s\n' "$C_DIM" "$(printf '%*s' "$width" '' | tr ' ' '-')" "$C_RESET"
}

section() {
    printf '\n%s%s %s%s\n' "$C_BOLD$C_CYAN" "$SYM_ARROW" "$1" "$C_RESET"
    rule
}

ok() {
    printf '  %s%s%s %s\n' "$C_GREEN" "$SYM_OK" "$C_RESET" "$1"
}

warn() {
    printf '  %s%s%s %s\n' "$C_YELLOW" "$SYM_WARN" "$C_RESET" "$1"
}

fail() {
    printf '  %s%s%s %s\n' "$C_RED" "$SYM_FAIL" "$C_RESET" "$1"
}

info() {
    printf '  %s-%s %s\n' "$C_DIM" "$C_RESET" "$1"
}

banner() {
    printf '\n%s%s%s\n' "$C_BOLD$C_CYAN" "MajorDoMo · обновление системы" "$C_RESET"
    printf '%sdeploy.sh · %s · %s%s\n' "$C_DIM" "$(date '+%d.%m.%Y %H:%M')" "$APP_ROOT" "$C_RESET"
    rule
}

error_block() {
    local title="$1"
    local hint="${2:-}"
    printf '\n'
    rule
    printf '  %s%s%s\n' "$C_BOLD$C_RED" "$title" "$C_RESET"
    if [[ -n "$hint" ]]; then
        printf '  %s\n' "$hint"
    fi
    if [[ -n "$LOG_FILE" && -s "$LOG_FILE" ]]; then
        printf '  Журнал: %s\n' "$LOG_FILE"
        printf '\n%s  Последние строки журнала:%s\n' "$C_DIM" "$C_RESET"
        tail -n 12 -- "$LOG_FILE" | sed 's/^/    /'
    fi
    printf '  Повторить после исправления: %s./deploy.sh%s\n' "$C_BOLD" "$C_RESET"
    printf '\n'
    rule
}

on_exit() {
    local code=$?
    if (( code == 0 )); then
        return 0
    fi
    if (( FINISHED == 0 )); then
        error_block "Обновление прервано (код $code)" "Смотрите сообщение об ошибке выше: в нём указано, что именно не получилось."
    fi
    exit "$code"
}
trap on_exit EXIT

# ── Журнал ───────────────────────────────────────────────────────────────────

init_log() {
    local directory="$APP_ROOT/cms/deploy"
    if ! mkdir -p -- "$directory" 2>/dev/null; then
        warn "Не удалось создать $directory — журнал вестись не будет"
        return 0
    fi
    LOG_FILE="$directory/deploy-$(date '+%Y%m%d-%H%M%S').log"
    if ! : > "$LOG_FILE" 2>/dev/null; then
        LOG_FILE=""
        warn "Нет прав на запись в $directory — журнал вестись не будет"
        return 0
    fi
    info "Журнал: ${LOG_FILE#"$APP_ROOT"/}"
}

log_line() {
    if [[ -n "$LOG_FILE" ]]; then
        printf '%s\n' "$1" >> "$LOG_FILE"
    fi
}

# ── Запуск PHP ───────────────────────────────────────────────────────────────

colorize() {
    local line="$1"
    case "$line" in
        *"[ERROR]"*) printf '%s%s%s' "$C_RED" "$line" "$C_RESET" ;;
        *"[OK]"*)    printf '%s%s%s' "$C_GREEN" "$line" "$C_RESET" ;;
        *"[WARN]"*)  printf '%s%s%s' "$C_YELLOW" "$line" "$C_RESET" ;;
        *"[INFO]"*)  printf '%s%s%s' "$C_CYAN" "$line" "$C_RESET" ;;
        "  Шаг "*)   printf '%s%s%s' "$C_BOLD$C_CYAN" "$line" "$C_RESET" ;;
        *)           printf '%s' "$line" ;;
    esac
}

# Потоково показывает вывод команды и пишет его в журнал без цветов.
run_php() {
    local -a command=("$PHP_BIN" "$APP_ROOT/majordomo" deploy:update "$@")
    local status=0
    local line

    log_line ""
    log_line "== $* =="
    set +e
    "${command[@]}" 2>&1 | while IFS= read -r line || [[ -n "$line" ]]; do
        log_line "$line"
        colorize "$line"
        printf '\n'
    done
    status=${PIPESTATUS[0]}
    set -e
    return "$status"
}

# ── Проверка запуска ─────────────────────────────────────────────────────────

find_php() {
    if [[ -n "$PHP_BIN" ]]; then
        if [[ ! -x "$PHP_BIN" ]]; then
            fail "PHP_BIN указывает на неисполняемый файл: $PHP_BIN"
            return 2
        fi
        return 0
    fi
    if command -v php >/dev/null 2>&1; then
        PHP_BIN="$(command -v php)"
        return 0
    fi
    if [[ -x /usr/bin/php ]]; then
        PHP_BIN=/usr/bin/php
        return 0
    fi
    if [[ -x /usr/local/bin/php ]]; then
        PHP_BIN=/usr/local/bin/php
        return 0
    fi
    fail "PHP не найден в PATH. Укажите путь: PHP_BIN=/usr/local/bin/php ./deploy.sh"
    return 2
}

check_php_version() {
    local version
    version="$("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null || true)"
    if [[ -z "$version" ]]; then
        fail "Не удалось запустить PHP: $PHP_BIN"
        return 2
    fi
    local major="${version%%.*}"
    local remainder="${version#*.}"
    local minor="${remainder%%.*}"
    if ! [[ "$major" =~ ^[0-9]+$ && "$minor" =~ ^[0-9]+$ ]]; then
        fail "Не удалось разобрать версию PHP: $version"
        return 2
    fi
    if (( major < 8 || (major == 8 && minor < 1) )); then
        fail "Нужен PHP 8.1 или новее, найден $version ($PHP_BIN)"
        return 2
    fi
    ok "PHP $version · $PHP_BIN"
}

detect_site_user() {
    local owner=""
    owner="$(stat -c '%U' -- "$APP_ROOT" 2>/dev/null || true)"
    if [[ -z "$owner" ]]; then
        owner="$(stat -f '%Su' -- "$APP_ROOT" 2>/dev/null || true)"
    fi
    if [[ -z "$owner" || "$owner" == "root" ]]; then
        owner="$(stat -c '%U' -- "$APP_ROOT/cms" 2>/dev/null || true)"
    fi
    printf '%s' "$owner"
}

reexec_as_site_user() {
    (( EUID == 0 )) || return 0
    if (( ALLOW_ROOT == 1 )); then
        return 0
    fi
    if [[ "${MAJORDOMO_DEPLOY_REEXEC:-}" == "1" ]]; then
        return 0
    fi

    local site_user
    site_user="$(detect_site_user)"
    if [[ -z "$site_user" || "$site_user" == "root" ]]; then
        warn "Запуск от root, пользователь проекта не определён — продолжаю от root."
        warn "Файлы получат владельца root: служба и веб-обновление могут потерять доступ."
        return 0
    fi
    if ! command -v sudo >/dev/null 2>&1; then
        warn "Запуск от root, sudo не найден — продолжаю от root (это не рекомендуется)."
        return 0
    fi

    warn "Запуск от root. Перезапускаю от пользователя проекта: $site_user"
    export MAJORDOMO_DEPLOY_REEXEC=1
    log_line "reexec as $site_user"
    # SUDO_USER при sudo -u остаётся root, а ServiceManager выбирает по нему
    # пользователя службы. Очищаем, чтобы внутри всё считалось от сайта.
    if (( ${#ORIGINAL_ARGS[@]} > 0 )); then
        exec sudo -u "$site_user" -H env SUDO_USER= bash "$APP_ROOT/deploy.sh" "${ORIGINAL_ARGS[@]}"
    fi
    exec sudo -u "$site_user" -H env SUDO_USER= bash "$APP_ROOT/deploy.sh"
}

# ── Аргументы ────────────────────────────────────────────────────────────────

usage() {
    cat <<EOF
MajorDoMo · deploy.sh — обновление системы из терминала.

Использование:
  ./deploy.sh [опции]

Режимы:
  --check              только проверки окружения, без изменений
  --plan               показать доступный коммит и ссылку на архив
  --yes, -y            не спрашивать подтверждение

Опции обновления (передаются команде deploy:update):
  --url=URL            архив обновления вместо MASTER_UPDATE_URL из .env
  --branch=ВЕТКА       ветка обновления: master, alpha или другая
  --skip-backup        не делать резервную копию базы (не рекомендуется)
  --no-restart         не перезапускать службу после обновления
  --keep-temp          оставить скачанный архив и распакованные файлы
  --insecure           не проверять TLS-сертификат при скачивании
  --force              обновить, даже если коммит уже установлен

Служебные:
  --allow-root         не перезапускать скрипт от пользователя проекта
  --help, -h           эта справка

Переменные окружения:
  PHP_BIN              путь к CLI PHP (по умолчанию php из PATH)

Коды выхода: 0 — успех, 1 — обновление не выполнено, 2 — ошибка запуска,
3 — отменено.

Пошагово:
  1. проверки окружения и прав;
  2. дамп базы (обязательный, если не указан --skip-backup);
  3. архив ровно того коммита, который сейчас в ленте;
  4. распаковка и проверка прав на каждый файл;
  5. режим обслуживания и пауза циклов;
  6. замена файлов по манифесту;
  7. composer install, сборка фронтенда, миграции;
  8. перезапуск службы и снятие режима обслуживания.

Подробности: docs/deploy/deploy-sh.md
EOF
}

parse_args() {
    if (( $# == 0 )); then
        return 0
    fi
    local arg
    for arg in "$@"; do
        case "$arg" in
            -h|--help) usage; exit 0 ;;
            --check) MODE="check" ;;
            --plan) MODE="plan" ;;
            --yes|-y) ASSUME_YES=1 ;;
            --allow-root) ALLOW_ROOT=1 ;;
            --skip-backup|--no-restart|--keep-temp|--insecure|--force) ;;
            --url=*|--branch=*) ;;
            --url|--branch)
                fail "Опция $arg требует значение: используйте $arg=... "
                FINISHED=1
                exit 2
                ;;
            --*) fail "Неизвестная опция: $arg (см. ./deploy.sh --help)"; FINISHED=1; exit 2 ;;
            *) fail "Неизвестный аргумент: $arg (см. ./deploy.sh --help)"; FINISHED=1; exit 2 ;;
        esac
    done
}

php_args() {
    local -a args=()
    local arg
    for arg in "${ORIGINAL_ARGS[@]}"; do
        case "$arg" in
            --allow-root) ;;
            --yes|-y) ;;
            -h|--help) ;;
            --check|--plan) ;;
            *) args+=("$arg") ;;
        esac
    done
    if (( ${#args[@]} > 0 )); then
        printf '%s\n' "${args[@]}"
    fi
}

# ── Шаги ─────────────────────────────────────────────────────────────────────

run_checks() {
    section "Проверки окружения"
    if ! run_php --check; then
        error_block "Проверки не пройдены" "Обновление не начато. Исправьте замечания выше и повторите ./deploy.sh"
        FINISHED=1
        return 1
    fi
    return 0
}

confirm_update() {
    if (( ASSUME_YES == 1 )); then
        return 0
    fi
    printf '\n'
    warn "Будут заменены файлы проекта, поставлены зависимости и перезапущена служба."
    info "Сначала показываются проверки окружения: при ошибке обновление остановится до изменений."
    printf '  Продолжить? [y/N]: '
    local answer=""
    read -r answer || true
    case "$answer" in
        y|Y|yes|YES|да|Да|д|Д) return 0 ;;
        *) return 3 ;;
    esac
}

run_update() {
    section "Обновление"
    local -a args=()
    local line
    while IFS= read -r line; do
        if [[ -n "$line" ]]; then
            args+=("$line")
        fi
    done < <(php_args)

    local status=0
    if (( ${#args[@]} > 0 )); then
        run_php --yes "${args[@]}" || status=$?
    else
        run_php --yes || status=$?
    fi
    return "$status"
}

print_success() {
    local log_path="${LOG_FILE:-}"
    printf '\n'
    rule
    printf '  %s%s Обновление завершено%s\n' "$C_BOLD$C_GREEN" "$SYM_OK" "$C_RESET"
    printf '  Файлы заменены, режим обслуживания снят.\n'
    printf '  Если служба не перезапускалась (--no-restart), перезапустите её вручную.\n'
    printf '  Версия в панели появится при первом открытии: запись восстановится сама.\n'
    if [[ -n "$log_path" ]]; then
        printf '  Журнал: %s\n' "${log_path#"$APP_ROOT"/}"
    fi
    rule
}

# ── Главное ──────────────────────────────────────────────────────────────────

main() {
    setup_style
    banner
    parse_args "$@"

    if [[ ! -f "$APP_ROOT/majordomo" || ! -f "$APP_ROOT/bootstrap.php" ]]; then
        error_block "Это не корень MajorDoMo" "Положите deploy.sh в корень проекта, рядом с bootstrap.php и majordomo."
        FINISHED=1
        exit 2
    fi
    ok "Корень проекта: $APP_ROOT"

    if ! find_php; then
        FINISHED=1
        exit 2
    fi
    if ! check_php_version; then
        FINISHED=1
        exit 2
    fi

    log_line "deploy.sh $*"
    log_line "root: $APP_ROOT"
    reexec_as_site_user

    init_log

    case "$MODE" in
        check)
            if ! run_checks; then
                exit 1
            fi
            printf '\n'
            ok "Проверки пройдены. Запуск обновления: ./deploy.sh"
            FINISHED=1
            exit 0
            ;;
        plan)
            section "Версия"
            local status=0
            run_php --plan || status=$?
            FINISHED=1
            if (( status != 0 )); then
                error_block "Не удалось определить версию" "Проверьте GIT_URL в .env и доступ сервера к хостингу."
                exit 1
            fi
            exit 0
            ;;
    esac

    confirm_update || {
        warn "Обновление отменено пользователем"
        FINISHED=1
        exit 3
    }

    local update_status=0
    run_update || update_status=$?
    if (( update_status != 0 )); then
        FINISHED=1
        error_block "Обновление не выполнено (код $update_status)" "Прочитайте сообщение об ошибке выше: там указано, что именно и как исправить."
        exit 1
    fi

    print_success
    FINISHED=1
    exit 0
}

main "$@"
