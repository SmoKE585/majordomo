#!/usr/bin/env bash
#
# Entrypoint docker-стенда MajorDoMo (только отладка).
#
# Порядок:
#   1) ждём MariaDB, Redis и MQTT;
#   2) создаём .env из переменных compose, если файла ещё нет;
#   3) готовим каталоги и права;
#   4) применяем миграции и настраиваем админа/MQTT (если БД уже заполнена);
#   5) запускаем php-fpm, nginx и супервизор циклов через supervisord.
#
# Любая команда после `docker compose run app <cmd>` выполняется как есть:
#   docker compose run --rm app php majordomo migrations:status

set -Eeuo pipefail

log() {
    printf '[majordomo] %s\n' "$*"
}

if [ "${1:-serve}" != "serve" ]; then
    exec "$@"
fi

APP_ROOT="/var/www/html"
cd "$APP_ROOT"

DB_HOST="${MJD_DB_HOST:-db}"
DB_PORT="${MJD_DB_PORT:-3306}"
DB_NAME="${MJD_DB_NAME:-majordomo}"
DB_USER="${MJD_DB_USER:-majordomo}"
DB_PASSWORD="${MJD_DB_PASSWORD:-majordomo}"

REDIS_HOST="${MJD_REDIS_HOST:-redis}"
REDIS_PORT="${MJD_REDIS_PORT:-6379}"
MQTT_HOST="${MJD_MQTT_HOST:-mosquitto}"
MQTT_PORT="${MJD_MQTT_PORT:-1883}"

TIMEZONE="${MJD_TIMEZONE:-Europe/Moscow}"
HTTP_PORT="${MJD_HTTP_PORT:-80}"
BASE_URL="${MJD_BASE_URL:-}"
DB_PROTECT_DROP="${MJD_DB_PROTECT_DROP:-1}"

ADMIN_USER="${MJD_ADMIN_USER:-admin}"
ADMIN_PASSWORD="${MJD_ADMIN_PASSWORD:-majordomo}"
ADMIN_NAME="${MJD_ADMIN_NAME:-Docker Admin}"
SITE_TIMEZONE="${MJD_SITE_TIMEZONE:-$TIMEZONE}"
SITE_LANGUAGE="${MJD_SITE_LANGUAGE:-ru}"
THEME="${MJD_THEME:-dark}"
MQTT_FORCE_CONFIG="${MJD_MQTT_FORCE_CONFIG:-0}"
ADMIN_RESET_PASSWORD="${MJD_ADMIN_RESET_PASSWORD:-0}"

PHP_BIN="$(command -v php)"
MYSQL_BIN="$(command -v mariadb || command -v mysql)"
MYSQLDUMP_BIN="$(command -v mysqldump || command -v mariadb-dump || true)"

export TZ="$TIMEZONE"
export MJD_ADMIN_USER="$ADMIN_USER"
export MJD_ADMIN_PASSWORD="$ADMIN_PASSWORD"
export MJD_ADMIN_NAME="$ADMIN_NAME"
export MJD_ADMIN_RESET_PASSWORD="$ADMIN_RESET_PASSWORD"
export MJD_MQTT_HOST="$MQTT_HOST"
export MJD_MQTT_PORT="$MQTT_PORT"
export MJD_MQTT_FORCE_CONFIG="$MQTT_FORCE_CONFIG"
export MJD_SITE_TIMEZONE="$SITE_TIMEZONE"
export MJD_SITE_LANGUAGE="$SITE_LANGUAGE"
export MJD_THEME="$THEME"
export MJD_REDIS_HOST="$REDIS_HOST"
export MJD_REDIS_PORT="$REDIS_PORT"

# ---------------------------------------------------------------------------
# Ожидание сервисов
# ---------------------------------------------------------------------------

