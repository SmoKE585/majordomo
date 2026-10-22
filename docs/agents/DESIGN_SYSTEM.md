---
title: Дизайн-система MajorDoMo
audience: agent
category: ui
category_label: Интерфейс
category_icon: layout-dashboard
category_order: 50
tags: дизайн-система, интерфейс, md-*, mdjui, bootstrap 5, tabler, компоненты
description: Обязательное руководство по интерфейсу админки MajorDoMo — токены, компоненты MDJUI, классы md-*, правила применения и чек-лист.
order: 10
---

# Дизайн-система MajorDoMo

## Коротко

Админка строится на Bootstrap 5.3, локальных токенах `--md-ui-*`, серверных компонентах `MDJUI` и классах `md-*` из `resources/css/ui-system.css`. Новые страницы собираются только из этих компонентов; альтернативные кнопки, поля, карточки, модалки и наборы иконок запрещены. Живая документация — UI Kit (`/admin.php?action=ui_kit`), канонический CSS — `resources/css/ui-system.css`.

Единая дизайн-система админки MajorDoMo. Документ обязателен для людей и ИИ-агентов, которые создают или меняют интерфейс панели управления.

- Живая документация: **`/admin.php?action=ui_kit`**
- Канонический CSS: `resources/css/ui-system.css`
- PHP-компоненты: `lib/ui/MDJUI.php` + `templates/ui/components/`
- Демонстрационные секции: `templates/ui_kit/sections/`
- Сборка: `npm run build`
- Каталог иконок: `npm run icons` (после обновления `@tabler/icons-webfont`)

> **Главное правило.** Сначала изучите этот файл и UI Kit. Используйте существующие компоненты. Запрещено создавать альтернативные реализации кнопок, полей ввода, селектов, карточек, модальных окон и других стандартных элементов без явной необходимости. Новые компоненты должны соответствовать существующей дизайн-системе.

---

## 1. Стек и границы

Проект — не Laravel-приложение. Фактический стек админки:

| Слой | Технология |
| --- | --- |
| Backend | PHP 8.1+ (локальное окружение — 8.4), MajorDoMo modules и шаблоны |
| UI base | Bootstrap 5.3 + Tabler Icons 3.49 |
| Локальные токены и компоненты | `resources/css/ui-system.css` |
| Сборка | Vite (`npm run build` → `public/assets/admin/admin.css` + `admin.js`) |
| Локальные интерактивные состояния | Alpine.js 3 |
| Select с поиском | Tom Select (`data-md-searchable`) |
| Графики | ApexCharts (lazy-chunk `public/assets/admin/charts.js`) |
| Шрифт | Rubik (локально, `public/assets/fonts/rubik/`) |

Laravel, Blade, Livewire и Tailwind в проекте отсутствуют. Поэтому «Blade-компоненты» реализованы эквивалентным серверным слоем:

- `MDJUI::button(...)`, `MDJUI::card(...)` и т. д. — публичный API;
- `templates/ui/components/<name>.php` — шаблон компонента, получает `$props` и `$slot`;
- `MDJUI::render('name', $props, $slot)` — низкоуровневый вызов для собственных компонентов.

Это даёт тот же контракт, что Blade: именованный компонент, параметры, слот, единая разметка и состояния. Если проект когда-нибудь перейдёт на Blade, partials и классы можно перенести без визуальных изменений; `wire:model` уже поддерживается через `attrs`.

Не добавляйте Tailwind, вторую систему токенов или вторую библиотеку иконок: они конфликтуют с Bootstrap и shell-админкой.

---

## 2. Быстрый старт

### Подключить библиотеку компонентов

```php
require_once(ROOT . 'lib/ui/MDJUI.php');
```

### Вывести кнопку

```php
<?= MDJUI::button('Сохранить', [
    'variant' => 'primary',
    'icon' => 'check',
]) ?>
```

### Собрать карточку

```php
<?= MDJUI::card([
    'title' => 'Кухня · Основной свет',
    'subtitle' => 'Обновлено 12 секунд назад',
    'actions' => MDJUI::button('Открыть', ['variant' => 'secondary', 'size' => 'sm']),
], '<p>Яркость 72% · Zigbee</p>') ?>
```

### Форма с `wire:model` / `x-model`

```php
<?= MDJUI::input([
    'name' => 'title',
    'label' => 'Название устройства',
    'value' => $title,
    'hint' => 'Отображается в списке устройств.',
    'attrs' => ['wire:model' => 'title'], // или ['x-model' => 'title']
]) ?>
```

`attrs` — сквозной словарь HTML-атрибутов. Он поддерживает `wire:model`, `x-model`, `wire:change`, `@input`, `data-*`, `aria-*` и любые другие атрибуты. Это и есть совместимость с Livewire/Alpine без привязки библиотеки к конкретному фреймворку.

---

## 3. Токены

Все значения — CSS-переменные на `body.md-admin-shell`. Не хардкодьте цвета, радиусы, отступы и высоты контролов.

### Цвета

| Токен | Light | Dark | Назначение |
| --- | --- | --- | --- |
| `--md-ui-bg` | `#f4f6f9` | `#0b1320` | фон страницы |
| `--md-ui-surface` | `#ffffff` | `#111c2c` | карточки, поля, модалки |
| `--md-ui-surface-2` | `#f8fafc` | `#162235` | тулбары, hover, вложенные зоны |
| `--md-ui-surface-3` | `#f1f5f9` | `#1b2940` | треки, disabled, skeleton |
| `--md-ui-border` | `#e4e8ef` | `rgba(255,255,255,.08)` | границы |
| `--md-ui-border-strong` | `#d3dae4` | `rgba(255,255,255,.14)` | границы контролов |
| `--md-ui-text` | `#101828` | `#eef4fb` | основной текст |
| `--md-ui-text-soft` | `#344054` | `#cbd5e1` | вторичный текст |
| `--md-ui-muted` | `#667085` | `#94a3b8` | подписи, meta |
| `--md-ui-primary` | `#2f7fbd` | `#6cb2e8` | действия, active, ссылки |
| `--md-ui-success` | `#12915c` | `#4ade80` | норма, онлайн |
| `--md-ui-warning` | `#b25e09` | `#fbbf24` | деградация, внимание |
| `--md-ui-danger` | `#c4322b` | `#f87171` | ошибка, destructive |
| `--md-ui-info` | `#2570a8` | `#60a5fa` | нейтральная информация |

Для мягких фонов используйте `--md-ui-*-soft`. Для статусов — `md-badge--*` и `md-status--*`, а не произвольные цвета.

### Типографика

| Роль | Размер / line-height | Вес | Пример |
| --- | --- | --- | --- |
| Display | 34 / 1.08 | 600 | заголовок страницы |
| H1 | 28 / 1.15 | 600 | раздел |
| H2 | 22 / 1.25 | 600 | карточка |
| H3 | 18 / 1.3 | 600 | подзаголовок |
| Body | 14 / 1.5 | 400 | основной текст |
| Small | 13 / 1.45 | 400/500 | подписи, формы |
| Caption | 11 / 1.4 | 500 | метки, таблицы, статусы |
| Code | 12 / 1.6 | 400 | код и ID |

- Семейство — Rubik. Не задавайте другие шрифты, кроме monospace для кода/логов.
- Заголовки — с отрицательным трекингом, служебные метки — с положительным и uppercase.
- Не используйте вес 700+ без причины.

### Отступы, радиусы, размеры

- Шкала отступов: 4, 8, 12, 16, 20, 24, 28, 32, 40, 48 px (`--md-ui-space-*`).
- Радиусы: 6, 9, 12, 14, 18 px и pill (`--md-ui-radius-*`).
- Контролы: `--md-ui-control-sm` 30 px, `--md-ui-control-md` 36 px, `--md-ui-control-lg` 44 px.
- Высота строки таблицы: `--md-ui-row-height` 48 px.
- Тени: `--md-ui-shadow-xs`, `--md-ui-shadow`, `--md-ui-shadow-pop`, `--md-ui-shadow-dialog`. Тень отделяет поверхность, а не создаёт декоративный объём.

### Фокус

```css
outline: 0;
border-color: var(--md-ui-primary);
box-shadow: 0 0 0 3px var(--md-ui-focus);
```

Для destructive-контролов используется `--md-ui-focus-danger`. Фокус всегда видим с клавиатуры. Не удаляйте `:focus-visible`.

### Тёмная тема

Тема переключается атрибутом `data-bs-theme` на `<html>` и cookie `theme`. Компоненты не должны иметь отдельную dark-разметку: они читают токены.

### Иконки

Канонический набор — **Tabler Icons 3.49**, 5 247 outline-иконок на единой сетке 24 px.

```php
<?= MDJUI::icon('bulb') ?>
<?= MDJUI::button('Включить', ['icon' => 'power']) ?>
```

```html
<i class="ti ti-bulb" aria-hidden="true"></i>
```

