---
title: Документация
audience: user, agent
category: general
description: Карта документации MajorDoMo: материалы для агентов, пользователей, DevOps и разработки.
---

# Документация

Всё, что не код и не статика, живёт здесь. Две основные аудитории разделены
по каталогам, чтобы агент и пользователь не читали лишнего.

## Карта

| Каталог | Для кого | Что внутри |
| --- | --- | --- |
| [`agents/`](agents/) | агенты и контрибьюторы | `AGENTS.md` (полные правила), `DESIGN_SYSTEM.md`, `configuration.md`, `entrypoints.md`, `install.md`, `project-structure.md`, `admin-assets.md`, `admin-module-ui-guidelines.md`, `admin-routing.md`, `third-party.md`, технические заметки по ядру и циклам |
| [`user/`](user/) | пользователи и администраторы | описание проекта (`README.md`), `commands.md` (CLI-команды), блог, контракты API (Postman) |
| [`deploy/`](deploy/) | DevOps | `nginx.conf.sample`, боевой `nginx.conf.may-13.ru.sample`, systemd-unit, `install-linux.sh` |
| [`dev/`](dev/) | разработка | `db_terminal.sql` — схема + демо-данные для локальной базы |
| [`archive/`](archive/) | история | разовые отчёты и разборы, не актуальные для ежедневной работы |
| `ui-redesign/` | локально | скриншоты итераций редизайна (в git не попадают) |

## База знаний в админке

Все Markdown-файлы из этого каталога отображаются в админке в разделе
**«База знаний»** (`/panel/knowledge_base.html`): с категориями, метками
аудитории (`user`/`agent`), поиском и оглавлением. Менять тексты нужно здесь,
в `docs/`; отдельной копии в базе данных нет.

- Как добавить метки и категорию: [`agents/knowledge-base.md`](agents/knowledge-base.md).
- Пользовательская инструкция по командам: [`user/commands.md`](user/commands.md).
- API и токены: [`user/api.md`](user/api.md); инструкция ИИ-клиенту — [`agents/api-client.md`](agents/api-client.md).
- Системные PHP-функции для сценариев, модулей и агентов: [`user/system-functions.md`](user/system-functions.md).

## С чего начать

- Впервые в проекте: [`user/README.md`](user/README.md) → [`agents/project-structure.md`](agents/project-structure.md).
- Первый запуск и мастер настройки: [`agents/install.md`](agents/install.md).
- Меняете интерфейс: [`agents/DESIGN_SYSTEM.md`](agents/DESIGN_SYSTEM.md) → [`agents/admin-module-ui-guidelines.md`](agents/admin-module-ui-guidelines.md).
- Читаете или добавляете настройки: [`agents/configuration.md`](agents/configuration.md) → [`agents/settings.md`](agents/settings.md).
- Меняете управление циклами: [`agents/cycles.md`](agents/cycles.md).
- Добавляете CLI-команду: [`agents/console.md`](agents/console.md); список команд для пользователя — [`user/commands.md`](user/commands.md).
- Добавляете системное событие или подписку: [`agents/events.md`](agents/events.md).
- Меняете свойства, `ONCHANGE` или привязку модуля к свойству: [`agents/property-bindings.md`](agents/property-bindings.md).
- Разбираете цепочку изменений свойства: [`agents/property-audit.md`](agents/property-audit.md).
- Меняете точку входа или boot: [`agents/entrypoints.md`](agents/entrypoints.md).
- Трогаете сборку: [`agents/admin-assets.md`](agents/admin-assets.md).
- Трогаете URL/роутинг: [`agents/admin-routing.md`](agents/admin-routing.md).
- Настраиваете сервер: [`deploy/`](deploy/).

## Правила

- Документация — часть репозитория: обновляйте её вместе с кодом в том же коммите.
- Пути в тексте указываются от корня репозитория (`public/assets/...`, `resources/...`).
- Ссылки — относительные, чтобы работали и на GitHub, и локально.
