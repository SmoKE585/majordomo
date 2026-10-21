# MajorDoMo

Self-hosted система умного дома на PHP. Идёт поэтапная модернизация: PHP 8.4+,
новая админка на Bootstrap 5, чистые URL `/panel/...`, отдельная сборка статики.

## Быстрый старт

Document root — `public/` (структура «а-ля Laravel»): точки входа и статика лежат там, код приложения — уровнем выше.

```bash
npm install
npm run build                  # админ-бандл + редактор + графики в public/assets/admin
php dev_server.php --port=8080 # локальный сервер (нужен MySQL/MariaDB)
```

Открыть: `http://127.0.0.1:8080/panel/`.

## Документация

- [docs/README.md](docs/README.md) — карта всей документации.
- [docs/user/README.md](docs/user/README.md) — описание проекта, установка, деплой.
- [docs/agents/AGENTS.md](docs/agents/AGENTS.md) — инструкции для агентов и контрибьюторов.
- [docs/agents/DESIGN_SYSTEM.md](docs/agents/DESIGN_SYSTEM.md) — дизайн-система админки.
- [docs/agents/project-structure.md](docs/agents/project-structure.md) — структура проекта и URL-контракт.

## Лицензия

См. [LICENSE](LICENSE).