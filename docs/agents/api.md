---
title: API ядра — разработка
audience: agent
category: development
tags: API, OpenAPI, ядро, совместимость
description: Общий диспетчер API, токены, проверка сетей, каталог операций и изолированные тесты.
---

# API ядра

Для агента-клиента: [инструкция по использованию HTTP API](api-client.md).
Пример nginx — `docs/deploy/nginx.conf.sample`: выделенный FastCGI location
сохраняет исходный URI, передаёт Authorization/X-API-Key/X-Forwarded-For,
не перехватывает JSON-ошибки PHP и возвращает JSON при лимите тела nginx.

`public/api.php` — только адаптер к `ApiApplication`. Последовательность:
config → InstallState → AccessPolicy → Request/JSON → loader/settings →
Core::apiDispatcher → Response. Ошибки и завершение legacy-кода перехватываются
на HTTP-границе: HTML и отладочный вывод не попадают в JSON. Секреты и тела
запросов не пишутся в аварийный журнал: используйте request_id.

`lib/core/Api/Contract.php` — источник маршрутов v1, схем валидации, описаний
панели и OpenAPI. Новую операцию добавляйте в каталог и Dispatcher; не заводите
независимый роутер/статическую копию документации. Публичная спецификация —
`/api/v1/openapi.json`; в панели доступна загрузка под доступом админки.
Формат соответствует [OpenAPI 3.0.3](https://spec.openapis.org/oas/v3.0.3.html).

`Dispatcher` не знает об HTTP-авторизации. В него можно войти только из уже
проверенного HTTP-входа или из доверенного PHP-кода. Флаг `internal`, заголовок,
имя хоста или query-параметр не дают привилегий. `ApiClient` относительные пути
обрабатывает локально, асинхронные операции кладёт в существующий core_runtime.
Новый тип задания `api` обрабатывает тот же OperationWorker. Исходные типы
`method` и `modulepropertyset` сохранены вместе с trace/chain.

`AccessPolicy` использует `NetworkAccess`, HOME_NETWORK, TRUSTED_PROXIES.
Если HOME_NETWORK пуст, допускаются частные сети IPv4/IPv6 и loopback. Если
задан — только перечисленные сети плюс loopback. XFF от недоверенного
соединения не доказывает локальность; доверенный прокси без достоверного
оригинального адреса тоже требует токен. Применяется и к legacy URL.

`TokenStore` работает через prepared mysqli/PDO-запросы: веб использует
соединение ядра, CLI — ленивое отдельное подключение без legacy loader,
тесты — SQLite. Список не читает TOKEN_HASH, секрет возвращается только при
issue/rotate. Ротация использует compare-and-swap по старому хешу, отзыв
не восстанавливает токен. Админские действия — POST + отдельный session CSRF.
Полный секрет приходит только AJAX-ответом, не сохраняется в session/URL.

Таблица api_tokens создаётся при установке раздела и основной миграцией
20261024_core_api_v1. Та же миграция переводит locations в InnoDB, чтобы
перестановка и удаление с обновлением привязок были транзакционными. В новом
install/schema.sql обе схемы присутствуют. Не запускайте миграции на рабочей
БД для проверки: тесты используют SQLite/фикстуры.

Совместимость legacy: /room и /rooms, setOrder, query/form, apiHandleResult,
setHandleResult, data/result, passed/timestamp. Внешние вызовы требуют токен.
В v1 изменение состояния не происходит через GET, исключение — произвольный
legacy API модуля, поведение которого определяет дополнение. Legacy выдаёт 400
для прежних ошибок поиска/валидации; v1 различает 404/405/422. Исправлены периоды
month/year (30 и 365 дней, без лишнего множителя 7).

Проверки: `php tests/api_smoke.php`, `php tests/api_http_smoke.php`, обычные
routing/config/console/template smoke, PHP lint, node --check и npm run build.
HTTP-фикстура запускается только на loopback и не загружает рабочую .env/БД.
