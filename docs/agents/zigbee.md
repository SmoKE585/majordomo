---
title: Модуль Zigbee и общий MQTT-транспорт
category: architecture
audience: agent
order: 36
---

# Zigbee

Обычный модуль стандартной поставки: `modules/zigbee/`, `templates/zigbee/`,
`public/assets/modules/zigbee/`. Не переносить Zigbee-логику в `lib/core`.
Старый форк `majordomo-zigbeedev` служил исходным ориентиром; его API, таблицы
и технические имена не используются. Импорт старых данных пока не реализован.

- `Schema` — пять таблиц InnoDB/utf8mb4 с уникальными идентификаторами.
- `Repository` — хранение и транзакции.
- `ValueTransformer` — проверяемые декларативные правила; без eval и SQL-кода.
- `Service` — Zigbee2MQTT discovery, exposes, события и команды.
- `zigbee` — стандартный module-фасад, подписки, формы и CSRF сессии панели.

## Общий транспорт

Модуль регистрируется стандартным `subscribeToEvent('zigbee', 'MQTT_MESSAGE')`.
`modules/mqtt/MqttIntegrations.php` читает этот реестр при подключении цикла,
запрашивает `getMqttSubscriptions()` у модулей и маршрутизирует исходные пакеты.
Маршрутизация идёт до `MQTT_STRIPMODE`, подавления повторов и разбора JSON.
Один физический пакет вызывает один и тот же callback библиотеки MQTT ровно
один раз даже при перекрывающихся подписках; следующий идентичный пакет —
отдельное событие.

Событие помещается без coalescing в `core_runtime`, тип `moduleevent`:
`target`, `path`, `params: {event, details}`. Worker загружает обычный модуль
и вызывает `processSubscription(event, details)`. Это общий контракт модулей,
а не специальная ветка для Zigbee. Ошибки обрабатываются стандартными retry.
Loopback HTTP и дополнительного MQTT-соединения нет.

Отправка идёт в `mqtt_queue` без coalescing, через соединение `cycle_mqtt`.
При активных подписках модулей цикл обслуживает общую очередь независимо от `MQTT_WRITE_METHOD`; настройка
по-прежнему выбирает способ отправки для существующих вызовов модуля MQTT.
Изменение экземпляров перезапускает MQTT штатным `cycle_mqttControl`.

## Данные и порядок

`zigbee_bridges` → `zigbee_devices` → `zigbee_properties` → `zigbee_bindings`;
`zigbee_events` — ограниченный диагностический журнал. Значения хранятся
как JSON, поэтому null, false, число 0, строка "0" и пустая строка различаются.
Изменение метаданных не перезаписывает пользовательские правила/привязки.

Запись объекта выполняется с исключением только подписчика `zigbee`,
затем вызывается явный метод. Fingerprint успешной доставки обновляется
только после обоих действий. Ошибки не скрывать и не превращать в успешный ACK.
Повторные события разрешены по умолчанию. Новое чтение не означает успешного
выполнения привязки; `RAW_VALUE`, `VALUE`, `LAST_DELIVERED` разделены.

Существующий writer `Properties` теперь откладывает ошибки очереди уведомлений
до выполнения ONCHANGE: уже сохранённое значение не должно остаться без
обработчика из-за сбоя другого подписчика. Ошибка всё равно возвращается
вызывающему, lock освобождается, остальные подписчики уведомляются.

Проверки без окружения приложения:

```text
php tests/zigbee_smoke.php
php tests/property_onchange_smoke.php
php tests/module_event_smoke.php
php tests/mqtt_throttle_smoke.php
php tests/routing_smoke.php
php tests/config_smoke.php
```

Первые два теста используют SQLite `:memory:` и mock-побочные эффекты,
без production-конфигурации, брокера и Redis.
