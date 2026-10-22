---
title: Systemd, sudo и обновление MajorDoMo
audience: user, agent
category: deploy
tags: systemd, sudo, fastpanel, деплой, maintenance
description: Установка systemd-службы от пользователя сайта, passwordless sudo для управления и обновление MajorDoMo.
order: 20
---

# Systemd, sudo и обновление MajorDoMo

Инструкция для установки на Linux с FastPanel: служба systemd, права на
перезапуск без пароля, обновление с Composer и npm, режим обслуживания.

## 1. Вводные

- проект: `/var/www/mjdm_smart15_usr/data/www/mjdm.smart155.ru`;
- пользователь сайта: `mjdm_smart15_usr`;
- PHP: `/usr/bin/php8.4`;
- служба: `majordomo-mjdm_smart15_usr.service`;
- старый юнит `majordomo.service` работает без `User=`, то есть от root.
  Новый юнит запускает `cycle.php` от пользователя сайта.

## 2. Один раз: новый юнит

```bash
cd /var/www/mjdm_smart15_usr/data/www/mjdm.smart155.ru

sudo php majordomo systemd:set \
  --service=majordomo-mjdm_smart15_usr \
  --user=mjdm_smart15_usr \
  --php=/usr/bin/php8.4 \
  --apply \
  --disable-old \
  --fix-owner
```

Что произойдёт:

1. прочитается текущий `majordomo.service`, сохранится `ExecStartPre` с ожиданием MySQL;
2. создастся `/etc/systemd/system/majordomo-mjdm_smart15_usr.service` с
   `User=mjdm_smart15_usr`, `WorkingDirectory` и `ExecStart=php cycle.php`;
3. `cms`, `vendor`, `public/assets`, `node_modules` получат владельца сайта
   (`--fix-owner`);
4. старый юнит будет остановлен и отключён только при `--disable-old`;
5. новый юнит включится и запустится.

Проверка:

```bash
systemctl status majordomo-mjdm_smart15_usr.service
systemctl is-enabled majordomo-mjdm_smart15_usr.service
```

Если что-то пошло не так, рядом с юнитом останется резервная копия
`majordomo-mjdm_smart15_usr.service.bak.<дата>`, а старый `majordomo.service`
будет запущен обратно.

## 3. Один раз: управление без пароля

```bash
sudo php majordomo systemd:give-permissions \
  --service=majordomo-mjdm_smart15_usr \
  --user=mjdm_smart15_usr
```

Создаются:

- `/usr/local/sbin/majordomo-mjdm_smart15_usr` — wrapper только для
  `restart|start|stop|status|is-active`;
- `/etc/sudoers.d/majordomo-mjdm_smart15_usr` — одна строка `NOPASSWD` для
  пользователя сайта.

Проверка:

```bash
sudo -u mjdm_smart15_usr sudo -n \
  /usr/local/sbin/majordomo-mjdm_smart15_usr status
```

Пользователь сайта **не получает** произвольный `systemctl`: wrapper принимает
только пять команд и всегда работает с одним unit’ом. Это важно: иначе
`sudo systemctl` с произвольными аргументами фактически равен root.

## 4. Настройки деплоя

В `.env`:

```dotenv
DEPLOY_COMPOSER=composer
DEPLOY_NODE=node
DEPLOY_NPM=npm
DEPLOY_ASSETS=always
DEPLOY_SERVICE=majordomo-mjdm_smart15_usr
DEPLOY_RESTART=1
DEPLOY_TIMEOUT=900
```

- `DEPLOY_ASSETS=always` — `npm run build` при каждом обновлении;
- `auto` — `npm ci` при изменении `package-lock.json`, `npm run build` при
  каждом обновлении; если npm нет, используется уже собранный бандл;
- `never` — не собирать (на сервере должен быть готовый `public/assets/admin`).

Node.js — 24 или новее. Composer должен быть доступен пользователю сайта или
через `DEPLOY_COMPOSER=/полный/путь/composer`.

## 5. Проверка обновления

Перед запуском:

```bash
php majordomo migrations:status
php majordomo maintenance:status
```

После обновления:

```bash
php majordomo migrations:status
php majordomo maintenance:status
systemctl status majordomo-mjdm_smart15_usr.service
```

Обновление само:

1. включает режим обслуживания;
2. делает бэкап только БД;
3. останавливает циклы;
4. удаляет файлы из `deploy/removals.json` и старый манифест;
5. копирует новый код;
6. запускает `composer install`;
7. проверяет `Bluerhinos\phpMQTT` и PHPMailer;
8. собирает фронтенд;
9. применяет все пропущенные миграции;
10. перезапускает службу и снимает режим обслуживания.

Файловых бэкапов нет — только БД.

## 6. Режим обслуживания

```bash
php majordomo maintenance:on --reason=manual --ttl=3600 --message="Технические работы"
php majordomo maintenance:status
php majordomo maintenance:off
```

Во время режима:

- сайт и API отвечают `503` с `Retry-After`;
- админка, вход и `/assets/` остаются доступны;
- маркер `cms/maintenance.json` переживает перезапуск службы.

При ошибке обновления режим **не снимается**, а циклы удерживаются файлом
`cms/cycles/deploy.hold`. Это защищает от MQTT-ошибок каждые пять секунд на
полуобновлённом коде.

## 7. Если обновление упало

Сначала посмотрите журнал:

```bash
tail -n 100 cms/debmes/restore.log
php majordomo maintenance:status
systemctl status majordomo-mjdm_smart15_usr.service
```

Типовые шаги восстановления:

```bash
cd /var/www/mjdm_smart15_usr/data/www/mjdm.smart155.ru

sudo -u mjdm_smart15_usr composer install \
  --no-dev --prefer-dist --no-interaction --optimize-autoloader

sudo -u mjdm_smart15_usr php majordomo migrations:run

php majordomo maintenance:off
```

`maintenance:off` также снимает hold циклов. Если нужно поднять только один
цикл, используйте `php majordomo cycles:start mqtt`.

Если Composer не может писать в `vendor`, проверьте владельца:

```bash
sudo chown -R mjdm_smart15_usr:mjdm_smart15_usr vendor cms public/assets
```

## 8. Откат на старый юнит

```bash
sudo systemctl disable --now majordomo-mjdm_smart15_usr.service
sudo systemctl enable --now majordomo.service
```

Не запускайте два юнита одновременно: оба будут держать `cycle.php` и создадут
дубли WebSocket/MQTT-подключений.

## 9. Безопасность

- `sudoers` содержит только wrapper, а не `systemctl` целиком;
- wrapper принадлежит `root:root`, режим `0755`;
- `sudoers` проверяется `visudo -cf` и имеет режим `0440`;
- служба работает от пользователя сайта, не от root;
- секреты `.env` не попадают в юнит и не передаются через `Environment`.
