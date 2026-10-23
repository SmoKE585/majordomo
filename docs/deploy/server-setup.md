---
title: Развёртывание MajorDoMo на сервере
audience: user, agent
category: deploy
tags: установка, сервер, fastpanel, systemd, composer, node, первый запуск
description: Пошаговая инструкция для первого развёртывания MajorDoMo на сервере: пользователи, права, systemd, Composer, Node.js, обновление и типовые ошибки.
order: 10
---

# Развёртывание MajorDoMo на сервере

Инструкция «с нуля» для одного сайта на FastPanel/Linux. Все команды — из
корня проекта, если не указано другое.

## 1. Что должно быть

- Linux с systemd, PHP-FPM, MySQL/MariaDB, nginx;
- PHP CLI и PHP-FPM **одной версии** (например, 8.3 или 8.4);
- Composer;
- Node.js **24+** и npm;
- проект уже лежит на диске, например:
  `/var/www/mjdm_smart15_usr/data/www/mjdm.smart155.ru`;
- пользователь сайта, например `mjdm_smart15_usr`. Он **не root**;
- доступ root (SSH root, `su -` или консоль FastPanel).

Проверка инструментов:

```bash
php -v
/usr/bin/php -v
# тот же путь, что указан в PATH_TO_PHP:
# PHP_CLI=/usr/bin/php
"$PHP_CLI" -v
composer --version
node --version
npm --version
systemctl --version
```

Важно: на FastPanel в PATH пользователя часто стоит старый `php` (например,
8.0), а сайт работает на PHP-FPM 8.3. Тогда `majordomo` падает с
`Composer detected issues ... You are running 8.0`. Для всех команд используй
тот же CLI, что указан в `PATH_TO_PHP`:

```bash
export PHP_CLI=/usr/bin/php
"$PHP_CLI" majordomo maintenance:status
```

`PHP_CLI` — это ровно то значение, которое стоит в `PATH_TO_PHP`. Не
подставляй версию вручную: на разных серверах путь и имя файла разные.

Если `node --version` меньше 24 — обнови Node.js, иначе `npm run build` может
не работать.

## 2. Две роли

| Кто | Что делает |
| --- | --- |
| root | создаёт systemd-службу, правит `/etc`, выдаёт права на перезапуск |
| пользователь сайта | запускает Composer, npm, миграции, обычные проверки |

**Не запускай Composer от root.** Иначе `vendor/` станет root-овым, и
следующая установка от сайта упадёт по правам.

## 3. Права на каталоги

FastPanel при создании сайта уже выставляет владельцем пользователя сайта.
Обычно ничего делать не нужно.

Проверить можно так:

```bash
cd /var/www/mjdm_smart15_usr/data/www/mjdm.smart155.ru
ls -ld cms vendor public/assets
```

Если после ручных запусков от root появились root-овые файлы, исправь только
этот случай:

```bash
chown -R mjdm_smart15_usr:mjdm_smart15_usr \
  cms vendor public/assets node_modules 2>/dev/null || true
```

`node_modules` может ещё не существовать — это нормально.

## 4. Один раз: systemd-служба

От root:

```bash
cd /var/www/mjdm_smart15_usr/data/www/mjdm.smart155.ru

"$PHP_CLI" majordomo systemd:set \
  --service=majordomo-mjdm_smart15_usr \
  --user=mjdm_smart15_usr \
  --php="$PHP_CLI" \
  --apply --disable-old
```

Важно:

- `--php` — путь к CLI PHP, который уже проверен на этом сервере. Если его не
  указать, берётся `PATH_TO_PHP`. Не подставляй имя версии вручную: на разных
  серверах оно разное. Если файла нет, служба упадёт с `status=203/EXEC`;
- `--apply` сразу включает новый unit;
- `--disable-old` останавливает старый `majordomo.service`;
- `--fix-owner` нужен только если `cms`, `vendor` или `public/assets` уже
  принадлежат root. FastPanel обычно выставляет права сам.

