# Управление фоновыми циклами

Циклы — это процессы `scripts/cycle_*.php`, которыми владеет супервизор
`cycle.php`. Веб-запрос не может убить чужой дочерний процесс, поэтому все
действия идут через персистентное желаемое состояние, а не через прямой
`kill`.

## 1. Слои

```
CycleStateStore      cms/cycles/state.json — желаемое состояние и generation
CycleSupervisor      применяет состояние внутри cycle.php
CycleManager         публичный API для админки, X-Ray и настроек
Threads              запуск/остановка процессов (legacy, сохранён)
```

- `lib/core/Cycles/CycleStateStore.php` — атомарная запись JSON с `flock`,
  heartbeat супервизора, флаг `reload`.
- `lib/core/Cycles/CycleSupervisor.php` — `bootstrap()`, `reconcile()`,
  `shouldStartAtBoot()`, `reloadRequested()`. Не управляет процессами сам:
  только наполняет существующие очереди `$to_start` / `$to_stop`.
- `lib/core/Cycles/CycleManager.php` — `start`, `stop`, `restart`, `disable`,
  `enable`, `restartAll`, `requestReload`.
- `cycle.php` — читает состояние, останавливает отключённые циклы, перезапускает
  поколение (`generation`) и при `reload` завершает работу, чтобы systemd
  поднял его с новым `.env`.

## 2. Три состояния

| Состояние | Что делает супервизор | Ошибки |
| --- | --- | --- |
| `running` | держит процесс живым, auto-recovery как раньше | пишутся |
| `stopped` | останавливает процесс и **не** запускает до команды «Запуск» | не пишутся |
| `disabled` | останавливает процесс, не запускает при старте, скрывает из health-check | не пишутся |

`stopped` и `disabled` сохраняются в `cms/cycles/state.json` и переживают
перезапуск базы, веб-сервера и самого `cycle.php`. Это принципиальное отличие
от старого флага в MEMORY-таблице.

«Отключить» — не пауза: процесс завершается, цикл больше не стартует и не
создаёт `cycle_stop`/`cycle_hang` в «Ошибках системы». «Включить» возвращает
`running` и запускает процесс.

## 3. Команды и поколения

- `start` / `stop` / `disable` / `enable` пишут желаемое состояние и, для
  совместимости со старыми модулями, тот же `cycle_<name>Control`.
- `restart` увеличивает `generation`. Супервизор видит новое поколение,
  ставит процесс в `$to_stop`, а через несколько секунд — в `$to_start`.
  Это не зависит от доставки одного флага и не может «потерять» рестарт.
- `restartAll()` увеличивает поколение только у циклов в состоянии `running`:
  отключённые и остановленные администратором циклы не включаются.
- После сохранения настроек вызывается `restartAll()`, чтобы дочерние процессы
  перечитали `.env`. Если изменилось подключение к БД — `requestReload()`:
  супервизор останавливает все процессы и завершается, systemd запускает его
  заново с новыми credentials.

## 4. Совместимость со старыми модулями

- `cycle_<name>Control` (`start`/`stop`/`restart`) по-прежнему обрабатывается
  в `cycle.php`; старые модули MQTT и saverestore продолжают работать.
- `cycle_<name>Run` остаётся heartbeat-ом.
- `cycle_<name>Disabled` читается при старте: старая установка с этим флагом
  мигрирует в `disabled` в state-файле.
- `CycleManager::state()` отдаёт те же поля, что X-Ray использовал раньше,
  плюс `desired`, `disabled` и `generation`.
- `Threads::closeThread()` сначала отправляет `SIGTERM` и ждёт до 3 секунд,
  затем делает `kill -9` / `taskkill /F /T`. Это даёт циклам шанс закрыть
  файлы и очередь.

## 5. X-Ray

- Команды: `start`, `stop`, `restart`, `disable`, `enable`.
- Статус `disabled` — отдельный, с приглушённым индикатором; действия
  показывают только «Включить».
- JSON endpoint `ajax/xray.html?action=service` возвращает `STATUS`,
  `STATUS_TEXT`, `ONLINE`, `DISABLED`, `DESIRED`, `UPDATED`.
- Таблица X-Ray строится из `CycleManager::names()` (файлы
  `scripts/cycle_*.php`), а не из удаляемых свойств `ThisComputer`.

## 6. Проверка

```bash
php -l cycle.php
php -l lib/core/Cycles/*.php
php tests/settings_cycles_smoke.php
```

Вручную:

1. Открыть X-Ray → «Циклы».
2. «Отключить» — процесс исчезает, статус «Отключён», в «Ошибках системы» нет
   новой записи.
3. Перезапустить `cycle.php` — отключённый цикл не стартует.
4. «Включить» — цикл стартует.
5. «Рестарт» — процесс меняется, статус проходит `stopping → starting →
   running`.
6. Сохранить настройки — running-циклы перезапускаются, disabled/stopped
   остаются как были.

## 7. Ограничения

- Для полного перезапуска супервизора при смене БД нужен systemd
  (`Restart=always`, пример — `docs/deploy/majordomo.service.example`).
  Без него `cycle.php` остановит циклы и завершится; запускать вручную.
- State-файл не является очередью команд: он описывает **желаемое состояние**.
  Если процесс упал сам, auto-recovery остаётся в `cycle.php` и учитывает
  только `running`.
