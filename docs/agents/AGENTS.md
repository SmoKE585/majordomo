---
title: AGENTS.md — правила проекта
audience: agent
category: general
tags: агенты, правила, состояние проекта, ограничения, миграция
description: Полные правила работы с репозиторием MajorDoMo — текущее состояние, что читать, что нельзя ломать и как проверять изменения.
order: 10
---

# AGENTS.md — MajorDoMo admin UI

Этот файл — полная версия правил для агентов, которые продолжают работу над MajorDoMo. Точка входа — `AGENTS.md` в корне репозитория; здесь описаны текущее состояние, границы изменений и то, что нельзя ломать.

## 1. Что за проект

MajorDoMo — self-hosted система умного дома на PHP. Идёт миграция старого кода (PHP 5/7, Bootstrap 3, jQuery) на PHP 8.1+ и современную админку на Bootstrap 5. Локальное окружение — PHP 8.4; новый код и Composer-зависимости должны сохранять совместимость с PHP 8.1.

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

- конфигурация переведена на `.env` + `MajorDoMo\Core\Config`; `config.php` оставлен шимом, легаси-константы сохранены для старых модулей;
- ядро и циклы стабилизированы под PHP 8;
- админ-оболочка переведена на Bootstrap 5;
- главная страница переделана: KPI, активность, циклы, БД, система, быстрый доступ;
- дашборд v2: живые KPI (объекты, устройства, циклы, БД), полоса важных предупреждений, график активности за 24 часа, ресурсы сервера (CPU/память/диск), донат циклов, ошибки системы и системные параметры; данные собирает `lib/dashboard_data.php` через `GET /api/v1/panel/dashboard` (legacy `admin.php?ajax_panel=1&op=dashboard` оставлен адаптером);
- служебный AJAX панели переведён на `/api/v1/panel/*`: дашборд, проверка обновлений, поиск, консоль, уведомления, билет WebSocket, история/цепочка свойства и X-Ray идут через `window.MDJAdminApi` (`resources/js/modules/admin_panel/07_api.js`) и `lib/core/Api/Panel.php`; маршруты панели не входят в OpenAPI и требуют сеанс администратора, а старые `ajax_panel`/`action=xray`/`check_updates` URL остались тонкими адаптерами без дублирования логики;
- ApexCharts подключён как отдельный lazy-chunk `public/assets/admin/charts.js` + `charts.css`, основной бандл не растёт;
- добавлены компоненты `MDJUI::meter()` и `MDJUI::chartCard()` с демо в UI Kit;
- верхняя панель и левое меню обновлены;
- иконки меню переведены на Tabler Icons через карту в `modules/panel.class.php` (Bootstrap Icons остаётся только для legacy-`glyphicon`);
- добавлен Vite-бандл: `public/assets/admin/admin.css` + `public/assets/admin/admin.js`; тяжёлый редактор кода вынесен в отдельный lazy-chunk `codeeditor.js/css`;
- jQuery обновлён до 3.7.1, подключён jQuery Migrate 3.4.1;
- Tom Select вынесен в бандл, появился `data-md-searchable`;
- удалены неиспользуемые вендорные копии (`amcharts`, `bootstrap5`, `bootstrap-icons`, `tom-select`);
- legacy inline-скрипт `templates/panel_js_scripts.html` вынесен в `public/assets/js/admin/panel.js`;
- тёмная тема доведена для главной, настроек, классов и объектов;
- модуль «Объекты» переведён на новый UI: таблица с поиском и фильтром по классу, карточка объекта, вкладки, форма, компактные свойства и методы;
- хедер переделан в отдельную карточку с inline-поиском и popup-результатами; endpoint поиска изолирован в `80_header_search.js` — позже сюда подключается Meilisearch;
- UI-паттерны объектов: таблица с поиском/фильтром, segmented-табы с иконками, компактные value-контролы высотой с input, единые иконочные action-меню и общий стиль dropdown;
- создана UI Kit / дизайн-система: `?action=ui_kit`, `docs/agents/DESIGN_SYSTEM.md`, `resources/css/ui-system.css`, PHP-компоненты `MDJUI` + `templates/ui/components/`, Alpine.js для локальных состояний;
- классы переведены на общую систему: страница-дерево с KPI, поиском, фильтром и раскрытием объектов, карточка класса, вкладки и форма; JS вынесен в `public/assets/modules/classes/classes.js`;
- свойства и методы классов переведены на общую систему: таблицы с поиском, сортировкой и склоняемым счётчиком, `md-badge` для истории/валидации/обработчиков, компактные формы на `md-field`/`md-input`/`md-select`, отдельные секции унаследованных свойств и методов; page-JS вынесен в `public/assets/modules/properties/properties.js` и `public/assets/modules/methods/methods.js`;
- история объектов переведена на общую систему: таблица с датой, событием, объектом и значениями, фильтр по объекту, пагинация `md-pagination`, empty-state и современная форма правки записи; дата в форме приведена к формату `type=date`;
- вкладки объекта доведены до `md-*`: детали, список/редактор методов, карточки свойств, модалка создания свойства и панель настройки; убраны светлые `btn-light`/`alert`/`form-control` из тёмной темы;
- свойства объекта получили UX-слой: поиск и фильтры (класс/объект/история/источник/метод), группы «Свойства класса» и «Свойства объекта» с количеством, явные метаданные «Меняет / Обновлено / Где», ссылка на источник и метод-обработчик, переходы «В классе» и к истории;
- карточка свойства объекта доработана: источник «Меняет» показывается короткой меткой (`HTTP · /objects/`, `Задание · …`, `Панель`, `Сценарий · …`, `API`) с полным значением в подсказке вместо длинного URL (он больше не превращается в ссылку, повторяющую запрос), «Меняет / Обновлено» стоят на двух стабильных строках, связанные модули выводятся подписанным блоком с бейджами `md-badge`; блок значения получил подсказку редактирования, dashed-состояние «не задано» и доступность с клавиатуры, а обновление по WebSocket подсвечивается мягкой анимацией на токенах (`outline` + `--md-ui-focus`) вместо старого свечения с `translateY`;
- добавлен always-on журнал изменений свойств (`property_audit_runs` / `property_audit_events`): trace id через очередь и `ONCHANGE`, имя запуска, актор, источник, параметры входа/выхода методов и сценариев, старое/новое значение и готовая цепочка; кнопка «Цепочка» у каждого свойства открывает `MDJUI::traceDrawer()` с переходами на метод/свойство/сценарий; retention настраивается `PROPERTY_AUDIT_RETENTION_DAYS`, выключателя нет; документация — `docs/agents/property-audit.md`;
- история свойства переведена на общий компонент `MDJUI::historyDrawer()` + `40_history.js`: общий drawer shell, период, текущее значение, статистика, график ApexCharts (ленивый chunk), журнал изменений, пагинация, обновление и CSV; endpoint истории выбирается из `data-history-*` и не ломается на чистых URL `/panel/...`;
- редактор метода объекта переведён в одну колонку: контекст «Редактируется метод объекта», карточка кода родителя (read-only, `MDJUI::codeSample()` с подсветкой и копированием) сверху, полная ширина формы, «Как запустить» и X-Ray ниже;
- добавлен компонент `MDJUI::choiceGroup()` — радио-варианты в виде карточек с рамкой, иконкой и пояснением; используется в редакторах методов классов и объектов, описан в UI Kit и `docs/agents/DESIGN_SYSTEM.md`;
- сценарии переведены на общую систему: таблица с поиском/фильтром, KPI, категории, импорт/экспорт, карточки сценария и категории;
- редактор кода переведён на CodeMirror 6 и отдельный lazy-chunk: единый toolbar, light/dark, авто-высота, поиск, F11/Esc, история версий, ошибки с подсветкой строки, мобильная вёрстка; старый `3rdparty/codemirror` удалён;
- настройки переведены на общую систему: KPI, `md-tabs` разделов, карточки настроек, `md-field`/`md-input`/`md-select`, локальный поиск;
- добавлен мастер первичной настройки `/install.php` на новой схеме `lib/core/Install/`: проверка PHP/расширений/прав/БД, запись `.env` с резервной копией, настройки интерфейса в `settings`, создание администратора при необходимости; при пустой базе мастер импортирует чистую схему `lib/core/Install/schema.sql` (только структура, без демо-данных и пользователей); `InstallGate` перенаправляет админку, сайт, API и object-эндпоинты, пока маркер `cms/modules_installed/install.installed` не завершён (старые установки с `control_modules.installed` считаются настроенными); документация — `docs/agents/install.md`, CLI — `tools/install.php`; авторизация панели работает через `Core::session()` и `AuthGate` на таблице `users` (см. `authentication.md`), старый список пользователей на фронтенде и `DISABLE_USERS_LIST` удалены;
- настройки мастера перенесены в «Общие настройки» (`lib/core/Settings/`): схема переиспользует `InstallSchema`, пишет `.env` и `settings`, проверяет смену БД, сохраняет старые DB-настройки отдельными разделами; после сохранения перезапускает циклы, при смене БД делает полный reload супервизора; выбор папок для путей — собственный project-scoped `MDFolderPicker` (`lib/core/Filesystem/FolderBrowser.php`), старый elFinder удалён; документация — `docs/agents/settings.md`;
- управление циклами переведено на OOP-схему (`lib/core/Cycles/`): персистентное состояние `cms/cycles/state.json` (`running`/`stopped`/`disabled`), поколения для надёжного рестарта, `CycleManager` + `CycleSupervisor`, отключение без ошибок и авто-рестарта, совместимость с `cycle_*Control`/`Disabled` для старых модулей и кнопки `disable`/`enable` в X-Ray; документация — `docs/agents/cycles.md`;
- «Обновление системы» (saverestore) переведено на общую систему: hero, KPI, карточки обновления/журнала/автоматизации, сценарии восстановления на `md-tabs`, модалка прогресса на `md-*` — этапы, проверки, журнал с копированием и автоскроллом, отдельные состояния успеха/ошибки; при ошибке модалка остаётся открытой, URL очищается от `mode=getlatest_iframe`;
- X-Ray переведён на общую систему: KPI, вкладки режимов, тулбар с фильтрами по дате и имени файла, консоль, карточки циклов и таблицы; генерация карточек/таблиц в `xray.js` использует `md-*`;
- консоль X-Ray выводит структурированные записи: дата, время, файл, уровень и краткое сообщение; полный текст раскрывается по клику, абсолютные пути проекта сокращаются;
- лог цикла X-Ray открывается в новом drawer: структурированные записи, фильтр, пауза автообновления, скачивание, очистка и empty-state для выключенного файлового лога;
- в X-Ray исправлено двойное экранирование команд таймеров: API отдаёт `COMMANDS` как есть, а клиент экранирует только при вставке в HTML, поэтому `cm('Vannaya.onIdle');` отображается с обычными кавычками; выполнение не менялось — `Scheduler` использует исходную строку из `jobs`.
- «Маркет дополнений» переведён на общую систему: hero, KPI, поиск, `md-tabs` категорий, компактные карточки модулей, ручная установка, empty-state, модалка с коммитами Git перед установкой/обновлением, отдельная модалка удаления и модалка прогресса на `md-*` с этапами и журналом; iframe-воркер невидим, при ошибке окно остаётся открытым, URL очищается;
- все view_mode-табы приведены к единому паттерну `md-tabs` (классы, сценарии, объекты, X-Ray, настройки, saverestore); `md-tabs--compact`/`md-tabs--line` помечены в UI Kit как служебные;
- исправлена мобильная тёмная тема shell: `.md-admin-layout` больше не оставляет светлый фон под заголовками страниц;
- глобальный drawer shell переведён на `md-drawer__*`: компактная шапка, прокручиваемое тело, футер, токены и мобильный full-screen; добавлен компонент `MDJUI::drawer()` и предпочтительный пример в UI Kit (`feedback`);
- админ-роутинг переведён на чистые URL `/panel/...`: единая таблица маршрутов `lib/admin_router.php`, outbound-преобразование в `parseLinks()`/`redirect()` с round-trip-проверкой, 302 с legacy `pd`-ссылок на канонические URL;
- добавлены нормальные 404: `lib/error_page.php` + `404.php` + nginx `error_page`, 404 внутри shell для неизвестных модулей, JSON для AJAX/part_load; удалён catch-all `*.html` → docs, который отдавал 200 на любую опечатку;
- модуль «Пользователи» переведён на общую систему: hero и KPI, таблица с поиском и фильтром ролей, карточка пользователя, форма профиля, пароля и прав, привязка к объекту; чистые URL `/panel/users.html`, `/panel/users/add.html`, `/panel/users/<id>.html` и `/panel/users/<id>/delete.html`;
- модуль «Устройства Online» (`pinghosts`) переведён на общую систему: hero и KPI со статусами, таблица с поиском и фильтром, карточка хоста с типом проверки, расписанием, привязкой к объекту, online/offline-действиями на цветных вкладках и журналом; редактор PHP-кода — CodeMirror 6 lazy-chunk, page-JS — `public/assets/modules/pinghosts/pinghosts.js`; чистые URL `/panel/pinghosts.html`, `/panel/pinghosts/add.html`, `/panel/pinghosts/<id>.html` и `/panel/pinghosts/<id>/delete.html`;
- модуль «Расположение» (`locations`) переведён на общую систему: hero и KPI, таблица помещений с поиском, фильтром по объектам, приоритетом и кнопками ↑/↓, карточка помещения и вкладка «Логика» с выбором метода room-объекта и CodeMirror 6; чистые URL `/panel/locations.html`, `/panel/locations/add.html`, `/panel/locations/<id>.html`, `/panel/locations/<id>/logic.html`, `/panel/locations/<id>/up.html`, `/panel/locations/<id>/down.html` и `/panel/locations/<id>/delete.html`;
- модуль «Ошибки системы» (`system_errors`) переведён на общую систему: hero и KPI, статус активных ошибок, таблица групп с поиском/фильтром, переключателем хранения истории, отдельная страница истории с раскрываемыми событиями, настройки хранения в модалке; отметка «просмотрено» больше не удаляет историю; история показывает источник ошибки (модуль, файл, строку, вызов) и для новых записей — URL/метод/IP запроса, а сырой backtrace разбирается на читаемый стек кадров с короткими путями; чистые URL `/panel/system_errors.html`, `/panel/system_errors/<id>.html`, `/panel/system_errors/<id>/history.html`, `/panel/system_errors/<id>/read.html`, `/panel/system_errors/<id>/delete.html`, `/panel/system_errors/readall.html` и `/panel/system_errors/test.html`;
- модуль MQTT переделан в рабочее пространство для больших журналов: hero и KPI, состояние цикла, группировка по устройствам или корневым путям, поиск и фильтры, серверная пагинация, live-обновление значений, отдельная страница настроек брокера и редактор топика с привязкой, публикацией и историей сообщений; page-CSS/JS — `public/assets/modules/mqtt/mqtt.css` и `public/assets/modules/mqtt/mqtt.js`; чистые URL `/panel/mqtt.html`, `/panel/mqtt/add.html`, `/panel/mqtt/<id>.html`, `/panel/mqtt/<id>/delete.html`, `/panel/mqtt/settings.html`, `/panel/mqtt/clear-trash.html` и `/panel/mqtt/optimize.html`;
- MQTT получил быстрый путь (`docs/agents/mqtt-runtime.md`): `cycle_mqtt` только принимает пакеты и пишет их в Redis, `cycle_mqtt_runtime` держит `MQTT_HANDLERS` обработчиков, писателя состояния и отдельного издателя; привязки индексируются в памяти и перечитываются по версии Redis без переподключения к брокеру; `mqtt.VALUE/UPDATED` — проекция с отставанием до секунды (UI накладывает значения из Redis), `mqtt_history` сохраняет отдельные события для привязанных топиков, `LOGGING`/`DEBUG_MODE` и команд; при недоступности Redis работает прежний синхронный путь; диагностика — `php majordomo mqtt:runtime`, настройки — «Общие настройки» → «MQTT и очередь», `CORE_WORKERS` — в «Производительности»;
- `core_runtime` при `CORE_WORKERS > 1` и включённом Redis выполняется параллельными воркерами (`lib/core/LaneEventQueue.php`) с порядком по объекту и эксклюзивной полосой `serial` для произвольного PHP (сценарии, API, события модулей); старые элементы очереди переносятся `migrateLegacyRuntime()`, `CORE_WORKERS=1` сохраняет прежний последовательный цикл;
- компонент `linkedobject` переведён на общую систему и собственный стабильный контроллер `MDLinkedObjectUI`: native input сохраняет `name/id/onchange`, свободный ввод, атомарная загрузка свойств/методов с кэшем, portal-dropdown, повторная инициализация после `partLoad`, поддержка клонированных строк и таблиц; демо — `?action=ui_kit#linkedobject`;
- добавлен раздел «База знаний» (`/panel/knowledge_base.html`): читает `docs/**/*.md` без отдельной таблицы в БД, показывает категории, метки аудитории (`user`/`agent`), поиск, карточки и статью с оглавлением; Markdown рендерится безопасным серверным рендерером, относительные ссылки и картинки переписываются на базу знаний/`/docs/`; пользовательская инструкция по CLI — `docs/user/commands.md`, правила разметки — `docs/agents/knowledge-base.md`; чистые URL `/panel/knowledge_base.html` и `/panel/knowledge_base/<path>.html`.
- добавлены компоненты `MDJUI::activityFeed()` (лента действий), `MDJUI::hint()` (контекстная подсказка с пунктами, шагами и закрытием) и `MDJUI::codeSample()` (read-only код с подсветкой PHP/Python, вкладками языков, номерами строк и копированием); подсветка — lazy-chunk `public/assets/admin/codehighlight.js` + `codehighlight.css` (highlight.js), демо — `?action=ui_kit#blocks`, документация — `docs/agents/DESIGN_SYSTEM.md`;
- исправлен горизонтальный скролл страницы на мобильных: scroll-контейнеры таблиц (`md-table__scroll`) получили `position: relative`, чтобы абсолютные visually-hidden элементы не растягивали документ; футер карточки и длинные бейджи переносятся; сетка saverestore и мобильные `md-tabs` больше не выходят за экран; подсказка на странице общих настроек переведена на `MDJUI::hint()`.
- модуль Shoutbox (`/panel/shoutbox.html`) переведён на общую систему: hero и KPI, журнал сообщений на `MDJUI::activityFeed()` с удалением отдельных записей и очисткой всего журнала, подсказка о `say()` и пример вызова на `MDJUI::hint()`/`MDJUI::codeSample()`; page-CSS — `public/assets/modules/shoutbox/shoutbox.css`.
- шаблонный слой сведён к одному движку: `lib/core/Template/JTemplate.php` (движок `[#...#]` + PHP-партиалы через `[#inc file.php#]`) и `lib/core/Template/Parser.php`, namespace `MajorDoMo\Core\Template`; легаси-имена `jTemplate`/`parser` сохранены через `class_alias`, поэтому 29 модулей и сторонние модули работают без изменений. Smarty удалён целиком (`.tpl`, `3rdparty/smarty3`): единственное дерево классов переписано в `templates/classes/view_tree.php` и рендерится за 0,1 мс вместо 2,6 мс. `[#if#]` больше не выполняется через `eval()` — новый `Condition` разбирает 623 существующих условия с теми же результатами (17 444 проверки), добавлен экранирующий фильтр `[#VAR|html#]`. Подстановки переведены с `str_replace` на `preg_replace_callback` (рендер оболочки 1,58× быстрее). Поведение подтверждено сравнением 14 страниц в обоих режимах `DEBUG_MODE`; тесты — `template_include/surface/render/condition_smoke`.
- модуль «Режимы» (`/panel/modes.html`) добавлен: hero и KPI, карточки режимов с быстрым включением, состояние режима и журнал переходов, форма с постоянным кодом для PHP, визуальные правила (время, событие, условия) с задержкой и действиями при переключении; page-CSS/JS — `public/assets/modules/modes/modes.css` и `public/assets/modules/modes/modes.js`; чистые URL `/panel/modes.html`, `/panel/modes/add.html` и `/panel/modes/<id>.html`; PHP-API `setMode()`/`enableMode()`/`disableMode()`/`isModeActive()`, CLI `modes:list`/`modes:set`, документация — `docs/user/modes.md`.
- база знаний читает не только `docs/**`, но и `modules/<name>/docs/**` **установленных** модулей: документация модуля лежит рядом с кодом, получает категорию `module-<name>` и исчезает из раздела после удаления модуля; правила — `docs/agents/knowledge-base.md`.
- фоновые вызовы ядра (сценарии, методы, привязки модулей, Python-хелперы) кодируют сегмент API через `rawurlencode`: названия с пробелом больше не теряются (`urlencode` давал `+`, а `Api\Request` разбирает путь через `rawurldecode`); ошибка фоновой API-операции теперь показывает путь и причину отказа, а не только `target`.
- логотипы приведены в порядок: `MDJUI::logo()` — одна разметка для всех страниц, оба варианта переключаются по `data-bs-theme`, ширина задаётся токеном `--md-logo-width`; в шапках используется вордмарк без мелкой подписи «…твой лучший умный дом» (`logo-*-clean-*.png`), которая в интерфейсе сжималась в нечитаемую полосу; заодно исправлены двойные логотипы на входе/мастере/404 — правила `md-logo` не попадали в бандл без сборки;
- профиль перенесён вниз левого меню: карточка `.md-admin-sidebar__profile` с инициалами, именем, ролью и отдельной кнопкой выхода, не прокручивается вместе с меню и остаётся видимой на телефоне; выход из шапки убран и возвращается только при `HIDE_LEFT_PANEL=1`;
- исправлена прокрутка мобильного меню: полноэкранная панель больше не опирается на `100vh` (на телефоне это «большой» вьюпорт, из-за чего низ списка уходил под адресную строку и не доскролливался), высота берётся из `top/bottom`, добавлен отступ `env(safe-area-inset-bottom)`.
- деплой переработан: файловые миграции с автообнаружением (`lib/core/Migrations/`), `deploy/removals.json` с защитой пользовательских папок, режим обслуживания `cms/maintenance.json` (сайт/API 503, админка открыта), пайплайн `lib/core/Deploy/` (preflight, Composer, npm, health-check, перезапуск systemd) и команды `systemd:set`/`systemd:give-permissions`; при сбое после замены файлов циклы удерживаются `cms/cycles/deploy.hold`; документация — [deployment.md](deployment.md) и [systemd-sudo.md](../deploy/systemd-sudo.md).