- Имя передаётся без префикса `ti-`: `temperature`, `device-lightbulb`, `shield-lock`.
- Живой поиск по всем 5 247 иконкам — в UI Kit, секция «Основы»: `/admin.php?action=ui_kit#foundations`.
- Клик по иконке в каталоге копирует готовый класс.
- Каталог генерируется командой `npm run icons` из `@tabler/icons-webfont` в `lib/ui/tabler-icons.json`.
- Bootstrap Icons остаётся в бандле только для legacy-совместимости `glyphicon`. Не используйте `bi bi-*` в новых компонентах.
- `MDJUI::icon()` понимает старые Bootstrap-имена и маппит их на Tabler, но это только переходный слой.
- Не подключайте Font Awesome, Heroicons и другие наборы: одна система — Tabler.

---

## 4. Каталог компонентов

### 4.1. Кнопки и действия

#### `MDJUI::button($label, $props = [])`

```php
<?= MDJUI::button('Сохранить', ['variant' => 'primary', 'icon' => 'check']) ?>
<?= MDJUI::button('Отмена', ['variant' => 'secondary']) ?>
<?= MDJUI::button('Настроить', ['variant' => 'outline', 'icon' => 'adjustments-horizontal']) ?>
<?= MDJUI::button('Подробнее', ['variant' => 'ghost', 'icon_after' => 'arrow-right']) ?>
<?= MDJUI::button('Удалить', ['variant' => 'danger', 'icon' => 'trash']) ?>
```

| Параметр | Значения | По умолчанию |
| --- | --- | --- |
| `variant` | `primary`, `secondary`, `outline`, `ghost`, `danger`, `success` | `primary` |
| `size` | `sm`, `md`, `lg` | `md` |
| `icon` / `icon_after` | имя Tabler Icons без `ti-` (можно и с `ti-`) | — |
| `loading` | bool | `false` |
| `disabled` | bool | `false` |
| `href` | URL | — |
| `type` | `button`, `submit`, `reset` | `button` |
| `block` | bool | `false` |
| `attrs` | массив HTML-атрибутов | `[]` |
| `class` | дополнительный класс | — |

Правила: один primary-action на экран; destructive — `danger` и подтверждение; в плотной строке — `sm`/`ghost`; icon-only — только `iconButton` с `aria-label`.

#### `MDJUI::iconButton($icon, $props = [])`

```php
<?= MDJUI::iconButton('pencil', ['label' => 'Редактировать']) ?>
<?= MDJUI::iconButton('trash', ['label' => 'Удалить', 'variant' => 'danger']) ?>
```

Параметры: `icon`, `label` (обязателен), `variant`, `size`, `href`, `loading`, `disabled`, `tooltip` (по умолчанию `true`), `attrs`.

#### `MDJUI::buttonGroup($slot, $props = [])`

```php
<?= MDJUI::buttonGroup(
    MDJUI::button('День', ['variant' => 'ghost', 'class' => 'is-active']) .
    MDJUI::button('Неделя', ['variant' => 'ghost'])
) ?>
```

Группируйте только взаимоисключающие действия. Не превращайте group в панель из 6 кнопок.

#### Dropdown button

Bootstrap 5 + `.md-menu`:

```html
<div class="dropdown">
  <button class="md-btn md-btn--secondary" data-bs-toggle="dropdown" aria-expanded="false">
    Действия <i class="ti ti-chevron-down" aria-hidden="true"></i>
  </button>
  <ul class="dropdown-menu md-menu">
    <li><button class="dropdown-item">Запустить</button></li>
    <li><hr class="dropdown-divider"></li>
    <li><button class="dropdown-item text-danger">Удалить</button></li>
  </ul>
</div>
```

#### Состояния

- `disabled` — пониженная непрозрачность, `pointer-events: none`, `aria-disabled`.
- `loading` — спиннер, `aria-busy`, блокировка повторного клика; ширина не скачет.
- hover/focus/active — живые CSS-состояния; для документации используются `is-demo-*`.

### 4.2. Бейджи, статусы, аватары

#### `MDJUI::badge($label, $tone = 'neutral', $props = [])`

```php
<?= MDJUI::badge('Онлайн', 'success', ['dot' => true]) ?>
<?= MDJUI::badge('Контур', 'outline') ?>
```

Тоны: `neutral`, `primary`, `success`, `warning`, `danger`, `info`, `outline`.

#### `MDJUI::status($state, $label = '', $props = [])`

```php
<?= MDJUI::status('online', 'Онлайн') ?>
<?= MDJUI::status('error', 'Ошибка связи') ?>
```

Состояния: `online`, `offline`, `error`, `warning`, `idle`. Статус — точка + текст, не только цвет.

#### `MDJUI::avatar($name, $props = [])` и `avatarGroup($people, $props)`

```php
<?= MDJUI::avatar('Анна Смирнова', ['tone' => 'primary', 'size' => 'md']) ?>
<?= MDJUI::avatarGroup($people, ['max' => 3, 'size' => 'sm']) ?>
```

`people` — массив `['name' => ..., 'src' => ..., 'tone' => ...]`. Группа показывает `+N` после `max`.

#### `MDJUI::logo($props = [])`

```php
<?= MDJUI::logo() ?>
<?= MDJUI::logo(['width' => 260, 'alt' => 'MajorDoMo — на главную']) ?>
```

| Параметр | Назначение |
| --- | --- |
| `width` | ширина в px; без него берётся `--md-logo-width` (по умолчанию 220 px) |
| `alt` | альтернативный текст, по умолчанию `MajorDoMo` |
| `root` | префикс URL (`ROOTHTML`), нужен на страницах без shell |
| `class`, `attrs` | сквозные атрибуты |

Компонент выводит оба варианта логотипа сразу и переключает их по `data-bs-theme`, поэтому тема меняется без мигания и перезагрузки. Файлы `logo-*-clean-*.png` — тот же логотип без мелкой подписи «…твой лучший умный дом»: в интерфейсе она сжималась до нечитаемой полосы. Полная версия с подписью осталась в `logo-*.png` для крупных носителей.

Задавать ширину лучше токеном на странице (`.md-auth__brand .md-logo { --md-logo-width: 248px; }`): инлайновый `width` перебивает CSS и мешает адаптивным правилам. Не копируйте разметку с двумя `<img>` в шаблоны — оба варианта видны, если одно из правил темы потеряется.

### 4.3. Карточки и контейнеры

#### `MDJUI::card($props = [], $slot = '')`

```php
<?= MDJUI::card([
    'eyebrow' => 'Устройство',
    'title' => 'Кухня · Основной свет',
    'subtitle' => 'Обновлено 12 секунд назад',
    'icon' => 'bulb',
    'actions' => MDJUI::button('Открыть', ['variant' => 'secondary', 'size' => 'sm']),
    'footer' => MDJUI::lastSeen(['label' => 'Обновлено', 'value' => '12 секунд назад']),
], '<p>Яркость 72% · Zigbee</p>') ?>
```

| Параметр | Назначение |
| --- | --- |
| `title`, `subtitle`, `eyebrow`, `icon` | заголовок и контекст |
| `actions` | HTML действий справа |
| `footer` | HTML футера |
| `flush` | убрать padding тела (таблицы) |
| `compact` | компактная карточка |
| `interactive` | hover-состояние |
| `body_class`, `class`, `id`, `attrs` | настройка разметки |
| `heading_level` | уровень заголовка, по умолчанию `3` |

Не вкладывайте карточки без необходимости. В сетке карточки одной строки должны иметь одинаковую высоту.

#### `MDJUI::kpi($props = [])`

```php
<?= MDJUI::kpi([
    'label' => 'Устройства онлайн',
    'value' => '17',
    'unit' => 'из 19',
    'icon' => 'broadcast',
    'tone' => 'success',
    'delta' => '+2',
    'delta_tone' => 'success',
    'meta' => 'За последние 24 часа',
]) ?>
```

KPI отвечает на один вопрос. Не смешивайте в одном KPI несколько метрик.

#### `MDJUI::sectionHeading($props = [])`

```php
<?= MDJUI::sectionHeading([
    'eyebrow' => 'Устройства',
    'title' => 'Все устройства',
    'description' => '19 устройств · 17 онлайн',
    'actions' => MDJUI::button('Добавить', ['icon' => 'plus']),
]) ?>
```

#### `MDJUI::emptyState($props = [])`

```php
<?= MDJUI::emptyState([
    'icon' => 'inbox',
    'title' => 'Пока нет данных',
    'text' => 'Устройства появятся здесь после подключения.',
    'action' => MDJUI::button('Добавить устройство', ['size' => 'sm']),
]) ?>
```

#### 404 / error page

Страница 404 собирается из того же `emptyState` и обёртки `.md-error-page`:

- `lib/error_page.php` — общий рендер (HTML и JSON, светлая/тёмная тема);
- `public/404.php` — точка входа для nginx `error_page 404`;
- внутри shell неизвестный модуль показывает `emptyState` в `md-error-state`;
- используйте `MDJUI::button(..., ['href' => ...])`, не свои кнопки;
- не хардкодьте цвета: только `--md-ui-*` токены;
- страница должна работать без БД и без shell.

