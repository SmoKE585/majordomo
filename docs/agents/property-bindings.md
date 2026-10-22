---
title: Свойства, ONCHANGE и подписки модулей
audience: agent
category: development
tags: свойства, подписки, привязки, linked_modules, onchange, модули
description: Полная цепочка записи свойства, вызова ONCHANGE и доставки значения привязанному модулю; правила владения привязкой и разбор старых поломок.
---

# Свойства, ONCHANGE и подписки модулей

Документ описывает обе стороны одной цепочки:

1. изменение свойства объектом или модулем → проверка → запись → история/WebSocket →
   `ONCHANGE` → уведомление привязанных модулей;
2. изменение свойства устройством → привязанный модуль получает значение и
   применяет его к своему оборудованию.

## 1. Где что лежит

| Слой | Файл |
| --- | --- |
| Публичные функции `gg/sg/getGlobal/setGlobal/callMethod/callMethodSafe` | `lib/objects.class.php` |
| Запись/чтение свойств, `ONCHANGE`, постановка уведомлений | `lib/core/Properties.php` |
| Разрешение объекта, свойства и строки значения | `lib/core/Objects.php` |
| Подписки `pvalues.LINKED_MODULES` | `lib/core/PropertyBindings.php` |
| Очередь фоновых операций | `lib/core/OperationsQueue.php`, `lib/core/ApiClient.php` |
| Выполнение уведомлений модулей | `lib/core/OperationWorker.php`, `scripts/cycle_core.php` |
| Журнал системных событий (отдельный механизм) | `lib/core/Events/EventBus.php` |

`EventBus` — это подписка на события системы (`MODULE_INSTALLED`,
`SYSTEM_UPDATED`, …), а не на свойства. Не смешивайте её с
`subscribeToEvent()`/`processSubscription()`.

## 2. Запись свойства

```
setGlobal('Lamp.status', 1, array('mqtt' => 0), 'zigbee')
  → Objects::setValue()
    → Properties::set()
      1. нормализация значения и источника
      2. валидация свойства (число/да/список/код)
      3. PropertyLock по (OBJECT_ID, PROPERTY) — сериализация записи одного свойства
      4. resolve свойства: объекта → класса → родительских классов
      5. UPDATE/INSERT pvalues.VALUE, SOURCE, UPDATED
      6. cache → WebSocket (если значение изменилось) → phistory_queue
      7. для каждого токена LINKED_MODULES:
             ApiClient → OperationsQueue('modulepropertyset')
      8. ONCHANGE: в web-запросе — прямой callMethod; в CLI/цикле — raiseEvent
```

Важные детали:

- Строка значения ищется по `(PROPERTY_ID, OBJECT_ID)`, а не по
  `PROPERTY_NAME`. Старое имя может остаться у переопределённого/унаследованного
  свойства и указывать на «мёртвую» строку.
- `no_linked`:
  - массив `array('mqtt' => 0)` — исключить только перечисленные модули;
  - `1` — не уведомлять привязанные модули; при наличии `LINKED_MODULES`
    легаси-поведение дополнительно пропускает WebSocket/историю/`ONCHANGE`
    (ранний `return`). Не используйте `1`, если нужен `ONCHANGE`.
- `ONCHANGE` вызывается и при повторной записи того же значения. Защита от
  рекурсии — `$property_linked_history`, только в пределах одного процесса.
- Уведомления модулей ставятся в очередь **до** `ONCHANGE`. Если постановка в
  очередь падает, значение уже сохранено, `ONCHANGE` всё равно выполняется, а
  исключение поднимается после него.

## 3. Доставка привязанному модулю

`OperationsQueue` хранит операцию `modulepropertyset` с параметрами
`object/property/value`. `cycle_core` забирает её, запускает `core_worker.php`
в отдельном PHP-процессе и загружает
`modules/<module>/<module>.class.php`.

Контракт модуля:

```php
public function propertySetHandle($object, $property, $value): void
```

Требования:

- обработчик должен быть идемпотентным: при потере подтверждения операция
  повторится;
- возврат `false`, исключение или таймаут — временная ошибка, привязка
  сохраняется, операция повторяется (до 3 попыток, затем `operations_failed`);
- обработчик не должен рассчитывать на HTTP-сессию или `$_SERVER` web-запроса;
- если файл модуля отсутствует, но модуль зарегистрирован
  (`cms/modules_installed/<module>.installed` или `project_modules`), привязка
  **не удаляется** — это считается деплоем/сбоем установки;
- если файла нет и модуль не зарегистрирован, после трёх попыток привязка
  вычищается как устаревшая;
- если класс загрузился, но метода `propertySetHandle` нет, привязка также
  считается устаревшей.

## 4. Хранение и владение привязкой

`pvalues.LINKED_MODULES` — список имён модулей через запятую. Формат сохранён
для совместимости со старыми модулями, бэкапами и страницей объекта.

Новый код:

```php
use MajorDoMo\Core\Core;

Core::bindings()->attach('Lamp', 'status', 'mqtt');
Core::bindings()->detach('Lamp', 'status', 'mqtt');
Core::bindings()->detachIfUnused('mqtt', 'Lamp', 'status', 'mqtt');
Core::bindings()->modules('Lamp', 'status'); // ['mqtt', 'scripts']
```

