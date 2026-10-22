# AGENTS.md

Этот файл — обязательный вход для агентов, которые работают с репозиторием.

**Полная версия инструкций: `docs/agents/AGENTS.md` — прочитайте её перед любыми изменениями.**

Коротко, что нельзя нарушать:

- Проект — MajorDoMo (PHP 8.1+, локальное окружение PHP 8.4), идёт поэтапный редизайн админки на Bootstrap 5. Новый код и Composer-зависимости должны сохранять совместимость с PHP 8.1.
- Перед любым UI-изменением читать `docs/agents/DESIGN_SYSTEM.md`; новые компоненты — только `MDJUI` + `md-*`.
- Старые модули должны продолжать работать: не ломать URL, формат данных, глобалы jQuery/Bootstrap, `partLoad`.
- Конфигурация — только `.env` + `MajorDoMo\Core\Config`; не добавлять `define()` и не читать `getenv()` в модулях. Подробно: `docs/agents/configuration.md`.
- CLI — `php majordomo`: каждая новая команда отдельным классом в `lib/core/Console/Commands/`, регистрация в `Console\Application`. Для функций, которым нужно управление из терминала, добавляйте команду вместе с реализацией; сначала читайте `docs/agents/console.md`. Не создавайте новые самостоятельные CLI-скрипты в корне или `tools/`.
- Статика — только в `public/assets/`, исходники бандла — в `resources/js/` и `resources/css/`; сборка `npm run build`.
- Никаких новых `<script>`/`<link>` на библиотеки в шаблонах: только npm + бандл.
- Проверка перед сдачей: `php -l`, `node --check`, `npm run build`, `php tests/routing_smoke.php`, `php tests/config_smoke.php`.

Остальная документация: `docs/README.md`.
