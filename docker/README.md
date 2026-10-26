# MajorDoMo в Docker — только для отладки

> **Это не production-развёртывание.** Docker-стенд нужен, чтобы запускать
> ядро, циклы, MQTT и базу на одной машине и смотреть, как ведут себя
> изменения. Боевая установка — Linux/Ubuntu, nginx, PHP-FPM, systemd и
> отдельные службы; см. [`docs/deploy/server-setup.md`](../docs/deploy/server-setup.md)
> и [`docs/agents/deployment.md`](../docs/agents/deployment.md).
>
> В стенде намеренно упрощены вещи, которые в production делаются иначе:
> nginx и PHP-FPM в одном контейнере, анонимный MQTT без TLS, нестрогий
> SQL-mode, предсказуемый пароль администратора. Не переносите эту
> конфигурацию на рабочий сервер.

Стенд поднимает:

| Сервис | Что делает | Наружу |
| --- | --- | --- |
| `app` | nginx + PHP-FPM + супервизор циклов + WebSocket | **только HTTP-порт** (по умолчанию 80) |
| `db` | MariaDB 11.4, старая схема MajorDoMo и миграции | нет |
| `redis` | быстрый путь MQTT, очередь `core_runtime`, кэш | нет |
| `mosquitto` | MQTT-брокер, к которому подключается `cycle_mqtt` | нет |

MariaDB, Redis и MQTT доступны только внутри сети compose. Снаружи открыт
один порт, чтобы заходить с компьютера или телефона в локальной сети.

## 1. Быстрый старт

Нужен Docker Desktop (Windows/macOS) или Docker Engine с Compose (Linux).
Для первого запуска желательно 4 ГБ свободной памяти и 5 ГБ диска.

```bash
# локально, http://localhost/
docker compose up -d --build

# с телефона/по LAN, если создан docker/.env с MJD_BASE_URL
docker compose --env-file docker/.env up -d --build
```

Первый запуск занимает несколько минут: скачиваются образы, собирается PHP,
импортируется демо-база и применяются миграции. Контейнер `app` может быть
`health: starting` до 4 минут — это нормально.

Открыть:

```text
http://localhost/
```

Логин по умолчанию:

```text
admin / majordomo
```

Пароль задаётся переменной `MJD_ADMIN_PASSWORD` (см. раздел 7). Если вход не
подходит, проверьте, что база заполнилась, и посмотрите
`docker compose logs app`.

Для доступа по LAN или с телефона создайте `docker/.env` с `MJD_BASE_URL`
(раздел 2) и запускайте `docker compose --env-file docker/.env up -d ...`.
Файл `docker/.env` не коммитится.

Остановить, сохранив данные:

```bash
docker compose down
```

Остановить и **удалить базу/логи/состояние** (стенд начнётся с нуля):

```bash
docker compose down -v
```

## 2. Доступ с телефона или другого компьютера

1. Узнайте IP компьютера в локальной сети, например `192.168.1.50`.
2. Разрешите входящие подключения к порту 80 в брандмауэре Windows.
3. Укажите этот адрес в `MJD_BASE_URL` **до первого запуска** или пересоздайте
   контейнер:

   ```bash
   MJD_BASE_URL=http://192.168.1.50 docker compose up -d --force-recreate
   ```

   На Windows PowerShell переменную удобнее задать в `docker/.env`:

   ```text
   MJD_BASE_URL=http://192.168.1.50
   ```

   и запустить:

   ```bash
   docker compose --env-file docker/.env up -d --build
   ```

4. Откройте на телефоне `http://192.168.1.50/`.

Почему это важно: сервер WebSocket сверяет `Origin` браузера с `BASE_URL`.
Для локальных и приватных адресов (`localhost`, `127.0.0.1`, `192.168.x.x`,
`10.x.x.x`) сделано исключение — это один локальный стенд, поэтому WebSocket
не отклонит подключение при небольшом расхождении. Публичные домены
проверяются строго. Но `BASE_URL` всё равно лучше указывать фактическим
LAN-адресом: от него зависят абсолютные ссылки в панели.

