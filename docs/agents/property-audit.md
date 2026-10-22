---
title: Журнал изменений свойств
audience: agent
category: development
tags: свойства, аудит, журнал, цепочка, trace, onchange, методы, сценарии
description: Устройство always-on журнала изменений свойств: таблицы, trace id, передача параметров и цепочки, API для модалки, retention.
---

# Журнал изменений свойств

Журнал включён всегда: отдельного `ENABLED`-флага нет. Отключать его нельзя,
потому что он является частью диагностики цепочек автоматизации. Настраивается
только срок хранения.

## 1. Зачем

Журнал отвечает на вопросы:

- кто/что изменил свойство;
- когда и в рамках какого запуска;
- какое значение было и какое стало;
- какой метод, сценарий или модуль выполнился до этого;
- какие параметры пришли в метод/сценарий и что вернулось;
- куда можно перейти, чтобы открыть метод, свойство, сценарий или модуль.

`phistory` остаётся историей значений для графиков и включается через
`KEEP_HISTORY`. Новый журнал — отдельный, всегда включённый поток фактов; он не
заменяет `phistory` и не требует `KEEP_HISTORY`.

## 2. Таблицы

Схема создаётся миграцией `20261023_property_audit_v1` в
`Migrations::ensurePropertyAuditSchema()` и присутствует в
`lib/core/Install/schema.sql`.

### `property_audit_runs`

Заголовок одного запуска:

- `ID`, `TRACE_ID` (32 hex);
- `STARTED`, `LAST_SEEN`, `FINISHED`, `STATUS`;
- `TRIGGER_TYPE` (`web`, `property`, `method`, `script`, `job`, `timer`,
  `module`, `event`, `system`);
- `TRIGGER_TITLE`, `SOURCE`, `ACTOR`, `IP`, `REQUEST_URI`;
- `PARAMS` — входные параметры запуска;
- `RESULT`, `ERROR`, `CHAIN`, `EVENTS_COUNT`.

### `property_audit_events`

Шаги запуска:

- `RUN_ID`, `TRACE_ID`, `ADDED`, `FINISHED`, `DURATION_MS`, `TYPE`, `STATUS`;
- `TITLE`, `SUBTITLE`;
- `OBJECT_ID`, `PROPERTY_ID`, `PROPERTY_NAME`;
- `METHOD_ID`, `CLASS_ID`, `SCRIPT_ID`, `MODULE`;
- `OLD_VALUE`, `NEW_VALUE`;
- `SOURCE`;
- `PARAMS` (вход), `OUT_PARAMS` (состояние после выполнения), `RESULT`;
- `ERROR`, `CHAIN`.

Типы событий: `trigger`, `job`, `method`, `script`, `module`, `property`,
`error`, `warning`, `chain`.

Свойство считается изменённым только при фактическом изменении значения.
Повторная запись того же значения по-прежнему доставляет `ONCHANGE` и
подписчикам, но новую строку «изменения» не создаёт.

## 3. Trace id и очередь

Запуск начинается в одном из мест:

- `Scheduler::execute()` — задача планировщика;
- `Methods::call()` / `Methods::callSafe()` — прямой или фоновый вызов метода;
- `runScript()` / `runScriptSafe()` — сценарий;
- `OperationWorker::execute()` — очередь `core_runtime`;
- `EventBus::drain()` — доставка системного события модульным подписчикам;
- `Properties::set()` — прямое изменение свойства без метода (ручное или API);
- `public/api.php` — прямой HTTP API, если пришёл `_trace`.

Дальше `_trace` передаётся через:

- `OperationsQueue` (payload `method` / `modulepropertyset` / `moduleevent`);
- `raiseEvent()` и `ONCHANGE`-параметры;
- `callMethodSafe()` и `runScriptSafe()`;
- `ApiClient` в `/api/method/*` и `/api/modulePropertySet/*`.

`PropertyChain` продолжает отвечать только за защиту от циклов свойств. Trace id
и цепочка шагов — отдельная ответственность `PropertyAudit`.

