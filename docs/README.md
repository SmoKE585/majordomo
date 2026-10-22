---
title: Документация
audience: user, agent
category: general
category_label: Общее
category_icon: book-2
category_order: 10
tags: документация, карта, навигация, категории
description: Карта документации MajorDoMo по категориям — что читать человеку, что агенту и что менять при доработках.
order: 1
---

# Документация

Документация — часть репозитория. Это же содержимое показывает раздел
**«База знаний»** в админке (`/panel/knowledge_base.html`), поэтому тексты
пишутся один раз здесь и не дублируются в шаблонах или базе данных.

## Как пользоваться

- **Пользователю** — начните с [`user/README.md`](user/README.md), затем
  [`user/commands.md`](user/commands.md) и [`user/modes.md`](user/modes.md).
- **Агенту** — сначала [`agents/AGENTS.md`](agents/AGENTS.md): правила проекта
  и текущее состояние. Перед переписыванием старого модуля —
  [`agents/legacy-module-migration.md`](agents/legacy-module-migration.md).
- **Перед изменением интерфейса** — [`agents/DESIGN_SYSTEM.md`](agents/DESIGN_SYSTEM.md)
  и [`agents/admin-module-ui-guidelines.md`](agents/admin-module-ui-guidelines.md).
- **В админке** — тот же материал с категориями, поиском и оглавлением.

## Категории

| Категория | Что внутри | Ключевые документы |
| --- | --- | --- |
| **Общее** | Обзор проекта, структура каталогов, установка | [`README`](README.md), [`project-structure`](agents/project-structure.md), [`install`](agents/install.md), [`AGENTS`](agents/AGENTS.md) |
| **Ядро** | Точки входа, конфигурация, авторизация, сервисы, ошибки, состояние БД, комментарии в коде | [`core-runtime`](agents/core-runtime.md), [`entrypoints`](agents/entrypoints.md), [`configuration`](agents/configuration.md), [`authentication`](agents/authentication.md), [`errors`](agents/errors.md), [`code-comments`](agents/code-comments.md), [`db-lib-analysis`](agents/db-lib-analysis.md) |
| **Свойства и данные** | Запись свойств, `ONCHANGE`, привязки, журнал изменений, справочник функций | [`property-bindings`](agents/property-bindings.md), [`property-audit`](agents/property-audit.md), [`system-functions`](user/system-functions.md) |
| **Автоматизация** | Циклы, супервизор, события, режимы | [`cycles`](agents/cycles.md), [`cycle-components`](agents/cycle-components.md), [`events`](agents/events.md), [`modes`](user/modes.md) |
| **Интерфейс** | Дизайн-система, UI-правила модулей, сборка статики, настройки, база знаний, WebSocket, редактор кода | [`DESIGN_SYSTEM`](agents/DESIGN_SYSTEM.md), [`admin-module-ui-guidelines`](agents/admin-module-ui-guidelines.md), [`admin-assets`](agents/admin-assets.md), [`settings`](agents/settings.md), [`knowledge-base`](agents/knowledge-base.md), [`websocket-ui-migration`](agents/websocket-ui-migration.md), [`codemirror-module-integration`](agents/codemirror-module-integration.md) |
| **API и маршруты** | REST API, токены, чистые URL, 404 | [`api`](agents/api.md), [`api-client`](agents/api-client.md), [`admin-routing`](agents/admin-routing.md), [`user/api`](user/api.md) |
| **Интеграции** | Сторонние библиотеки, Zigbee, сеть и память MQTT/WebSocket | [`third-party`](agents/third-party.md), [`zigbee`](agents/zigbee.md), [`user/zigbee`](user/zigbee.md), [`network-memory`](user/network-memory.md) |
| **Переписывание модулей** | Пошаговый порядок перевода legacy-модуля на новый формат | [`legacy-module-migration`](agents/legacy-module-migration.md) |
| **Пользователю** | Команды, журналы, режимы, вход в панель, инструкции | [`user/README`](user/README.md), [`commands`](user/commands.md), [`logs`](user/logs.md), [`modes`](user/modes.md), [`authentication`](user/authentication.md) |

Исторические разборы и разовые отчёты лежат в [`archive/`](archive/) и не
являются инструкциями: актуальное поведение описано в профильных документах
категорий.

## Быстрые маршруты

| Задача | Что читать |
| --- | --- |
| Первый запуск и настройка | [`install`](agents/install.md) → [`configuration`](agents/configuration.md) |
| Вход в панель и сессии | [`user/authentication`](user/authentication.md) → [`agents/authentication`](agents/authentication.md) |
| Понять, где что лежит | [`project-structure`](agents/project-structure.md) |
| Поменять свойство, `ONCHANGE` или привязку | [`property-bindings`](agents/property-bindings.md) |
| Разобрать цепочку изменения свойства | [`property-audit`](agents/property-audit.md) |
| Добавить или разобрать ошибку | [`errors`](agents/errors.md) |
| Переписать старый модуль | [`legacy-module-migration`](agents/legacy-module-migration.md) |
| Сделать страницу в админке | [`DESIGN_SYSTEM`](agents/DESIGN_SYSTEM.md) → [`admin-module-ui-guidelines`](agents/admin-module-ui-guidelines.md) |
| Добавить маршрут или 404 | [`admin-routing`](agents/admin-routing.md) |
| Фоновый цикл или событие | [`cycles`](agents/cycles.md) → [`events`](agents/events.md) |
| CLI-команда | [`console`](agents/console.md) → [`user/commands`](user/commands.md) |
| API-клиент | [`api`](agents/api.md) → [`api-client`](agents/api-client.md) |
| Деплой и nginx | [`deploy/`](deploy/) |

## Правила

- Документация меняется в том же коммите, что и код.
- Весь текст — на русском. Технические имена файлов, классов, функций и
  ключей остаются как есть; английские куски переводятся.
- У каждого файла — front matter: `title`, `audience`, `category`,
  `description`, `tags`, `order`. Категория задаётся явно, а не по каталогу:
  в `agents/` лежат документы разных категорий.
- Пути указываются от корня репозитория (`public/assets/...`, `lib/core/...`).
- Ссылки — относительные, чтобы работали и на GitHub, и в админке.
- Документация конкретного модуля живёт в `modules/<name>/docs/` и видна в
  «Базе знаний» только у установленного модуля.
- Не дублируйте один и тот же текст в двух документах: дайте ссылку.
- Проверяйте изменения командой `php tests/docs_quality_smoke.php`: она ловит
  отсутствующий front matter, неизвестную категорию, битые относительные ссылки
  и английский текст.

Правила разметки и метаданных — [`agents/knowledge-base.md`](agents/knowledge-base.md).
