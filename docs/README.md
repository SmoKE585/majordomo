# Документация

Всё, что не код и не статика, живёт здесь. Две основные аудитории разделены
по каталогам, чтобы агент и пользователь не читали лишнего.

## Карта

| Каталог | Для кого | Что внутри |
| --- | --- | --- |
| [`agents/`](agents/) | агенты и контрибьюторы | `AGENTS.md` (полные правила), `DESIGN_SYSTEM.md`, `configuration.md`, `project-structure.md`, `admin-assets.md`, `admin-module-ui-guidelines.md`, `admin-routing.md`, `third-party.md`, технические заметки по ядру и циклам |
| [`user/`](user/) | пользователи и администраторы | описание проекта (`README.md`), блог, контракты API (Postman) |
| [`deploy/`](deploy/) | DevOps | `nginx.conf.sample`, боевой `nginx.conf.may-13.ru.sample`, systemd-unit, `install-linux.sh` |
| [`dev/`](dev/) | разработка | `db_terminal.sql` — схема + демо-данные для локальной базы |
| [`archive/`](archive/) | история | разовые отчёты и разборы, не актуальные для ежедневной работы |
| `ui-redesign/` | локально | скриншоты итераций редизайна (в git не попадают) |

## С чего начать

- Впервые в проекте: [`user/README.md`](user/README.md) → [`agents/project-structure.md`](agents/project-structure.md).
- Меняете интерфейс: [`agents/DESIGN_SYSTEM.md`](agents/DESIGN_SYSTEM.md) → [`agents/admin-module-ui-guidelines.md`](agents/admin-module-ui-guidelines.md).
- Читаете или добавляете настройки: [`agents/configuration.md`](agents/configuration.md).
- Трогаете сборку: [`agents/admin-assets.md`](agents/admin-assets.md).
- Трогаете URL/роутинг: [`agents/admin-routing.md`](agents/admin-routing.md).
- Настраиваете сервер: [`deploy/`](deploy/).

## Правила

- Документация — часть репозитория: обновляйте её вместе с кодом в том же коммите.
- Пути в тексте указываются от корня репозитория (`public/assets/...`, `resources/...`).
- Ссылки — относительные, чтобы работали и на GitHub, и локально.