- исправлена фиксация версии системного обновления: `getLatest()` скачивает архив строго по коммиту (`getArchiveURLForCommit`), а не по «плавающей» ветке; коммит и SHA-1 архива пишутся в `cms/saverestore/update.pending.json` до замены файлов, после успешного рестарта — в конфиг модуля, а `reconcilePendingUpdate()` восстанавливает запись, если запрос оборвался; лента обновлений больше не используется для угадывания версии; HTTP-кэш вынесен в `lib/core/Http/HttpCache.php` (Redis при `USE_REDIS`, иначе файл) с ETag/Last-Modified и сбросом после установки; документация — [http-cache.md](http-cache.md).
- добавлен модуль «ИИ-помощник» (`/panel/ai_assistant.html`): провайдеры и модели OpenAI-совместимого API с шифрованием токенов, настраиваемые агенты и два стандартных профиля, защищённые Tools чтения установки (`InstallationInspector`), чат с SSE, остановкой и историей, панель ИИ в CodeMirror с вставкой одной отменяемой операцией, текстовый поиск знаний и опциональный Qdrant с пошаговой сборкой индекса; отдельного выключателя нет — установленный модуль доступен, готовность определяется моделью и агентом; раздел вынесен карточкой в левое меню и блоком на главную; CLI — `ai:rag-check`, `ai:index`; документация — `modules/ai_assistant/docs/`, план — [ai-agents-todo.md](ai-agents-todo.md). Внешний провайдер и реальный Qdrant автоматическими тестами не проверялись.