Если 80-й порт занят, используйте другой внешний порт:

```text
MJD_HTTP_PORT=8080
MJD_BASE_URL=http://192.168.1.50:8080
```

Внутри контейнера nginx всегда слушает 80; меняется только публикация порта.

## 3. База: старая схема, чистая схема или пустая

Режим задаётся `MJD_DB_SEED` при **первом** создании тома `majordomo_db`:

| Значение | Что импортируется | Зачем |
| --- | --- | --- |
| `legacy` (по умолчанию) | `docs/dev/db_terminal.sql` — старый демо-дамп MajorDoMo 2020 года | Проверять миграции и работу на структуре, доставшейся от прежних версий |
| `clean` | `lib/core/Install/schema.sql` — только схема без демо-данных | Чистая установка, как после мастера `/install.php` |
| `none` | ничего | База создаётся вручную или мастером |

После импорта entrypoint применяет файловые миграции ядра
(`lib/core/Migrations/`). То же самое затем делает `cycle.php` при старте.
Если миграция падает, контейнер не останавливается: ошибка пишется в
`docker compose logs app`, а панель остаётся доступной для разбора.

Проверить и применить миграции вручную:

```bash
docker compose exec -u www-data app php majordomo migrations:status
docker compose exec -u www-data app php majordomo migrations:run
docker compose exec -u www-data app php majordomo migrations:run --pending
```

Чтобы сменить режим, нужно удалить именно том базы:

```bash
docker compose down
docker volume rm majordomo-debug_majordomo_db
MJD_DB_SEED=clean docker compose up -d
```

Имя тома зависит от имени проекта (`name: majordomo-debug` в compose). Точный
список: `docker volume ls | grep majordomo`.

## 4. Защита базы от удаления

В стенде включён `DB_PROTECT_DROP=1`. Приложение отказывается выполнять
`DROP DATABASE` и `DROP SCHEMA` — независимо от того, пришёл запрос из
панели, старого модуля, CLI или нового кода. `DROP TABLE`, `ALTER TABLE` и
миграции при этом работают: иначе нельзя было бы проверять обновление схемы.

Это защита **на уровне приложения**. Она не мешает:

- удалить том командой `docker compose down -v`;
- выполнить `DROP DATABASE` вручную через `mariadb` под root;
- пересоздать базу, если вы осознанно отключили защиту
  (`MJD_DB_PROTECT_DROP=0`).

Если нужно начать с чистой базы, используйте удаление тома, а не SQL:

```bash
docker compose down -v
docker compose up -d --build
```

Проверить, что защита включена:

```bash
docker compose exec app php -r "require '/var/www/html/bootstrap.php'; var_dump(MajorDoMo\Core\Config::bool('DB_PROTECT_DROP'));"
```

## 5. MQTT: брокер внутри стенда

`mosquitto` слушает `1883` только внутри compose. `cycle_mqtt` подключается
к `mosquitto:1883`; при первом запуске `configure.php` записывает эти
параметры в конфигурацию модуля MQTT.

Проверить публикацию и подписку:

```bash
# Подписаться на всё, что приходит в /var/now/#
docker compose exec app mosquitto_sub -h mosquitto -t '/var/now/#' -v

# В другом терминале — опубликовать тестовое значение
docker compose exec app mosquitto_pub -h mosquitto -t '/var/now/docker/test' -m '42'
```

После публикации откройте `/panel/mqtt.html`: тема `/var/now/docker/test`
должна появиться в журнале. Чтобы значение попало в свойство, создайте
привязку в редакторе темы (объект и свойство) — после этого публикация будет
обновлять свойство.

Проверить состояние быстрого пути:

```bash
docker compose exec -u www-data app php majordomo mqtt:runtime
docker compose exec -u www-data app php majordomo mqtt:runtime --failed
```

Если брокер перезапускался, `cycle_mqtt` переподключится сам; при
необходимости перезапустите цикл:

```bash
docker compose exec -u www-data app php majordomo cycles:restart mqtt
```