#### `MDJUI::skeleton($props = [])`

```php
<?= MDJUI::skeleton(['width' => '60%']) ?>
<?= MDJUI::skeleton(['variant' => 'circle', 'width' => '32px', 'height' => '32px']) ?>
<?= MDJUI::skeleton(['variant' => 'block', 'height' => '72px']) ?>
```

#### `MDJUI::progress($value, $props = [])`

```php
<?= MDJUI::progress(72, ['label' => 'Яркость', 'show_value' => true, 'tone' => 'success']) ?>
```

Параметры: `value`, `max`, `tone`, `label`, `show_value`, `size` (`sm`/`md`).

#### `MDJUI::meter($props = [])`

Компактная строка ресурса: подпись, значение, прогресс и пояснение. Используется на дашборде для CPU, памяти и диска.

```php
<?= MDJUI::meter([
    'label' => 'CPU',
    'value' => '18%',
    'percent' => 18,
    'icon' => 'cpu',
    'hint' => '20 ядер · load 1,2',
    'tone' => 'primary',
]) ?>
```

Тоны: `primary`, `success`, `warning`, `danger`, `info`, `neutral`. Если `value` не передан, показывается процент. `tone` лучше выбирать по порогам: до 75% — `primary`, 75–90% — `warning`, выше 90% — `danger`.

#### `MDJUI::chartCard($props = [], $slot = '')`

Единый каркас для графика: заголовок, описание, действия, полотно и футер. Полотно помечается `data-md-chart` и заполняется утверждённой библиотекой (ApexCharts) из страничного JS.

```php
<?= MDJUI::chartCard([
    'title' => 'Активность за 24 часа',
    'subtitle' => 'Свойства и скрипты',
    'icon' => 'chart-area-line',
    'chart_key' => 'activity',
    'height' => '270px',
    'actions' => MDJUI::badge('24 часа', 'neutral'),
], MDJUI::sparkline([
    'series' => [4, 7, 3, 9, 6, 12, 8],
    'label' => 'Демо-данные',
    'value' => 'пик 12',
])) ?>
```

| Параметр | Назначение |
| --- | --- |
| `title`, `subtitle`, `eyebrow`, `icon` | заголовок и контекст |
| `actions`, `footer` | действия и метаданные |
| `height` | минимальная высота полотна, по умолчанию `260px` |
| `chart_id`, `chart_key` | id и `data-md-chart` для JS |
| `chart_data` | JSON в `data-md-chart-data` для статической инициализации |
| `canvas_attrs`, `class`, `attrs` | сквозные атрибуты |

Не рисуйте canvas/SVG вручную. Библиотека графиков собирается в отдельный lazy-chunk `public/assets/admin/charts.js` и подключается только там, где есть график.

### 4.4. Формы

#### `MDJUI::field($props, $slot)`

Field Group: label, description, hint, error, required/optional.

```php
<?= MDJUI::field([
    'label' => 'Порог температуры',
    'for' => 'threshold',
    'description' => 'Сценарий сработает, когда значение превысит порог.',
    'hint' => 'Диапазон: 16–28 °C.',
], MDJUI::input(['name' => 'threshold', 'value' => '24', 'suffix' => '°C', 'wrap' => false])) ?>
```

#### `MDJUI::input($props = [])`

```php
<?= MDJUI::input([
    'type' => 'text',
    'name' => 'title',
    'label' => 'Название',
    'value' => '',
    'placeholder' => 'Кухня · Свет',
    'hint' => 'Отображается в списке устройств.',
    'attrs' => ['wire:model' => 'title'],
]) ?>
```

| Параметр | Назначение |
| --- | --- |
| `type` | `text`, `number`, `password`, `search`, `email`, `date`, `time` |
| `name`, `id`, `value`, `placeholder` | стандартные атрибуты |
| `label`, `description`, `hint`, `error` | Field Group |
| `required`, `optional`, `disabled`, `readonly` | состояния |
| `size` | `sm`, `md`, `lg` |
| `icon` | иконка слева |
| `prefix`, `suffix` | префикс/суффикс |
| `password_toggle` | кнопка показа пароля |
| `min`, `max`, `step`, `inputmode`, `autocomplete` | нативные атрибуты |
| `wrap` | принудительно обернуть/не оборачивать в field |
| `attrs`, `class` | сквозные атрибуты |

#### `MDJUI::textarea($props = [])`

```php
<?= MDJUI::textarea([
    'name' => 'description',
    'label' => 'Описание',
    'rows' => 4,
    'hint' => 'До 240 символов.',
]) ?>
```

#### `MDJUI::select($props = [])`

```php
<?= MDJUI::select([
    'name' => 'class_id',
    'label' => 'Класс',
    'options' => ['light' => 'Свет', 'climate' => 'Климат'],
    'selected' => 'light',
]) ?>

<?= MDJUI::select([
    'name' => 'room_id',
    'label' => 'Помещение',
    'options' => $roomOptions,
    'searchable' => true,
    'placeholder' => 'Выберите помещение',
]) ?>

<?= MDJUI::select([
    'name' => 'rooms[]',
    'label' => 'Помещения',
    'options' => $roomOptions,
    'multiple' => true,
    'searchable' => true,
    'selected' => ['kitchen', 'living'],
]) ?>
```

`searchable` добавляет `data-md-searchable`; Tom Select инициализируется общим модулем. Для обычных селектов не создавайте собственный combobox.

#### Связи объектов (`linkedobject`)

Специализированный редактор для полей `object / property / method` из старых модулей. Это не замена `MDJUI::select`: значение может быть свободным текстом (вызов метода с параметрами, нестандартное имя объекта), а исходный `input` уже существует в legacy-шаблоне и должен остаться полем формы.

```html
<!-- Native input обязателен: компонент найдёт его по id/name и сохранит -->
<input type="text" name="linked_object" id="linked_object" value="[#LINKED_OBJECT#]">

[#module name="linkedobject"
    object_field="linked_object"
    property_field="linked_property"
    method_field="linked_method"
    width="100%"#]
```

Что делает компонент:

- переносит native `input` внутрь своего контрола, сохраняя `name`, `id`, inline-обработчики и jQuery-подписки;
- при выборе значения записывает его в native input и отправляет нативные `input` / `change`, поэтому старые обработчики продолжают работать;
- перехватывает `$('#field').val('...')` и синхронизирует UI без обязательного `.trigger('change')`;
- загружает свойства и методы одним атомарным запросом `op=context`, кэширует результат по объекту и дедуплицирует запросы между всеми экземплярами;
- не очищает сохранённые значения при ошибке сети или неизвестном объекте — показывает предупреждение и оставляет возможность сохранить;
- рисует dropdown в portal (`<body>`) с пересчётом позиции, поэтому старые таблицы, `overflow: hidden` и модалки его не обрезают;
- поддерживает свободный ввод, клавиатуру (`↑ ↓ Enter Esc Tab Home End PageUp/PageDown`), `aria-*`, светлую/тёмную тему и 390 px;
- инициализируется повторно после `partLoad`, AJAX-строк и клонирования; дубли `uniq` в клонах строк разводятся по DOM-контейнеру;
- в таблицах автоматически включает compact-режим и не раздвигает ячейку.

JS API (`resources/js/modules/admin_panel/120_linkedobject.js`):

```js
MDLinkedObjectUI.init(root);                 // идемпотентно
MDLinkedObjectUI.open('linked_object');
MDLinkedObjectUI.setValue('linked_object', 'Kitchen.Light');
MDLinkedObjectUI.getValue('linked_object');
MDLinkedObjectUI.refresh('linked_object');
MDLinkedObjectUI.destroy(root);
```

Контракт: `[data-md-linkedobject]`, `data-md-linkedobject-role`, `data-md-linkedobject-field-id`, `data-md-linkedobject-base-url`. Разметка и стили — `templates/linkedobject/linkedobject.html` + `resources/css/ui-system.css`. Живой пример — `?action=ui_kit#linkedobject`.

#### `MDJUI::checkbox($props = [])`, `radio($props = [])`, `switchControl($props = [])`

```php
<?= MDJUI::checkbox([
    'name' => 'notify',
    'label' => 'Уведомлять о событиях',
    'description' => 'Push и e-mail при изменении состояния.',
    'checked' => true,
]) ?>

<?= MDJUI::radio(['name' => 'mode', 'value' => 'auto', 'label' => 'Авто', 'checked' => true]) ?>
<?= MDJUI::radio(['name' => 'mode', 'value' => 'manual', 'label' => 'Вручную']) ?>

<?= MDJUI::switchControl([
    'name' => 'enabled',
    'label' => 'Сценарий включён',
    'description' => 'Отключённый сценарий не запускается.',
    'checked' => true,
]) ?>
```

Все три поддерживают `attrs` (`x-model`, `wire:model`) и `aria_label` для switch без текста.