Следующий этап — **полировка мобильных деталей объектов** (длинные значения в редакторах, компактные формы), затем остальные модули.

## 3. Что читать перед изменениями

WebSocket перенесён в `lib/core/WebSockets/` и использует Composer Workerman 5.x.
Он только для чтения: локальные сети без ключа по правилам API, извне API-токен
или одноразовый билет сессии администратора. Начальный снимок и подписка на все
свойства автоматические. `lib/websockets/`, `cycle_wscache` и рестарт каждые
шесть часов удалены; очередь читает сервер. Протокол, nginx и лимиты —
`docs/agents/websocket-ui-migration.md`, инструкция — `docs/user/websockets.md`.

API ядра: `public/api.php` → `ApiApplication` → `lib/core/Api/`.
Панель — `/panel/api.html`, строгие маршруты — `/api/v1/`, legacy URL сохранены.
Локальные сети проходят без токена, внешние запросы — Bearer/X-API-Key.
Документация разработки: `docs/agents/api.md`; инструкция агенту-клиенту:
`docs/agents/api-client.md`; пример сервера — `docs/deploy/nginx.conf.sample`.

**CLI:** единая точка входа — `php majordomo`. Перед добавлением команды прочитайте
`docs/agents/console.md`: отдельный класс в `lib/core/Console/Commands/`, явная
регистрация в `Console\Application`, справка, JSON, коды завершения и изолированные
тесты обязательны. Если новая функция требует обслуживания или диагностики из
терминала, добавляйте команду в том же изменении. Не создавайте новые отдельные
CLI-скрипты; существующие `tools/config.php` и `tools/install.php` — адаптеры
совместимости, бизнес-логика в них не добавляется.

