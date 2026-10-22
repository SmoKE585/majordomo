# MajorDoMo

Self-hosted система умного дома на PHP. Идёт поэтапная модернизация: PHP 8.1+,
новая админка на Bootstrap 5, чистые URL `/panel/...`, отдельная сборка статики.

## Быстрый старт

Document root — `public/` (структура «а-ля Laravel»): точки входа и статика лежат там, код приложения — уровнем выше.

```bash
cp .env.example .env           # Windows: copy .env.example .env; заполните DB_*
composer install               # PHP-зависимости и автозагрузка (PHP 8.1+)
npm install
npm run build                  # админ-бандл + редактор + графики в public/assets/admin
php dev_server.php --port=8080 # локальный сервер (нужен MySQL/MariaDB)
```

`dev_server.php` сам создаст `.env` при первом запуске, если файла нет.
Открыть: `http://127.0.0.1:8080/panel/`. На новой установке панель перенаправит
на мастер первичной настройки `http://127.0.0.1:8080/install.php`: он проверит
окружение, подключит базу, запишет `.env` и настройки интерфейса. Подробно —
[docs/agents/install.md](docs/agents/install.md). После установки те же
параметры и смена базы доступны в «Общих настройках» панели; сохранение
перезапускает циклы — см. [docs/agents/settings.md](docs/agents/settings.md) и
[docs/agents/cycles.md](docs/agents/cycles.md).

PHP-пакеты устанавливаются через Composer из корня проекта. Каталог
`3rdparty/` удалён, локальная версия MQTT находится в `packages/phpmqtt/`. Установка и деплой —
[docs/agents/third-party.md](docs/agents/third-party.md).

## Документация

Единая консоль: `php majordomo` (список команд),
`php majordomo help cycles:restart` (справка), `php majordomo about` (информация).
Управление циклами, диагностика, журналы, конфигурация и установка —
[инструкция](docs/user/commands.md); создание новых команд —
[правила для агентов](docs/agents/console.md).

- [docs/README.md](docs/README.md) — карта всей документации по категориям.
- [docs/user/README.md](docs/user/README.md) — описание проекта, установка, деплой.
- [docs/agents/AGENTS.md](docs/agents/AGENTS.md) — инструкции для агентов и контрибьюторов.
- [docs/agents/legacy-module-migration.md](docs/agents/legacy-module-migration.md) — переписывание старых модулей.
- [docs/agents/configuration.md](docs/agents/configuration.md) — `.env`, `Config` и правила для модулей.
- [docs/agents/DESIGN_SYSTEM.md](docs/agents/DESIGN_SYSTEM.md) — дизайн-система админки.
- [docs/agents/project-structure.md](docs/agents/project-structure.md) — структура проекта и URL-контракт.

## Лицензия

См. [LICENSE](LICENSE).
