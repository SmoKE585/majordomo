# Системные события

Событийный слой для изменений самой системы: установка и удаление модулей,
обновления, настройки, изменения набора циклов. Он же — публичный API для
модулей, сценариев и MQTT.

Задача слоя: избавить ядро от «угадывания» изменений (сканирование файлов,
опрос таблиц) и дать единый журнал того, что произошло с системой.

## 1. Слои

```
emitter (market, saverestore, settings, cycle.php)
        │
        ▼
EventBus::emit(NAME, payload, source)
        ├─ system_events        append-only журнал + очередь доставки
        ├─ events (registerEvent) зеркало: лента, MQTT, привязки к свойствам/методам
        └─ core listeners       синхронно, EventBus::on()
                │
                ▼
cycle_core → EventBus::drain() → processSubscriptions(NAME, payload)
                → module::processSubscription() у подписчиков subscribeToEvent()
```

- `lib/core/Events/SystemEvents.php` — словарь имён и типизированные хелперы
  (`moduleInstalled()`, `systemUpdated()`, `settingsChanged()`, …).
- `lib/core/Events/EventBus.php` — запись, доставка, чтение (`since()`,
  `lastId()`), очистка (`prune()`).
- `system_events` — таблица журнала и очереди (`PROCESSED=0` — ещё не доставлено
  модулям). Создаётся миграцией `20261022_system_events_v1`.

## 2. События

| Имя | Когда | Payload |
| --- | --- | --- |
| `MODULE_INSTALLED` | модуль установлен из маркета | `module`, `source` |
| `MODULE_REMOVED` | модуль удалён | `module`, `cycles[]` |
| `MODULE_UPDATED` | модуль обновлён | `module`, `from`, `to` |
| `SYSTEM_INSTALLED` | завершён мастер установки (зарезервировано) | `version` |
| `SYSTEM_UPDATE_STARTED` | обновление начало замену файлов | `from`, `to`, `branch` |
| `SYSTEM_UPDATED` | обновление применено | `from`, `to`, `branch` |
| `SYSTEM_UPDATE_FAILED` | обновление не применено | `reason` |
| `SYSTEM_UPDATE_ABORTED` | пауза циклов отменена | `reason` |
| `SETTINGS_CHANGED` | сохранены общие настройки | `section`, `keys[]` (без значений) |
| `CYCLE_SCRIPT_ADDED` | супервизор увидел новый `scripts/cycle_*.php` | `cycle` |
| `CYCLE_SCRIPT_REMOVED` | скрипт цикла исчез, надзор снят | `cycle` |

Имена — верхний регистр с подчёркиваниями: это требование шины хуков, где
подписка хранится в `settings` как `HOOK_EVENT_<NAME>` и после `load_settings`
становится константой `SETTINGS_HOOK_EVENT_<NAME>`.

## 3. Как подписаться

Модуль:

```php
subscribeToEvent('my_module', SystemEvents::SYSTEM_UPDATED, '', 10);

public function processSubscription($event_name, $details = '')
{
    if ($event_name !== SystemEvents::SYSTEM_UPDATED) return;
    // $details — payload события
}
```

Доставка модулям идёт через очередь: `emit()` не вызывает подписчиков сам,
поэтому медленный или упавший модуль не задерживает обновление и не теряет
событие. Очередь разбирает `cycle_core` (не чаще раза в 2 секунды, по 20
событий). Ошибка подписчика сохраняется в `system_events.ERROR`, событие
помечается обработанным и не зацикливается.

Сценарий/метод объекта: подписка на событие доступна через штатные хуки, а
привязку «событие → свойство/метод» можно сделать в ленте `events`
(`events_params.LINKED_OBJECT/PROPERTY/METHOD`) — зеркало `registerEvent()`
создаёт там запись автоматически.

Внутри одного процесса (тест, CLI-инструмент, сам emitter) можно подписаться
напрямую:

```php
EventBus::on(SystemEvents::MODULE_REMOVED, function (string $name, array $payload, string $source) {
    // ...
});
```

## 4. Чтение и обслуживание

- `EventBus::since($id)` — события новее `$id` (для супервизора и интеграций).
- `EventBus::lastId([$names])` — максимальный id, опционально по списку имён.
- `EventBus::drain($limit)` — доставить очередь модулям.
- `EventBus::prune($days, $keepMinimum)` — удалить историю старше `$days`
  (минимум `$keepMinimum` последних записей сохраняется). Вызывается из
  обслуживания; по умолчанию — 90 дней.

Если базы нет (тест, ранний boot, CLI без БД), `emit()` не падает: событие
уходит только синхронным слушателям, запись в журнал пропускается.

## 5. Супервизор циклов

Раньше `cycle.php` сканировал `scripts/` каждые 5 секунд. Теперь:

- раз в 5 секунд супервизор смотрит `lastId()` по «топологическим» событиям
  (`MODULE_*`, `CYCLE_SCRIPT_*`);
- если появилось новое событие — пересканирует `scripts/` сразу;
- если событий нет — полный скан раз в 60 секунд как страховка (файл могли
  удалить руками или через git).

Сам супервизор тоже публикует `CYCLE_SCRIPT_ADDED`/`CYCLE_SCRIPT_REMOVED`,
поэтому журнал показывает, когда цикл появился или снят с надзора.

## 6. Интерфейс

`/panel/events.html` → вкладка «Журнал системы» (или
`?action=events&view_mode=system_events`): лента `MDJUI::activityFeed()` с
группировкой по датам, фильтром по событию, режимом «только проблемные»,
пагинацией и очисткой старой истории.

## 7. Как добавить событие

1. Добавить константу и заголовок в `SystemEvents` (`names()`, `title()`,
   `severity()`).
2. Добавить хелпер с payload (только скалярные значения и массивы строк; без
   секретов и паролей).
3. Вызвать хелпер в точке изменения, обернув в `try/catch` — событие не должно
   ломать основную операцию.
4. Если на событие должен реагировать супервизор, добавить имя в список
   «топологических» в `cycle.php`.
5. Обновить таблицу в этом файле.

## 8. Проверка

```bash
php -l lib/core/Events/*.php
php tests/system_events_smoke.php
php tests/cycle_watchdog_smoke.php
```

`system_events_smoke.php` не требует БД: используется in-memory double
соединения, проверяются запись, приоритеты слушателей, фильтры, очередь,
подтверждение доставки и деградация без базы.