1. `docs/agents/DESIGN_SYSTEM.md` — **обязательно** перед любым UI-изменением: компоненты, токены, примеры, правила.
2. `docs/agents/project-structure.md` — раскладка каталогов, URL → файл, nginx-редиректы.
3. `docs/agents/admin-assets.md` — сборка, бандл, как добавлять пакеты.
4. `docs/agents/admin-module-ui-guidelines.md` — UI-правила для модулей.
5. `docs/agents/admin-routing.md` — контракт чистых URL, legacy `pd` и 404.
6. `docs/agents/third-party.md` — Composer, локальный пакет MQTT и ассеты; `3rdparty/` удалён.
7. `docs/agents/configuration.md` — `.env`, `Config`, типы, легаси-константы и правила для модулей.
8. `docs/agents/entrypoints.md` — точки входа, `Application`, `AdminApplication`, `SiteApplication` и контракт глобалов.
9. `docs/agents/install.md` — мастер первичной настройки, маркер установки и gate.
10. `docs/agents/knowledge-base.md` — как добавить инструкцию в базу знаний, метки и категории.
    Общие PHP-операции и их результаты — `docs/user/system-functions.md`; прежде чем обращаться к системным таблицам, ищите готовый API там.
11. `docs/agents/legacy-module-migration.md` — **обязательно** перед переписыванием старого модуля: приоритеты, порядок, новый формат, чек-лист.
12. `docs/agents/errors.md` — как формулировать ошибки (что / почему / чем грозит / что делать) и как разбирать журнал.
13. `docs/agents/code-comments.md` — **обязательно** при правке кода: комментарии по-русски и дата изменения класса.
14. Этот файл — текущее состояние и ограничения.
15. `docs/agents/deployment.md` — пайплайн деплоя, миграции, removals, maintenance и systemd; пошаговое развёртывание — `docs/deploy/server-setup.md`, детали sudo — `docs/deploy/systemd-sudo.md`.
16. `docs/agents/mqtt-runtime.md` — быстрый путь MQTT, полосы `core_runtime`, проекция состояния, настройки и диагностика.
17. `docs/agents/http-cache.md` — кэш HTTP-ответов (Redis/файл), условные запросы ETag/Last-Modified и сброс после обновления.

Если документы противоречат друг другу, приоритет у `docs/agents/AGENTS.md` и фактического кода.

## 4. Карта админки

Раскладка проекта (подробно — `docs/agents/project-structure.md`):

- `public/` — document root: точки входа (`index.php`, `admin.php`, `nf.php`, `api.php`,
  `404.php`), `assets/`, `favicon.ico`, `robots.txt`. Остальное по HTTP недоступно;
- `public/assets/` — всё, что браузер скачивает напрямую, публикуется как `/assets/`
  (`admin/` — бандл, `css/` и `js/` — legacy-статика, `img/`, `vendor/` — браузерные
  библиотеки, `modules/<name>/` — CSS/JS страниц модулей);
- `resources/` — исходники Vite (`js/` — энтри и модули, `css/` — стили), в бандл, не отдаются напрямую;
- `bootstrap.php` — `chdir` в корень приложения; его подключает каждый public-вход;
- `modules/`, `cms/`, `docs/` — вне document root, публикуются через `root`-override.
- `scripts/`, `packages/`, `vendor/` — системные каталоги без HTTP-доступа;
  `objects/` и `3rdparty/` удалены. Внешние вызовы идут через API v1,
  внутренние фоновые операции — прямо через очередь и сервисы ядра.