Правила:

- `attach/detach` меняют **только** `LINKED_MODULES` compare-and-swap-запросом.
  Они не перезаписывают `VALUE`, `UPDATED`, `SOURCE` и не теряют подписчика,
  добавленного параллельно.
- Токены нормализуются: пробелы и дубликаты удаляются при ближайшей записи.
- Имя модуля проверяется тем же шаблоном, что и worker:
  `^[A-Za-z_][A-Za-z0-9_]*$`.
- `detachIfUnused($table, …)` принимает только идентификатор таблицы
  (`^[A-Za-z_][A-Za-z0-9_]*$`) и снимает подписку, только если в этой таблице
  больше нет строки с такой парой `LINKED_OBJECT/LINKED_PROPERTY`.
- Владелец привязки — запись модуля (`mqtt`, `scripts`, `zigbee_bindings`, …).
  Сначала удалите/сохраните запись, затем вызывайте `detachIfUnused()`. Иначе
  проверка увидит текущую запись и оставит «вечную» подписку.
- `LINKED_MODULES` — `varchar(255)`. Переполнение отклоняется с понятной
  ошибкой; молчаливое обрезание запрещено.

Старые функции остаются фасадами:

```php
addLinkedProperty($object, $property, $module);            // → attach()
removeLinkedProperty($object, $property, $module);         // → detach()
removeLinkedPropertyIfNotUsed($table, $object, $property, $module); // → detachIfUnused()
```

## 5. Почему старые привязки «слетали»

Исправленные причины:

1. `addLinkedProperty()` читал всю строку `pvalues`, менял `LINKED_MODULES` и
   писал строку целиком. Параллельная запись значения откатывалась к старому
   `VALUE`, а параллельно добавленный подписчик терялся.
2. `removeLinkedPropertyIfNotUsed()` вызывался до сохранения записи модуля.
   Проверка видела саму редактируемую запись и не снимала старую подписку.
3. `scripts_edit.inc.php` снимал подписку по **новым** вычисленным значениям,
   а не по старым, и до `UPDATE`/`INSERT`.
4. `mqtt_edit.inc.php` снимал `mqtt` безусловно. Если два топика MQTT смотрели
   на одно свойство, редактирование одного отключало второй.
5. Подписка могла быть записана в устаревшую строку `pvalues` с тем же
   `PROPERTY_NAME`, но другим `PROPERTY_ID` (переопределённое свойство класса).
6. `LIKE` в обработчиках MQTT/scripts считал `_` и `%` в имени объекта
   шаблоном и мог вызвать чужой топик/сценарий.
7. Удаление сценария не снимало его подписку; удаление MQTT-топика снимало
   корректно, но редактирование — нет.
8. Удаление файла модуля во время деплоя трактовалось как «модуль удалён» и
   вычищало рабочие привязки после трёх коротких повторов.

## 6. Проверка перед продакшеном

```bash
php tests/property_onchange_smoke.php
php tests/stale_module_bindings_smoke.php
```

Проверить в БД:

```sql
-- Подписчики, которых нет в каталоге модулей
SELECT p.ID, p.PROPERTY_NAME, p.LINKED_MODULES
FROM pvalues p
WHERE p.LINKED_MODULES <> '';

-- Дубли строк значения (должно быть пусто после миграции)
SELECT PROPERTY_ID, OBJECT_ID, COUNT(*) AS CNT
FROM pvalues GROUP BY PROPERTY_ID, OBJECT_ID HAVING CNT > 1;

-- Неразобранные фоновые операции и постоянные ошибки
SELECT COUNT(*) FROM operations_queue;
SELECT COUNT(*) FROM operations_failed;
```

Эксплуатационные условия:

- `cycle_core` запущен и живой: без него очередь `modulepropertyset` не
  разбирается;
- очередь операции живёт 1 час (`EXPIRE`): после длительного простоя
  уведомления, не доставленные за это время, пропадают; значение свойства при
  этом остаётся корректным;
- ошибки доставки видны в `operations_failed` (SQL) или
  `queue:failed:core_runtime` (Redis), а также в журнале `core_runtime`;
- при обновлении, которое заменяет файлы модулей, подписки на зарегистрированные
  модули не удаляются;
- после восстановления из бэкапа проверьте, что `LINKED_MODULES` ссылается на
  реально установленные модули.

## 7. Как добавить привязку в новый модуль

1. Добавьте в таблицу модуля поля `LINKED_OBJECT` и `LINKED_PROPERTY`
   (по необходимости `LINKED_METHOD`).
2. При сохранении записи: сначала `UPDATE/INSERT`, затем
   `addLinkedProperty($object, $property, $module)`.
3. При удалении/смене привязки: сначала удалите/сохраните запись, затем
   `removeLinkedPropertyIfNotUsed('<table>', $oldObject, $oldProperty, $module)`.
4. Реализуйте `propertySetHandle($object, $property, $value)`; не полагайтесь на
   web-сессию и делайте обработчик идемпотентным.
5. Не добавляйте модуль в `LINKED_MODULES` вручную и не пишите `pvalues`
   целиком.
