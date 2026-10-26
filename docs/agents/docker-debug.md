---
title: Docker-стенд для отладки
audience: agent
category: deploy
tags: docker, отладка, compose, mariadb, redis, mqtt, миграции, db_protect_drop
description: Правила и устройство docker-стенда MajorDoMo: только отладка, один внешний порт, встроенные MariaDB/Redis/Mosquitto, защита базы от DROP DATABASE и проверка миграций.
order: 40
---

# Docker-стенд для отладки

> **Docker в проекте — только для локальной отладки и тестирования.**
> Это не production-развёртывание и не замена `docs/deploy/server-setup.md`.
> Боевая система работает на Ubuntu с nginx, PHP-FPM, systemd, отдельными
> MariaDB/Redis/MQTT и штатным деплоем. Не переносите docker-конфигурацию
> (анонимный MQTT, один контейнер, предсказуемые пароли, нестрогий SQL-mode)
> на рабочий сервер.

Пользовательская инструкция — `docker/README.md` в корне репозитория.
Короткая версия — [`docs/user/docker-debug.md`](../user/docker-debug.md).

## 1. Что запускается

`docker compose` поднимает четыре сервиса:

| Сервис | Содержимое | Внешний порт |
| --- | --- | --- |
| `app` | nginx, PHP-FPM, `cycle.php`, WebSocket | только HTTP (по умолчанию 80) |
| `db` | MariaDB 11.4 | нет |
| `redis` | Redis 7.4, `appendonly`, `maxmemory-policy noeviction` | нет |
| `mosquitto` | MQTT-брокер, анонимный доступ | нет |

Внутри `app` работают:

- nginx — отдаёт `public/`, проксирует `/ws` на Workerman `127.0.0.1:8001`;
- PHP-FPM — слушает `127.0.0.1:9000`;
- supervisord — держит nginx, PHP-FPM и `cycle.php`;
- `cycle.php` — сам запускает дочерние `scripts/cycle_*.php`, включая
  `cycle_mqtt` и `cycle_websockets`.

Код приложения лежит в образе. Named volumes хранят только runtime:
`majordomo_cms` (`cms/`), `majordomo_var` (`var/`), `majordomo_backup`
(`backup/`), базу, Redis и данные Mosquitto.

## 2. Файлы

| Путь | Назначение |
| --- | --- |
| `docker-compose.yml` | Сервисы, порты, healthcheck, volumes |
| `docker/.env.example` | Переменные стенда для `--env-file` |
| `docker/app/Dockerfile` | PHP 8.4 + nginx + supervisor + расширения |
| `docker/app/entrypoint.sh` | Ожидание сервисов, генерация `.env`, миграции, запуск |
| `docker/app/nginx.conf` | Конфиг nginx для одного контейнера |
| `docker/app/php.ini` | Лимиты, OPcache, сессии |
| `docker/app/php-fpm.conf` | Пул PHP-FPM |
| `docker/app/supervisord.conf` | Управление nginx/PHP-FPM/циклами |
| `docker/init/configure.php` | Админ, базовые настройки, MQTT, маркер установки |
| `docker/db/seed.sh` | Первичный импорт `legacy`/`clean` |
| `docker/mosquitto/mosquitto.conf` | Анонимный брокер только для отладки |
| `.dockerignore` | Не тащит в образ `.env`, `vendor`, `node_modules`, runtime |

## 3. Жёсткие правила

1. **Наружу только один HTTP-порт.** Не публикуйте `3306`, `6379`, `1883`,
   `9000`, `8001` и не добавляйте `network_mode: host`. Для отладки MQTT
   используйте `docker compose exec app mosquitto_pub ...`.
2. **Docker — не production.** Не добавляйте systemd, TLS-терминацию,
   секреты, боевые пароли и production-прокси в этот стенд. Для боевой
   установки есть `docs/deploy/` и `docs/agents/deployment.md`.
3. **База защищена от `DROP DATABASE`.** `DB_PROTECT_DROP=1` включается
   entrypoint'ом. Проверка живёт в `lib/core/DatabaseGuard.php` и вызывается
   из `lib/mysqli.class.php`, `lib/mysql.class.php`, `lib/core/Database.php` и
   `lib/db.class.php`. Не убирайте её и не обходите прямым SQL. `DROP TABLE`
   и миграции разрешены — иначе нельзя проверять обновление схемы.
4. **Не монтируйте весь репозиторий в `/var/www/html`.** Это скроет `vendor/`,
   `.env` и runtime-каталоги образа и смешает локальную разработку с
   docker-базой. Для горячей правки используйте `docker compose cp` и
   перезапуск цикла, для постоянной — `up -d --build`.