## 6. Циклы и WebSocket

`cycle.php` — супервизор, он запускает дочерние `scripts/cycle_*.php`.
В контейнере его держит supervisord, поэтому упавший супервизор поднимается
заново.

```bash
# Статус циклов
docker compose exec -u www-data app php majordomo cycles:status

# Перезапустить один цикл или все running-циклы
docker compose exec -u www-data app php majordomo cycles:restart mqtt
docker compose exec -u www-data app php majordomo cycles:restart --all

# Живые логи приложения, nginx, PHP-FPM и cycle.php
docker compose logs -f app

# Файловые журналы циклов внутри тома
docker compose exec app ls -la cms/debmes
```

Команды `php majordomo`, которые пишут в `cms/`, запускайте от `www-data`
(флаг `-u www-data`), как в примерах выше. Read-only команды работают и от
root, но единый пользователь избавляет от root-owned файлов в runtime.
Применение `stop`/`start`/`restart` занимает несколько секунд: супервизор
читает состояние раз в 5 секунд, а `cycle_websockets` после ручного
перезапуска может один раз пройти восстановление.

В X-Ray (`/panel/xray.html`) видно состояние циклов, консоль и журналы.
WebSocket доступен браузеру по `/ws`; nginx проксирует его на
`127.0.0.1:8001` внутри контейнера. Если живые обновления не работают,
сначала проверьте `BASE_URL` (раздел 2) и цикл `websockets`:

```bash
docker compose exec -u www-data app php majordomo cycles:status | grep -i websocket
docker compose exec app sh -c 'php -r "\$s=@fsockopen(\"127.0.0.1\",8001); exit(\$s?0:1);" && echo ws-port-ok'
```

## 7. Переменные стенда

Все переменные необязательны: у них есть значения по умолчанию в
`docker-compose.yml`. Для постоянной настройки скопируйте
[`docker/.env.example`](.env.example) в `docker/.env` и запускайте
`docker compose --env-file docker/.env up -d --build`.

| Переменная | По умолчанию | Смысл |
| --- | --- | --- |
| `MJD_HTTP_PORT` | `80` | Внешний HTTP-порт |
| `MJD_BASE_URL` | `http://localhost` | Публичный адрес для ссылок, API и проверки Origin WebSocket |
| `MJD_TIMEZONE` | `Europe/Moscow` | Часовой пояс контейнера и PHP |
| `MJD_SITE_TIMEZONE` | `Europe/Moscow` | Настройка `SITE_TIMEZONE` в базе |
| `MJD_SITE_LANGUAGE` | `ru` | Язык интерфейса |
| `MJD_THEME` | `dark` | Тема по умолчанию (если в базе ещё нет `THEME`) |
| `MJD_DB_NAME` | `majordomo` | Имя базы |
| `MJD_DB_USER` | `majordomo` | Пользователь приложения |
| `MJD_DB_PASSWORD` | `majordomo` | Пароль приложения |
| `MJD_DB_ROOT_PASSWORD` | `majordomo-root` | Root-пароль MariaDB (только для диагностики) |
| `MJD_DB_SEED` | `legacy` | `legacy` \| `clean` \| `none` |
| `MJD_DB_PROTECT_DROP` | `1` | Запрет `DROP DATABASE`/`DROP SCHEMA` из приложения |
| `MJD_ADMIN_USER` | `admin` | Логин администратора |
| `MJD_ADMIN_PASSWORD` | `majordomo` | Пароль при первом создании/сбросе |
| `MJD_ADMIN_RESET_PASSWORD` | `0` | `1` — сбросить пароль при старте |
| `MJD_RUN_MIGRATIONS` | `1` | Применять миграции при старте |
| `MJD_FORCE_ENV` | `0` | `1` — перезаписать `.env` из переменных compose |
| `MJD_MQTT_FORCE_CONFIG` | `0` | `1` — вернуть MQTT на встроенный `mosquitto` |

