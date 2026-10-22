---
title: Компоненты состояния и управления циклами
audience: agent
category: automation
tags: циклы, xray, виджет, md-cycle, интерфейс, data-атрибуты
description: Переиспользуемые компоненты статуса и управления циклами для страниц модулей и X-Ray: cycleCard, md-cycle-table, data-атрибуты и контракт endpoint.
order: 20
---

# Компоненты состояния и управления циклами

## Коротко

Готовые компоненты для статуса и управления циклами в модулях и X-Ray:
`MDJUI::cycleCard()`, таблица `md-cycle-table`, data-атрибуты и контракт
endpoint. Читайте, когда выводите состояние цикла на странице модуля.

## Что доступно

- `MDJUI::cycleCard()` — серверный виджет цикла.
  - `compact => true` — компактная полоса для страницы модуля (MQTT и т. п.);
  - `compact => false` — полная карточка (вариант для отдельной страницы).
- `md-cycle-table` — построчная/табличная раскладка для списка сервисов X-Ray.
- `resources/js/modules/admin_panel/119_cycle_widget.js` — опрос, AJAX-команды и
  обновление состояния на месте для обоих вариантов.

## Серверный компонент

```php
<?= MDJUI::cycleCard([
    'name' => 'cycle_mqtt',
    'label' => 'Состояние цикла',
    'status' => 'stopped',          // running|starting|stopping|hang|stopped|disabled|unknown
    'status_text' => 'Остановлен',
    'online' => false,
    'updated' => '08.07.2020 17:55:07',
    'auto_restart' => false,        // null скрывает строку автоперезапуска
    'compact' => true,
    'poll' => true,
    'endpoint' => ROOTHTML . 'ajax/xray.html?action=service',
    'log_url' => '',                // необязательная ссылка; без неё кнопки «Лог» нет
]) ?>
```

Правила отрисовки:

- `stopped`/`unknown` — **Запуск** и **Отключить**.
- `running`/`starting`/`stopping`/`hang` — **Рестарт**, **Остановить** и
  **Отключить**.
- `disabled` — только **Включить** и **Лог**, если задан `log_url`.
- `disabled` — сохранённое состояние: процесс остановлен и не запускается, пока
  администратор не включит цикл. Это не пауза интерфейса.
- Бейдж состояния и сигнальная точка берут цвет из состояния; не задавайте цвета
  вручную в шаблонах модулей.
- Компактная полоса ограничена шириной `500px`, чтобы не превращаться в пустую
  карточку на всю ширину.
- Полная карточка показывает название, описание, «Обновлено» и
  «Автоперезапуск»; компактная — имя, состояние, ONLINE/OFFLINE, «Обновлено» и
  действия.

## Таблица X-Ray

`public/assets/modules/xray/xray.js` формирует разметку:

```html
<div class="md-cycle-table">
  <div class="md-cycle-table__head">…</div>
  <div class="md-cycle-table__row is-stopped" data-md-cycle
       data-md-cycle-name="cycle_mqtt"
       data-md-cycle-state="stopped"
       data-md-cycle-endpoint="/ajax/xray.html?action=service"
       data-md-cycle-poll="0">
    … идентификация / состояние / обновлено / действия …
  </div>
</div>
```

Колонки: **цикл**, **состояние**, **обновлено**, **действия** (четвёртая
колонка в шапке пустая). На ширине `≤900px` шапка скрывается, а строка
складывается в две линии. Действия — иконочные кнопки с `title`/`aria-label`.

## Data-атрибуты

- `data-md-cycle` — отмечает виджет/строку для общего контроллера.
- `data-md-cycle-name` — имя цикла, которое уходит в endpoint.
- `data-md-cycle-state` — текущее состояние для CSS/JS; дублирует `status`.
- `data-md-cycle-endpoint` — JSON endpoint; по умолчанию
  `ROOTHTML + ajax/xray.html?action=service`.
- `data-md-cycle-poll="1|0"` — включает/выключает периодическое обновление.
  Строки X-Ray обновляет общий опрос страницы, мини-виджет модуля опрашивает
  себя сам.
- `data-md-cycle-command="start|stop|restart|disable|enable"` — кнопки действий.
- `data-md-cycle-status` — бейдж состояния.
- `data-md-cycle-online` — индикатор ONLINE/OFFLINE.
- `data-md-cycle-updated` — текст последнего обновления.
- `data-md-cycle-log-url` — необязательная ссылка на лог: шаблон выводит её,
  если передан `log_url`; общий контроллер её не читает.

Служебный `data-md-cycle-bound` контроллер ставит сам, чтобы не инициализировать
один виджет дважды; вручную его задавать не нужно.

## Контракт бэкенда

`modules/xray/xray.class.php::service_control()` возвращает:

```json
{
  "cycle": "cycle_mqtt",
  "STATUS": "running",
  "STATUS_TEXT": "Работает",
  "UPDATED": 1594216507,
  "ONLINE": 1,
  "DISABLED": 0,
  "DESIRED": "running",
  "BODY": "…legacy-сводка…"
}
```

Команды отправляются на тот же endpoint (`op=start|stop|restart|disable|enable`,
GET или POST). Они проходят через `MajorDoMo\Core\Cycles\CycleManager`; не
создавайте второй API управления для модуля. Устройство хранения состояния и
поведение супервизора описаны в [циклах](cycles.md).

## Файлы

- `lib/ui/MDJUI.php` — `cycleCard()`.
- `templates/ui/components/cycle-card.php` — разметка.
- `resources/css/ui-system.css` — `.md-cycle-card`, `.md-cycle-table`,
  состояния и адаптивные правила.
- `resources/js/modules/admin_panel/119_cycle_widget.js` — контроллер.
- `templates/xray/xray.html` — раскладка X-Ray и действия сервиса.
- `public/assets/modules/xray/xray.js` — строки таблицы X-Ray.
- `modules/xray/xray.class.php` — начальное состояние и JSON endpoint.
