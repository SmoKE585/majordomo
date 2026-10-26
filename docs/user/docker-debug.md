---
title: Запуск MajorDoMo в Docker (только отладка)
audience: user
category: deploy
tags: docker, отладка, запуск, пароль, mqtt, база, порт, миграции
description: Как поднять отладочный стенд MajorDoMo в Docker, войти в панель, проверить MQTT и циклы, не потерять базу и почему этот способ не предназначен для production.
order: 45
---

# Запуск MajorDoMo в Docker (только отладка)

Docker-стенд нужен, чтобы быстро проверять ядро, циклы, MQTT и миграции на
одной машине. **Это не production-развёртывание.** На рабочем сервере
используются Ubuntu, nginx, PHP-FPM, systemd и отдельные службы — см.
[`docs/deploy/server-setup.md`](../deploy/server-setup.md).

Полная инструкция со всеми переменными и разбором ошибок — `docker/README.md`
в корне репозитория.

## Быстрый старт

Нужен Docker Desktop или Docker Engine с Compose.

```bash
docker compose up -d --build
```

Открыть `http://localhost/`, войти:

```text
admin / majordomo
```

Первый запуск занимает несколько минут: импортируется старый демо-дамп,
применяются файловые миграции, запускаются циклы. Контейнер может быть
`health: starting` до 4 минут.

Остановить, сохранив данные:

```bash
docker compose down
```

Удалить базу и все данные стенда:

```bash
docker compose down -v
```

## Что внутри

| Сервис | Назначение | Наружу |
| --- | --- | --- |
| `app` | nginx, PHP-FPM, циклы, WebSocket | только порт 80 |
| `db` | MariaDB со старой схемой и миграциями | нет |
| `redis` | быстрый путь MQTT и очередь ядра | нет |
| `mosquitto` | MQTT-брокер | нет |

Наружу открыт один HTTP-порт, чтобы заходить с компьютера или телефона в
локальной сети. База, Redis и MQTT доступны только внутри compose.

## Доступ с телефона

1. Узнайте IP компьютера, например `192.168.1.50`.
2. Разрешите порт 80 в брандмауэре.
3. Запустите с адресом компьютера:

   ```bash
   MJD_BASE_URL=http://192.168.1.50 docker compose up -d --force-recreate
   ```

4. Откройте на телефоне `http://192.168.1.50/`.

`localhost` в `MJD_BASE_URL` можно оставить для доступа с этого же компьютера,
но для телефона лучше указать LAN-адрес: WebSocket теперь принимает
локальные/приватные адреса, однако от `BASE_URL` зависят абсолютные ссылки в
панели. Если 80-й порт занят, задайте `MJD_HTTP_PORT=8080` и
`MJD_BASE_URL=http://192.168.1.50:8080`.

## База и миграции

По умолчанию (`MJD_DB_SEED=legacy`) импортируется `docs/dev/db_terminal.sql`
— старая структура MajorDoMo с демо-данными. Затем автоматически применяются
миграции ядра. Это и есть проверка «как пройдут миграции на старой базе».

Другие режимы:

- `MJD_DB_SEED=clean` — чистая схема без демо-данных;
- `MJD_DB_SEED=none` — пустая база, мастер `/install.php`.

Проверить миграции вручную:

```bash
docker compose exec -u www-data app php majordomo migrations:status
docker compose exec -u www-data app php majordomo migrations:run
```

## База защищена от удаления

В стенде включён `DB_PROTECT_DROP=1`: приложение не выполняет
`DROP DATABASE` и `DROP SCHEMA`. Это защита от случайного удаления базы из
панели, модуля или CLI. `DROP TABLE` и миграции работают.

Чтобы действительно начать с нуля, удалите том базы:

```bash
docker compose down -v
docker compose up -d --build
```

## MQTT

Брокер уже поднят и подключён к циклу MQTT. Проверка:

```bash
docker compose exec app mosquitto_pub -h mosquitto -t '/var/now/docker/test' -m '42'
docker compose exec app mosquitto_sub -h mosquitto -t '/var/now/#' -v
```

После публикации тема появится в `/panel/mqtt.html`. Чтобы значение попало в
свойство, создайте привязку в редакторе темы.

## Циклы и логи

```bash
docker compose exec -u www-data app php majordomo cycles:status
docker compose logs -f app
```

В X-Ray (`/panel/xray.html`) видно состояние циклов и журналы. Первые 30
секунд после старта супервизор намеренно ждёт, пока поднимутся база и MQTT.

## После изменения кода

```bash
docker compose up -d --build
```

Образ пересобирается из текущего каталога. Для статики сначала выполните
`npm run build` на хосте. Контейнер не монтирует код с хоста, чтобы не
смешивать локальные `.env`, `vendor/` и runtime-данные с docker-базой.

## Важно

- Не открывайте стенд в интернет: MQTT без пароля, TLS нет.
- Не публикуйте порты 3306, 6379, 1883, 8001.
- Не используйте docker-конфигурацию как инструкцию по production-деплою.
- `docker compose down -v` удаляет данные без дополнительного подтверждения.