5. **`BASE_URL` лучше задавать фактическим адресом.** Для локальных и
   приватных адресов (`localhost`, `127.0.0.1`, LAN) WebSocket считает их
   одним стендом и не отклоняет Origin при расхождении; публичный домен
   должен совпадать точно. От `BASE_URL` зависят абсолютные ссылки в панели.
6. **`MJD_*` — переменные compose, а не `Config`.** Приложение читает `.env`,
   который генерирует entrypoint. Новые ключи добавляйте в `ConfigSchema`,
   `.env.example`, entrypoint и `docker/.env.example`.
7. **Не меняйте `docs/dev/db_terminal.sql` ради стенда.** Это общий демо-дамп.
   Если нужна другая стартовая схема — добавьте отдельный seed-файл и режим в
   `docker/db/seed.sh`, обновите документацию.
8. **`cycle.php` намеренно ждёт 30 секунд** перед загрузкой приложения: база и
   MQTT после старта контейнера должны успеть подняться. Не «оптимизируйте»
   эту паузу в docker-коде; healthcheck имеет `start_period: 240s`.
9. **Не используйте `docker compose down -v` как обычную команду.** Она
   удаляет базу и все данные стенда. В инструкциях всегда предупреждайте об
   этом.

## 4. Как менять стенд

### Добавить PHP-расширение

Добавьте имя в `install-php-extensions` в `docker/app/Dockerfile` и
пересоберите образ. Не устанавливайте расширения вручную в контейнере: они
исчезнут при пересоздании.

### Добавить сервис

Добавьте его в `docker-compose.yml` с healthcheck и внутренним именем.
Публикуйте порт только у `app`. Если сервису нужен доступ из приложения,
передайте имя через `MJD_*` и добавьте ключ в `.env` (entrypoint).

### Изменить стартовую базу

- `MJD_DB_SEED=legacy` — старый дамп + миграции (по умолчанию);
- `MJD_DB_SEED=clean` — чистая схема;
- `MJD_DB_SEED=none` — пустая база, мастер `/install.php`.

Режим применяется только при создании тома `majordomo_db`. Для смены режима
удалите том базы.

### Изменить nginx

Правьте `docker/app/nginx.conf`. Обязательно сохраните:

- `location = /ws` с `Upgrade`/`Connection` и `X-Forwarded-For $remote_addr`;
- `location ~ ^/api(?:\.php)?(?:/|$)` с исходным `REQUEST_URI`;
- `location ^~ /panel/` и `/modules/`;
- запрет `lib/`, `templates/`, `scripts/`, `vendor/`, `.env`, `config.php`.

После правки: `docker compose up -d --build`.

### Изменить настройки при старте

`docker/init/configure.php` идемпотентен. Он не сбрасывает пароль
существующего администратора без `MJD_ADMIN_RESET_PASSWORD=1` и не
перезаписывает MQTT-хост, если там уже не `localhost`. Сохраняйте это
поведение: иначе правки из панели будут теряться при каждом рестарте.

## 5. Проверка изменений

Минимум:

```bash
docker compose config --quiet
docker compose up -d --build
docker compose ps
curl -fsS -o /dev/null http://127.0.0.1/panel/
docker compose exec -u www-data app php majordomo migrations:status
docker compose exec -u www-data app php majordomo cycles:status
docker compose exec -u www-data app php majordomo mqtt:runtime
```

Дополнительно:

- панель открывается и пускает `admin` / пароль из `MJD_ADMIN_PASSWORD`;
- X-Ray показывает `cycle_main`, `cycle_core`, `cycle_mqtt`,
  `cycle_mqtt_runtime`, `cycle_websockets` в состоянии `running`;
- `mosquitto_pub` в `/var/now/docker/test` появляется в `/panel/mqtt.html`;
- WebSocket: в консоли браузера соединение `/ws` открыто, живое обновление
  свойства приходит;
- защита базы:

  ```bash
  php tests/db_guard_smoke.php
  docker compose exec app php -r "require '/var/www/html/bootstrap.php'; var_dump(MajorDoMo\Core\Config::bool('DB_PROTECT_DROP'));"
  ```

- документация: `php tests/docs_quality_smoke.php`, `php -l` новых PHP-файлов.

## 6. Ограничения

- Нет systemd: перезапуск только через supervisord.
- Нет TLS и авторизации MQTT: только доверенная локальная сеть.
- Нет отдельных контейнеров nginx/PHP-FPM: топология упрощена.
- Нет production-обновления через `deploy.sh`; обновление стенда —
  пересборка образа.
- `DB_PROTECT_DROP` защищает только приложение. `down -v` и root-доступ к
  MariaDB по-прежнему удаляют данные.
