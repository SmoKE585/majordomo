# AGENTS.md — MajorDoMo admin UI

Этот файл — точка входа для агентов, которые продолжают работу над MajorDoMo. Он описывает текущее состояние, границы изменений и то, что нельзя ломать.

## 1. Что за проект

MajorDoMo — self-hosted система умного дома на PHP. Идёт миграция старого кода (PHP 5/7, Bootstrap 3, jQuery) на PHP 8.4+ и современную админку на Bootstrap 5.

Текущая цель — **поэтапный редизайн админки**:

- современный SaaS-like интерфейс;
- единая дизайн-система на всех страницах;
- мобильная вёрстка;
- тёмная тема;
- минимум самописного JS;
- готовые библиотеки через npm;
- старые модули должны продолжать работать.

Админка — единственный целевой UI. Пользовательские сцены и старый фронтенд-модуль меню не модернизируются в рамках этой работы.

## 2. Текущий статус (октябрь 2026)

Уже сделано:

- ядро и циклы стабилизированы под PHP 8;
- админ-оболочка переведена на Bootstrap 5;
- главная страница переделана: KPI, активность, циклы, БД, система, быстрый доступ;
- дашборд v2: живые KPI (объекты, устройства, циклы, БД), полоса важных предупреждений, график активности за 24 часа, ресурсы сервера (CPU/память/диск), донат циклов, ошибки системы и системные параметры; данные собирает `lib/dashboard_data.php` через `admin.php?ajax_panel=1&op=dashboard`;
- ApexCharts подключён как отдельный lazy-chunk `assets/admin/charts.js` + `charts.css`, основной бандл не растёт;
- добавлены компоненты `MDJUI::meter()` и `MDJUI::chartCard()` с демо в UI Kit;
- верхняя панель и левое меню обновлены;
- иконки меню переведены на Tabler Icons через карту в `modules/panel.class.php` (Bootstrap Icons остаётся только для legacy-`glyphicon`);
- добавлен Vite-бандл: `assets/admin/admin.css` + `assets/admin/admin.js`; тяжёлый редактор кода вынесен в отдельный lazy-chunk `codeeditor.js/css`;
- jQuery обновлён до 3.7.1, подключён jQuery Migrate 3.4.1;
- Tom Select вынесен в бандл, появился `data-md-searchable`;
- удалены неиспользуемые вендорные копии (`amcharts`, `bootstrap5`, `bootstrap-icons`, `tom-select`);
- legacy inline-скрипт `templates/panel_js_scripts.html` вынесен в `js/admin/panel.js`;
- тёмная тема доведена для главной, настроек, классов и объектов;
- модуль «Объекты» переведён на новый UI: таблица с поиском и фильтром по классу, карточка объекта, вкладки, форма, компактные свойства и методы;
- хедер переделан в отдельную карточку с inline-поиском и popup-результатами; endpoint поиска изолирован в `80_header_search.js` — позже сюда подключается Meilisearch;
- UI-паттерны объектов: таблица с поиском/фильтром, segmented-табы с иконками, компактные value-контролы высотой с input, единые иконочные action-меню и общий стиль dropdown;
- создана UI Kit / дизайн-система: `?action=ui_kit`, `DESIGN_SYSTEM.md`, `css/admin/ui-system.css`, PHP-компоненты `MDJUI` + `templates/ui/components/`, Alpine.js для локальных состояний;
- классы переведены на общую систему: страница-дерево с KPI, поиском, фильтром и раскрытием объектов, карточка класса, вкладки и форма; JS вынесен в `templates/classes/classes.js`;
- свойства и методы классов переведены на общую систему: таблицы с поиском, сортировкой и склоняемым счётчиком, `md-badge` для истории/валидации/обработчиков, компактные формы на `md-field`/`md-input`/`md-select`, отдельные секции унаследованных свойств и методов; page-JS вынесен в `templates/properties/properties.js` и `templates/methods/methods.js`;
- история объектов переведена на общую систему: таблица с датой, событием, объектом и значениями, фильтр по объекту, пагинация `md-pagination`, empty-state и современная форма правки записи; дата в форме приведена к формату `type=date`;
- вкладки объекта доведены до `md-*`: детали, список/редактор методов, карточки свойств, модалка создания свойства и панель настройки; убраны светлые `btn-light`/`alert`/`form-control` из тёмной темы;
- свойства объекта получили UX-слой: поиск и фильтры (класс/объект/история/источник/метод), группы «Свойства класса» и «Свойства объекта» с количеством, явные метаданные «Меняет / Обновлено / Где», ссылка на источник и метод-обработчик, переходы «В классе» и к истории;
- история свойства переведена на общий компонент `MDJUI::historyDrawer()` + `40_history.js`: общий drawer shell, период, текущее значение, статистика, график ApexCharts (ленивый chunk), журнал изменений, пагинация, обновление и CSV; endpoint истории выбирается из `data-history-*` и не ломается на чистых URL `/panel/...`;
- редактор метода объекта переведён в одну колонку: контекст «Редактируется метод объекта», карточка кода родителя (read-only) сверху, полная ширина формы, «Как запустить» и X-Ray ниже;
- добавлен компонент `MDJUI::choiceGroup()` — радио-варианты в виде карточек с рамкой, иконкой и пояснением; используется в редакторах методов классов и объектов, описан в UI Kit и `DESIGN_SYSTEM.md`;
- сценарии переведены на общую систему: таблица с поиском/фильтром, KPI, категории, импорт/экспорт, карточки сценария и категории;
- редактор кода переведён на CodeMirror 6 и отдельный lazy-chunk: единый toolbar, light/dark, авто-высота, поиск, F11/Esc, история версий, ошибки с подсветкой строки, мобильная вёрстка; старый `3rdparty/codemirror` удалён;
- настройки переведены на общую систему: KPI, `md-tabs` разделов, карточки настроек, `md-field`/`md-input`/`md-select`, локальный поиск;
- «Обновление системы» (saverestore) переведено на общую систему: hero, KPI, карточки обновления/журнала/автоматизации, сценарии восстановления на `md-tabs`, модалка прогресса;
- X-Ray переведён на общую систему: KPI, вкладки режимов, тулбар с фильтрами по дате и имени файла, консоль, карточки циклов и таблицы; генерация карточек/таблиц в `xray.js` использует `md-*`;
- консоль X-Ray выводит структурированные записи: дата, время, файл, уровень и краткое сообщение; полный текст раскрывается по клику, абсолютные пути проекта сокращаются;
- лог цикла X-Ray открывается в новом drawer: структурированные записи, фильтр, пауза автообновления, скачивание, очистка и empty-state для выключенного файлового лога;
- «Маркет дополнений» переведён на общую систему: hero, KPI, поиск, `md-tabs` категорий, компактные карточки модулей, ручная установка, empty-state и модалка с коммитами Git перед установкой/обновлением;
- все view_mode-табы приведены к единому паттерну `md-tabs` (классы, сценарии, объекты, X-Ray, настройки, saverestore); `md-tabs--compact`/`md-tabs--line` помечены в UI Kit как служебные;
- исправлена мобильная тёмная тема shell: `.md-admin-layout` больше не оставляет светлый фон под заголовками страниц;
- глобальный drawer shell переведён на `md-drawer__*`: компактная шапка, прокручиваемое тело, футер, токены и мобильный full-screen; добавлен компонент `MDJUI::drawer()` и предпочтительный пример в UI Kit (`feedback`);
- админ-роутинг переведён на чистые URL `/panel/...`: единая таблица маршрутов `lib/admin_router.php`, outbound-преобразование в `parseLinks()`/`redirect()` с round-trip-проверкой, 302 с legacy `pd`-ссылок на канонические URL;
- добавлены нормальные 404: `lib/error_page.php` + `404.php` + nginx `error_page`, 404 внутри shell для неизвестных модулей, JSON для AJAX/part_load; удалён catch-all `*.html` → docs, который отдавал 200 на любую опечатку;
- модуль «Пользователи» переведён на общую систему: hero и KPI, таблица с поиском и фильтром ролей, карточка пользователя, форма профиля, пароля и прав, привязка к объекту; чистые URL `/panel/users.html`, `/panel/users/add.html`, `/panel/users/<id>.html` и `/panel/users/<id>/delete.html`;
- модуль «Устройства Online» (`pinghosts`) переведён на общую систему: hero и KPI со статусами, таблица с поиском и фильтром, карточка хоста с типом проверки, расписанием, привязкой к объекту, online/offline-действиями на цветных вкладках и журналом; редактор PHP-кода — CodeMirror 6 lazy-chunk, page-JS — `templates/pinghosts/pinghosts.js`; чистые URL `/panel/pinghosts.html`, `/panel/pinghosts/add.html`, `/panel/pinghosts/<id>.html` и `/panel/pinghosts/<id>/delete.html`;
- модуль «Расположение» (`locations`) переведён на общую систему: hero и KPI, таблица помещений с поиском, фильтром по объектам, приоритетом и кнопками ↑/↓, карточка помещения и вкладка «Логика» с выбором метода room-объекта и CodeMirror 6; чистые URL `/panel/locations.html`, `/panel/locations/add.html`, `/panel/locations/<id>.html`, `/panel/locations/<id>/logic.html`, `/panel/locations/<id>/up.html`, `/panel/locations/<id>/down.html` и `/panel/locations/<id>/delete.html`;
- модуль «Ошибки системы» (`system_errors`) переведён на общую систему: hero и KPI, статус активных ошибок, таблица групп с поиском/фильтром, переключателем хранения истории, отдельная страница истории с раскрываемыми событиями, настройки хранения в модалке; отметка «просмотрено» больше не удаляет историю; история показывает источник ошибки (модуль, файл, строку, вызов) и для новых записей — URL/метод/IP запроса, а сырой backtrace разбирается на читаемый стек кадров с короткими путями; чистые URL `/panel/system_errors.html`, `/panel/system_errors/<id>.html`, `/panel/system_errors/<id>/history.html`, `/panel/system_errors/<id>/read.html`, `/panel/system_errors/<id>/delete.html`, `/panel/system_errors/readall.html` и `/panel/system_errors/test.html`;
- модуль MQTT переделан в рабочее пространство для больших журналов: hero и KPI, состояние цикла, группировка по устройствам или корневым путям, поиск и фильтры, серверная пагинация, live-обновление значений, отдельная страница настроек брокера и редактор топика с привязкой, публикацией и историей сообщений; page-CSS/JS — `templates/mqtt/mqtt.css` и `templates/mqtt/mqtt.js`; чистые URL `/panel/mqtt.html`, `/panel/mqtt/add.html`, `/panel/mqtt/<id>.html`, `/panel/mqtt/<id>/delete.html`, `/panel/mqtt/settings.html`, `/panel/mqtt/clear-trash.html` и `/panel/mqtt/optimize.html`;
- компонент `linkedobject` переведён на общую систему и собственный стабильный контроллер `MDLinkedObjectUI`: native input сохраняет `name/id/onchange`, свободный ввод, атомарная загрузка свойств/методов с кэшем, portal-dropdown, повторная инициализация после `partLoad`, поддержка клонированных строк и таблиц; демо — `?action=ui_kit#linkedobject`.

