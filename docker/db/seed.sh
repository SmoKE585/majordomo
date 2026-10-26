#!/usr/bin/env bash
#
# Первичное заполнение базы docker-стенда.
# Запускается официальным entrypoint MariaDB только при пустом /var/lib/mysql.
# Режим задаётся MJD_DB_SEED:
#   legacy — старый демо-дамп docs/dev/db_terminal.sql + миграции ядра (по умолчанию);
#   clean  — чистая схема lib/core/Install/schema.sql без демо-данных;
#   none   — не трогать базу (мастер /install.php останется доступным).

set -Eeuo pipefail

seed="${MJD_DB_SEED:-legacy}"
database="${MARIADB_DATABASE:-majordomo}"

case "$database" in
    *[!A-Za-z0-9_]*)
        echo "[db-seed] недопустимое имя базы: $database" >&2
        exit 1
        ;;
esac

if [ "$seed" = "none" ]; then
    echo "[db-seed] MJD_DB_SEED=none: база остаётся пустой"
    exit 0
fi

count="$(MYSQL_PWD="${MARIADB_ROOT_PASSWORD:-}" mariadb --protocol=socket -uroot -N -B \
    -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${database}'" 2>/dev/null || echo 0)"

if [ "${count:-0}" -gt 0 ]; then
    echo "[db-seed] в базе уже ${count} таблиц: заполнение пропущено"
    exit 0
fi

case "$seed" in
    legacy)
        source_file="/seed/legacy.sql"
        ;;
    clean)
        source_file="/seed/schema.sql"
        ;;
    *)
        echo "[db-seed] неизвестный MJD_DB_SEED=${seed}; допустимо legacy|clean|none" >&2
        exit 1
        ;;
esac

if [ ! -s "$source_file" ]; then
    echo "[db-seed] файл не найден или пуст: $source_file" >&2
    exit 1
fi

echo "[db-seed] импортирую ${seed} из ${source_file} в ${database}"
MYSQL_PWD="${MARIADB_ROOT_PASSWORD:-}" mariadb --protocol=socket -uroot "$database" < "$source_file"

count="$(MYSQL_PWD="${MARIADB_ROOT_PASSWORD:-}" mariadb --protocol=socket -uroot -N -B \
    -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${database}'")"
echo "[db-seed] готово: ${count} таблиц"