wait_for_db() {
    local attempt=0
    while [ "$attempt" -lt 90 ]; do
        if MYSQL_PWD="$DB_PASSWORD" "$MYSQL_BIN" --protocol=tcp \
            -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" \
            -e 'SELECT 1' >/dev/null 2>&1; then
            log "MariaDB готова: ${DB_HOST}:${DB_PORT}/${DB_NAME}"
            return 0
        fi
        attempt=$((attempt + 1))
        if [ $((attempt % 10)) -eq 0 ]; then
            log "Ждём MariaDB (${attempt}/90)…"
        fi
        sleep 2
    done
    log "MariaDB не ответила за 180 секунд"
    return 1
}

wait_for_redis() {
    local attempt=0
    while [ "$attempt" -lt 90 ]; do
        if "$PHP_BIN" -r '
            $r = new Redis();
            if (!@$r->connect(getenv("MJD_REDIS_HOST"), (int)getenv("MJD_REDIS_PORT"), 2)) { exit(1); }
            $pong = @$r->ping();
            exit(($pong === true || $pong === "+PONG") ? 0 : 1);
        ' >/dev/null 2>&1; then
            log "Redis готов: ${REDIS_HOST}:${REDIS_PORT}"
            return 0
        fi
        attempt=$((attempt + 1))
        if [ $((attempt % 10)) -eq 0 ]; then
            log "Ждём Redis (${attempt}/90)…"
        fi
        sleep 2
    done
    log "Redis не ответил за 180 секунд"
    return 1
}

wait_for_mqtt() {
    local attempt=0
    while [ "$attempt" -lt 90 ]; do
        if "$PHP_BIN" -r '
            $socket = @fsockopen(getenv("MJD_MQTT_HOST"), (int)getenv("MJD_MQTT_PORT"), $errno, $error, 2);
            if ($socket === false) { exit(1); }
            fclose($socket);
            exit(0);
        ' >/dev/null 2>&1; then
            log "MQTT-брокер готов: ${MQTT_HOST}:${MQTT_PORT}"
            return 0
        fi
        attempt=$((attempt + 1))
        if [ $((attempt % 10)) -eq 0 ]; then
            log "Ждём MQTT (${attempt}/90)…"
        fi
        sleep 2
    done
    log "MQTT-брокер не ответил за 180 секунд"
    return 1
}

wait_for_db
wait_for_redis
wait_for_mqtt

# ---------------------------------------------------------------------------
# .env
# ---------------------------------------------------------------------------

if [ -z "$BASE_URL" ]; then
    if [ "$HTTP_PORT" = "80" ]; then
        BASE_URL="http://localhost"
    else
        BASE_URL="http://localhost:${HTTP_PORT}"
    fi
fi

write_env() {
    log "Создаю .env для docker-стенда"
    cat > "$APP_ROOT/.env" <<EOF
# Сгенерировано docker/app/entrypoint.sh. Только отладка.
# Чтобы сохранить правки из панели, не пересоздавайте контейнер без нужды:
# .env живёт внутри контейнера и обновляется при пересоздании.

DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASSWORD="${DB_PASSWORD}"
DB_PROTECT_DROP=${DB_PROTECT_DROP}

DIR_TEMPLATES=./templates/
DIR_MODULES=./modules/
ROOTHTML=/
BASE_URL=${BASE_URL}

PATH_TO_PHP=${PHP_BIN}
PATH_TO_MYSQLDUMP=${MYSQLDUMP_BIN}

PROJECT_TITLE="MajorDoMo Docker Debug"
DEBUG_MODE=1
APP_ENV=development
TIMEZONE=${TIMEZONE}

# Обновления из репозитория в отладочном стенде отключены: код заменяется
# пересборкой образа, а не самообновлением.
GIT_URL=

USE_REDIS=${REDIS_HOST}
REDIS_PORT=${REDIS_PORT}
REDIS_DB=0
REDIS_PREFIX=majordomo:docker:

CORE_WORKERS=3
WAIT_FOR_MAIN_CYCLE=0
LOWER_BACKGROUND_PROCESSES=0
ENABLE_PANEL_ACCELERATION=0
SEPARATE_HISTORY_STORAGE=0
HISTORY_NO_OPTIMIZE=0
PROPERTY_AUDIT_RETENTION_DAYS=7

MQTT_ASYNC_ENABLED=1
MQTT_HANDLERS=3
MQTT_HANDLER_LEASE=120
MQTT_MAX_ATTEMPTS=5

LOG_CYCLES=1
LOG_PHP_WARNINGS=1
VERBOSE_LOG=0
LOG_MAX_SIZE=10
EOF
    chown www-data:www-data "$APP_ROOT/.env"
    chmod 0640 "$APP_ROOT/.env"
}

