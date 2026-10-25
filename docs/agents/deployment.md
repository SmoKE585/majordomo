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

Рабочее окружение — только Linux (Ubuntu). Windows допускается как локальная
машина разработчика, но не является платформой размещения системы. Службы,
управление процессами и производительность проверяются для Linux; совместимость
с особенностями Windows не должна усложнять рабочий код. Для таких тестов на
Windows используйте изолированный Linux-контейнер.

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
| `lib/core/Deploy/ArchiveDownloader.php` | скачивание архива: таймауты, IPv4-повтор, прогресс |
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

**Источник обновления** CLI берёт так же, как панель: `--url`, затем выбор в
«Обновлении системы» (`project_modules.DATA` модуля `saverestore` →
`MASTER_UPDATE_URL`), затем `.env`. Пока CLI читал только `.env`, `deploy.sh`
мог скачать другую ветку: в форке `master` — старая раскладка MajorDoMo
(docroot в корне, без `public/` и `bootstrap.php`), а текущий проект живёт в
`alpha`. Теперь такой архив отвергается до замены файлов, и то же самое
проверяет веб-путь (`saverestore::upload()`): чужое дерево поверх установки
не применяется ни из панели, ни из терминала.

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

## Загрузка архива

Архив скачивает `lib/core/Deploy/ArchiveDownloader` — один класс на веб-обновление
и `deploy:update`. Настройки подобраны под «плохие» хостинги:

- подключение — не больше 20 с (`CONNECTTIMEOUT`), вся загрузка — до 900 с;
- если скорость падает ниже 1 КБ/с на минуту, загрузка обрывается с ошибкой,
  а не висит до конца таймаута;
- при сбое повтор с принудительным IPv4 и HTTP/1.1 (частая причина зависания на
  `codeload.github.com`);
- только при TLS-ошибке — последняя попытка без проверки сертификата, с
  предупреждением в журнал (в системе может не быть корневых сертификатов);
- прогресс («Скачано 12,3 МБ из 24,1 МБ (51%)») идёт в журнал, поэтому окно
  обновления больше не «молчит» на этом шаге;
- после загрузки в журнал пишется диагностика: скорость, время соединения,
  время, проведённое в колбэке прогресса, IP и версия HTTP, а также прокси из
  окружения (`http_proxy`/`https_proxy` — curl берёт их неявно). Если «медленно»,
  по этим числам сразу видно, сеть это или вывод прогресса в браузер;
- файл пишется в `master.tgz.part` и переименовывается только после успеха.

Веб-путь пишет прогресс через `saverestore::outputProgress()` — 512 байт и
`flush()` вместо `echonow()` с 16 КБ паддинга и `DebMes` на каждую строку:
запись вывода из колбэка curl напрямую влияет на скорость передачи.

## Composer и IPv4

На хостингах, где IPv6 до Packagist/GitHub не отвечает, `composer install`
может надолго зависнуть на проверке lock-файла. Пайплайн запускает Composer с
`COMPOSER_IPRESOLVE=4`, `COMPOSER_HOME` и `GIT_TERMINAL_PROMPT=0`.

**Важно:** до 2026-10-23 эти переменные вычислялись, но не передавались
процессу — `composer install` шёл с обычными настройками и мог висеть на IPv6
или на запросе пароля Git. Теперь окружение передаётся явно.

Дополнительно пайплайн проверяет `composer install --help` и добавляет
`--no-audit` только если установленный Composer его поддерживает. В Composer
2.4–2.8 флаг отключает автоматический аудит, а в 2.9 аудит стал opt-in и флага
больше нет — иначе обновление начиналось бы с ошибки «option does not exist».

Для ручного запуска:

```bash
COMPOSER_IPRESOLVE=4 composer install \
  --no-dev --prefer-dist --no-interaction --optimize-autoloader -vvv
```

На Composer 2.4–2.8 можно добавить `--no-audit`; на 2.9 он не нужен.

Переменная действует только на Composer; системный IPv4/IPv6 не меняется.

## Ход выполнения в панели

Composer, npm и миграции печатают вывод построчно: `CommandRunner::run()` отдаёт
строки через колбэк, `Deployment` пересылает их в журнал обновления, а при
молчании дольше 20 с присылает пульс «выполняется N с». Поэтому «Применение»
больше не выглядит зависшим, даже когда Composer скачивает пакеты.

Если запрос всё-таки оборвался (таймаут php-fpm, обрыв прокси), фронт
`saverestore.js` замечает это по окончанию загрузки iframe-воркера или по
отсутствию событий и показывает ошибку с этапом, на котором всё встало, вместо
вечного «В процессе».

## npm и devDependencies

`npm ci` запускается с `NODE_ENV=development` и `--include=dev`: иначе на
FastPanel с `NODE_ENV=production` не устанавливается `vite`, и `npm run build`
падает с `sh: 1: vite: not found` (код 127). Устаревшую настройку
`npm_config_production` не выставляем: npm 11 ругается на неё и предлагает
`--omit=dev`; `--include=dev` важнее.

Сборка идёт без `reportCompressedSize`: деплою нужны файлы, а не gzip-цифры,
поэтому шаг «computing gzip size…» и лишнее сжатие крупных шрифтов убраны.

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