Следующий этап — **полировка мобильных деталей объектов** (длинные значения в редакторах, компактные формы), затем остальные модули.

## 3. Что читать перед изменениями

1. `DESIGN_SYSTEM.md` — **обязательно** перед любым UI-изменением: компоненты, токены, примеры, правила.
2. `docs/admin-assets.md` — сборка, бандл, как добавлять пакеты.
3. `docs/admin-module-ui-guidelines.md` — UI-правила для модулей.
4. `docs/admin-routing.md` — контракт чистых URL, legacy `pd` и 404.
5. `.codex/skills/majordomo-admin-ui-guidelines/SKILL.md` — краткая версия для агента.
6. Этот файл — текущее состояние и ограничения.

Если документы противоречат друг другу, приоритет у `AGENTS.md` и фактического кода.

## 4. Карта админки

| Путь | Назначение |
| --- | --- |
| `admin.php` | Точка входа админки, part-load, language-константы, 302 на чистый URL |
| `nf.php` | Inbound-роутинг pretty URL, legacy `pd`, 404 |
| `lib/admin_router.php` | Единая таблица маршрутов и преобразование legacy ↔ pretty |
| `lib/error_page.php` | Общий рендер 404 (HTML/JSON, светлая/тёмная тема) |
| `404.php` | Публичная точка входа для nginx `error_page 404` |
| `modules/panel.class.php` | Сборка shell: меню, иконки, уведомления, данные панели, 404 для модулей |
| `lib/dashboard_data.php` | Сбор данных дашборда: ресурсы, БД, счётчики, циклы, ошибки, активность |
| `modules/inc_panel_ajax.php` | AJAX панели; `op=dashboard` отдаёт JSON дашборда |
| `templates/panel.html` | Каркас страницы: topbar, sidebar, контент, drawers |
| `templates/panel_default.html` | Главная страница (dashboard v2) |
| `templates/panel_nav_left.html` | Левое меню |
| `templates/common_header_admin.html` | Подключение бандла, тема, legacy-скрипты |
| `css/admin/admin_panel.css` | Legacy CSS (~8 тыс. строк), ещё используется |
| `css/admin/ui-v2.css` | Новый слой shell/dashboard: токены, верхняя панель, меню, тёмная тема |
| `css/admin/ui-system.css` | Канонический слой дизайн-системы: токены, `md-*` компоненты, состояния |
| `lib/ui/MDJUI.php` | Серверный API UI-компонентов + демо-данные |
| `templates/ui/components/` | Разметка переиспользуемых компонентов |
| `templates/ui_kit/` | Страница UI Kit и её секции |
| `modules/ui_kit/` | Модуль админки `?action=ui_kit` |
| `DESIGN_SYSTEM.md` | Обязательная документация дизайн-системы |
| `js/admin/modules/admin_panel/` | Модули shell: core, drawer, search, console, notifications, init, dashboard, controls, header search, objects, data table, alpine, shared UI components, dashboard charts |
| `js/admin/modules/admin_panel/100_chart_runtime.js` | Общий lazy-loader ApexCharts (`window.MDJCharts`) для дашборда и истории |
| `js/admin/modules/admin_panel/101_dashboard_charts.js` | Графики дашборда поверх общего chart runtime |
| `js/admin/ui_kit.js` | Page-only JS UI Kit: каталог иконок, copy, scrollspy; подключается только на `?action=ui_kit` |
| `js/admin/panel.js` | Legacy-глобалы shell (`partLoad`, фильтр меню, file browser) |
| `js/scripts.js` | Legacy-глобальные хелперы старых шаблонов |
| `src/admin/main.js` | Единственный entry point Vite |
| `src/admin/charts-entry.js` | Entry отдельного Vite-chunk графиков (ApexCharts) |
| `vite.charts.config.js` | Сборка `assets/admin/charts.js` + `charts.css` |
| `assets/admin/` | Сгенерированный бандл, коммитится |
| `js/admin/codeeditor.js` | Обвязка CodeMirror 6 и рендер редактора; отдельный lazy-chunk |
| `js/admin/codeeditor-loader.js` | Lazy-загрузка редактора; входит в основной бандл |
| `css/admin/codeeditor.css` | Стили редактора, темы, поиск, drawer истории |
| `src/admin/codeeditor-entry.js` | Entry отдельного Vite-chunk |
| `vite.codeeditor.config.js` | Сборка `assets/admin/codeeditor.js/css` |
| `tools/screenshot.mjs` | Локальная проверка страниц через headless Chrome + CDP |

