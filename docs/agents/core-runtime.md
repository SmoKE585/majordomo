# Новое ядро: свойства, кеш и очереди

Переписанные службы находятся в `lib/core/`, namespace — `MajorDoMo\Core`.
`lib/core.class.php` подключает Composer (`vendor/autoload.php`, если установлен)
и сохраняет локальный autoloader для работы до первого `composer install`.
Composer загружает `MajorDoMo\Core\` через PSR-4 из `lib/core/`.
Старые библиотеки в `3rdparty/` подключаются прежним способом и не перенесены.
Службы получают зависимости через конструкторы; `Core` собирает их для старого загрузчика.

```php
use MajorDoMo\Core\Core;

$value = Core::objects()->value('Lamp.status');
Core::objects()->setValue('Lamp.status', 1, array('mqtt' => '0'), 'my_module');

$messages = Core::queue()->claim('my_module', 20, 60);
foreach ($messages as $message) {
    // Сначала выполнить операцию. Подтвердить только после успеха.
    Core::queue()->acknowledge($message);
    // При отказе: Core::queue()->release($message, 5).
}
```

## Совместимость

- `gg`, `sg`, `getGlobal`, `setGlobal`, `getObject`, `callMethod`, `callMethodSafe`,
  функции кеша и методы `objects` остаются фасадами с прежними сигнатурами.
- В пользовательском PHP-коде метода и валидации `$this` по-прежнему означает
  исходный объект MajorDoMo. Валидация может изменять `$value`.
- Сохраняются преобразование типов `gg`, обработчики, история, связанные модули,
  наследование и параметр `no_linked`.
- Старый `checkOperationsQueue()` сохраняет потребление при чтении. Надёжные
  потребители должны пользоваться `claim` / `acknowledge`; MQTT уже переведён.
- Сигнатура старых функций временно сохранена; TODO не означает разрешение
  удалить их до отдельной миграции пользовательских модулей.

## Службы

| Класс | Ответственность |
| --- | --- |
| `Objects`, `Properties`, `Methods` | Поиск объектов, чтение/запись свойств, разрешение наследования, вызов методов |
| `CodeExecutor` | Контекст выполнения пользовательского кода |
| `Database` | Доступ к существующей MySQL-обёртке без новых глобальных SQL-вызовов в службах |
| `Cache`, `MetadataCache`, `RedisConnection`, `RedisClient` | Значения, метаданные, соединение и восстановление после сетевого сбоя |
| `PropertyLock` | Сериализация записи одного свойства |
| `OperationsQueue`, `WebSocketQueue`, `OperationWorker` | Резервирование сообщений, подтверждение, повтор и доставка |
| `HistoryStorage`, `HistoryWriter` | Таблицы истории, транзакционная запись пачек и перенос данных |
| `Scheduler`, `ProcessRunner` | Владение задачей, результат, ограниченные повторы, отдельный PHP-процесс |
| `NetworkAccess` | IP/CIDR и доверенные reverse proxy |
| `Migrations` | Версионированное обновление схемы |

## Redis и отказы

Redis рекомендуется для нового ядра. Существующее `USE_REDIS` не включается
автоматически в рабочем `.env`; SQL-режим оставлен для совместимости.
В режиме Redis heartbeat, кеш и очереди используют Redis. История и актуальные
значения свойств остаются в SQL.

Ключи разделены по назначению и базе MajorDoMo: по умолчанию
`mjd:<hash DB_NAME>:...`. Для явного пространства имён можно задать
`REDIS_PREFIX`. Пространство нужно сохранять при обычном обновлении.
Очистка кеша использует `SCAN`, не вызывает `FLUSHDB` и не затрагивает очереди,
heartbeat или чужие ключи. Старые Redis-списки очередей импортируются атомарно.

При недоступности настроенного Redis запись не выдаёт ложный успех и не
переключается незаметно на второе независимое хранилище кеша. Повреждённое
соединение сбрасывается; повтор подключения ограничен паузой в две секунды.

Очередь обеспечивает повтор после истечения владения сообщением. Повтор
выполнения возможен, если обработчик сделал действие и завершился до
подтверждения; обработчики внешних действий должны быть идемпотентными.
Redis должен иметь настроенные persistence и резерв памяти: для очередей
нужен `noeviction`, а допустимая потеря последних записей определяется режимом
AOF/`appendfsync`. Код не меняет настройки рабочего Redis. Это не гарантия
exactly-once и не распределённая транзакция между Redis, SQL и устройством.

## Обновление схемы

Миграция запускается при обновлении/переустановке ядра и перед стартом циклов.
Применённая версия хранится в `core_migrations`; повторный запуск безопасен.
Для большой истории переход MyISAM → InnoDB может занять время и требует
свободного места. Штатное обновление предварительно создаёт резервную копию
БД и останавливает циклы. Миграции не запускаются на обычном `gg()`.

Миграция переводит горячие таблицы в InnoDB, добавляет индексы и поля владения
очередями/задачами. Дубли `pvalues` объединяются с сохранением последнего
значения, привязок и ссылок истории. Старые отдельные таблицы истории дублей
сохраняются как резервные копии; неизвестные поля сторонних модулей не удаляются.

У Connect удаляются регистрация, настройки подписчика, точные свойства цикла,
и принадлежащая только этому модулю таблица `public_calls` (владелец подтверждён
по старому `dbInstall`). Обновление удаляет остатки его файлов. Имена вроде
`connected` и `connectionStatus` не являются признаками принадлежности Connect.
Рабочий `.env` миграция не переписывает.

Внутренние асинхронные вызовы методов и связанных модулей больше не занимают
HTTP/FPM-процессы: их принимает `cycle_core.php`, выполнение изолировано в
`core_worker.php`. Методы и задания сохраняют отдельное PHP-окружение.
Неуспешные операции повторяются три раза; последние ошибки сохраняются в
`jobs.LAST_ERROR`, Redis `queue:failed:core_runtime` или `operations_failed`.

## Изолированные проверки

`tests/core_runtime_smoke.php` не загружает рабочий `.env` и создаёт
случайную тестовую БД. Требует `APP_ENV=testing`, явные loopback-порты тестовых
MariaDB/Redis и маркеры disposable-окружения. Кеш теста имеет отдельное
пространство; тест не вызывает `FLUSHDB`. На Windows без phpredis тестовый
RESP-клиент выполняет команды и Lua на настоящем изолированном Redis.

Пример создания disposable-сервисов (без production-томов):

```powershell
docker run -d --rm --name mjd-test-db -p 127.0.0.1::3306 --tmpfs /var/lib/mysql -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 mariadb:11
docker run -d --rm --name mjd-test-redis -p 127.0.0.1::6379 redis:7.4-alpine
docker exec mjd-test-db mariadb -e "CREATE DATABASE codex_mjd_test_guard; CREATE TABLE codex_mjd_test_guard.marker (TOKEN varchar(100)); INSERT INTO codex_mjd_test_guard.marker VALUES ('disposable-20261021');"
docker exec mjd-test-redis redis-cli -n 15 SET codex:mjd-test-sandbox disposable-20261021
docker port mjd-test-db
docker port mjd-test-redis
# Подставить выданные Docker порты:
$env:APP_ENV = 'testing'
$env:MJD_TEST_DB_PORT = '<db-port>'
$env:MJD_TEST_REDIS_PORT = '<redis-port>'
php tests/core_runtime_smoke.php
# Дополнительные режимы: MJD_TEST_SQL_CACHE=1 / MJD_TEST_SEPARATE_HISTORY=1 / MJD_TEST_USE_SEED=1.
```

Для реального HTTP-клиента есть локальная Postman simulation:

```powershell
postman simulation run docs/user/api/postman/simulations/core-api.sim.yaml --port 55559 --output ndjson
$env:MJD_TEST_SIM_URL = 'http://127.0.0.1:55559'
php tests/core_runtime_smoke.php
```

Контракт стенда основан на существующем `apiHandleResult` и HTTP-ошибках.
Проверяются JSON/HTML 500, таймаут, отказ соединения. Ничего не публикуется
в Postman workspace. Проверки доказывают поведение служб и настоящих отдельных
PHP-процессов на стенде, а не производительность или состояние рабочего сервера.