| Путь | Назначение |
| --- | --- |
| `public/admin.php` | Тонкий адаптер: создаёт `AdminApplication` |
| `public/nf.php` | Inbound-роутинг pretty URL, legacy `pd`, 404 |
| `public/index.php` | Тонкий адаптер: создаёт `SiteApplication` |
| `public/api.php` | REST API (`/api/...`) |
| `public/install.php` | Мастер первичной настройки (тонкий адаптер `InstallApplication`) |
| `bootstrap.php` | `chdir` в корень и общий загрузчик `lib/core.class.php` (Composer + локальный core autoloader) |
| `lib/core/Application.php` | Общий boot: config → loader → settings, таймеры, сессия |
| `lib/core/AdminApplication.php` | Админка: canonicalize, part_load, `panel`, вывод |
| `lib/core/SiteApplication.php` | Сайт: `application`, 404, postprocess, вывод |
| `lib/core/InstallApplication.php` | Мастер: сессия/CSRF, `Installer`, рендер шагов и экрана успеха |
| `lib/core/Install/` | `InstallState`, `InstallGate`, `Installer`, `InstallSchema`, `EnvFile`, `InstallDatabase`, `InstallEnvironment`, `InstallFormRenderer`, `schema.sql` |
| `lib/core/Settings/` | `SettingsSchema`, `SettingsManager`, `SettingsPresenter` — общие настройки поверх мастера |
| `lib/core/Cycles/` | `CycleStateStore`, `CycleSupervisor`, `CycleManager` — персистентное управление циклами |
| `lib/core/Filesystem/` | `FolderBrowser` — read-only обзор каталогов в границах проекта для выбора путей |
| `lib/core/Template/` | Шаблонный слой: `JTemplate` (движок `[#VAR#]`/`[#if#]`/`[#inc#]`/`[#module#]`, PHP-партиалы) и `Parser` (точка входа, `.tpl`/`.xslt` — маркер «не поддерживается»); `Condition` — безопасный вычислитель условий без `eval()`; `lib/jTemplate.class.php` и `lib/parser.class.php` — только `class_alias` для легаси-кода |
| `templates/install/install.php` | Разметка мастера (MDJUI, шаги, обзор) |
| `public/assets/install/` | Page-only CSS/JS мастера |
| `tools/install.php` | CLI: `status`, `check`, `test-db`, `reset`, `complete` |
| `lib/admin_router.php` | Единая таблица маршрутов и преобразование legacy ↔ pretty |
| `lib/error_page.php` | Общий рендер 404 (HTML/JSON, светлая/тёмная тема) |
| `public/404.php` | Публичная точка входа для nginx `error_page 404` |
| `modules/panel.class.php` | Сборка shell: меню, иконки, уведомления, данные панели, 404 для модулей |
| `lib/dashboard_data.php` | Сбор данных дашборда: ресурсы, БД, счётчики, циклы, ошибки, активность |
| `modules/inc_panel_ajax.php` | Тонкий адаптер старого AJAX панели над `lib/core/Api/Panel*` |
| `templates/panel.html` | Каркас страницы: topbar, sidebar, контент, drawers |
| `templates/panel_default.html` | Главная страница (dashboard v2) |
| `templates/panel_nav_left.html` | Левое меню |
| `templates/common_header_admin.html` | Подключение бандла, тема, legacy-скрипты |
| `resources/css/admin_panel.css` | Legacy CSS (~8 тыс. строк), ещё используется |
| `resources/css/ui-v2.css` | Новый слой shell/dashboard: токены, верхняя панель, меню, тёмная тема |
| `resources/css/ui-system.css` | Канонический слой дизайн-системы: токены, `md-*` компоненты, состояния |
| `lib/ui/MDJUI.php` | Серверный API UI-компонентов + демо-данные |
| `templates/ui/components/` | Разметка переиспользуемых компонентов |
| `templates/ui_kit/` | Страница UI Kit и её секции |
| `modules/ui_kit/` | Модуль админки `?action=ui_kit` |
| `lib/knowledge_base.php` | Реестр документов базы знаний: front matter, категории, аудитория, поиск |
| `lib/markdown.php` | Безопасный серверный рендерер Markdown для базы знаний |
| `modules/knowledge_base/` | Модуль админки «База знаний» (`/panel/knowledge_base.html`) |
| `templates/knowledge_base/` | Список материалов, статья, состояние «не найдено» |
| `public/assets/modules/knowledge_base/` | Page-only CSS/JS базы знаний (не входят в общий бандл) |
| `public/assets/modules/shoutbox/` | Page-only CSS журнала `say()` (`/panel/shoutbox.html`) |
| `docs/agents/DESIGN_SYSTEM.md` | Обязательная документация дизайн-системы |
| `resources/js/modules/admin_panel/` | Модули shell: core, drawer, search, console, notifications, init, dashboard, controls, header search, objects, data table, alpine, shared UI components, dashboard charts |
| `resources/js/modules/admin_panel/100_chart_runtime.js` | Общий lazy-loader ApexCharts (`window.MDJCharts`) для дашборда и истории |
| `resources/js/modules/admin_panel/101_dashboard_charts.js` | Графики дашборда поверх общего chart runtime |
| `public/assets/js/admin/ui_kit.js` | Page-only JS UI Kit: каталог иконок, copy, scrollspy; подключается только на `?action=ui_kit` |
| `public/assets/js/admin/panel.js` | Legacy-глобалы shell (`partLoad`, фильтр меню, file browser) |
| `public/assets/js/scripts.js` | Legacy-глобальные хелперы старых шаблонов |
| `resources/js/app.js` | Единственный entry point Vite (собирается в `admin.js`) |
| `resources/js/bootstrap.js` | jQuery-глобалы (`window.jQuery`, `window.$`), импортируется первым |
| `resources/js/charts.js` | Entry отдельного Vite-chunk графиков (ApexCharts) |
| `vite.charts.config.js` | Сборка `public/assets/admin/charts.js` + `charts.css` |
| `public/assets/admin/` | Сгенерированный бандл, коммитится |
| `resources/js/codehighlight/index.js` | Entry lazy-chunk подсветки read-only примеров (highlight.js: PHP, Python, JS, HTML/XML, CSS, Bash, JSON, YAML) |
| `resources/css/codehighlight.css` | Палитра подсветки для `md-code-sample` |
| `vite.codehighlight.config.js` | Сборка `public/assets/admin/codehighlight.js` + `codehighlight.css` |
| `resources/js/modules/admin_panel/123_code_sample.js` | Вкладки, копирование и lazy-загрузка подсветки для `MDJUI::codeSample()` |
| `resources/js/codeeditor/editor.js` | Обвязка CodeMirror 6 и рендер редактора; отдельный lazy-chunk |
| `resources/js/codeeditor/loader.js` | Lazy-загрузка редактора; входит в основной бандл |
| `resources/css/codeeditor.css` | Стили редактора, темы, поиск, drawer истории |
| `resources/js/codeeditor/index.js` | Entry отдельного Vite-chunk |
| `vite.codeeditor.config.js` | Сборка `public/assets/admin/codeeditor.js/css` |
| `tools/screenshot.mjs` | Локальная проверка страниц через headless Chrome + CDP |

## 5. Сборка и запуск

### Ассеты

```bash
npm install
npm run build          # admin bundle + code editor + charts + code sample highlighter
npm run watch          # admin bundle
npm run watch:editor   # code editor (второй терминал)
npm run watch:charts   # графики дашборда (второй терминал)
npm run watch:highlight # подсветка примеров кода (второй терминал)
```

- Не редактируйте `public/assets/admin/*` вручную.
- После `npm run build` при необходимости поднимите версию в `templates/common_header_admin.html` (`?v=...`), `window.MDCodeEditorConfig`, `window.MDChartsConfig` и `window.MDCodeHighlightConfig`.
- Бандл коммитится как fallback, но системное обновление собирает его на сервере (`DEPLOY_ASSETS=always` по умолчанию) при наличии Node.js 24+. `DEPLOY_ASSETS=never` оставляет только готовый бандл.
- Редактор кода — отдельный lazy-chunk `public/assets/admin/codeeditor.js/css`; его собирает `vite.codeeditor.config.js`.
- Графики — отдельный lazy-chunk `public/assets/admin/charts.js` + `charts.css` (ApexCharts); его собирает `vite.charts.config.js`, загрузка идёт из общего `100_chart_runtime.js` (дашборд и история).
- Подсветка read-only примеров — отдельный lazy-chunk `public/assets/admin/codehighlight.js` + `codehighlight.css` (highlight.js); собирает `vite.codehighlight.config.js`, загружает `123_code_sample.js` только при наличии `[data-md-code-sample]`.

### Локальный сервер

```bash
php dev_server.php --host=127.0.0.1 --port=8080
```

Нужен MySQL/MariaDB. Настройки лежат в `.env` (не коммитится): `copy .env.example .env` и заполните доступы. `config.php` — только шим для старых include-точек. Для чистой локальной базы можно импортировать `docs/dev/db_terminal.sql` (в нём схема + демо-данные; часть данных историческая).

Локально `dev_server.php` отдаёт `public/` как document root, а `modules/`, `cms/`, `docs/` — из корня проекта (как nginx). Системные `scripts/`, `packages/`, `vendor/` закрыты; `/objects/` и `/3rdparty/` удалены. CWD перед запуском PHP = каталог файла, как в php-fpm.