## 5. Сборка и запуск

### Ассеты

```bash
npm install
npm run build          # admin bundle + code editor chunk + charts chunk
npm run watch          # admin bundle
npm run watch:editor   # code editor (второй терминал)
npm run watch:charts   # графики дашборда (второй терминал)
```

- Не редактируйте `assets/admin/*` вручную.
- После `npm run build` при необходимости поднимите версию в `templates/common_header_admin.html` (`?v=...`), `window.MDCodeEditorConfig` и `window.MDChartsConfig`.
- Бандл коммитится; на продакшене Node.js не нужен.
- Редактор кода — отдельный lazy-chunk `assets/admin/codeeditor.js/css`; его собирает `vite.codeeditor.config.js`.
- Графики — отдельный lazy-chunk `assets/admin/charts.js` + `charts.css` (ApexCharts); его собирает `vite.charts.config.js`, загрузка идёт из общего `100_chart_runtime.js` (дашборд и история).

### Локальный сервер

```bash
php dev_server.php --host=127.0.0.1 --port=8080
```

Нужен MySQL/MariaDB. `config.php` локальный и не коммитится. Для чистой локальной базы можно импортировать `db_terminal.sql` (в нём схема + демо-данные; часть данных историческая).

Локально `dev_server.php` умеет отдавать `objects/` через `index.php` — это нужно для `objects/?script=dash_my_block`.

