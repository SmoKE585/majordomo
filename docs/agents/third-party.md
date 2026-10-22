---
title: "Сторонние библиотеки: Composer и локальные пакеты"
audience: agent
category: integration
category_label: Интеграции
category_icon: plug-connected
category_order: 70
tags: Composer, библиотеки, PHPMailer, MQTT
description: Установка PHP-зависимостей, локальная версия MQTT и размещение шрифтов после удаления 3rdparty.
order: 10
---

# Сторонние библиотеки

PHP-библиотеки устанавливаются Composer в `vendor/`. Каталог `3rdparty/`
удалён. Нельзя вручную копировать код в `vendor/`: он восстанавливается из
`composer.lock` и локальных пакетов при установке.

## Состав

| Пакет или ресурс | Источник | Подключение |
| --- | --- | --- |
| `phpmailer/phpmailer` 7.x | Composer, совместим с PHP 8.1 | Автозагрузка `PHPMailer\PHPMailer\*`; публичные `SendMail()` и `SendMail_HTML()` сохранены. |
| `workerman/workerman` 5.x | Composer | Сервер ядра `lib/core/WebSockets/`. |
| `majordomo/phpmqtt` 1.x | Локальный Composer path-пакет `packages/phpmqtt/` | Автозагрузка `Bluerhinos\phpMQTT`. |
| Rubik | `public/assets/fonts/rubik/` | CSS импортируется в Vite из `resources/js/app.js`. |
| Пагинация | `lib/core/Pagination.php` | Журнал действий использует SQL COUNT/LIMIT и ограниченное окно страниц. Внешний Paginator удалён. |

Локальный MQTT-пакет сохраняет лицензию BlueRhinos и доработки MajorDoMo:
`setReadLimits()`, таймаут незавершённого пакета, потоковый пропуск слишком
больших QoS 0 сообщений и обработку SUBACK. Подменять его upstream-копией без
этих ограничений нельзя. Проверки — `tests/network_memory_smoke.php` и
`tests/zigbee_smoke.php`.

Composer создаёт ссылку `vendor/majordomo/phpmqtt` на `packages/phpmqtt/`
(junction в Windows). Исходники пакета хранятся в Git и должны доставляться
на сервер вместе с приложением. Изменения локального клиента вносятся только
в `packages/phpmqtt/src/phpMQTT.php`, не в `vendor/`.

## Установка и деплой

```bash
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
composer check-platform-reqs --no-dev
```

В `composer.json` зафиксирован `config.platform.php=8.1.0`. Новые зависимости
должны поддерживать PHP 8.1. Composer не подключает рабочую БД или Redis.
На сервере используется `install`, версии обновляются локально с переносом
`composer.json` и `composer.lock`. После доставки перезапускаются CLI-циклы.

Общий загрузчик — `lib/core.class.php`: он подключает `vendor/autoload.php`
для HTTP, CLI, модулей и сценариев. Ручные include PHPMailer и MQTT удалены.
Встроенный updater не запускает Composer автоматически.

`vendor/`, `packages/` и системный `scripts/` не публикуются nginx или сервером
разработки. Старый HTTP-вход `/objects/` удалён; внешние интеграции используют
[API v1](api-client.md), внутренние вызовы — сервисы и очередь ядра.
См. [структуру проекта](project-structure.md) и [пример nginx](../deploy/nginx.conf.sample).

## Проверки

```bash
composer validate --strict
composer dump-autoload --optimize --strict-psr
php tests/composer_smoke.php
php tests/network_memory_smoke.php
php tests/zigbee_smoke.php
php tests/pagination_smoke.php
php tests/private_paths_smoke.php
php tests/routing_smoke.php
```

Проверки используют временные файлы, SQLite и loopback; рабочее окружение
не загружается. Реальная отправка почты не выполняется.