### Деплой

Целевой веб-сервер — только nginx. Единственный источник правды — `docs/deploy/nginx.conf.sample`; `.htaccess` в проекте нет и добавлять его не нужно. Правила, которые раньше лежали в `.htaccess` (роутинг на `nf.php`/`api.php`, запрет `lib/`, `templates/*.html`, `config.php`, `.env` и dotfiles), перенесены в этот конфиг.

### Скриншоты

```bash
node tools/screenshot.mjs --url "http://127.0.0.1:8080/admin.php" --out "docs/ui-redesign/home.png" --cookie "prj=<session>"
```

Требуется Chrome/Edge. Скрипт поднимает headless-браузер, ставит cookie, ждёт AJAX и сохраняет PNG. Для тёмной темы добавьте `; theme=dark` в cookie.

## 6. UI-правила

- **Перед созданием или изменением интерфейса сначала изучите `docs/agents/DESIGN_SYSTEM.md` и существующие UI-компоненты.** Используйте готовые компоненты. Запрещено создавать альтернативные реализации кнопок, полей ввода, селектов, карточек, модальных окон и других стандартных элементов без явной необходимости. Новые компоненты должны соответствовать существующей дизайн-системе.
- Bootstrap 5 — база. Новые компоненты — Bootstrap 5 API + `md-*` классы.
- Новый общий CSS — только в `resources/css/ui-system.css` (канонический слой дизайн-системы). `ui-v2.css` отвечает за shell/dashboard и не разрастается новыми компонентами.
- Legacy `admin_panel.css` не разрастается новыми компонентами; только точечные правки.
- PHP-компоненты: `MDJUI` + `templates/ui/components/`; не дублируйте их разметку на страницах.
- Новый JS — модуль в `resources/js/modules/admin_panel/`, регистрация через `MDJAdminUI.registerModuleUI`.
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
- `window.MDJAdminUI`;
- `window.MDFolderPicker` (read-only выбор папки; legacy `openFileBrowser` делегирует ему);
- глобальные функции из `public/assets/js/scripts.js` и `public/assets/js/admin/panel.js`.

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
- Конфигурацию: `.env`, `Config::get()`, легаси-мост. Не добавлять `define()` и не читать `getenv()` в модулях.
- Авторизацию: `AuthGate` до loader, только администраторы из `users`, cookie-сессия ядра и CSRF; правила — `docs/agents/authentication.md`. Старые `session->data`, `usual()` и фронтенд модулей удалены.
- Формат данных и API старых модулей.
- Таблицу маршрутов и round-trip-проверку в `lib/admin_router.php`: без неё
  старые ссылки начнут терять состояние.
- Catch-all-маршрут `([\w-]+).html` → docs: он маскировал 404 под 200.
- Статус 404 в `admin.php`: не перезаписывайте его на 200 при выводе shell.

## 9. Куда что добавлять

### Системные PHP-функции и доступ к данным

- Перед работой с объектами, свойствами, методами, сценариями, историей, событиями, таймерами и кэшем прочитайте [справочник системных функций](../user/system-functions.md). Реализации собраны по назначению в `lib/core/Helpers/`, единое подключение — `lib/core/Helpers/bootstrap.php` через штатный `lib/loader.php`.
- Используйте существующие функции (`getGlobal`, `setGlobal`, `callMethod`, `runScript`, `setTimeOut` и другие) или соответствующие сервисы `Core::*`. Не читайте и не изменяйте системные таблицы напрямую из вызывающего кода, если операция уже есть в общем API: это обходит кэш, `ONCHANGE`, связанные модули, аудит, историю и очередь уведомлений.
- Если операции не хватает, добавьте её в существующий сервис ядра и, когда она нужна сценариям или модулям, в соответствующую группу helpers. Новую группу подключайте явно в `Helpers/bootstrap.php`. Не создавайте ещё одну реализацию той же операции в модуле. SQL допустим внутри слоя, владеющего данными, в миграциях и для собственных таблиц модуля; повторно реализовывать системные операции через SQL нельзя.
- Модуль остаётся модулем: его код живёт в `modules/<name>/`, устанавливается и удаляется штатно и не переносится в `lib/core/`. В ядро попадают только универсальные операции, нужные нескольким модулям; перенос модуля или его части в ядро — отдельное решение владельца, а не побочный эффект миграции. Подробно — `docs/agents/legacy-module-migration.md`.
- Старые файлы `lib/objects.class.php`, `lib/common.class.php` и остальные адаптеры сохраняются для внешних модулей. Не возвращайте в них реализации. Инициализация HTTP-параметров в `lib/general.class.php` и класс `xml_data` в `lib/xml_data.class.php` сохраняют прежний жизненный цикл.
- Не переименовывайте публичные функции и параметры, не меняйте их порядок, значения по умолчанию, передачу по ссылке и допустимые типы. Короткие имена (`sg`, `gg`, `cm`, `rs`) — публичные алиасы, удалять их нельзя. Дубли сводите к делегированию основной функции.
- Синхронная обёртка возвращает исходный результат исполнителя, включая `false`, `0`, пустую строку, массив и `null`; ошибки не подменяются успешным статусом. Для фоновых операций документируйте отдельно статус постановки и результат выполнения. Изменения возвращаемого результата существующей функции описывайте в справочнике и проверяйте тестами.
- Обновляйте справочник вместе с API. Запускайте `php tests/system_helpers_smoke.php`: он проверяет сохранённые публичные сигнатуры, старые пути подключения, результаты алиасов, таймеры и полноту справочника без рабочей БД и внешних сервисов. Контракт `tests/fixtures/system_helpers.json` фиксирует существующий API; не переписывайте его только ради прохождения теста при случайном изменении сигнатур.

### Размещение остальных изменений

- Общий компонент: `resources/css/ui-system.css` + метод в `lib/ui/MDJUI.php` + partial в `templates/ui/components/`.
- Общее поведение: модуль в `resources/js/modules/admin_panel/`, импорт в `resources/js/app.js`, регистрация через `MDJAdminUI`.
- Страничный CSS/JS: в `public/assets/modules/<module>/`, только на страницах своего модуля; **не переносить в общий бандл** без отдельной явной задачи.
- Демо компонента: секция в `templates/ui_kit/sections/` и запись в `docs/agents/DESIGN_SYSTEM.md`; page-only JS UI Kit — `public/assets/js/admin/ui_kit.js`.
- Новая библиотека: `npm install`, импорт в `resources/js/app.js` (или отдельный entry, если библиотека тяжёлая), при необходимости `window.X = ...`, `npm run build`. Тяжёлые библиотеки вроде CodeMirror и ApexCharts держите в отдельных lazy-chunk, а не в основном бандле.
- Новая иконка модуля: добавить в `$sidebarIcons` в `modules/panel.class.php` (значение — `ti-*` из Tabler).
- Новая настройка: `ConfigSchema` + `.env.example` + `Config::get()`; не добавлять константу. Подробно — `docs/agents/configuration.md`.
- Новая настройка первого запуска: поле в `lib/core/Install/InstallSchema.php`, при необходимости — правило в `Installer`, вариант в `InstallFormRenderer`/`templates/install/install.php`; секреты помечать `secret`, env-ключ обязан быть в `ConfigSchema`.
- Новая страница UI Kit не нужна: используйте `/admin.php?action=ui_kit`.
- Новая инструкция для пользователя: Markdown-файл в `docs/user/`; для агента — в `docs/agents/`. Метки аудитории, категория и порядок задаются во front matter, см. `docs/agents/knowledge-base.md`.

## 10. Дорожная карта