> Кастомный `input[type=checkbox]`/`switch` должен иметь `data-md-raw-checkbox`. Иначе legacy-модуль `initCheckboxToggles` добавит глобальный класс `md-admin-toggle-checkbox` и превратит контрол в стандартный переключатель админки. `MDJUI::checkbox()` и `MDJUI::switchControl()` ставят этот атрибут автоматически.

#### `MDJUI::choiceGroup($props = [])`

Радио-группа в виде карточек с рамкой: заголовок, пояснение и опциональная иконка. Выбранная карточка подсвечивается primary-рамкой и мягким фоном; вся карточка кликабельна.

```php
<?= MDJUI::choiceGroup([
    'name' => 'run_type',
    'aria_label' => 'Реализация метода',
    'selected' => 'code',
    'options' => [
        ['value' => 'script', 'label' => 'Сценарий', 'description' => 'Выполнить готовый сценарий', 'icon' => 'file-code'],
        ['value' => 'code', 'label' => 'Код', 'description' => 'PHP или Python прямо в методе', 'icon' => 'code'],
    ],
]) ?>
```

| Параметр | Назначение |
| --- | --- |
| `name` | имя радио-группы |
| `options` | список `value / label / description / icon / disabled` или карта `value => label` |
| `selected` | выбранное значение (строка или массив) |
| `aria_label` | подпись группы для скринридеров |
| `required`, `class`, `attrs` | состояния и сквозные атрибуты |

Используйте для 2–4 взаимоисключающих вариантов, которые нужно объяснить. Для длинных списков — `MDJUI::select()`, для одиночного согласия — `MDJUI::radio()`/`checkbox()`.

#### `MDJUI::range($props = [])`

```php
<?= MDJUI::range([
    'name' => 'brightness',
    'label' => 'Яркость',
    'value' => 72,
    'min' => 1,
    'max' => 100,
    'unit' => '%',
]) ?>
```

Значение выводится в `<output>` и обновляется общим контроллером. Не дублируйте `oninput`.

#### Date / time / color / file

- Date и time — нативные `input[type=date|time]` через `MDJUI::input`.
- Color — `input[type=color]` + hex-поле, синхронизация через Alpine `mdColorPicker`.
- File upload — зона `.md-upload` + Alpine `mdFileUpload` (см. UI Kit); серверная проверка обязательна.

#### Folder picker

Для путей (бэкапы, логи, сертификаты) используйте read-only диалог
`MDJUI::folderPicker()` и глобальный `MDFolderPicker`:

```php
<?= MDJUI::folderPicker([
    'id' => 'mdSettingsFolderPicker',
    'endpoint' => ROOTHTML . 'ajax/settings.html?op=folder',
]) ?>

<button type="button" class="md-btn md-btn--secondary md-btn--sm"
        data-md-folder-picker="value_BACKUP_PATH"
        data-md-folder-picker-modal="mdSettingsFolderPicker">
    Выбрать
</button>
```

Endpoint возвращает `{ok, path, absolute, parent, root, dirs}` и обязан
ограничивать навигацию корнем проекта. Компонент не загружает и не меняет
файлы: это picker каталогов, а не файловый менеджер. Выбор записывается в
native input и вызывает `input`/`change`, поэтому зависимости настроек и
legacy-обработчики продолжают работать. JS API: `MDFolderPicker.open(inputId)`;
стили — `.md-folder-picker__*` в `ui-system.css`. Живой пример —
`?action=ui_kit#forms`.

### 4.5. Уведомления и диалоги

#### `MDJUI::alert($type, $title, $text = '', $props = [])`

```php
<?= MDJUI::alert('success', 'Сценарий сохранён', 'Изменения применены.') ?>
<?= MDJUI::alert('danger', 'Нет связи', 'Устройство недоступно 18 минут.', [
    'actions' => MDJUI::button('Проверить', ['variant' => 'secondary', 'size' => 'sm']),
]) ?>
```

Типы: `info`, `success`, `warning`, `danger`. `dismissible => true` для второстепенных сообщений.

#### Disclosure

`MDJUI::disclosure($props, $slot)` — native details/summary с клавиатурной
навигацией. Параметры: `title`, `description`, `badge`, `badge_tone`, `open`,
`id`, `class`, `attrs`. CSS — `md-disclosure__*`, цвета/отступы на токенах.
Используется для описания операций API; подходит для инструкций и подробностей.
Не вставляйте интерактивные элементы в summary раскрывающегося блока.
Демо — UI Kit → «Лента и код».

#### Toast

```php
<button type="button" class="md-btn md-btn--primary"
        onclick="MDJUIToast.show({type:'success', title:'Сохранено', text:'Сценарий обновлён'})">
  Сохранить
</button>
```

В Alpine-разметке: `$store.toasts.push({type, title, text, duration})`. Общий host находится в `templates/panel.html`, справа вверху под шапкой; отдельные host в модулях не добавлять. Store ограничивает очередь тремя сообщениями. Старые `MDJAdminUI.showToast()` и `$.toast()` делегируют в этот же компонент. `duration=0` оставляет сообщение до закрытия.

#### Modal и confirmation

Используйте Bootstrap 5 modal с классами `modal fade md-modal`. Обязательны `aria-labelledby`, заголовок и кнопка закрытия. Destructive-кнопка — `md-btn--danger` справа. Не используйте `alert()` и `confirm()` браузера.

Для готовой оболочки используйте `MDJUI::modal($props, $slot)`: уникальный `id`, `title`, `footer` (HTML из компонентов), `size` (`sm/lg/xl`), `attrs`. Открытие — Bootstrap `data-bs-toggle="modal"`/`data-bs-target` или Modal API. Демо modal и disclosure — UI Kit → «Лента и код».

#### Drawer

Единый паттерн для контекстных панелей и глобального shell (`#mdAdminDrawerHost`): inset-панель со скруглением, компактная шапка с eyebrow, заголовком и подзаголовком, прокручиваемое тело и футер с действиями. Для консоли и логов используется тот же shell — не создавайте отдельную шапку.

Используйте компонент:

```php
<?= MDJUI::drawer([
    'eyebrow' => 'Устройство',
    'title' => 'Кухня · Основной свет',
    'subtitle' => 'Zigbee · 92% сигнала',
    'width' => '440px',
    'footer' => MDJUI::button('Сохранить', ['variant' => 'primary']),
], $drawerBody) ?>
```

Компонент поддерживает `attrs`, `backdrop_attrs`, `close_attrs` для Alpine/JS, `open` для серверного открытия и `body_id`/`footer_id` для динамического содержимого. На мобильном drawer занимает весь экран. Футер скрывается, если действий нет.

#### History drawer

`MDJUI::historyDrawer()` — готовый шаблон истории значения: период, текущее значение, статистика, график ApexCharts, журнал изменений и пагинация. Он монтируется в общий drawer host, поэтому шапка, футер, Esc и мобильное поведение совпадают с консолью и X-Ray.

```php
<button type="button" class="md-btn md-btn--secondary"
        data-md-history-open
        data-md-history-target="propertyHistory"
        data-history-object-id="12"
        data-history-property-id="34"
        data-history-title="Kitchen.Temperature"
        data-history-subtitle="Температура воздуха на кухне">
  История
</button>

<?= MDJUI::historyDrawer([
    'id' => 'propertyHistory',
    'eyebrow' => 'История свойства',
    'default_range' => '24h',
]) ?>
```

Источник данных задаётся триггером:

- `data-history-url` — явный endpoint; относительный URL автоматически становится корневым от `ROOTHTML`;
- `data-history-object-id` + `data-history-property-id` — встроенный endpoint истории свойства объекта;
- `data-md-history-demo` на самом компоненте — JSON для UI Kit/статичных демо (без сети).

Ответ endpoint: `{status: "ok", meta, range, current, stats, chart, history, history_pagination}`. График рисуется только для `meta.is_numeric` и `chart.has_data`; иначе компонент показывает empty-state, а журнал остаётся доступным. Кнопки «Обновить» и «CSV» находятся в футере drawer, период — в `md-tabs`. Длинные значения переносятся и прокручиваются внутри карточки текущего значения.

#### Trace drawer

`MDJUI::traceDrawer()` — always-on журнал изменений свойства: запуск, метод,
сценарий, параметры на входе/выходе, старое/новое значение и готовые ссылки на
метод, свойство, сценарий или модуль. Это не замена `historyDrawer`: история
отвечает за график значения, trace — за причинную цепочку.

```php
<button type="button" class="md-btn md-btn--secondary"
        data-md-trace-open
        data-md-trace-target="propertyTrace"
        data-trace-object-id="12"
        data-trace-property-id="34"
        data-trace-title="Kitchen.Temperature">
  Цепочка
</button>

<?= MDJUI::traceDrawer([
    'id' => 'propertyTrace',
    'eyebrow' => 'Цепочка изменений',
    'default_range' => '24h',
]) ?>
```

Компонент монтируется в общий drawer host; данные грузит
`resources/js/modules/admin_panel/41_trace.js`. Внутри — нативные `<details>`,
поэтому длинная цепочка не требует отдельного JS-аккордеона. Для статичного
демо в UI Kit используйте `demo_data`. Тёмная тема и 390 px наследуются от
`md-drawer`.

