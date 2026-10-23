---
title: Деплой и обновление системы
audience: agent
category: agents
tags: деплой, миграции, removals, maintenance, systemd, composer, npm
description: Пайплайн системного обновления, файловые миграции, безопасное удаление файлов, режим обслуживания и управление systemd.
order: 30
---

# Деплой и обновление системы

## Что это

Единый пайплайн системного обновления: режим обслуживания, пауза циклов,
удаление выведенных файлов, замена кода, `composer install`, `npm run build`,
миграции, health-check и перезапуск systemd-службы.

Файловых бэкапов нет: перед обновлением сохраняется только БД. Это осознанное
решение владельца проекта; страховка от нерабочей автозагрузки — preflight и
health-check, а не копия `vendor`.

## Слои

| Класс | Ответственность |
| --- | --- |
| `lib/core/Deploy/CommandRunner.php` | запуск команд без shell, таймауты, обрезка вывода |
| `lib/core/Deploy/Deployment.php` | preflight, Composer, npm, миграции, health-check, состояние |
| `lib/core/Deploy/FileRemovals.php` | безопасное удаление по `deploy/removals.json` |
| `lib/core/Deploy/ServiceManager.php` | пользователь сайта, PHP, unit, wrapper, `systemctl` |
| `lib/core/Deploy/SystemdUnit.php` | рендер unit/wrapper/sudoers без записи |
| `lib/core/Deploy/SystemdSetup.php` | установка unit и sudoers, backup конфигов ОС |
| `lib/core/Deploy/UpdateSource.php` | Atom-лента, ветка и архив ровно одного коммита |
| `lib/core/Deploy/SystemUpdate.php` | пайплайн без HTTP: проверки, дамп, архив, замена, зависимости, рестарт |
| `lib/core/Maintenance/` | маркер `cms/maintenance.json`, gate и страница 503 |
| `lib/core/Migrations/` | файловые миграции и `Migrator` |
| `lib/core/Cycles/` | пауза обновления, hold циклов, поколения |

Два входа в один и тот же порядок шагов: веб-модуль `saverestore`
(`mode=getlatest` → `update_iframe.php`) и CLI
`php majordomo deploy:update` (обёртка — `deploy.sh` в корне). Второй нужен,
когда HTTP-запрос не доживает до конца обновления. Артефакты общие:
`cms/saverestore/update.pending.json`, `cms/saverestore/system_update_manifest.json`,
`cms/deploy/last.json`, `cms/maintenance.json`, `cms/cycles/deploy.hold`.
CLI не пишет версию в конфиг модуля сам: pending-файл со статусом `applied`
восстанавливает запись при первом открытии панели (`reconcilePendingUpdate()`).
Подробности — [deploy.sh](../deploy/deploy-sh.md).

## Порядок

1. `collectSystemUpdatePreparationIssues()` вызывает preflight деплоя.
2. `saverestore::upload()` включает `MaintenanceMode`.
3. `CycleMaintenance` останавливает все циклы и ждёт подтверждения.
4. `FileRemovals` применяет `deploy/removals.json`.
5. `removeFilesDeletedFromSystemUpdate()` удаляет файлы, которые были в старом
   манифесте, но исчезли из нового архива.
6. Код копируется.
7. `Deployment::composerInstall()`, `verifyAutoload()`, `buildAssets()`,
   `runMigrations()`.
8. Устаревшие маркеры модулей, манифест, события обновления.
9. `CycleMaintenance::finish()`, перезапуск службы (веб) или reload (авто).
10. Снятие режима обслуживания, `cms/deploy/last.json`.

Версия (`LATEST_UPDATED_ID`, ветка, `LatestUpdate*`, событие `system_updated`)
фиксируется только после успешного перезапуска/reload. Если служба не поднялась,
версия не записывается, а обновление считается неуспешным.

Версия привязана к архиву, а не к ленте: `getLatest()` заново читает ленту,
берёт последний коммит и скачивает архив ровно этого коммита
(`getArchiveURLForCommit()`), поэтому содержимое файлов и записанный SHA
совпадают. Поддерживаются GitHub, GitLab и Gitea (ветка или `refs/heads/...`).
Источник, который не умеет отдать архив по коммиту (например, статический
`update.tar.gz`), обновление не начинает — иначе версию пришлось бы угадывать.