### Деплой

Целевой веб-сервер — только nginx. Единственный источник правды — `nginx.conf.sample`; `.htaccess` в проекте нет и добавлять его не нужно. Правила, которые раньше лежали в `.htaccess` (роутинг на `nf.php`/`api.php`, запрет `lib/`, `templates/*.html`, `config.php`), перенесены в этот конфиг.

### Скриншоты

```bash
node tools/screenshot.mjs --url "http://127.0.0.1:8080/admin.php" --out "docs/ui-redesign/home.png" --cookie "prj=<session>"
```

Требуется Chrome/Edge. Скрипт поднимает headless-браузер, ставит cookie, ждёт AJAX и сохраняет PNG. Для тёмной темы добавьте `; theme=dark` в cookie.

## 6. UI-правила

- **Перед созданием или изменением интерфейса сначала изучите `DESIGN_SYSTEM.md` и существующие UI-компоненты.** Используйте готовые компоненты. Запрещено создавать альтернативные реализации кнопок, полей ввода, селектов, карточек, модальных окон и других стандартных элементов без явной необходимости. Новые компоненты должны соответствовать существующей дизайн-системе.
- Bootstrap 5 — база. Новые компоненты — Bootstrap 5 API + `md-*` классы.
- Новый общий CSS — только в `css/admin/ui-system.css` (канонический слой дизайн-системы). `ui-v2.css` отвечает за shell/dashboard и не разрастается новыми компонентами.
- Legacy `admin_panel.css` не разрастается новыми компонентами; только точечные правки.
- PHP-компоненты: `MDJUI` + `templates/ui/components/`; не дублируйте их разметку на страницах.
- Новый JS — модуль в `js/admin/modules/admin_panel/`, регистрация через `MDJAdminUI.registerModuleUI`.
- CSS/JS конкретного модуля не добавляйте в общий `admin.css`/`admin.js`: держите локально и подключайте только в шаблоне этого модуля. Общий бандл — shell, дизайн-система, общие библиотеки и общие компоненты.
- Локальные состояния — Alpine.js; `Alpine.start()` вызывает только `115_alpine.js`. Не запускайте Alpine повторно в шаблонах.
- Никакого нового jQuery-кода и новых jQuery-плагинов.
- Никаких новых `<script>`/`<link>` для библиотек в шаблонах модулей — только npm + бандл.
- Редактор кода: `data-code-editor` + разметка `md-code-editor__*`; CodeMirror 6 грузится lazy-chunk автоматически. Для drawer/modal используйте `data-code-editor-lazy="1"` и `MDCodeEditor.init(container)`. Не подключайте CodeMirror вручную.
- Селекты с поиском: `data-md-searchable` (Tom Select уже в бандле).
- Иконки: только Tabler Icons (`ti ti-*`, `MDJUI::icon('name')`, каталог `?action=ui_kit#foundations`). Bootstrap Icons остаётся только для legacy-`glyphicon`; новые `bi bi-*` не добавляются. После обновления пакета — `npm run icons`.
- Хедер-поиск: `80_header_search.js`, минимум 3 символа, popup под input, «Все результаты» открывает полный drawer. Формат ответа — `sections/total/items`; при подключении Meilisearch менять только источник данных, не разметку popup.
- Графики: только через пакет (например, ApexCharts), не самописный canvas/SVG. Компактные sparkline-бары — разрешённый примитив.
- Мобильная ширина: проверять 390 px.
- Тёмная тема: использовать `--md-ui-*` и `--bs-*`, не хардкодить белый фон.
- Не оборачивать страницу в огромный декоративный контейнер; плотность и воздух важнее.
- Новые страницы должны выглядеть как главная: те же радиусы, отступы, типографика, статусы.