#### Popover и tooltip

```html
<button class="md-btn md-btn--secondary"
        data-bs-toggle="popover"
        data-bs-title="Что такое уставка?"
        data-bs-content="Целевая температура...">Подсказка</button>

<button class="md-icon-btn" data-bs-toggle="tooltip" data-bs-title="Обновить">...</button>
```

Tooltip — короткая подсказка, не документация. Popover — пояснение с действием.

### 4.6. Навигация

- Tabs: **предпочтительный и единый паттерн** — сегментированные `md-tabs` (Bootstrap `nav-tabs` + `md-tabs`). Используется для `view_mode`, разделов страницы и вкладок карточек во всех модулях. `md-tabs--compact` и `md-tabs--line` — только служебные варианты; для навигации по разделам их не применять.
- Breadcrumbs: `ol.breadcrumb.md-breadcrumb`; последний пункт — `active`.
- Pagination: `ul.pagination.md-pagination`; активная страница — `active`, prev/next обязательны.
- Dropdown: `.dropdown-menu.md-menu`, пункты `.dropdown-item`.
- Context menu: Alpine `mdContextMenu`, `.md-context-menu`, закрывается по Esc/клику вне.
- Sidebar: `.md-sidenav`, группы `.md-sidenav__group`, активный пункт `.is-active`, счётчики `.md-badge`.
- Подвал shell-меню: `.md-admin-sidebar__profile` — аккаунт `.md-admin-sidebar__account` (аватар с инициалами, имя, роль) и отдельная кнопка выхода `.md-admin-sidebar__logout`. Подвал не прокручивается вместе с меню и остаётся видимым на телефоне; выход из шапки убран, но возвращается при `HIDE_LEFT_PANEL=1`, где меню нет.
- Search bar: `.md-searchbar`; `Ctrl K` зарезервирован за глобальным поиском shell.
- Command palette: Bootstrap modal `.md-command` + Alpine `mdCommandPalette`; данные — JSON в `data-md-palette`.

### 4.7. Таблицы и данные

Data Table — серверный рендер + прогрессивное улучшение (`resources/js/modules/admin_panel/118_data_table.js`).

```html
<div class="md-table-wrap" data-md-table data-md-table-page-size="6" data-md-table-unit="устройств">
  <div class="md-table__toolbar">
    <div class="md-table__search">
      <i class="ti ti-search" aria-hidden="true"></i>
      <input type="search" data-md-table-search placeholder="Поиск">
    </div>
    <select class="md-table__filter" data-md-table-filter="state">...</select>
    <div class="md-table__meta" data-md-table-meta>8 устройств</div>
  </div>

  <div class="md-table__scroll">
    <table class="md-table">
      <thead>
        <tr>
          <th class="md-table__check"><input type="checkbox" data-md-table-check-all></th>
          <th><button class="md-table__sort" data-md-table-sort="name">Устройство <i class="ti ti-arrows-up-down"></i></button></th>
        </tr>
      </thead>
      <tbody data-md-table-body>
        <tr data-md-row data-row-id="101" data-search="кухня свет онлайн" data-state="online" data-sort-name="Кухня · Свет">
          <td class="md-table__check"><input type="checkbox" data-md-row-check></td>
          <td>...</td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="md-table__empty" data-md-table-empty><!-- empty state --></div>
  <div class="md-table__footer">
    <span data-md-table-count>Показано 1–6 из 8</span>
    <ul class="pagination md-pagination" data-md-table-pagination></ul>
  </div>
</div>
```

Контракт атрибутов:

| Атрибут | Назначение |
| --- | --- |
| `data-md-table` | инициализация контроллера |
| `data-md-table-page-size` | строк на страницу |
| `data-md-table-unit` | единица в meta |
| `data-md-table-unit-one` / `-few` / `-many` | склоняемые формы счётчика (`1 метод`, `2 метода`, `5 методов`); при наличии заменяют `data-md-table-unit` |
| `data-md-table-search` | поле поиска |
| `data-md-table-filter="state"` | фильтр по `data-state` строки |
| `data-md-table-sort="name"` | сортировка по `data-sort-name` |
| `data-md-row`, `data-row-id` | строка и её id |
| `data-search` | текст для поиска |
| `data-md-table-check-all`, `data-md-row-check` | выбор строк |
| `data-md-table-meta`, `data-md-table-count`, `data-md-table-empty`, `data-md-table-pagination` | служебные зоны |

Строки рендерит сервер. JS не загружает данные и не меняет API модуля: он только сортирует, фильтрует, разбивает на страницы и запоминает выбор. Если контейнера `data-md-table-pagination` нет, пагинация отключается и показываются все отфильтрованные строки.

Для мобильных обязательна обёртка `.md-table__scroll` (горизонтальный скролл). Не превращайте таблицу в набор карточек без необходимости.

#### Tree table

Иерархические данные (классы, категории, дерево объектов) используют тот же `.md-table` плюс тонкий слой `.md-tree-table__*`:

```html
<table class="md-table md-tree-table">
  <tbody>
    <tr data-md-class-row data-class-id="10" data-parent-id="0" data-objects="1">
      <td>
        <div class="md-tree-table__cell" style="--md-tree-level: 0">
          <button type="button" class="md-tree-table__toggle" data-md-class-toggle aria-expanded="false">
            <i class="ti ti-chevron-right" aria-hidden="true"></i>
          </button>
          <span class="md-tree-table__icon"><i class="ti ti-hierarchy-2" aria-hidden="true"></i></span>
          <span class="md-tree-table__text">
            <a class="md-tree-table__title" href="?view_mode=edit_classes&amp;id=10">Computer</a>
            <span class="md-tree-table__desc">Описание класса</span>
          </span>
        </div>
      </td>
    </tr>
    <tr class="md-tree-table__detail" hidden>
      <td colspan="5"><div class="md-tree-table__panel" style="--md-tree-level: 0">…</div></td>
    </tr>
  </tbody>
</table>
```

- Уровень вложенности — `--md-tree-level` (0, 1, 2…), а не вложенные таблицы.
- Шеврон поворачивается через `[aria-expanded="true"]`; `hidden` на detail-строке — единственный источник видимости.
- Внутри detail-панели объекты показываются как `.md-tree-objects` / `.md-tree-object`.
- Серверный рендер обязателен; JS только переключает `hidden`, `aria-expanded` и фильтрует строки.

### 4.8. Компоненты умного дома

Все они используют те же токены, карточки и статусы, что и остальная админка. Отдельного «IoT-стиля» нет.

#### `MDJUI::deviceCard($props)`

```php
<?= MDJUI::deviceCard([
    'name' => 'Кухня · Основной свет',
    'room' => 'Кухня',
    'type' => 'Свет',
    'icon' => 'bulb',
    'state' => 'online',
    'state_label' => 'Онлайн',
    'value' => '72%',
    'value_label' => 'Яркость',
    'tone' => 'warning',
    'updated' => '12 сек назад',
    'connection' => 'Zigbee',
    'signal' => 92,
    'actions' => MDJUI::iconButton('power', ['label' => 'Переключить', 'size' => 'sm']),
]) ?>
```

#### `MDJUI::sensor($props)`

```php
<?= MDJUI::sensor([
    'label' => 'Температура',
    'value' => '22,5',
    'unit' => '°C',
    'icon' => 'temperature',
    'tone' => 'primary',
    'trend' => '−0,4°',
    'trend_dir' => 'down',
    'hint' => 'Гостиная · 4 секунды назад',
]) ?>
```

#### `MDJUI::thermostat($props)` и `lightControl($props)`

```php
<?= MDJUI::thermostat([
    'name' => 'Гостиная · Термостат',
    'room' => 'Гостиная',
    'current' => 22.5,
    'target' => 23,
    'min' => 16,
    'max' => 28,
    'step' => 0.5,
]) ?>

<?= MDJUI::lightControl([
    'name' => 'Основной свет',
    'room' => 'Кухня',
    'on' => true,
    'brightness' => 72,
    'temp' => 2700,
]) ?>
```

Оба интерактивны через Alpine. Локальное состояние не сохраняется на сервер автоматически: для сохранения используйте `fetch`/Livewire-обновление. Не дублируйте их разметку на страницах.

#### `MDJUI::sceneCard($props)`, `automationRow($props)`

```php
<?= MDJUI::sceneCard([
    'name' => 'Доброе утро',
    'description' => 'Свет 40%, шторы открыты, кофеварка',
    'icon' => 'sunrise',
    'tone' => 'warning',
    'schedule' => 'Пн–Пт · 07:00',
    'state' => 'online',
    'state_label' => 'Активна',
    'runs' => '18 запусков',
]) ?>

<?= MDJUI::automationRow([
    'name' => 'Свет по движению',
    'description' => 'Коридор · датчик движения',
    'icon' => 'walk',
    'state' => 'online',
    'state_label' => 'Работает',
    'last' => '2 мин назад',
    'runs' => 128,
]) ?>
```