1. ~~Shell + dashboard + сборка~~ — сделано.
2. ~~UI Kit / дизайн-система: токены, `md-*` компоненты, документация, Alpine~~ — сделано.
3. ~~Объекты: список, карточка, свойства, методы, формы, поиск, тёмная тема~~ — сделано; история объекта и редактор методов переведены на `md-*`, история свойства — на общий `MDJUI::historyDrawer()`.
4. ~~Классы: список-дерево, KPI, поиск/фильтр, карточка и форма~~ — сделано; свойства, методы и унаследованные элементы переведены на общую систему.
5. ~~Сценарии: список, категории, KPI, импорт/экспорт, карточки~~ — сделано; редактор кода переведён на CodeMirror 6 (lazy-chunk), осталась финальная проверка тёмной темы на старых вкладках.
6. ~~Настройки, обновление системы, X-Ray, маркет, системные ошибки~~ — переведены на общую систему.
7. Чистка legacy CSS/JS, перенос оставшихся глобальных скриптов, возможно server-rendered фрагменты/Alpine для сокращения JS.
8. Обновление/удаление старых фронтенд-модулей — отдельным явным этапом.

## 11. Проверка перед завершением

- `php tests/console_smoke.php` и `php tests/console_users_smoke.php` — при изменениях CLI: парсер, справка, оформление, JSON, циклы, журналы, пользователи и смена пароля на изолированных фикстурах/SQLite без рабочей БД/Redis.

- `php -l` для изменённых PHP-файлов.
- `node --check` для изменённых JS.
- `npm run build` после изменений в `resources/`.
- `node tests/panel_notifications_smoke.mjs` — модуль уведомлений в общем бандле: на странице входа без `window.ROOTHTML` опроса журнала нет, а в оболочке адрес строится по префиксу проекта.
- `php tests/websocket_smoke.php` и `php tests/websocket_transport_smoke.php` — билет запоминает фактический каталог сессий (включая зафиксированный `php_admin_value`) и проверяется отдельным процессом.
- `php tests/routing_smoke.php` — контракт friendly URL, round-trip и nginx-конфига.
- `php tests/config_smoke.php` — парсер `.env`, типы, кеш и легаси-мост.
- `composer validate --strict`, `composer dump-autoload --optimize --strict-psr`, `php tests/composer_smoke.php` — при изменениях PHP-зависимостей или автозагрузки. PHPMailer и локальный MQTT загружаются только Composer.
- `php tests/install_smoke.php` — `.env`-писатель, маркер установки, схема, gate и рендер мастера.
- `php tests/settings_cycles_smoke.php` — схема «Общих настроек», персистентные состояния, hold и reconcile циклов.
- `php tests/migrations_smoke.php`, `php tests/file_removals_smoke.php` — файловые миграции и безопасное удаление по `deploy/removals.json`.
- `php tests/maintenance_smoke.php`, `php tests/deploy_smoke.php`, `php tests/systemd_smoke.php` — режим 503, preflight/health-check, unit/wrapper/sudoers без root и рабочей БД.
- `php tests/property_audit_smoke.php` — журнал изменений свойств: trace id, шаги, параметры входа/выхода, старое/новое значение и продолжение запуска.
- `php tests/script_safe_path_smoke.php` — сообщения, сценарии и подписки ставятся прямо в очередь ядра без HTTP/API; исходные имена сохраняются.
- `php tests/mqtt_binding_index_smoke.php` — версионированный индекс привязок, префиксы, ведущий `/`, поиск по объекту/свойству и наложение состояния.
- `php tests/lane_queue_smoke.php`, `php tests/core_lanes_smoke.php` — реальный изолированный Redis: порядок в полосе, параллельность, эксклюзивность `serial`, ack/reap/delayed/fail и параллельные исполнители `core_runtime` (нужен `MJD_TEST_REDIS_PORT`).
- `php tests/mqtt_runtime_smoke.php`, `php tests/mqtt_runtime_processing_smoke.php` — коалесинг состояния, проекция в SQLite, отдельная история, JSON-привязки, write-path, strict mode и троттлинг (нужен `MJD_TEST_REDIS_PORT`).
- `php tests/pagination_smoke.php`, `php tests/private_paths_smoke.php` — границы SQL-пагинации и закрытие системных путей.
- `php tests/error_catalog_smoke.php` — каталог ошибок: у каждого кода есть название и блоки «что это / что произошло / почему / чем грозит / что делать».
- `php tests/api_failure_log_smoke.php` — сбой API попадает в «Ошибки системы» с маршрутом, request_id, классом, сообщением и стеком; 4xx и режим обслуживания журнал не засоряют.
- `php tests/docs_quality_smoke.php` — документация: front matter, известные категории, рабочие относительные ссылки и русский язык.
- `php tests/folder_picker_smoke.php` — границы folder picker: обход `..`, абсолютные пути вне проекта, скрытые каталоги, fallback на корень.
- `php tests/knowledge_base_smoke.php` — сканирование `docs/`, front matter, безопасный Markdown, ссылки и категории базы знаний.
- `php tests/knowledge_base_utf8_smoke.php` — front matter с кириллицей не режется на байте `0x85` (буква «х»), а битые байты не попадают в карточку.
- В изменённых классах есть заголовок с записью «Изменения: ГГГГ-ММ-ДД» и понятные комментарии на русском (`docs/agents/code-comments.md`).
- `php tests/template_include_smoke.php` — `[#inc *.php#]` рендерит PHP-партиал с `$data` при `DEBUG_MODE=0`, `.tpl`/`.xslt` отдают маркер «не поддерживается», легаси-алиасы ведут на ядро.
- `php tests/template_surface_smoke.php` — целостность шаблонов: существование `[#inc#]`/`[#module#]`, баланс `[#begin#]`/`[#end#]`, отсутствие дампов и `.tpl`/`.xslt`, отсутствие Smarty, алиасы `jTemplate`/`parser`.
- `php tests/template_render_smoke.php` — рендер всех шаблонов без БД при `DEBUG_MODE=0`: нет неотрендеренных тегов, потерянных инклюдов и предупреждений движка.
- `php tests/template_condition_smoke.php` — все `[#if#]`-условия дают тот же результат, что старая eval-реализация (623 условия × 28 наборов данных), и проверка `[#VAR|html#]`.
- `php tools/config-audit.php` — не выросло ли число обращений к легаси-константам.
- Открыть `/panel/` и затронутые страницы: console/exceptions, 390 px, светлая и тёмная темы.
- После чистой установки проверить вход в `/panel/` с созданным администратором; без сессии должна открываться форма входа.
- Проверить старую `pd`-ссылку: 302 на `/panel/...` и то же состояние.
- Проверить 404: `/nonexistent.html`, `/panel/does_not_exist.html`, `/nonexistent`.
- Проверить `partLoad`-переход между страницами.
- Проверить, что старые модули не потеряли jQuery/Bootstrap-глобалы.

## 12. Известный технический долг