## 7. Контракт совместимости

Старые модули — не музей, они должны работать.

Глобалы, на которые рассчитывает legacy-код:

- `window.jQuery`, `window.$`;
- `window.bootstrap` (Modal, Collapse, Dropdown, Tab, Tooltip, Popover);
- `$.fancybox`;
- `$.toast`;
- `$.cookie`;
- `$.publish` / `$.subscribe` (tiny-pubsub);
- `window.TomSelect`;
- `window.HtmlDurationPicker`;
- `window.MDJAdminUI`;
- глобальные функции из `js/scripts.js` и `js/admin/panel.js`.

Контракт shell:

- `window.MDJAdminUI.registerModuleUI(name, {init})` — модуль инициализируется на `boot(root)`;
- `partLoad()` подгружает контент и вызывает `MDJAdminUI.boot()`;
- после part-load CSS/JS модуля должны продолжать работать;
- `MDJAdminUI.initBootstrapWidgets(root)` — для tooltip/popover после динамической вставки.

Контракт роутинга:

- старые URL с `pd`/`md`/`inst` обязаны открываться;
- `parseLinks()` и `module::redirect()` сами отдают pretty URL, если состояние
  выражается без потерь;
- `admin.php` делает 302 на канонический URL только для GET-страниц (не для
  `part_load`, `ajax`, `print`, `popup`, POST);