#### `MDJUI::energyWidget($props)`, `sparkline($props)`

```php
<?= MDJUI::energyWidget([
    'today' => '8,4 кВт·ч',
    'yesterday' => '9,1 кВт·ч',
    'delta' => '−7,7%',
    'cost' => '412 ₽',
    'peak' => '19:00–21:00',
    'usage' => $usage,
    'series' => $series,
]) ?>

<?= MDJUI::sparkline([
    'series' => $series,
    'label' => 'За 24 часа',
    'value' => 'пик 19:00–21:00',
    'tone' => 'primary',
]) ?>
```

Sparkline — компактные CSS-бары. Для графиков с осями и tooltip используйте утверждённую библиотеку графиков (ApexCharts или аналог), а не самописный canvas/SVG.

#### `MDJUI::connectionStatus($props)`, `lastSeen($props)`, `roomCard($props)`

```php
<?= MDJUI::connectionStatus([
    'label' => 'MQTT',
    'state' => 'online',
    'state_label' => 'Подключено',
    'detail' => '12 мс',
    'icon' => 'broadcast',
]) ?>

<?= MDJUI::lastSeen([
    'label' => 'Обновлено',
    'value' => '12 секунд назад',
    'datetime' => '2026-10-20 22:01:48',
]) ?>

<?= MDJUI::roomCard([
    'name' => 'Гостиная',
    'icon' => 'lamp',
    'devices' => 5,
    'online' => 5,
    'temperature' => '22,5 °C',
    'humidity' => '44%',
    'active' => 'Свет 72% · Термостат 23 °C',
    'state' => 'online',
]) ?>
```

Connection Status — статус транспорта, не устройства. Last Seen всегда содержит относительное время и точную дату в `title`.

### 4.9. Редактор кода

Редактор — CodeMirror 6 в lazy-chunk `public/assets/admin/codeeditor.js` + `codeeditor.css`. Он не входит в основной бандл и грузится только на страницах с `[data-code-editor]` или при открытии консоли.

Базовая разметка:

```html
<div class="md-code-editor" data-code-editor
     data-code-editor-key="method_12"
     data-code-editor-mode="php"
     data-code-editor-theme="auto"
     data-code-editor-autosave="0"
     data-code-editor-min-lines="20"
     data-code-editor-max-lines="20">

  <div class="md-code-editor__toolbar" data-code-editor-toolbar>
    <label class="md-code-editor__mode-switch" data-mode="php">
      <input type="checkbox" data-code-editor-mode-toggle aria-label="Режим редактора">
      <span class="md-code-editor__mode-switch-option md-code-editor__mode-switch-option--php">PHP</span>
      <span class="md-code-editor__mode-switch-option md-code-editor__mode-switch-option--python">Python</span>
      <input type="hidden" name="code_editor_mode" value="php" data-code-editor-mode-select>
    </label>

    <span class="md-code-editor__toolbar-divider" aria-hidden="true"></span>
    <div class="md-code-editor__toolbar-group">
      <button type="button" class="md-code-editor__action" data-code-editor-action="save"
              title="Сохранить версию (Ctrl+S)" aria-label="Сохранить версию">
        <i class="ti ti-device-floppy" aria-hidden="true"></i>
        <span class="md-code-editor__action-label">Сохранить</span>
      </button>
      <button type="button" class="md-code-editor__action" data-code-editor-action="restore"
              title="История версий (Ctrl+R)" aria-label="История версий">
        <i class="ti ti-history" aria-hidden="true"></i>
        <span class="md-code-editor__action-label">Восстановить</span>
      </button>
    </div>

    <div class="md-code-editor__toolbar-spacer"></div>
    <span class="md-code-editor__status" data-code-editor-status aria-live="polite"></span>
    <button type="button" class="md-code-editor__icon-btn" data-code-editor-action="search"
            title="Найти (Ctrl+F)" aria-label="Найти">
      <i class="ti ti-search" aria-hidden="true"></i>
    </button>
    <button type="button" class="md-code-editor__icon-btn" data-code-editor-action="fullscreen"
            title="Полный экран (F11)" aria-label="Полный экран">
      <i class="ti ti-maximize" aria-hidden="true"></i>
    </button>
  </div>

  <textarea name="code">...</textarea>
</div>
```

Правила:

- Кнопки toolbar — только `md-code-editor__action` / `md-code-editor__icon-btn`, не Bootstrap `btn`.
- У icon-only кнопок обязательны `title` и `aria-label`: ниже 992 px подписи скрываются.
- Языковая плашка для одного языка — `md-code-editor__lang-badge` вместо переключателя.
- Footer (`Стр/стлб`, язык, «Изменено», UTF-8) генерирует JS; не дублируйте его.
- Высота авто-растёт от 5 строк до `data-code-editor-max-lines`; `0` в max-lines — рост по контенту.
- Состояния: `is-loading` (skeleton), `is-ready`, `is-dirty`, `is-autosave-countdown`, `is-fullscreen`.
- Тема: `auto` следует `data-bs-theme`, `light`/`dark` принудительные.
- Для редактора в закрытом drawer/modal: `data-code-editor-lazy="1"` и `MDCodeEditor.init(container)` при открытии.
- Не добавляйте `<script>`/`<link>` CodeMirror в шаблон: загрузчик уже в бандле.
- Отступы — **табы**, ширина отображения 4. `Tab`/`Shift+Tab` меняют отступ, `Ctrl+Alt+L` и `Ctrl+Alt+I` переиндентируют выделение или весь документ (как Reformat Code в PhpStorm).
- Иконка клавиатуры в toolbar открывает popover с горячими клавишами; список задаётся в `SHORTCUT_GROUPS` в `resources/js/codeeditor/editor.js`. Не дублируйте его в шаблонах.
- Дополнительно: `Ctrl+Y` — удалить строку, `Shift+Alt+↑/↓` — переместить строку, `Ctrl+G` — перейти к строке, `Ctrl+/` — комментарий, `Ctrl+D` — дублировать строку.
- Хоткеи работают и на русской раскладке: обработчик `handleLayoutShortcut` использует `event.code` (`KeyL`, `KeyY`, `Slash` и т.д.), а не `event.key`.

### 4.10. Лента, подсказки и код

Три готовых блока для страниц-инструкций, карточек объектов и настроек. Демо — `?action=ui_kit#blocks`.

#### `MDJUI::activityFeed($props)`

Лента действий с группировкой по датам, тоном события, автором, метаданными и ссылкой на источник.

```php
<?= MDJUI::activityFeed([
    'eyebrow' => 'История',
    'title' => 'Лента активности',
    'subtitle' => 'Последние действия в системе',
    'limit' => 4,
    'more_href' => '?action=objects',
    'more_label' => 'Вся история',
    'items' => [
        [
            'date' => 'Сегодня',
            'title' => 'Кухня · Основной свет',
            'text' => 'Яркость изменена с 0% на 72%.',
            'time' => '2 мин назад',
            'datetime' => '2026-10-22 14:02:00',
            'icon' => 'bulb',
            'tone' => 'primary',
            'actor' => 'Сценарий «Доброе утро»',
            'meta' => ['Zigbee', ['text' => 'Кухня', 'icon' => 'map-pin']],
            'badge' => 'Выполнено',
            'badge_tone' => 'success',
            'href' => '?action=objects',
            'unread' => true,
            'actions' => MDJUI::iconButton('trash', ['label' => 'Удалить', 'variant' => 'danger', 'size' => 'sm']),
        ],
    ],
]) ?>
```

| Параметр | Назначение |
| --- | --- |
| `items` | события; `date` задаёт заголовок группы, `title`/`text`/`time`/`datetime` — содержимое |
| `groups` | альтернатива `items`: `[['label' => 'Сегодня', 'items' => [...]]]` |
| `actions` (в событии) | HTML действий справа в строке метаданных (например, удалить запись) |
| `title`, `subtitle`, `eyebrow`, `icon`, `actions`, `footer` | каркас карточки |
| `limit` | показать первые N событий; остаток считается в футере |
| `more_href`, `more_label` | ссылка на полную историю |
| `compact`, `bare` | плотный режим / без карточки |
| `empty_title`, `empty_text` | пустое состояние |

Тоны: `primary`, `success`, `warning`, `danger`, `info`, `neutral`. Если `icon` не задан, он выбирается по тону. Для боковой колонки используйте `compact => true`; не вкладывайте ленту в ленту. Текст события сохраняет переводы строк (`white-space: pre-line`), но экранируется сервером.

#### `MDJUI::hint($props, $slot = '')`

Контекстная подсказка: объясняет, как работает настройка, и остаётся рядом с формой. Для результата операции используйте `MDJUI::alert()`, для краткого подтверждения — toast.

```php
<?= MDJUI::hint([
    'tone' => 'tip',
    'title' => 'Как работает история свойства',
    'text' => 'История хранится столько дней, сколько указано в настройках свойства.',
    'points' => [
        'Значение записывается только при изменении.',
        'Старые точки удаляются фоновым циклом.',
    ],
    'link' => '?action=knowledge_base',
    'link_label' => 'Открыть инструкцию',
    'dismissible' => false,
]) ?>
```