if [ ! -f "$APP_ROOT/.env" ] || [ "${MJD_FORCE_ENV:-0}" = "1" ]; then
    write_env
else
    log "Использую существующий .env"
fi

# Runtime-каталоги могут быть созданы заново после docker compose down.
mkdir -p \
    "$APP_ROOT/backup" \
    "$APP_ROOT/cms/cached" \
    "$APP_ROOT/cms/cycles" \
    "$APP_ROOT/cms/debmes" \
    "$APP_ROOT/cms/deploy" \
    "$APP_ROOT/cms/files" \
    "$APP_ROOT/cms/html" \
    "$APP_ROOT/cms/images" \
    "$APP_ROOT/cms/layouts" \
    "$APP_ROOT/cms/modules_installed" \
    "$APP_ROOT/cms/products" \
    "$APP_ROOT/cms/saverestore" \
    "$APP_ROOT/cms/sessions" \
    "$APP_ROOT/cms/sounds" \
    "$APP_ROOT/cms/templates" \
    "$APP_ROOT/cms/texts" \
    "$APP_ROOT/cms/weather" \
    "$APP_ROOT/var/events" \
    "$APP_ROOT/var/security" \
    "$APP_ROOT/var/sessions"

chown -R www-data:www-data "$APP_ROOT/cms" "$APP_ROOT/var" "$APP_ROOT/backup" 2>/dev/null || true
chmod -R u+rwX,g+rwX "$APP_ROOT/cms" "$APP_ROOT/var" "$APP_ROOT/backup" 2>/dev/null || true
chown www-data:www-data "$APP_ROOT/.env" 2>/dev/null || true

# ---------------------------------------------------------------------------
# Миграции и первичная настройка
# ---------------------------------------------------------------------------

run_as_www() {
    su -m -s /bin/sh www-data -c "cd ${APP_ROOT} && $*"
}

db_table_count() {
    case "$DB_NAME" in
        *[!A-Za-z0-9_]*)
            log "Недопустимое имя базы: ${DB_NAME}"
            return 1
            ;;
    esac
    MYSQL_PWD="$DB_PASSWORD" "$MYSQL_BIN" --protocol=tcp \
        -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -N -B \
        -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}'" \
        2>/dev/null || echo 0
}

if [ "${MJD_RUN_MIGRATIONS:-1}" = "1" ]; then
    tables="$(db_table_count || echo 0)"
    if [ "${tables:-0}" -gt 0 ]; then
        log "В базе ${tables} таблиц: применяю миграции"
        if ! run_as_www "$PHP_BIN majordomo migrations:run"; then
            log "ПРЕДУПРЕЖДЕНИЕ: миграции завершились ошибкой; смотрите docker compose logs app"
            log "Статус: docker compose exec app php majordomo migrations:status"
        fi

        log "Настраиваю администратора, MQTT и базовые параметры"
        if ! run_as_www "$PHP_BIN docker/init/configure.php"; then
            log "ПРЕДУПРЕЖДЕНИЕ: configure.php не завершился; проверьте БД и .env"
        fi
    else
        log "База пуста: миграции пропущены, откройте /install.php для первичной настройки"
    fi
fi

log "Запускаю php-fpm, nginx и циклы"
exec /usr/bin/supervisord -c /etc/supervisor/majordomo.conf -n