- inbound и outbound используют одну таблицу `lib/admin_router.php`.

## 8. Что нельзя ломать

- Порядок в `common_header_admin.html`: конфиг/тема → `admin.css` → `admin.js` → `scripts.js` → `panel.js`.
- `partLoad` и кэш part-load.
- `#partLoadContent`, `#preloader`, `#wsStatus` и другие ID, которые ищут старые скрипты.
- Глобальные функции, вызываемые из inline `onclick` старых шаблонов.
- Совместимость с модулями, которые не переписаны: не удаляйте их CSS/JS, пока не проверены входящие ссылки.
- PHP 8.4: новые предупреждения/ошибки недопустимы.
- Права доступа/авторизацию (`control_access`, `panel.class.php`) без явной задачи.
- Формат данных и API старых модулей.
- Таблицу маршрутов и round-trip-проверку в `lib/admin_router.php`: без неё
  старые ссылки начнут терять состояние.
- Catch-all-маршрут `([\w-]+).html` → docs: он маскировал 404 под 200.
- Статус 404 в `admin.php`: не перезаписывайте его на 200 при выводе shell.

## 9. Куда что добавлять

- Общий компонент: `css/admin/ui-system.css` + метод в `lib/ui/MDJUI.php` + partial в `templates/ui/components/`.
- Общее поведение: модуль в `js/admin/modules/admin_panel/`, импорт в `src/admin/main.js`, регистрация через `MDJAdminUI`.
- Страничный CSS/JS: рядом с модулем (`templates/<module>/...`), только на его страницах; **не переносить в общий бандл** без отдельной явной задачи.
- Демо компонента: секция в `templates/ui_kit/sections/` и запись в `DESIGN_SYSTEM.md`; page-only JS UI Kit — `js/admin/ui_kit.js`.
- Новая библиотека: `npm install`, импорт в `src/admin/main.js` (или отдельный entry, если библиотека тяжёлая), при необходимости `window.X = ...`, `npm run build`. Тяжёлые библиотеки вроде CodeMirror и ApexCharts держите в отдельных lazy-chunk, а не в основном бандле.
- Новая иконка модуля: добавить в `$sidebarIcons` в `modules/panel.class.php` (значение — `ti-*` из Tabler).
- Новая страница UI Kit не нужна: используйте `/admin.php?action=ui_kit`.

## 10. Дорожная карта