| Параметр | Назначение |
| --- | --- |
| `tone` | `tip`, `info`, `success`, `warning`, `danger` |
| `title`, `text`, `eyebrow` | заголовок и пояснение; `eyebrow` по умолчанию зависит от тона |
| `icon` | иконка вместо стандартной для тона |
| `points` / `steps` | маркированный список / нумерованные шаги |
| `actions`, `link`, `link_label` | кнопки и ссылка «Подробнее» |
| `dismissible`, `compact` | закрытие и плотный режим |
| `$slot` | произвольный HTML после текста (таблицы, ссылки, код) |

Подсказка не должна дублировать сообщение об ошибке поля и не заменяет инструкцию в базе знаний. Одна подсказка на смысловой блок; если их больше двух, вынесите детали в статью.

#### `MDJUI::codeSample($props)`

Read-only пример кода с подсветкой PHP/Python, номерами строк и копированием. Подсветка — отдельный lazy-chunk `public/assets/admin/codehighlight.js` + `codehighlight.css` (highlight.js), загружается только на страницах с примерами.

```php
<?= MDJUI::codeSample([
    'eyebrow' => 'Пример',
    'title' => 'Изменить яркость из метода',
    'caption' => 'Метод объекта Kitchen.Light',
    'tabs' => [
        ['language' => 'php', 'label' => 'PHP', 'code' => $phpCode],
        ['language' => 'python', 'label' => 'Python', 'code' => $pythonCode],
    ],
]) ?>

<?= MDJUI::codeSample([
    'language' => 'php',
    'code' => '$this->setProperty("Kitchen.Light.Brightness", 72);',
    'line_numbers' => false,
    'caption' => 'Одна строка',
]) ?>
```

| Параметр | Назначение |
| --- | --- |
| `language` + `code` | один язык: `php`, `python`, `javascript`, `html` (рендерится как XML), `css`, `bash`, `json`, `yaml`, `sql` (принимается, но в текущем чанке подсветки языка нет) |
| `tabs` | несколько языков: `language`, `label`, `code`; вкладки переключаются мышью и клавиатурой |
| `title`, `eyebrow`, `caption`, `footer` | подписи блока |
| `copy` | кнопка копирования (по умолчанию `true`); копируется активная вкладка |
| `line_numbers` | номера строк (по умолчанию `true`) |
| `max_height` | максимальная высота с прокруткой, например `280px` |
| `show_language` | плашка языка для одного языка |
| `wrap` | перенос строк вместо горизонтальной прокрутки |

Правила: не используйте редактор `data-code-editor` для read-only примеров — он тяжёлый и требует CodeMirror. Для больших файлов давайте ссылку на редактор/базу знаний, а в подсказке оставляйте минимальный работающий фрагмент. Код экранируется сервером; не передавайте в `code` HTML. Копирование и вкладки работают до загрузки подсветки, поэтому пример остаётся usable при медленной сети.

Read-only код в карточках тоже выводится этим компонентом, а не безымянным `<pre>`: например, код метода класса в редакторе метода объекта (`templates/objects/objects_parent_method_code.php`). Компонент сам экранирует код, поэтому в него передаётся исходный текст, без `htmlspecialchars()`.

---

## 5. Правила применения

### Цвета

- Фон — `--md-ui-bg`; поверхности — `--md-ui-surface`/`--md-ui-surface-2`/`--md-ui-surface-3`.
- Primary — только действия и active. Не красьте в primary заголовки и большие фоны.
- Success/warning/danger/info — семантические пары `color + soft`.
- Не используйте произвольные hex, `lime`, `red`, `#000080` и т. п.

### Размеры и отступы

- Высота контролов — только 30/36/44 px.
- Радиусы — из шкалы; в одной группе элементов одинаковые.
- Отступы — из шкалы 4/8/12/16/20/24/32/40/48.
- Не подгоняйте высоту кнопки под input вручную: используйте токены.

### Типографика

- Один `h1` на страницу, далее `h2` секции и `h3` карточки.
- Не используйте uppercase для длинных фраз — только для служебных меток.
- Числа в таблицах и KPI — `font-variant-numeric: tabular-nums`, если это важно для сравнения.

### Иконки

- Только Tabler Icons. Не подключайте Font Awesome, Heroicons и не смешивайте наборы.
- Bootstrap Icons используется только для legacy-`glyphicon`; новые `bi bi-*` не добавляются.
- Icon-only кнопка обязана иметь `aria-label`.
- Декоративные иконки — `aria-hidden="true"`.
- Иконка не должна быть единственным способом передать статус: добавляйте текст или badge.

### Состояния

- default / hover / focus-visible / active / disabled / loading / error.
- Ошибка поля — `aria-invalid`, сообщение рядом, не только toast.
- Loading — локальный спиннер/скелетон, не блокировка всей страницы.
- Disabled — читаемый, но явно недоступный.

### Адаптивность

- Проверяйте 390 px (и 360 px для плотных таблиц).
- Breakpoints: 1199, 991, 767, 575 px.
- Таблицы — `.md-table__scroll`; модалки — не шире viewport; drawer — на всю ширину на mobile.
- Сетки карточек: 3–4 колонки на desktop, 2 на tablet, 1 на mobile.
- Не добавляйте собственный fixed-header внутри модуля.
- Полноэкранные панели (меню, drawer) ограничивайте через `top: 0` + `bottom: 0` или `100dvh`, а не `100vh`: на телефоне `vh` равен «большому» вьюпорту, и низ панели уезжает под адресную строку. В эмуляции этого не видно — проверяйте на устройстве.
- Нижние панели и кнопки — с `padding-bottom: calc(… + env(safe-area-inset-bottom, 0px))`, иначе их перекрывает домашний индикатор iPhone.

### Тёмная тема

- Только токены. Никаких `background: #fff` и `color: #000` в компонентах.
- Проверяйте контраст muted-текста и иконок.
- Не делайте отдельную dark-разметку.
- Для изображений/логотипов учитывайте тёмный фон.

### Доступность

- Интерактив — `button` или `a`, не `div onclick`.
- Видимый focus ring.
- `aria-label` для icon-only.
- `aria-labelledby` для модалок.
- Keyboard: Tab, Enter/Space, Esc для закрытия.
- Статус не только цветом.

---

## 6. Компоновка страниц

### Типовая страница модуля

```php
<div class="md-ui-page">
<?= MDJUI::sectionHeading([
            'eyebrow' => 'Устройства',
            'title' => 'Все устройства',
            'description' => '19 устройств · 17 онлайн · 2 требуют внимания',
            'actions' => MDJUI::button('Добавить устройство', ['icon' => 'plus']),
        ]) ?>

        <div class="md-ui-kpi-grid">
                <?= MDJUI::kpi([...]) ?>
        </div>

        <div class="md-table-wrap" data-md-table>...</div>
</div>
```

Для legacy-шаблонов (jTemplate/Smarty), где нельзя вызвать `MDJUI`, повторяйте ту же разметку: `.md-ui-page.md-ui-page--stack` → `.md-ui-hero` → `.md-ui-kpi-grid` → `.md-table-wrap`. Не создавайте альтернативную шапку или собственные KPI-карточки.

Порядок:

1. заголовок страницы с одним primary-action;
2. KPI, если они отвечают на ключевые вопросы;
3. фильтры/поиск в одной панели;
4. основной контент (таблица или карточки);
5. пустое/loading/error состояние;
6. действия — в строке или в заголовке, не в двух местах одновременно.

### Формы

- Label сверху, описание/подсказка под контролом.
- Ошибка — рядом с полем, не только toast.
- Один primary-action; destructive — слева и отделён.
- Группируйте поля по смыслу, а не по количеству.
- `required` помечайте `*`; необязательные — `необязательно`.

### Таблицы

- Поиск и фильтры — в одной панели над таблицей.
- Действия — в конце строки, не более 2–3 видимых.
- Статусы — `md-status`, не цветные строки.
- Числа — выравнивайте вправо, текст — влево.
- На mobile — горизонтальный скролл внутри `.md-table__scroll`.

### Карточки

- Заголовок + описание + действия.
- Не вкладывайте карточки без причины.
- В сетке — одинаковая высота и одинаковый набор отступов.
- KPI — не более 4 в ряд; на mobile — 1–2.

---

## 7. JavaScript и интерактивность

### Alpine

Зарегистрированные общие компоненты (`resources/js/modules/admin_panel/116_ui_components.js`):

| Компонент | Назначение |
| --- | --- |
| `mdLight` | свет: on/off, яркость, температура |
| `mdScene` | сцена: enabled, запуск, toast |
| `mdFileUpload` | drag-and-drop, список файлов |
| `mdColorPicker` | color + hex |
| `mdCommandPalette` | поиск, группы, keyboard |
| `mdContextMenu` | правый клик, позиционирование |
| `mdValidationForm` | inline-валидация |