- `resources/css/admin_panel.css` — ~8 тыс. строк, много дублей и «финальных полировок».
- `public/assets/js/scripts.js` и `public/assets/js/admin/panel.js` — глобальные legacy-скрипты.
- CSS/JS части модулей (`public/assets/modules/settings/settings.css`, `public/assets/modules/scripts/scripts.js` и т.д.) сознательно остаются локальными: подключаются только на страницах своих модулей, чтобы не грузить код всех модулей сразу. В общий бандл их не переносить.
- `100_dashboard.js` и `110_objects.js` пока остаются в общем бандле как часть текущего shell/objects; это существующий долг, а не паттерн для новых модулей. При переделке этих модулей их page-specific JS выносить локально.
- В `templates/objects/objects_edit_properties.html` остаётся большой inline-скрипт (редактирование значений, WebSocket) — кандидат на вынос в module UI.
- Тёмная тема не проверена на отдельных legacy-страницах вне текущего этапа (старые вкладки и служебные модули).
- ApexCharts грузится отдельным чанком `public/assets/admin/charts.js` через общий `100_chart_runtime.js`; на страницах без графиков чарт-рантайм не скачивается.
- `public/assets/js/window.js`, `public/assets/js/jquery-ui-1.9.1.custom.min.js` и т.п. — legacy фронтенда; не трогать без проверки входящих ссылок.
- Шаблоны подставляют значения без HTML-экранирования: `templateSafe()` гасит только шаблонные разделители. Для новых шаблонов есть явный фильтр `[#VAR|html#]`; глобальный автоэскейп не включаем, потому что легаси-шаблоны передают намеренный HTML (`[#module#]`, готовые фрагменты).
- Компилирующий кэш шаблонов **не нужен**: замер профайлера показал, что на рендер уходит 4–6 мс из 50–117 мс страницы (`Parse template panel.html` минус вложенный `module_*`), остальное — запросы модулей, `getKeyData` (14,7 мс на странице классов), `loader` и подключение к БД. После перевода подстановок на `preg_replace_callback` рендер оболочки ускорен в 1,58×; дальнейшая компиляция окупит ~0,3 мс при высоком риске.
- `getKeyData()` в `lib/core/Helpers/Objects.php` — N+1: на каждый объект отдельный `SQLSelectOne` + `getGlobal()` по каждому data-key свойству. Это главный оставшийся тормоз страницы классов (14,7 мс при 21 объекте) — кандидат на пакетную загрузку.

## 13. Удалённые/неактуальные файлы

Не возвращайте их без причины:

- `modules/duration_input/`, `templates/duration_input/` и маркер установки — неиспользуемый модуль DurationInput; библиотека `html-duration-picker`, её локальная копия в `public/assets/vendor/duration-picker/` и глобал `window.HtmlDurationPicker` также удалены: других вызовов в проекте нет.
- `3rdparty/smarty3/` — 1,1 МБ, второй шаблонизатор ради одного файла `templates/classes/view_tree.tpl`; дерево классов переписано в `templates/classes/view_tree.php`, `.tpl`/`.xslt` теперь отдают маркер «не поддерживается» и в проекте не используются;
- `templates/panel_nav_top.tpl` — Smarty-дубль верхней навигации, не подключался ни из одного шаблона;
- `templates/classes/view_tree.html` — мёртвый HTML-дубль `view_tree.tpl` (0 вхождений в коде и шаблонах), содержал `[#if RESULT AND …#]`, что в PHP 8 даёт `Undefined constant` и фатал; удалён вместе с переносом шаблонного слоя;
- `3rdparty/amcharts/` — графики главной заменены на CSS/данные, библиотека не использовалась;
- `3rdparty/bootstrap5/` — Bootstrap теперь в npm-бандле;
- `3rdparty/bootstrap-icons/` — иконки в бандле;
- `3rdparty/tom-select/` — Tom Select в бандле;
- `3rdparty/codemirror/` — CodeMirror 5 удалён; редактор теперь CodeMirror 6 в lazy-chunk `codeeditor.js`;
- `js/admin/admin_panel.js` — старый загрузчик модулей, заменён импортами Vite;
- `templates/panel_js_scripts.html` — содержимое перенесено в `public/assets/js/admin/panel.js`;
- `css/admin.css` — не имел входящих ссылок;
- `js/admin.js` — был помечен DEPRECATED, не использовался;
- `js/datepicker.js` — не имел входящих ссылок, заменён современными date-подходами;
- `3rdparty/d3`, `3rdparty/googleblockly`, `3rdparty/select2`, `3rdparty/spectrum`, `3rdparty/swiper`, `3rdparty/tinyslider`, `3rdparty/hammer`, `3rdparty/svg-pan-zoom`, `3rdparty/jquery.contextmenu` — 0 входящих ссылок, заменены npm-пакетами (ApexCharts, CodeMirror 6, Tom Select);
- `js/builder.js`, `js/effects.js`, `js/dragdrop.js`, `js/slider.js`, `js/system.js`, `js/gmaps.js`, `js/jquery.autocomplete.js`, `js/jquery.betterTooltip.js`, `js/jquery.bgiframe.js`, `js/jquery.event.drag-2.2.js`, `js/jquery.gritter.js`, `js/ui.core.js`, `js/ui.draggable.js`, `js/ui.resizable.js` — мёртвая legacy-фронтенд-цепочка (script.aculo.us, jQuery UI-дубли, неиспользуемые плагины);
- `css/jquery.autocomplete.css`, `css/jquery.betterTooltip.css`, `css/jquery.gritter.css`, `css/ui-*/jquery-ui-1.9.1.custom.min.css` — парные к удалённым скриптам и min-дубли;
- `templates/scripts.js`, `templates/debug_templates.js`, `templates/scripts/scripts_widgets.css` — не подключались ни из одного шаблона;
- `public/diagnostic.php`, `public/print_all.php`, `public/write_error.php`, `public/command.php`, `public/backup.php` — неиспользуемые точки входа: ссылки на них убраны (страница ошибки, hero «Ошибок системы», X-Ray, `load_settings.php`, `scripts.js`); бэкапы делает saverestore;
- `pChart/` и `3rdparty/jpgraph/` — 0 ссылок в коде и БД, графики давно на ApexCharts;
- `3rdparty/freeboard/` — дашборды freeboard: эндпоинты-виджеты никуда не подключены;
- `rc/` — архаичный пульт (Windows-бинарники + `rc/index.php`); URL `/rc/...` теперь 301 на `/panel/`;
- `3rdparty/elFinder/` — неограниченный файловый менеджер с upload/write; заменён project-scoped folder picker в настройках (`lib/core/Filesystem/FolderBrowser.php`, `MDFolderPicker`);
- `.tmp-p.json`, `obj.bat`, `templates_alt/`, `lib/_vimage_tahoma.ttf`, `cms/icons/1_iPhone_MUSIC_5_sm.png` — мусор без ссылок (аватар `cms/avatars/1_ver-logo_any_tickets-large.png` оставлен: он привязан к пользователю в БД);
- `.htaccess` (корень и подкаталоги `lib/`, `templates/`, `cms/`, `backup/`) — правила перенесены в `docs/deploy/nginx.conf.sample`, деплой только на nginx; каталоги, которые держались на этих файлах, сохранены через `.gitkeep`;
- `modules/control_access/`, `templates/control_access/`, `modules/master/`, `templates/master/` — старые вход и ACL панели; новая авторизация на `users` находится в `lib/core/Auth/`;
- таблица `admin_users` удалена из схемы/установщика, встроенная проверка HTTP Basic Auth в `load_settings.php` (`EXT_ACCESS_*`, `REMOTE_*`) удалена; в существующих БД таблица может остаться как неиспользуемая; доступ закрывает nginx;
- `config.php.sample` — заменён документированным `.env.example`; настройки больше не живут в PHP-файле.
- `scripts/sample.php`, `scripts/sample_cycle_arduino_direct.php`, `scripts/php_serial.class.php`, `scripts/snmp_trap_daemon.php` — неиспользуемые примеры и старый SNMP-демон; serial-библиотека требовалась только примеру Arduino, модуль `snmpdevices` отсутствует. Рабочие `cycle_*.php`, `core_worker.php`, `periodical_db_save.php` и `startup_maintenance.php` сохранены.

## 14. Комментарии в коде и история изменений

- Новые и изменённые комментарии — по-русски и для человека: объясняйте смысл,
  причину и последствия, а не пересказывайте синтаксис.
- У каждого класса — короткий заголовок «что это и зачем». При изменении кода
  добавьте в него запись `ГГГГ-ММ-ДД — что изменилось` (новые записи сверху).
- Для файлов без класса (`*.inc.php`, скрипты, миграции) — короткий заголовок
  файла с датой и описанием изменения.
- Подробно, с примерами «хорошо/плохо» — `docs/agents/code-comments.md`.