1. ~~Shell + dashboard + сборка~~ — сделано.
2. ~~UI Kit / дизайн-система: токены, `md-*` компоненты, документация, Alpine~~ — сделано.
3. ~~Объекты: список, карточка, свойства, методы, формы, поиск, тёмная тема~~ — сделано; история объекта и редактор методов переведены на `md-*`, история свойства — на общий `MDJUI::historyDrawer()`.
4. ~~Классы: список-дерево, KPI, поиск/фильтр, карточка и форма~~ — сделано; свойства, методы и унаследованные элементы переведены на общую систему.
5. ~~Сценарии: список, категории, KPI, импорт/экспорт, карточки~~ — сделано; редактор кода переведён на CodeMirror 6 (lazy-chunk), осталась финальная проверка тёмной темы на старых вкладках.
6. ~~Настройки, обновление системы, X-Ray, маркет~~ — переведены на общую систему; остались системные ошибки.
7. Чистка legacy CSS/JS, перенос оставшихся глобальных скриптов, возможно server-rendered фрагменты/Alpine для сокращения JS.
8. Обновление/удаление старых фронтенд-модулей — отдельным явным этапом.

## 11. Проверка перед завершением

- `php -l` для изменённых PHP-файлов.
- `node --check` для изменённых JS.
- `npm run build` после изменений в `src/admin`.
- `php scripts/routing_smoke.php` — контракт friendly URL, round-trip и nginx-конфига.
- Открыть `/panel/` и затронутые страницы: console/exceptions, 390 px, светлая и тёмная темы.
- Проверить старую `pd`-ссылку: 302 на `/panel/...` и то же состояние.
- Проверить 404: `/nonexistent.html`, `/panel/does_not_exist.html`, `/nonexistent`.
- Проверить `partLoad`-переход между страницами.
- Проверить, что старые модули не потеряли jQuery/Bootstrap-глобалы.

## 12. Известный технический долг

- `css/admin/admin_panel.css` — ~8 тыс. строк, много дублей и «финальных полировок».
- `js/scripts.js` и `js/admin/panel.js` — глобальные legacy-скрипты.
- CSS/JS части модулей (`templates/settings/settings.css`, `templates/scripts/scripts.js` и т.д.) сознательно остаются локальными: подключаются только на страницах своих модулей, чтобы не грузить код всех модулей сразу. В общий бандл их не переносить.
- `100_dashboard.js` и `110_objects.js` пока остаются в общем бандле как часть текущего shell/objects; это существующий долг, а не паттерн для новых модулей. При переделке этих модулей их page-specific JS выносить локально.
- В `templates/objects/objects_edit_properties.html` остаётся большой inline-скрипт (редактирование значений, WebSocket) — кандидат на вынос в module UI.
- Тёмная тема не проверена на отдельных legacy-страницах вне текущего этапа (старые вкладки и служебные модули).
- ApexCharts грузится отдельным чанком `assets/admin/charts.js` через общий `100_chart_runtime.js`; на страницах без графиков чарт-рантайм не скачивается.
- `js/window.js`, `js/builder.js`, `js/gmaps.js`, `js/jquery-ui-1.9.1.custom.min.js` и т.п. — legacy фронтенда; не трогать без проверки входящих ссылок.

## 13. Удалённые/неактуальные файлы

Не возвращайте их без причины:

- `3rdparty/amcharts/` — графики главной заменены на CSS/данные, библиотека не использовалась;
- `3rdparty/bootstrap5/` — Bootstrap теперь в npm-бандле;
- `3rdparty/bootstrap-icons/` — иконки в бандле;
- `3rdparty/tom-select/` — Tom Select в бандле;
- `3rdparty/codemirror/` — CodeMirror 5 удалён; редактор теперь CodeMirror 6 в lazy-chunk `codeeditor.js`;
- `js/admin/admin_panel.js` — старый загрузчик модулей, заменён импортами Vite;
- `templates/panel_js_scripts.html` — содержимое перенесено в `js/admin/panel.js`;
- `css/admin.css` — не имел входящих ссылок;
- `js/admin.js` — был помечен DEPRECATED, не использовался;
- `js/datepicker.js` — не имел входящих ссылок, заменён современными date-подходами;
- `.htaccess` (корень и подкаталоги `lib/`, `templates/`, `cms/`, `backup/`, `3rdparty/elFinder/php/.tmp/`) — правила перенесены в `nginx.conf.sample`, деплой только на nginx; каталоги, которые держались на этих файлах, сохранены через `.gitkeep`.