Компонент `mdIconBrowser`, copy-кнопки и scrollspy живут в `public/assets/js/admin/ui_kit.js` и подключаются только на странице UI Kit.

Toast store: `$store.toasts.push({type, title, text, duration})`, удаление — `$store.toasts.remove(id)`.
Глобально: `window.MDJUIToast.show(...)`.

Alpine запускается один раз после boot shell; на `partLoad` вызывается `Alpine.initTree(root)`. Не вызывайте `Alpine.start()` в шаблонах модулей.

### Правило подключения ассетов модулей

Общий бандл `admin.css` + `admin.js` — только shell, дизайн-система, общие библиотеки и общие компоненты. CSS/JS конкретного модуля **не добавляйте в общий бандл**: храните рядом с модулем и подключайте в его шаблоне, чтобы код загружался только на странице этого модуля. Если модуль не установлен или его страница не открыта, его ассеты не должны загружаться.

Исключение по форме, но не по смыслу — редактор кода: его код общий, но тяжёлый, поэтому он собран отдельным lazy-chunk и грузится только при наличии `[data-code-editor]`. Это не повод складывать в общий бандл CSS/JS конкретных модулей.

### Редактор кода

`resources/js/codeeditor/editor.js` + `resources/css/codeeditor.css`, сборка — `vite.codeeditor.config.js`. Загрузчик `resources/js/codeeditor/loader.js` входит в `admin.js`; сам редактор — в `public/assets/admin/codeeditor.js` (не в `admin.js`). Консоль инициализируется при открытии drawer, остальные редакторы — при появлении на странице.

### Data Table

`resources/js/modules/admin_panel/118_data_table.js`, контракт — в разделе 4.7. Контроллер идемпотентен, повторная инициализация не создаёт дубли.

### Управление контролами

`70_controls.js`:

- `data-md-searchable` — Tom Select;
- `input[type=range].md-range` — заполнение трека и `<output>`;
- `data-md-password-toggle` — показ пароля;
- `data-md-dismiss="alert"` — закрытие alert.

### Связи объектов

`120_linkedobject.js` — общий контроллер `[data-md-linkedobject]`, `window.MDLinkedObjectUI`. Он не инициализирует Tom Select и не зависит от jQuery; native input остаётся источником значения. Модуль регистрируется через `MDJAdminUI.registerModuleUI('linkedobject', …)`, поэтому работает после `partLoad`. Стили — в `resources/css/ui-system.css`, секция LinkedObject.

#### Плотные таблицы связей

Для таблицы с отдельными колонками объекта, свойства и метода используйте `<table class="md-table" data-md-linkedobject-layout="table">`. Контроллер `linkedobject` включает общий режим `md-linkedobject--table`: скрывает повторные подписи и нижние панели, оставляет native input, поиск, очистку и portal-dropdown. Поля сохраняют `name`, `id`, события и свободный ввод. У каждого input задавайте `aria-label`, названия колонок — в `th`. Без атрибута существующий вид компонента сохраняется. Демо: UI Kit → LinkedObject → «Компактная таблица связей».

### Тема

`window.MDJAdminUI` + `data-md-theme-choice`; cookie `theme` (light/dark). Тему меняет только shell, не отдельные страницы.

---

## 8. Как добавить новый компонент

1. Проверьте UI Kit и этот документ — возможно, компонент уже есть.
2. Создайте `templates/ui/components/my-widget.php`; используйте `$props` и `$slot`, экранируйте текст через `MDJUI::e`.
3. Добавьте публичный метод в `lib/ui/MDJUI.php` (`MDJUI::myWidget(...)`) и, при необходимости, `MDJUI::render`.
4. Опишите стили в `resources/css/ui-system.css` с префиксом `md-`, только на токенах.
5. Добавьте состояния: default/hover/focus/disabled/loading/error, dark theme, 390 px.
6. Добавьте демо в `templates/ui_kit/sections/` и обновите этот файл.
7. `npm run build`; проверьте `?action=ui_kit` в светлой/тёмной теме и на 390 px.
8. `php -l` для PHP, `node --check` для JS.

Пример компонента:

```php
<?php
// templates/ui/components/my-widget.php
$props += ['title' => '', 'tone' => 'neutral', 'attrs' => []];
?>
<article class="md-my-widget md-my-widget--<?= MDJUI::e($props['tone']) ?>"<?= MDJUI::attrs($props['attrs']) ?>>
    <h3 class="md-my-widget__title"><?= MDJUI::e($props['title']) ?></h3>
    <?= $slot ?>
</article>
```

```php
// lib/ui/MDJUI.php
public static function myWidget(array $props = array(), $slot = '')
{
    return self::render('my-widget', $props, $slot);
}
```

Запрещено:

- добавлять стили компонента в `admin_panel.css` или `<style>` в шаблоне;
- создавать второй `.btn`, `.input`, `.modal` с другим поведением;
- подключать новую библиотеку ради одного виджета;
- использовать inline `onclick` с jQuery;
- задавать цвета/радиусы/размеры вне токенов;
- оставлять демо без документации и проверки dark/mobile.

---

## 9. Карта файлов

| Путь | Назначение |
| --- | --- |
| `resources/css/ui-system.css` | токены, `md-*` компоненты, demo-утилиты |
| `lib/ui/MDJUI.php` | серверный API компонентов + демо-данные |
| `lib/ui/tabler-icons.json` | каталог 5 247 Tabler-иконок |
| `tools/generate-icon-catalog.mjs` | генератор каталога (`npm run icons`) |
| `templates/ui/components/*.php` | разметка компонентов |
| `templates/ui_kit/ui_kit.php` | каркас страницы UI Kit |
| `templates/ui_kit/sections/*.php` | секции документации |
| `modules/ui_kit/ui_kit.class.php` | модуль админки, маршрут `?action=ui_kit` |
| `resources/js/modules/admin_panel/115_alpine.js` | запуск Alpine и partLoad |
| `resources/js/modules/admin_panel/116_ui_components.js` | общие Alpine-компоненты, toast store |
| `resources/js/modules/admin_panel/118_data_table.js` | data table |
| `public/assets/js/admin/ui_kit.js` | page-only логика UI Kit (каталог иконок, copy, scrollspy) |
| `resources/js/codeeditor/editor.js` | обвязка CodeMirror 6 и рендер редактора |
| `resources/js/codeeditor/loader.js` | lazy-загрузка редактора (в основном бандле) |
| `resources/css/codeeditor.css` | стили редактора, темы, поиск, drawer истории |
| `resources/js/codeeditor/index.js` | entry отдельного Vite-chunk |
| `vite.codeeditor.config.js` | сборка `public/assets/admin/codeeditor.js/css` |
| `resources/js/modules/admin_panel/70_controls.js` | Tom Select, range, password, alert |
| `resources/js/modules/admin_panel/120_linkedobject.js` | LinkedObject: native input, поиск, context, portal dropdown |
| `templates/linkedobject/linkedobject.html` | разметка `object/property/method` |
| `templates/ui_kit/sections/linkedobject.php` | живое демо UI Kit |
| `resources/js/app.js` | imports Vite |
| `templates/common_header_admin.html` | версия бандла и URL lazy-chunk |

Страница UI Kit доступна по **`/admin.php?action=ui_kit`**. Модуль регистрируется автоматически при первом открытии и появляется в разделе «Система» бокового меню.

---

## 10. Чек-лист перед завершением

- [ ] Использованы существующие компоненты и токены.
- [ ] Нет альтернативных кнопок/полей/селектов/карточек/модалок.
- [ ] Один primary-action; destructive отделён.
- [ ] Формы: label, hint, inline-ошибки, `required`.
- [ ] Таблица: поиск, фильтр, сортировка, выбор, empty/loading.
- [ ] Проверены light/dark, 390 px и 360 px.
- [ ] Проверены hover/focus/active/disabled/loading.
- [ ] Icon-only элементы имеют `aria-label`.
- [ ] Модалки/дроверы закрываются Esc и кликом вне.
- [ ] Нет нового jQuery-кода и inline `<script>` с UI-логикой.
- [ ] `php -l`, `node --check`, `npm run build` выполнены.
- [ ] Демо добавлено в UI Kit, документация обновлена.

---

## 11. Совместимость и ограничения

- Стек проекта не содержит Laravel/Livewire/Blade/Tailwind. Компоненты реализованы PHP-слоем `MDJUI` + partials; это осознанная адаптация, а не отказ от требований. `wire:model`/`x-model` уже поддерживаются через `attrs`.
- Legacy-страницы на Bootstrap 3/jQuery не переписываются в рамках UI-системы. Они продолжают работать; новые страницы используют `md-*`.
- `admin_panel.css` — legacy. Не добавляйте туда новые компоненты.
- Если для компонента нужна сложная логика (data grid, date range, chart), сначала проверьте готовую библиотеку в общем бандле. Свой код — только тонкий слой.
- Графики с осями/tooltip — через библиотеку, не самописный canvas/SVG. Компактные sparkline-бары — разрешённый примитив.