`OperationWorker` вызывает `PropertyAudit::enter($traceId)` для продолжения
родительского запуска и `PropertyAudit::begin()` только если trace отсутствует
(старые записи в очереди, внешние вызовы).

## 4. Что попадает в параметры

- Метод: `$params` до выполнения (без `m_c_s`, `r_s_m`, `r_s_s`, `raiseEvent`,
  `_trace`), `OUT_PARAMS` после выполнения и возвращённый результат.
- Сценарий: входные `$params` и `OUT_PARAMS`.
- Задача: `COMMANDS` и результат `eval`.
- Модуль: `object/property/value` или `event/details`.
- Свойство: `OLD_VALUE`, `NEW_VALUE`, `SOURCE`, `CHAIN`.

Значения обрезаются до 64 КБ; для обычных домашних значений это полный объём.
Журнал не пытается редактировать секреты: не пишите пароли в свойства и
`$params`, если не хотите видеть их в диагностике.

## 5. API и UI

Endpoint находится в модуле «Объекты»:

```
GET /admin.php?action=objects&md=objects&view_mode=edit_objects&id=<object>&tab=properties
    &ajax=1&op=property_trace&property_id=<property>
    &trace_range=24h&trace_page=1
```

Ответ:

```json
{
  "status": "ok",
  "meta": {"object_id": 7, "property_id": 17, "property_name": "Lamp.status"},
  "range": {"key": "24h", "label": "24 часа"},
  "runs": [
    {
      "id": 12,
      "trace_id": "…",
      "trigger_type": "method",
      "trigger_title": "Lamp.onTimer",
      "started_label": "22.10.2026 16:13:00",
      "status": "done",
      "events": [
        {"type": "method", "method_id": 7, "params": {}, "result": true},
        {"type": "property", "old_value": "0", "new_value": "1"}
      ]
    }
  ],
  "pagination": {"page": 1, "total": 1}
}
```

Сервер сам добавляет к событиям `links` — готовые URL на метод, свойство,
сценарий или модуль. UI не строит маршруты повторно.

В карточке свойства кнопка **«Цепочка»** открывает `MDJUI::traceDrawer()`.
Компонент монтируется в общий drawer host (`20_drawer.js`), данные грузит
`resources/js/modules/admin_panel/41_trace.js`. Периоды: 3 ч, 12 ч, 24 ч, 7 дн.,
30 дн. По умолчанию открыт последний запуск; остальные — сворачиваемые
`<details>`, чтобы длинная история не занимала весь экран.

## 6. Retention

`PROPERTY_AUDIT_RETENTION_DAYS` в `.env`:

- `0` (по умолчанию) — хранить бессрочно;
- положительное число — `startup_maintenance.php` удаляет старые события и
  заголовки запусков.

Очистка идёт пакетами и не трогает записи новее срока. Перед включением
retention сделайте резервную копию БД, если журнал нужен для разбора инцидента.

## 7. Гарантии и отказы

- Запись свойства не падает из-за журнала: все ошибки аудита перехватываются.
- Ошибка журнала показывается через `ErrorReporter` (код `property_audit`) не
  чаще одного раза за окно.
- Если таблицы ещё нет, `ensureSchema()` создаст её при первой записи; штатная
  миграция делает это раньше.
- Запись аудита синхронная. При очень высокой частоте изменений (сотни в
  секунду) её стоит перевести на батч/очередь; сейчас это осознанный компромисс
  ради простоты и надёжности.
- Запуск может остаться в статусе `running`, если процесс был убит до
  завершения. Это факт, а не потеря изменения: события уже записаны.

## 8. Проверка

```bash
php tests/property_audit_smoke.php
php tests/property_onchange_smoke.php
php tests/stale_module_bindings_smoke.php
```

Проверьте вручную: откройте свойство → «Цепочка» → последний запуск, затем
перейдите по ссылке на метод и обратно. Журнал доступен и для свойств без
`KEEP_HISTORY`.
