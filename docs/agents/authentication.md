---
title: Авторизация и сессии
audience: agent
category: core
tags: авторизация, сессии, API, CSRF, пользователи
description: Единая граница доступа к админке, сессионный доступ к API, защита входа и отсутствие старого фронтенда.
---

# Авторизация и сессии

Админка — единственный UI. Вход `/admin.php?auth=login`, выход — только POST
на `/admin.php?auth=logout` с `_md_csrf`. Пользователь берётся из `users`,
войти может только `IS_ADMIN=1` с непустым паролем. Входа по HOST,
IS_DEFAULT, имени пользователя или параметру `prj` нет.

`AuthGate` выполняется до loader и работы модулей: в AdminApplication,
SiteApplication и непосредственно в loader для прямых PHP-входов. API имеет
собственную границу в ApiApplication. CLI не проходит HTTP-авторизацию.
Старый `/objects/` удалён; интеграции используют API v1, в том числе
`/api/v1/module/{name}`. Системный `scripts/` сохранён для CLI и не публикуется
через nginx или сервер разработки. `vendor/` и `packages/` также закрыты.

`Core::session()` возвращает `Http\Session`: get/set/has/forget/pull для
состояния админки, user() для проверенного пользователя, csrfToken(),
checkCsrf(), logout(). Состояние хранится нативным PHP-массивом; публичного
`data`, сериализованного DATA и старого класса `session` больше нет.
Глобальная переменная `$session` сохраняется как ссылка на сервис ядра.
Идентичность находится отдельно от состояния модулей и не устанавливается
через get/set. В HTTP-параметрах запрещён импорт `_SESSION`, session, db
и суперглобалов в `$GLOBALS`.

Cookie `prj`: HttpOnly, SameSite=Strict, только cookie, strict mode, срок
до закрытия браузера. При входе/выходе меняются ID и CSRF. Secure определяется
по HTTPS либо X-Forwarded-Proto от доверенного прокси. Loopback
(`127.0.0.1`, `::1`) доверенный всегда; `TRUSTED_PROXIES` только добавляет
внешние прокси. Хранилище — `var/sessions`, вне document root и
legacy-публичных каталогов. Старые сессии не переносятся: после обновления
требуется войти заново.

Срок: час без запросов, абсолютный максимум 12 часов. На каждом запросе
проверяются наличие пользователя, IS_ADMIN и fingerprint пароля/логина.
Смена пароля через Users или CLI, удаление и снятие прав отзывают доступ
в админку и сессионный доступ к API. Клиентские AJAX-ответы 401 переводят
браузер на вход, partLoad получает JSON с NEED_RELOAD.

`Passwords` принимает старый SHA-512 и обновляет его после успешного входа
через compare-and-swap. Новые пароли: `mdm1:` + password_hash от base64 SHA-384
пароля; это сохраняет прежний предел 1024 байта без усечения bcrypt на 72 байтах.
Установщик, Users и CLI используют один механизм. Не хешировать эти значения
повторно при dbInstall модуля.

API: явный Bearer/X-API-Key проверяется первым, затем cookie администратора,
затем старое правило HOME_NETWORK. Явный неверный токен или просроченная
переданная cookie не откатываются к другому способу. Запросы без cookie
сохраняют прежние правила LAN/токена. Для сессионных POST/PUT/PATCH/DELETE
обязателен X-CSRF-Token; GET legacy API тоже защищён от другого origin.
Обычные HTTP API-токены не создаются при входе и не зависят от сессии.

Общий `05_auth.js` добавляет X-CSRF-Token из meta md-csrf-token к fetch/XHR
того же origin и `_md_csrf` к формам. Для старых форм/iframe допускается
подтверждённый same-origin контекст; межсайтовые и same-site с другого origin
запросы запрещены. Для legacy GET с действиями тоже нужен same-origin
контекст либо CSRF. Ссылки на главную панель доступны как обычная навигация.

Три ошибки с одного IP за 15 минут блокируют этот IP на 15 минут. Счётчик
общий для cookies/логинов и PHP-воркеров: ограниченный файл
`var/security/login-attempts.json` под flock. При блокировке публикуется
`AUTH_BRUTE_FORCE` с IP, логином, числом ошибок и сроком блокировки;
повторные заблокированные запросы событие не дублируют. До loader событие
сохраняется через EventBus::defer(), затем публикуется cycle_core или
авторизованной админкой. XFF учитывается только от
TRUSTED_PROXIES. Пароли, хеши и cookie не журналируются. События пишутся
в стандартный `<LOG_DIRECTORY|cms/debmes>/YYYY-MM-DD/auth.log`, доступны
в X-Ray и через `php majordomo logs:tail auth`. Снятие блокировки:
`php majordomo auth:unblock <ip>`; запускайте CLI от пользователя PHP,
чтобы сохранялись права на приватные runtime-каталоги.

Старый application и фронтенд-шаблоны/виджеты удалены. Модули рендерят только
admin; не возвращать usual(), action_usual или автоматический выбор пользователя.
`index.php` оставлен для временных админских AJAX (включая /ajax/*.html,
getlatestnote и ajaxgetglobal/ajaxsetglobal), защищённых AuthGate. Новые
операции добавляются в API. Корень `/` направляет в `/panel/`.

Проверки: `auth_smoke.php`, `auth_http_smoke.php --ui`, routing/config,
api/api_http, console/console_users, install, system_helpers и template smoke.
Auth-тесты используют временные каталоги, SQLite и loopback HTTP без рабочей .env.