Перед заменой файлов `getLatest()` пишет `cms/saverestore/update.pending.json`
(коммит, ветка, SHA-1 архива). После замены файлов и зависимостей статус
меняется на `applied`, после успешного рестарта — запись в конфиг и удаление
файла. Если запрос умер во время рестарта, `reconcilePendingUpdate()` при
следующем открытии панели или проверке обновлений восстановит версию из
`applied`-записи; `downloaded` и `failed` не применяются. Подменённый
`master.tgz` отвергается по несовпадению SHA-1.

После записи версии сбрасывается кэш ленты обновлений (`clearURLCache()`),
иначе старый ответ показывал бы «есть обновление» ещё до получаса.

## Composer и IPv4

На хостингах, где IPv6 до Packagist/GitHub не отвечает, `composer install`
может надолго зависнуть на проверке lock-файла. Пайплайн запускает Composer с
`COMPOSER_IPRESOLVE=4`. Для ручного запуска:

```bash
COMPOSER_IPRESOLVE=4 composer install \
  --no-dev --prefer-dist --no-interaction --optimize-autoloader -vvv
```

Переменная действует только на Composer; системный IPv4/IPv6 не меняется.

## npm и devDependencies

`npm ci` запускается с `NODE_ENV=development`, `NPM_CONFIG_PRODUCTION=false` и
`--include=dev`: иначе на FastPanel с `NODE_ENV=production` не устанавливается
`vite`, и `npm run build` падает с `sh: 1: vite: not found` (код 127).

Для ручной сборки:

```bash
NODE_ENV=development npm ci --include=dev --no-audit --no-fund
NODE_ENV=development npm run build
```

## Ошибки

- До замены файлов: циклы не трогали, режим обслуживания выключается.
- После замены файлов: режим обслуживания остаётся, `cms/cycles/deploy.hold`
  удерживает циклы остановленными, событие `system_update_failed`.
- Исправление: `composer install`, `migrations:run`, затем
  `maintenance:off` (он снимает hold) или `cycles:start <name>`.

Автообновление запускается внутри `cycle.php`. Ему нельзя вызывать
`systemctl restart`: systemd остановит всю cgroup вместе с updater’ом. Вместо
этого после успешных шагов ставится reload-флаг, `cycle.php` завершается, а
systemd поднимает его заново по `Restart=always`.

## Миграции

- файлы `lib/core/Migrations/YYYY_MM_DD_NNNNNN_name.php`;
- каждый возвращает анонимный класс — наследник `Migrations\Migration`;
- версия хранится в `core_migrations`, старые строки не меняются;
- `Migrations::run()` сканирует каталог и применяет все отсутствующие версии,
  включая пропущенные;
- ошибка не пишет версию, повторный запуск повторяет миграцию;
- `php majordomo migrations:status` и `migrations:run`.

## Удаление файлов

`deploy/removals.json`:

```json
{
  "files": ["objects/index.php"],
  "directories": [{"path": "modules/old", "recursive": true}],
  "patterns": ["templates/classes/views/S*.html"]
}
```

- удаляются только точные пути и совпадения pattern;
- `cms/`, `backup/`, `database_backup/`, `vendor/`, `node_modules/`, `.git/`,
  `.env`, `config.php`, `composer.json/lock` защищены;
- symlink удаляется как ссылка, цель не разыменовывается;
- старый манифест `cms/saverestore/system_update_manifest.json` удаляет только
  файлы, поставленные предыдущим обновлением; пользовательские папки не
  сравниваются с git.

## Права

Служба должна работать от пользователя сайта, а веб-деплой — иметь право
писать в `vendor`, `public/assets`, `cms`, `node_modules`. Для systemd:

```bash
sudo php majordomo systemd:set --apply --disable-old
sudo php majordomo systemd:give-permissions
```

Подробная инструкция — [systemd, sudo и обновление](../deploy/systemd-sudo.md).

## Проверки

```bash
php tests/migrations_smoke.php
php tests/file_removals_smoke.php
php tests/maintenance_smoke.php
php tests/systemd_smoke.php
php tests/deploy_smoke.php
php tests/settings_cycles_smoke.php
php tests/cycle_maintenance_smoke.php
```

Тесты не требуют рабочей БД и root; systemd проверяется на уровне рендера
unit/wrapper/sudoers.