Проверка:

```bash
systemctl status majordomo-mjdm_smart15_usr.service --no-pager -l
systemctl is-active majordomo-mjdm_smart15_usr.service
```

В статусе должно быть `active (running)` и запущенные `cycle_*.php`.

## 5. Служба systemd

Службой управляет systemd:

```text
systemd
  └─ majordomo-mjdm_smart15_usr.service
       └─ cycle.php              ← процесс-менеджер циклов MajorDoMo
            ├─ cycle_main.php
            ├─ cycle_core.php
            ├─ cycle_mqtt.php
            └─ ...
```

`cycle.php` запускает и перезапускает дочерние `cycle_*.php`, а systemd следит
за самим `cycle.php` и поднимает его после падения (`Restart=always`).

Не запускай `cycle.php` вручную из SSH, пока служба активна: получится второй
процесс и дубли MQTT/WebSocket.

Управление:

```bash
systemctl status majordomo-mjdm_smart15_usr.service --no-pager
systemctl restart majordomo-mjdm_smart15_usr.service
systemctl stop majordomo-mjdm_smart15_usr.service
systemctl start majordomo-mjdm_smart15_usr.service
systemctl enable majordomo-mjdm_smart15_usr.service
systemctl disable majordomo-mjdm_smart15_usr.service
```

Логи службы:

```bash
journalctl -u majordomo-mjdm_smart15_usr.service -n 100 --no-pager
journalctl -u majordomo-mjdm_smart15_usr.service -f
```

Один сайт — один unit `majordomo-<user>.service`.

## 6. Один раз: управление без пароля

От root:

```bash
"$PHP_CLI" majordomo systemd:give-permissions \
  --service=majordomo-mjdm_smart15_usr \
  --user=mjdm_smart15_usr
```

Проверка от root:

```bash
sudo -u mjdm_smart15_usr sudo -n \
  /usr/local/sbin/majordomo-mjdm_smart15_usr status
```

Не должно быть запроса пароля и `not allowed`. Сайту выдаётся только wrapper
для `restart/start/stop/status/is-active`, а не произвольный `systemctl`.

## 7. Один раз: старый unit

Только после того, как новый unit стабильно работает:

```bash
systemctl disable --now majordomo.service
rm -f /etc/systemd/system/majordomo.service
rm -f /etc/systemd/system/multi-user.target.wants/majordomo.service
systemctl daemon-reload
systemctl reset-failed majordomo.service 2>/dev/null || true
systemctl list-unit-files | grep majordomo
```

Должен остаться только `majordomo-mjdm_smart15_usr.service`.

## 8. Настройки `.env`

В `.env` проекта:

```dotenv
PATH_TO_PHP=/usr/bin/php

DEPLOY_COMPOSER=composer
DEPLOY_NODE=node
DEPLOY_NPM=npm
DEPLOY_ASSETS=always
DEPLOY_SERVICE=majordomo-mjdm_smart15_usr
DEPLOY_RESTART=1
DEPLOY_TIMEOUT=900
```

`DEPLOY_ASSETS=always` означает `npm run build` при каждом обновлении. Если
Node на сервере нет, поставь `DEPLOY_ASSETS=never`, но тогда обновляй
`public/assets/admin` вместе с кодом.

## 9. Первый запуск

От пользователя сайта:

```bash
cd /var/www/mjdm_smart15_usr/data/www/mjdm.smart155.ru

"$PHP_CLI" majordomo migrations:run
"$PHP_CLI" majordomo cycles:status
"$PHP_CLI" majordomo maintenance:status
```

Если maintenance включён после неудачной попытки:

```bash
"$PHP_CLI" majordomo maintenance:off
```

Проверка сайта:

```bash
curl -I https://ваш-домен/
```

Должен быть обычный ответ, не `503`.

## 10. Обычное обновление

Через панель: **Система → Обновление системы**. Пайплайн сам:

1. включает режим обслуживания (сайт/API — 503, админка открыта);
2. делает бэкап БД;
3. останавливает циклы;
4. удаляет старые системные файлы по `deploy/removals.json`;
5. копирует новый код;
6. запускает Composer с `COMPOSER_IPRESOLVE=4`;
7. ставит npm-зависимости с `NODE_ENV=development` и собирает фронтенд;
8. применяет все пропущенные миграции;
9. перезапускает службу;
10. фиксирует версию и снимает режим обслуживания.

Версия записывается **после** успешного перезапуска. Если перезапуск не удался,
обновление считается неуспешным, сайт остаётся в режиме обслуживания, циклы —
в `deploy.hold`.

Из SSH те же шаги выполняет `deploy.sh` в корне проекта (подробно —
[deploy.sh](deploy-sh.md)):

```bash
./deploy.sh --check    # проверить окружение
./deploy.sh            # обновить с подтверждением
```

## 11. Если обновление упало

Смотри:

```bash
tail -n 100 cms/debmes/restore.log
"$PHP_CLI" majordomo maintenance:status
systemctl status majordomo-mjdm_smart15_usr.service --no-pager -l
```

Дальше — по ошибке.

### `composer install` висит

IPv6 до Packagist/GitHub. Для старого кода добавь в PHP-FPM pool:

```ini
env[COMPOSER_IPRESOLVE] = 4
```

и перезагрузи FPM:

```bash
systemctl reload php8.3-fpm
```

Либо запусти вручную от пользователя сайта:

```bash
COMPOSER_IPRESOLVE=4 composer install \
  --no-dev --prefer-dist --no-interaction \
  --optimize-autoloader -vvv
```

### `vite: not found` (код 127)

`npm ci` отработал в production-режиме и не поставил devDependencies:

```bash
NODE_ENV=development npm ci --include=dev --no-audit --no-fund
NODE_ENV=development npm run build
test -x node_modules/.bin/vite && echo "vite OK"
```

### `status=203/EXEC`

В unit указан несуществующий PHP. Проверь:

```bash
systemctl cat majordomo-mjdm_smart15_usr.service
ls -l "$PHP_CLI"
"$PHP_CLI" -v
```

В `ExecStart=` должен быть `/usr/bin/php`, а не `php-fpm` и не отсутствующий
`php8.4`. Исправь unit и:

```bash
systemctl daemon-reload
systemctl restart majordomo-mjdm_smart15_usr.service
```

### После обновления сайт отдаёт 503

Проверь, что обновление действительно закончилось:

```bash
"$PHP_CLI" majordomo maintenance:status
"$PHP_CLI" majordomo cycles:status
```

Если всё в порядке:

```bash
"$PHP_CLI" majordomo maintenance:off
```

Не выключай maintenance, пока не выполнились миграции и сборка.

## 12. Где смотреть логи

```bash
journalctl -u majordomo-mjdm_smart15_usr.service -n 100 --no-pager
tail -n 100 cms/debmes/restore.log
tail -n 100 cms/debmes/errors.log
"$PHP_CLI" majordomo logs:tail errors --lines=100
```

## 13. Короткая шпаргалка

```bash
# root: один раз
"$PHP_CLI" majordomo systemd:set ... --apply --disable-old
"$PHP_CLI" majordomo systemd:give-permissions ...

# сайт: проверки
"$PHP_CLI" majordomo migrations:status
"$PHP_CLI" majordomo maintenance:status
"$PHP_CLI" majordomo cycles:status

# сайт: обновление вручную, если панель не может
COMPOSER_IPRESOLVE=4 composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
NODE_ENV=development npm ci --include=dev --no-audit --no-fund
NODE_ENV=development npm run build
"$PHP_CLI" majordomo migrations:run
"$PHP_CLI" majordomo maintenance:off
```

Подробнее про systemd и sudo — [systemd-sudo.md](systemd-sudo.md).