`.env` создаётся внутри контейнера при первом старте. При пересоздании
контейнера (`up --force-recreate`, `up --build`) он генерируется заново из
переменных compose. Если вы меняли `.env` через панель и хотите это
сохранить, не пересоздавайте контейнер без необходимости; для постоянной
настройки используйте переменные compose.

Имя базы, пользователь и пароль применяются MariaDB при **первом** создании
тома `majordomo_db`. После смены `MJD_DB_NAME`/`MJD_DB_USER`/`MJD_DB_PASSWORD`
на существующем томе либо измените пользователя в MariaDB вручную, либо
пересоздайте стенд с `docker compose down -v`.

## 8. Как менять код ядра

Образ собирается из текущего каталога репозитория. После правок PHP:

```bash
docker compose up -d --build
```

Пересборка использует кэш слоёв: заново копируется только изменившийся код,
зависимости Composer пересобираются лишь при изменении `composer.json` или
`composer.lock`.

Если менялись исходники статики (`resources/js`, `resources/css`), сначала
соберите бандл на хосте:

```bash
npm run build
docker compose up -d --build
```

Контейнер не монтирует код с хоста: так исключены случайные правки
`vendor/`, `.env` и runtime-каталогов из локальной разработки. Если нужна
горячая правка без пересборки, используйте
`docker compose cp <файл> app:/var/www/html/<путь>` и перезапустите нужный
цикл; для постоянной работы всё же пересоберите образ.

## 9. Диагностика и частые проблемы

```bash
docker compose ps
docker compose logs -f app
docker compose exec -u www-data app php majordomo doctor
docker compose exec -u www-data app php majordomo cycles:status
docker compose exec -u www-data app php majordomo migrations:status
docker compose exec -u www-data app php majordomo mqtt:runtime
```

| Симптом | Что проверить |
| --- | --- |
| `port is already allocated` | Порт 80 занят: задайте `MJD_HTTP_PORT=8080` и `MJD_BASE_URL=http://localhost:8080` |
| `app` долго `health: starting` | Первый импорт базы и миграции могут занять 2–4 минуты; смотрите `docker compose logs -f app` |
| Панель уводит на `/install.php` | База пустая или не записан маркер. Проверьте `MJD_DB_SEED`, логи `db` и `app`; при необходимости `docker compose down -v` и запустите снова |
| Не подходит пароль | `MJD_ADMIN_PASSWORD`, затем `docker compose exec -u www-data app php majordomo users:list`; сброс — `MJD_ADMIN_RESET_PASSWORD=1 docker compose up -d --force-recreate` |
| Нет живых обновлений | `MJD_BASE_URL` должен совпадать с адресом в браузере; проверьте цикл `websockets` |
| MQTT-тема не появляется | Смотрите `docker compose logs app`, `docker compose exec -u www-data app php majordomo cycles:status`; проверьте, что `cycle_mqtt` запущен |
| Миграция упала | `docker compose exec -u www-data app php majordomo migrations:status`, полный текст — в `cms/debmes` и `docker compose logs app` |
| Телефон не открывает страницу | IP и Wi-Fi, брандмауэр Windows, `MJD_BASE_URL`, изоляция клиентов на роутере |

Сброс только веб-состояния (сессии, кэш), без базы:

```bash
docker compose exec -u www-data app rm -rf cms/cached var/sessions cms/sessions
docker compose restart app
docker compose exec -u www-data app php majordomo config:clear
```

## 10. Ограничения стенда

- Нет TLS: не открывайте порт в интернет.
- MQTT без пароля: любой в локальной сети, кто дошёл до брокера, может
  публиковать. Порт брокера наружу не опубликован, но сеть compose общая.
- Нет systemd: перезапуском занимается supervisord внутри контейнера.
- Нет отдельных контейнеров для nginx/PHP-FPM: это упрощает отладку, но не
  повторяет production-топологию.
- База защищена только приложением. `docker compose down -v` и root-доступ к
  MariaDB по-прежнему удаляют данные.
- Docker-стенд не проверяет обновление системы через `deploy.sh` и не
  заменяет проверки на Ubuntu.
