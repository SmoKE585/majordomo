---
title: Системные PHP-функции MajorDoMo
audience: user, agent
category: data
tags: функции, PHP, сценарии, helpers, setGlobal, getGlobal, sg, gg, cm, API, режимы, привязки
description: Справочник встроенных функций: свойства, методы, сценарии, таймеры, события, история, кэш, файлы, режимы, привязки и совместимость модулей.
order: 30
---

# Системные PHP-функции MajorDoMo

## Коротко

Справочник публичных PHP-функций MajorDoMo: что вызывать из сценариев, методов
и модулей, какие параметры и результаты ожидать. Открывайте, когда нужна
готовая операция вместо прямого SQL, и сверяйтесь перед изменением общего API.

Справочник для авторов сценариев, модулей и агентов. Здесь перечислены 272 функции общего PHP API с точными именами, параметрами, значениями по умолчанию и описанием. Они доступны в штатном окружении MajorDoMo после загрузки библиотек. Код в редакторе сценариев вводится без открывающего тега PHP.

## Быстрые примеры

```php
// Читать и записывать свойства через систему.
$status = getGlobal('Lamp.status');
$result = setGlobal('Lamp.status', 1);
// Короткие имена равнозначны: gg() и sg().

// Синхронно выполнить метод или сценарий и получить его результат.
$result = callMethod('Lamp.turnOn', array('level' => 50));
$result = runScript('Evening', array('room' => 'Kitchen'));
// cm()/runMethod() — алиасы callMethod(), callScript()/rs() — runScript().

// Повторный вызов с тем же именем заменяет таймер.
$id = setTimeOut('Kitchen.off', "callMethod('KitchenLight.turnOff');", 60);
$pendingId = timeOutExists('Kitchen.off');
$removed = clearTimeOut('Kitchen.off');

// Последние 60 минут истории свойства.
$history = getHistory('Sensor.temperature', -3600);
$average = getHistoryAvg('Sensor.temperature', -3600);
```

`setGlobal()` проходит через системную запись свойства: обработчики `ONCHANGE`, привязки модулей, кэш, аудит и уведомления выполняются по правилам ядра. Прямой `UPDATE pvalues` обходит этот механизм. Для объектов, методов, истории, таймеров, событий и кэша также используйте соответствующий общий API. В ядре доступны существующие сервисы `MajorDoMo\Core\Core::*`.

Имена без объекта относятся к `ThisComputer`; полное имя обычно имеет вид `Object.property` или `Object.method`. Не путайте ID записи значения, ID свойства и имя свойства. Параметры `setGlobal()` `no_linked` и `source` передаются ядру без изменений; подавление привязок включайте только осознанно, см. [привязки свойств](../agents/property-bindings.md).

Уведомления модулей: `Core::notifications()->send($module, $message, $type, $options)`
и прежние методы `$this->sendNotification()` / `$this->readNotification()`.
Непрочитанные повторы не сохраняются и не логируются; расширенные поля
описаны в [уведомлениях модулей](module-notifications.md).

## Результаты и совместимость

Синхронные обёртки возвращают исходное значение исполнителя без приведения к bool: `false`, `0`, пустая строка, массив и `null` могут быть допустимым результатом. Проверяйте их строго. Для получения данных добавьте в код явный `return`. Отсутствие `return` не гарантирует `null`: у исполнителя методов есть правила наследования результата, описанные ниже.

Явный `false` метода означает отказ. При наследовании такой отказ на любом
уровне останавливает дальнейшее выполнение: дочерний `return true` не маскирует
ошибку родителя. Остальные значения, включая `0`, не считаются отказом.

`setGlobal()`/`sg()` при успешной записи возвращают `null`, при отклонённой валидации — `false`, а если объект не найден — `0`. Это результат сервиса записи, а не подтверждение того, что `ONCHANGE` или модульный обработчик уже выполнились: они идут через очередь.

Значение, история и задания обработчиков сохраняются одной транзакцией SQL.
Отказ записи задания откатывает изменение и передаётся исключением. При
включённом Redis исполнение очереди остаётся в Redis; SQL сохраняет задания
до подтверждения. Подробнее — [надёжная доставка](../agents/runtime-delivery.md).

`runScript()`, `callScript()` и `rs()` теперь одинаково передают результат сценария. Сохранено различие параметров по умолчанию: у `runScript`/`callScript` это пустая строка, у `rs` — `0`. `clearScheduledJob()`, `deleteScheduledJob()` и `clearTimeOut()` возвращают результат удаления; `processCommand()` передаёт результат вызванного метода, если вызов состоялся. Раньше некоторые обёртки теряли результат. Это намеренное изменение: код, специально сравнивавший их результат с `null`, нужно проверить.

Фоновая операция не возвращает будущий результат: ID задания, статус отправки или ответ постановки в очередь не подтверждают завершение. Суффикс `Safe` — историческое имя механизма выполнения, а не гарантия синхронности, валидации или авторизации. Выбирайте синхронную функцию, когда нужен непосредственный результат.

Сохранены имена функций и параметров (включая исторические опечатки), порядок аргументов, значения по умолчанию и передача по ссылке. Алиасы не удалены. Устранено предупреждение при отсутствии таймера. В `mysort_array()` исправлено сравнение: учитывается любой положительный результат `strcmp`, равные элементы сохраняют порядок; вместо квадратичного обмена используется штатная сортировка.

В `genPassword()` несовместимый с PHP 8 вызов `crypt()` без соли заменён генерацией через `random_int()`. Формат a–z/0–9 и длина по умолчанию сохранены; отрицательная длина явно отклоняется.

## Наследование методов: параметры и return

Для метода объекта можно выбрать вызов родителя до кода объекта, после него
или отключить вызов родителя (`CALL_PARENT`: `1`, `2` и `0` соответственно).
Эти же правила применяются к наследованию методов классов.

### Передача данных между родителем и объектом

Уровни одного вызова наследования используют общий `$params`: добавление,
изменение, удаление ключей и замена массива видны следующему уровню. Например,
при выборе «Родитель до кода объекта» в родителе:

```php
$params['brightness'] = 50;
$params['NEW_VALUE'] = 1;
```

В коде метода объекта эти значения уже доступны:

```php
$brightness = $params['brightness'];
$newValue = $params['NEW_VALUE'];
```

Обычная локальная переменная `$brightness`, объявленная только в родителе,
автоматически в код объекта не передаётся: у каждого метода своя область
локальных переменных. Для временных данных текущего вызова используйте
`$params`; записывать их в свойства объекта или глобальные переменные не нужно.

Если родитель выполняется после объекта, он видит изменения `$params`,
сделанные объектом. Через несколько уровней классов параметры передаются
таким же образом. Обычный массив аргументов внешнего `callMethod()` передаётся
по значению: совместное изменение параметров относится к уровням наследования,
а не к независимому вложенному вызову другого метода.

При вызове родителя до объекта его результат также доступен в коде объекта
как `$parent_success`. Несмотря на имя, это может быть число, строка или
массив, а не только логическое значение.

### Что останавливает выполнение

- `return;` или `return null;` завершает только код текущего метода.
  Дочерний метод автоматически не отменяется.
- `return true;`, `return 0;`, `return '';` или возврат массива тоже
  не запрещает выполнение дочернего метода. Для остановки не используется
  проверка «пустого» значения: только строгий `false` означает отказ.
- `return false;` означает ошибку выполнения. Если родитель вызван до объекта,
  код объекта не запускается. Отказ объекта также не запускает родителя,
  настроенного на выполнение после объекта.
- Исключение прерывает выполнение и передаётся вызывающему коду.

В фоновой очереди `false` приводит к штатным повторам операции и регистрации
ошибки. Не используйте его как обычный выход по условию, если повторять
автоматизацию не нужно. Отдельной штатной команды «успешно остановить всю
цепочку наследования» сейчас нет.

Если код метода завершился без результата либо вернул `null`, исполнитель
возвращает результат родителя, а при отсутствии его вызова — `true`.
Поэтому `callMethod()` после обычного `return;` может вернуть `true`, а не `null`.
Явный результат, отличный от `null`, сохраняется; отказ любого уровня
наследования не маскируется успешным результатом другого уровня.

Когда родитель настроен после объекта, отказ родителя не отменяет уже
выполненные действия объекта. Наследование методов не является транзакцией.

## Где находятся функции и как их добавлять

Реализации находятся в `lib/core/Helpers/` и разбиты по назначению. Единая точка подключения — `lib/core/Helpers/bootstrap.php`; её вызывает штатный `lib/loader.php`. Она регистрирует функции, но не заменяет загрузку конфигурации, соединения БД и настроек приложения. Не подключайте её через Composer `autoload.files` до инициализации окружения.

Старые пути (`lib/objects.class.php`, `lib/common.class.php`, `lib/syncfiles.class.php` и остальные) сохранены как адаптеры. Старые модули продолжают использовать `include_once`/`require_once`. HTTP-инициализация и условная legacy-функция `stripit(&$a)` остаются в `lib/general.class.php`; класс `xml_data` — в `lib/xml_data.class.php`. Не подключайте отдельную реализацию helpers поверх штатного boot.

Агентам: перед SQL к системным таблицам найдите операцию в справочнике и сервисах ядра. Недостающую операцию добавляйте в существующий сервис и подходящую группу helpers, если она нужна сценариям и модулям. Новую группу явно подключайте в `Helpers/bootstrap.php`. Внутри слоя хранения и для собственных таблиц модуля SQL допустим. Справочник обновляется вместе с API; контракт и старые подключения проверяет `php tests/system_helpers_smoke.php`.

Здесь собраны общие функции, ранее распределённые по 19 библиотекам `lib/`. Частные функции роутинга, Markdown, обработчиков PHP-ошибок, шаблонного postprocess и конкретных модулей остаются рядом с соответствующей подсистемой. Legacy-заглушки и ограничения отмечены отдельно: они сохранены для совместимости.

## Каталог функций

В таблицах приведена полная PHP-сигнатура. `&` означает изменение аргумента по ссылке, `...` — переменное число аргументов. У служебных процедур без результата возвращается `null` или объявленный `void`.

### Объекты, классы, свойства и методы

Реализация: `lib/core/Helpers/Objects.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function addClass($class_name, $parent_class = '')` | Возвращает ID существующего класса либо создаёт класс, при необходимости вместе с родительским. |
| `function addClassMethod($class_name, $method_name, $code = '', $key = '')` | Добавляет или обновляет метод класса; key позволяет заменить именованный фрагмент кода. Возвращает ID метода. |
| `function addClassObject($class_name, $object_name, $system = '')` | Возвращает ID существующего объекта либо создаёт объект указанного класса. |
| `function addClassProperty($class_name, $property_name, $keep_history = 0)` | Возвращает ID свойства класса, создавая его при отсутствии; keep_history задаёт хранение истории при создании. |
| `function callMethod($method_name, $params = 0)` | Синхронно вызывает метод объекта и возвращает его результат. |
| `function callMethodSafe($method_name, $params = 0)` | Вызывает метод через механизм безопасного выполнения ядра; результат зависит от способа исполнения. Не является проверкой прав доступа. |
| `function cm($method_name, $params = 0)` | Алиас callMethod с тем же результатом. |
| `function deleteObject($object_id)` | Удаляет объект через модуль объектов вместе с предусмотренной модулем очисткой данных. |
| `function getClassProperties($class_id, $def = '')` | Возвращает свойства класса с наследованием и переопределениями; использует кэш в пределах процесса. |
| `function getClassPropertiesBatch($class_id)` | Возвращает свойства класса с наследованием пакетной загрузкой метаданных. |
| `function getClassTemplate($class_id, $view = '')` | Возвращает шаблон представления класса с поиском у родителя и стандартным шаблоном при отсутствии файла. |
| `function getGlobal($varname)` | Читает значение свойства через объектный API и кэш; имя без объекта относится к ThisComputer. Возвращает значение либо false. |
| `function getKeyData($object_id)` | Формирует текстовое описание объекта по свойствам с DATA_KEY. |
| `function getObject($name)` | Возвращает экземпляр объекта по имени; если объект не найден — 0. |
| `function getObjectClassTemplate($object_name, $view = '')` | Возвращает шаблон класса указанного объекта и представления. |
| `function getObjectsByClass($class_name)` | Возвращает записи объектов класса и его дочерних классов. |
| `function getObjectsByClassCollectIds($class_id, $children_by_parent, $visited = array())` | Внутренний обход дерева классов для getObjectsByClass с защитой от циклических ссылок. |
| `function getObjectsByProperty($property_name, $condition = '', $condition_value = '')` | Ищет объекты по имени свойства и условию; возвращает список имён. Если нет записей значения свойства — legacy-результат 0. |
| `function getRoomObjectByLocation($location_id, $auto_add = 0)` | Возвращает имя объекта помещения; auto_add разрешает создать объект при отсутствии. |
| `function getUserObjectByTitle($user_id, $auto_add = 0)` | Возвращает имя объекта пользователя по ID пользователя; auto_add разрешает создание. |
| `function getValueIdByName($object_name, $property)` | Возвращает ID записи значения по имени объекта и свойства. |
| `function gg($varname)` | Алиас getGlobal с тем же результатом. |
| `function injectObjectMethodCode($method_name, $key, $code)` | Добавляет или заменяет именованный фрагмент кода метода объекта. |
| `function objectClassChanged($object_id)` | Согласует свойства объекта с новым классом после смены класса. |
| `function processTitle($title, $object = 0)` | Подставляет значения свойств и специальные выражения в строку заголовка. |
| `function returnTypedValue($value)` | Приводит числовые строки к int/float; сохраняет строки с ведущим нулём и обычный текст. |
| `function rs($script_id, $params = 0)` | Алиас runScript; возвращает результат. Собственное значение params по умолчанию — 0. |
| `function runMethod($method_name, $params = 0)` | Алиас callMethod с тем же результатом. |
| `function setGlobal($varname, $value, $no_linked = 0, $source = '')` | Записывает свойство через объектный API: обработчики, привязки, кэш и аудит проходят штатный путь. При успехе возвращает `null`, при отклонённой валидации — `false`, если объект не найден — `0`. |
| `function sg($varname, $value, $no_linked = 0, $source = '')` | Алиас setGlobal; передаёт все параметры и возвращает тот же результат. |

### Привязки модулей к свойствам

Реализация: `lib/core/Helpers/PropertyBindings.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function addLinkedProperty($object, $property, $module)` | Подключает модуль к изменениям свойства через сервис привязок. |
| `function removeLinkedProperty($object, $property, $module)` | Удаляет привязку модуля к свойству через сервис привязок. |
| `function removeLinkedPropertyIfNotUsed($table_name, $object, $property, $module)` | Удаляет привязку только при отсутствии других ссылок на свойство в указанной таблице модуля. |

### Чтение и очистка истории

Реализация: `lib/core/Helpers/ObjectHistory.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function cleanUpPropertyHistory($property_id, $max_age_days)` | Удаляет устаревшую историю всех значений свойства; возвращает количество удалённых записей. |
| `function cleanUpValueHistory($value_id, $max_age_days, $data_type = 0)` | Удаляет устаревшую историю по ID значения; возвращает количество удалённых записей. |
| `function getHistory($varname, $start_time, $stop_time = 0)` | Возвращает записи истории VALUE/ADDED за период. Неположительные границы задают смещение в секундах от текущего времени. |
| `function getHistoryAvg($varname, $start_time, $stop_time = 0, $integral = false)` | Возвращает среднее за период; integral включает расчёт с учётом времени между измерениями. |
| `function getHistoryAvgDay($varname, $start_time, $stop_time = 0)` | Возвращает средние значения истории с группировкой по дням за период. |
| `function getHistoryCount($varname, $start_time, $stop_time = 0)` | Возвращает количество записей за период либо false, если не найдено свойство. |
| `function getHistoryMax($varname, $start_time, $stop_time = 0)` | Возвращает максимум за период; при отсутствии записей в некоторых случаях используется текущее значение, иначе false. |
| `function getHistoryMin($varname, $start_time, $stop_time = 0)` | Возвращает минимум за период; при отсутствии записей в некоторых случаях используется текущее значение, иначе false. |
| `function getHistorySum($varname, $start_time, $stop_time = 0)` | Возвращает сумму значений за период либо false, если не найдено свойство. |
| `function getHistoryValue($varname, $time, $nerest = false)` | Возвращает значение на момент времени: ближайшее при nerest=true, иначе с интерполяцией. Написание nerest сохранено для совместимости. |
| `function getHistoryValueId($varname)` | Находит ID значения свойства для истории; при отсутствии возвращает false. |

### Хранилище истории

Реализация: `lib/core/Helpers/HistoryStorage.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function createHistoryTable($value_id)` | Обеспечивает отдельное хранилище истории значения и возвращает имя таблицы. |
| `function moveDataFromMainHistoryToTable($value_id)` | Переносит историю значения из общей таблицы в отдельное хранилище. |
| `function moveDataFromTableToMainHistory($value_id)` | Переносит историю значения из отдельного хранилища в общую таблицу. |

### Сценарии

Реализация: `lib/core/Helpers/Scripts.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function callScript($id, $params = '')` | Алиас runScript; теперь также возвращает исходный результат синхронного выполнения. |
| `function runScript($id, $params = '')` | Синхронно выполняет сценарий по ID или имени и возвращает исходный результат. |
| `function runScriptSafe($id, $params = 0)` | Запускает сценарий через механизм безопасного выполнения с цепочкой вызовов и аудитом; фоновая операция ставится напрямую в очередь ядра без HTTP/API. Не является проверкой прав доступа. |

### Таймеры и расписание

Реализация: `lib/core/Helpers/Scheduler.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function addScheduledJob($title, $commands, $datetime, $expire = 1800)` | Создаёт задание на Unix timestamp datetime, удаляя задания с тем же названием. Возвращает ID; expire — допустимое опоздание в секундах. |
| `function clearScheduledJob($title)` | Удаляет задания по названию и возвращает результат SQL-операции. |
| `function clearTimeOut($title)` | Алиас удаления задания по названию; возвращает результат clearScheduledJob. |
| `function deleteScheduledJob($id)` | Удаляет задание по ID и возвращает результат SQL-операции. |
| `function runScheduledJobs(?callable $progress = null)` | Служебный запуск планировщика; progress вызывается между заданиями. Возвращает результат Scheduler. |
| `function setTimeOut($title, $commands, $timeout = 0, $expire = 0)` | Ставит именованное задание через timeout секунд; возвращает ID. Повторная постановка заменяет задание с тем же названием. |
| `function timeOutExists($title)` | Возвращает ID необработанного задания; при отсутствии записи — 0 без предупреждения PHP. |

Таймеры сохраняют исходную цепочку изменений. При доступном Redis сроки
исполнения обслуживаются в Redis, а SQL сохраняет команды и результаты.
При ошибке по умолчанию три попытки с задержками 5 и 10 секунд; чтобы
передать отказ вызываемой функции, возвращайте её результат из команды.
Подробнее — [отложенные задания](jobs.md).

### Системные события

Реализация: `lib/core/Helpers/Events.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function registerEvent($eventName, $details = '', $expire_in = 0)` | Регистрирует системное событие через модуль событий; возвращает результат регистрации. expire_in задаёт время жизни. |
| `function registeredEventDetails($eventName)` | Возвращает DETAILS последнего события; legacy-контракт отсутствия записи зависит от результата SQLSelectOne. |
| `function registeredEventTime($eventName)` | Возвращает Unix timestamp последнего системного события с таким именем либо -1. |

### Подписки и обработчики событий

Реализация: `lib/core/Helpers/Subscriptions.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function processSubscriptionByModule($module_name, $event_name, &$details)` | Вызывает обработчик подписки модуля с details по ссылке и возвращает его вывод. |
| `function processSubscriptions($event_name, $details = '', $return_output = false)` | Синхронно обрабатывает подписчиков по приоритету. При return_output=true возвращает вывод, иначе признак PROCESSED; при отсутствии обработчиков — 0. |
| `function processSubscriptionsSafe($event_name, $details = '')` | Передаёт обработку подписок в фон; возвращает 1 после отправки, а не результат обработчиков. |
| `function removeMissingSubscribers()` | Удаляет из настроек подписки на отсутствующие модули. |
| `function subscribeToEvent($module_name, $event_name, $filter_details = '', $priority = 0)` | Регистрирует подписку модуля на событие с фильтром и приоритетом. |
| `function unsubscribeFromEvent($module_name, $event_name = '')` | Удаляет подписку модуля на событие либо на все события, если имя не задано. |

### Режимы системы

Реализация: `lib/core/Helpers/Modes.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function disableMode(string $name, string $source = 'code'): bool` | Алиас setMode с active=false. |
| `function enableMode(string $name, string $source = 'code'): bool` | Алиас setMode с active=true. |
| `function isModeActive(string $name): bool` | Возвращает bool: включён ли именованный режим системы. |
| `function modesProcessEvent(string $event, array $details): void` | Вызывается шиной подписок и передаёт событие в `ModeManager::tick()`; без установленного модуля `modes` ничего не делает. |
| `function setMode(string $name, bool $active, string $source = 'code'): bool` | Включает или выключает режим через ModeManager; возвращает bool по контракту менеджера. |

### Сообщения и озвучивание

Реализация: `lib/core/Helpers/Messages.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function ask($prompt, $target = '')` | Создаёт сообщение-вопрос и передаёт ASK с адресатом. |
| `function say($ph, $level = 0, $member_id = 0, $source = '')` | Добавляет сообщение в журнал и передаёт его штатным обработчикам озвучивания или команд. |
| `function sayReply($ph, $level = 0, $replyto = '')` | Ищет терминал исходного запроса и отправляет ответ, при необходимости использует say. |
| `function sayReplySafe($ph, $level = 0, $replyto = '')` | Фоновая отправка ответа через внутреннюю точку входа. |
| `function saySafe($ph, $level = 0, $member_id = 0, $source = '')` | Фоновая отправка say; возвращает статус отправки запроса. |
| `function sayTo($ph, $level = 0, $destination = '')` | Добавляет сообщение для терминала и передаёт SAYTO; возвращает 1, при пустом адресате — 0. |
| `function sayToSafe($ph, $level = 0, $destination = '')` | Фоновая отправка адресного сообщения через очередь ядра; возвращает статус постановки. |

### Команды и воспроизведение звука

Реализация: `lib/core/Helpers/Commands.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function playSound($filename, $exclusive = 0, $priority = 0)` | Передаёт воспроизведение файла настроенному плееру через очередь safe_exec с учётом hooks. |
| `function processCommand($command)` | Передаёт нераспознанную команду методу ThisComputer.commandReceived и возвращает его результат, если вызов выполнен. |

### Контекст диалога и шаблоны команд

Реализация: `lib/core/Helpers/Context.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function addPattern($title, $options = array(), $overwrite = 0)` | Создаёт или обновляет шаблон распознавания команды; overwrite разрешает замену записи. |
| `function context_activate($id, $no_action = 0, $history = '', $user_id = 0, $details = 0)` | Активирует контекст с историей и возможным выполнением действия. |
| `function context_activate_ext($id, $timeout = 0, $timeout_code = '', $timeout_context_id = 0, $user_id = 0)` | Активирует внешний контекст и при необходимости планирует таймер выхода или действия. |
| `function context_clear($user_id = 0)` | Сбрасывает активный контекст пользователя. |
| `function context_get_history($user_id = 0)` | Возвращает историю контекста пользователя либо пустую строку. |
| `function context_getcurrent($from_user_id = 0)` | Возвращает контекст пользователя: ID или строку ext<ID> для внешнего контекста; без пользователя — 0. |
| `function context_getuser()` | Возвращает ID пользователя контекста из глобального контекста, сессии либо пользователя по умолчанию. |
| `function context_timeout($id, $user_id = 0)` | Обрабатывает истечение времени внешнего контекста. |

### Терминалы

Реализация: `lib/core/Helpers/Terminals.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function getAllTerminals($limit = -1, $order = 'ID', $sort = 'ASC')` | Возвращает терминалы с ограничением и сортировкой; legacy-пустой список может быть array(null). |
| `function getLocalIp()` | Определяет локальный IP через механизм терминалов. |
| `function getMainTerminal()` | Возвращает основной терминал по существующим правилам. |
| `function getMajorDroidTerminals($limit = -1, $order = 'ID', $sort = 'ASC')` | Возвращает терминалы MajorDroid. |
| `function getOnlineTerminals($limit = -1, $order = 'ID', $sort = 'ASC')` | Возвращает терминалы online. |
| `function getTerminalByID($id)` | Возвращает запись терминала по ID. |
| `function getTerminalsByCANTTS($order = 'ID', $sort = 'ASC')` | Возвращает терминалы с TTS и выбранной сортировкой. |
| `function getTerminalsByHost($host, $limit = -1, $order = 'ID', $sort = 'ASC')` | Ищет терминалы по хосту с учётом локальных адресов. |
| `function getTerminalsByName($name, $limit = -1, $order = 'ID', $sort = 'ASC')` | Ищет терминалы по имени или заголовку с ограничением и сортировкой. |
| `function getTerminalsByPlayer($player, $limit = -1, $order = 'ID', $sort = 'ASC')` | Ищет терминалы по проигрывателю. |
| `function getTerminalsCanPlay($limit = -1, $order = 'ID', $sort = 'ASC')` | Возвращает терминалы, поддерживающие воспроизведение. |
| `function setTerminalMML($host = 'localhost', $mml = 0)` | Меняет минимальный уровень сообщений терминала. |

### Кэш значений и циклов

Реализация: `lib/core/Helpers/Cache.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function checkCycleFromCache($key)` | Читает состояние цикла через специализированный кэш циклов. |
| `function checkFromCache($key)` | Читает кэш; отсутствие обозначается false, поэтому проверяйте результат строго. |
| `function clearCacheData($prefix = '')` | Очищает кэш по префиксу; пустой префикс означает общую очистку. |
| `function deleteFromCache($key)` | Удаляет ключ из кэша через сервис ядра. |
| `function ensureCachedValuesTable()` | Служебное обеспечение SQL-хранилища кэша. |
| `function getAllCache($prefix = '')` | Возвращает записи кэша по префиксу. |
| `function saveCycleToCache($key, $value)` | Записывает состояние цикла через специализированный кэш. |
| `function saveToCache($key, $value, $only_if_missing = false)` | Записывает ключ кэша; only_if_missing запрещает перезапись существующего значения. |

### Legacy-доступ к Redis

Реализация: `lib/core/Helpers/Redis.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function mjdGetRedisConnection()` | Legacy-доступ к соединению Redis; при отключённом Redis возвращает null. |

### Очередь операций

Реализация: `lib/core/Helpers/OperationsQueue.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function addToOperationsQueue($topic, $dataname, $datavalue = '', $uniq = false, $ttl = 60)` | Добавляет операцию в очередь ядра; uniq и ttl задают дедупликацию и время жизни по контракту сервиса. |
| `function checkOperationsQueue($topic)` | Legacy-чтение очереди: захватывает и сразу подтверждает операции, возвращает список. Новым надёжным обработчикам нужны claim/acknowledge сервиса очереди. |

### Уведомления WebSocket

Реализация: `lib/core/Helpers/WebSockets.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function getWebSocketClientPath()` | Возвращает путь подключения WebSocket с системным токеном при наличии. |
| `function postToWebSocket($property, $value, $post_action = 'PostProperty')` | Совместимый алиас очереди WebSocket. Возвращает результат постановки, а не доставки; массивы свойств и значений ставятся поэлементно. Прямого подключения к WS больше нет. |
| `function postToWebSocketQueue($property, $value, $post_action = 'PostProperty')` | Ставит уведомление свойства или события в очередь WebSocket. |

### Внутренний API

Реализация: `lib/core/Helpers/Api.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function callAPI($api_url, $method = 'GET', $params = 0, $wait_response = false)` | Вызывает внутренний API через сервис ядра; wait_response определяет ожидание ответа. Фоновый ответ не равен результату самой операции. |
| `function callAPISync($api_url, $method = 'GET', $params = 0)` | Алиас callAPI с wait_response=true; возвращает ответ синхронного вызова. |

### HTTP-запросы

Реализация: `lib/core/Helpers/Http.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function getURL($url, $cache = 0, $username = '', $password = '', $background = false, $curl_options = 0)` | Выполняет HTTP GET и возвращает ответ; поддерживает кэш с валидацией ETag/Last-Modified, авторизацию, curl_options и фоновый режим. Кэш — Redis при включённом `USE_REDIS`, иначе файл в `cms/cached/http`; ошибки сети и ответы 4xx/5xx не кэшируются. |
| `function getURLBackground($url, $cache = 0, $username = '', $password = '')` | Отправляет GET в фоне и возвращает true после вызова отправки. Это не подтверждение выполнения удалённой операции. |
| `function postURL($url, $query = array(), $cache = 0, $username = '', $password = '', $background = false)` | Выполняет HTTP POST с данными и возвращает ответ; поддерживает кэш, авторизацию и фоновый режим. Ключ кэша включает тело запроса. |
| `function postURLBackground($url, $query = array(), $cache = 0, $username = '', $password = '')` | Отправляет POST в фоне; результат удалённой операции не возвращает. |
| `function getURLCacheFile($url)` | Возвращает путь файловой копии GET-ответа для URL (даже если включён Redis); нужен для диагностики. |
| `function clearURLCache($url)` | Удаляет кэш одного URL из Redis и файлового хранилища. |

### Сетевые проверки

Реализация: `lib/core/Helpers/Network.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function isOnline($host)` | Возвращает 1 или 0 по сохранённому статусу хоста в pinghosts; сам сетевую проверку не выполняет. |
| `function ping($host)` | Выполняет системный ping хоста и возвращает признак полученного ответа. |

### Запуск команд

Реализация: `lib/core/Helpers/Processes.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function execInBackground($cmd)` | Запускает системную команду в фоне способом текущей ОС; результата команды не возвращает. |
| `function safe_exec($command, $exclusive = 0, $priority = 0, $on_complete = '')` | Ставит команду в очередь safe_execs и возвращает ID; on_complete — действие после завершения. |

### Диагностика

Реализация: `lib/core/Helpers/Logging.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function DebMes($errorMessage, $logLevel = "debug", $severity = '')` | Пишет сообщение в системный файловый журнал; severity управляет уровнем и маршрутизацией ошибок. |
| `function dprint($data = 0, $stop = 1, $show_history = 0)` | Legacy-отладочный вывод; stop может завершить запрос. Только для диагностики. |
| `function getEvalCode()` | Возвращает диагностический код с номерами строк либо false. |
| `function getLogger($context = null)` | Legacy-заглушка старого логгера, возвращает false. Для новых диагностических записей используйте DebMes. |
| `function setEvalCode($code = '')` | Сохраняет текущий выполняемый код для диагностики ошибок. |

### Журнал действий и ошибок

Реализация: `lib/core/Helpers/ActionLog.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function actionLogDetectSource($context = array())` | Определяет источник записи журнала по контексту и запросу. |
| `function echonow($msg, $color = '')` | Пишет сообщение в журнал обновлений и немедленно выводит его в поток ответа. |
| `function getActionLogRequestId($context = array())` | Возвращает ID запроса из контекста, заголовка либо генерирует ID в пределах запроса. |
| `function getActionsLogAvailableFields()` | Служебно определяет доступные столбцы журнала действий для совместимости схем. |
| `function logAction($action_type, $details = '', $context = array(), ...$legacy_args)` | Пишет действие в журнал с контекстом; поддерживает старый позиционный формат дополнительных параметров. |
| `function registerError($code = 'custom', $details = '')` | Регистрирует системную ошибку и подробности через существующий механизм ошибок. |
| `function sanitizeActionLogData($data, $key = '')` | Рекурсивно маскирует чувствительные поля и ограничивает размер текстовых значений в журнале. |
| `function verbose_log($data)` | Пишет подробную трассировку вызовов, если включён VERBOSE_LOG. |

### Измерение производительности

Реализация: `lib/core/Helpers/Performance.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function PerformanceReport($no_print = 0)` | Формирует HTML-отчёт профайлера, при no_print=0 также выводит его; возвращает строку отчёта. |
| `function endMeasure($mpoint)` | Завершает измерение и накапливает статистику. |
| `function getmicrotime()` | Возвращает текущее время с микросекундами как число. |
| `function startMeasure($mpoint)` | Начинает именованное измерение времени в общем профайлере. |

### Состояние системы

Реализация: `lib/core/Helpers/System.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function IsWindowsOS()` | Возвращает bool: выполняется ли PHP в Windows. |
| `function getSystemSerial($force_update = 0)` | Возвращает сохранённый или вычисленный идентификатор системы; force_update требует пересчитать его. |
| `function isRebootRequired()` | Проверяет наличие системного флага reboot. |
| `function resetRebootRequired()` | Снимает флаг перезапуска и журналирует результат. |
| `function return_memory_usage()` | Возвращает текущий объём выделенной памяти в читаемом виде. |
| `function setRebootRequired($reason = '')` | Устанавливает флаг необходимости перезапуска и записывает причину в журнал. |

### Время и интервалы

Реализация: `lib/core/Helpers/Time.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function getNumberWord($number, $suffix)` | Выбирает форму слова для числа по массиву окончаний. |
| `function getPassedText($updatedTime)` | Возвращает локализованное описание времени, прошедшего с timestamp или текстовой даты. |
| `function isWeekDay()` | Отрицание isWeekEnd; государственные праздники не учитываются. |
| `function isWeekEnd()` | Проверяет, является ли сегодня субботой или воскресеньем. |
| `function recognizeTime($text, &$newText = '')` | Распознаёт временной интервал в тексте; newText по ссылке получает оставшийся текст. |
| `function textToNumbers($text)` | Legacy-заглушка: возвращает исходный текст без преобразования слов в числа. |
| `function timeAfter($tm)` | Проверяет, что текущее время не раньше указанного времени. |
| `function timeBefore($tm)` | Проверяет, что текущее время не позже указанного времени. |
| `function timeBetween($tm1, $tm2)` | Проверяет попадание текущего времени в интервал, включая переход через полночь. |
| `function timeConvert($tm)` | Преобразует HH:MM в Unix timestamp текущего дня; числовой timestamp передаёт дальше. |
| `function timeIs($tm)` | Проверяет совпадение текущего времени с HH:MM с точностью до минуты. |
| `function timeNow($tm = 0)` | Возвращает локализованное текстовое время либо HH:MM для timestamp. |

### Legacy-смещения времени

Реализация: `lib/core/Helpers/LocalTime.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function getLocalTime($diff = 0)` | Возвращает дату и время с часовым смещением относительно GMT по legacy-правилам. |
| `function recLocalTime($table, $id, $gmt, $field = "ADDED")` | Пересчитывает временное поле записи с учётом смещения; служебная низкоуровневая операция. |
| `function setLocalTime($now_date, $diff = 0)` | Преобразует дату с часовым смещением относительно GMT по legacy-правилам. |

### Одноразовые уведомления панели

Реализация: `lib/core/Helpers/Notifications.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function mdjCollectRequestFlashNotifications($scope = 'admin')` | Собирает уведомления из параметров запроса в сессию панели. |
| `function mdjConsumeFlashNotifications($scope = 'admin')` | Возвращает и удаляет накопленные уведомления указанной области. |
| `function mdjEnsureSession()` | Возвращает существующую сессию панели либо создаёт её. |
| `function mdjExtractFlashNotificationsFromUrl($url, $scope = 'admin')` | Извлекает уведомления из legacy-параметров URL и возвращает очищенный URL. |
| `function mdjFlashError($message, $title = '', $scope = 'admin')` | Добавляет одноразовое уведомление об ошибке. |
| `function mdjFlashInfo($message, $title = '', $scope = 'admin')` | Добавляет информационное одноразовое уведомление. |
| `function mdjFlashSuccess($message, $title = '', $scope = 'admin')` | Добавляет уведомление об успехе; возвращает результат mdjPushFlashNotification. |
| `function mdjFlashWarning($message, $title = '', $scope = 'admin')` | Добавляет одноразовое предупреждение. |
| `function mdjNormalizeFlashType($type)` | Нормализует тип уведомления к success, error, warning или info. |
| `function mdjPushFlashNotification($message, $type = 'info', $title = '', $scope = 'admin')` | Добавляет одноразовое уведомление в сессию; false для пустого текста, иначе true. |

### Legacy-правила доступа

Реализация: `lib/core/Helpers/Access.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function checkAccess($object_type, $object_id)` | Legacy-проверка правил доступа для типа и ID сущности. Не заменяет проверку доступа в конкретной точке входа. |
| `function checkAccessCopy($object_type, $src_id, $dst_id)` | Копирует правила доступа между сущностями одного типа. |
| `function checkAccessDefined($object_type, $object_id)` | Проверяет наличие явно заданных правил доступа к сущности. |

### Параметры запросов и редиректы

Реализация: `lib/core/Helpers/Request.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function gr($var_name, $type = 'trim')` | Возвращает параметр запроса с указанной обработкой; trim применяется по умолчанию. Не заменяет серверную валидацию. |
| `function redirect($url, $owner = "", $no_sid = 0)` | Выполняет HTTP-редирект с поддержкой legacy-сессии и штатного преобразования ссылок. |

### Данные старых шаблонов

Реализация: `lib/core/Helpers/ViewData.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function colorizeArray(&$ar, $every = 2)` | Добавляет метку NEW_COLOR каждой every-й строке массива для старых шаблонов. |
| `function multipleArraySelect($in, &$ar, $field = "SELECTED")` | Помечает элементы массива, чьи TITLE входят в список; изменяет массив по ссылке. |
| `function outArray($title, $ar, &$out)` | Преобразует список строк в данные legacy-шаблона с NUM, TITLE и меткой HALF. |
| `function outHash($var, &$hash)` | Копирует ключи массива в переданный по ссылке массив вывода. |
| `function paging(&$data, $onPage, &$out)` | Обрезает массив до текущей страницы и заполняет метаданные пагинации в out; массивы изменяются по ссылке. |

### Legacy-проверки строк

Реализация: `lib/core/Helpers/Validation.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function checkBadwords($s, $replace = 1)` | Проверяет текст по таблице запрещённых слов; replace включает замену найденных фрагментов. |
| `function checkEmail($email)` | Legacy-проверка адреса по старому регулярному выражению с ограничениями старого формата доменов. |
| `function checkGeneral($field)` | Проверяет минимальную длину текстового поля, возвращает 1 или 0. |
| `function checkPassword($psw)` | Legacy-проверка минимальной длины пароля; не современная политика безопасности паролей. |
| `function genPassword($len = 5)` | Генерирует строку из a–z и 0–9 заданной длины через random_int. Нулевая длина даёт пустую строку; отрицательная вызывает InvalidArgumentException. Значение по умолчанию 5 сохранено для совместимости. |

### Почта

Реализация: `lib/core/Helpers/Mail.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function SendMail($from, $to, $subj, $body = "", $attach = "")` | Отправляет текстовое письмо через SendMail_HTML, возвращает результат отправки. |
| `function SendMail_HTML($from, $to, $subj, $body = "", $attach = "")` | Отправляет HTML-письмо с вложениями через PHPMailer; возвращает результат либо false при обработанной ошибке. |

### Каталоги, модули и транслитерация

Реализация: `lib/core/Helpers/Utilities.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function CreateDir($dirPath)` | Legacy-создание каталога при отсутствии. Ошибки подавляются существующей реализацией; для нового кода используйте проверяемые файловые операции. |
| `function isModuleInstalled($module_name)` | Проверяет маркер установленного модуля в cms/modules_installed. |
| `function transliterate($string)` | Преобразует русские буквы в латинские символы. |

### Кодировки и сортировка

Реализация: `lib/core/Helpers/LegacyUtilities.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function mysort_array($ar, $field = "TITLE")` | Возвращает массив, отсортированный по строковому полю. Стабильная сортировка PHP 8 корректно обрабатывает любой знак strcmp. |
| `function utf2win($in)` | Преобразует UTF-8 в Windows-1251 через iconv. |
| `function win2utf($in)` | Преобразует Windows-1251 в UTF-8 через iconv. |

### Байты и Base64

Реализация: `lib/core/Helpers/Binary.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function HexStringToArray($buf)` | Преобразует пары шестнадцатеричных символов в массив байтов. |
| `function HexStringToString($buf)` | Преобразует пары шестнадцатеричных символов в бинарную строку. |
| `function binaryToString($buf)` | Преобразует бинарную строку в шестнадцатеричное представление. |
| `function makePayload($data)` | Собирает бинарную строку из числовых кодов байтов. |
| `function urlsafe_b64decode($string)` | Декодирует legacy URL-safe Base64. |
| `function urlsafe_b64encode($string)` | Кодирует строку в legacy URL-safe Base64 без завершающих знаков равенства. |

### Преобразование цветов

Реализация: `lib/core/Helpers/Colors.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function hexToHsv($hex)` | Преобразует шестнадцатеричный цвет в HSV. |
| `function hsvToHex($h, $s, $v)` | Преобразует HSV в шестнадцатеричный цвет. |
| `function hsvToRgb($h, $s, $v)` | Преобразует HSV в массив RGB по шкалам существующей реализации. |
| `function rgbToHsv($r, $g, $b)` | Преобразует RGB в HSV по шкалам существующей реализации. |

### Файлы, синхронизация и медиа

Реализация: `lib/core/Helpers/Files.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function LoadFile($filename)` | Читает содержимое файла и возвращает строку; ошибки открытия не скрываются новым слоем. |
| `function SaveFile($filename, $data)` | Записывает файл под блокировкой; возвращает 1 при успешном открытии по legacy-контракту, иначе 0. Полноту записи отдельно не проверяет. |
| `function UTF_Encode($str, $type)` | Legacy-преобразование кодировки с учётом типа. |
| `function checkfile($path, $move)` | Служебная обработка файла для legacy-копирования или перемещения. |
| `function clearCache($verbose = 0)` | Очищает cms/cached (включая HTTP-кэш в файлах и Redis); это другой кэш, чем clearCacheData. Сам HTTP-кэш — `Core::httpCache()`, см. `docs/agents/http-cache.md`. |
| `function copyFile($src, $dst)` | Копирует файл с legacy-обработкой пути и метаданных. |
| `function copyFiles($source, $destination, $over = 0, $patterns = 0)` | Копирует файлы между каталогами с правилами перезаписи и шаблонами. |
| `function copyNewFile($path, $days)` | Обрабатывает копирование файлов по пути и временному ограничению через legacy-синхронизацию. |
| `function copyTree($source, $destination, $over = 0, $patterns = 0)` | Копирует дерево каталогов с правилами перезаписи и шаблонами. |
| `function getDirFiles($dir, &$results = array())` | Собирает файлы одного каталога без рекурсии: NAME, FILENAME, DT, TM, SIZE. Дополняет results по ссылке и возвращает его. |
| `function getDirTree($dir, &$results = array())` | Рекурсивно собирает плоский список файлов с FILENAME, DT, TM, SIZE, дополняя results по ссылке. |
| `function getFilesTree($destination, $sort = 'name')` | Возвращает дерево файлов с выбранной сортировкой. |
| `function getLocalFilesTree($dir, $pattern, $ex_pattern, &$log, $verbose)` | Собирает дерево файлов с включающим и исключающим шаблонами; log передаётся по ссылке. |
| `function getMediaDurationSeconds($file)` | Получает длительность медиафайла в секундах через механизм определения метаданных. |
| `function getRandomLine($filename)` | Возвращает случайную строку из файла. |
| `function get_media_info($file)` | Возвращает метаданные медиафайла с использованием внешних инструментов. |
| `function get_mime_type($filename)` | Определяет MIME-тип файла по существующим правилам. |
| `function get_remote_filesize($url)` | Определяет размер удалённого HTTP-ресурса. |
| `function is_dir2($d)` | Legacy-проверка каталога, исключающая служебные пути с точкой. |
| `function keepLatestLimitedBySize($path, $max_size, $removeEmptyFolders = true)` | Удаляет старые файлы, сохраняя свежие в пределах ограничения размера; может удалять пустые каталоги. |
| `function makeDir($dir, $sep = '/')` | Создаёт структуру каталогов по пути с заданным разделителем. |
| `function preparePathTime($s, $mtime)` | Подставляет в шаблон пути компоненты текущего времени и времени изменения файла. |
| `function processLine($line, $hash = '')` | Разбирает строку legacy-описания файлов с подстановками. |
| `function processLines($data)` | Обрабатывает строки legacy-описания файлов и синхронизации. |
| `function remote_file_exists($url)` | Проверяет наличие удалённого ресурса HTTP-запросом. |
| `function removeEmptySubFolders($path)` | Рекурсивно удаляет пустые каталоги, включая переданный path, если он оказался пустым. |
| `function removeTree($destination, $iframe = 0)` | Рекурсивно удаляет дерево; iframe включает потоковый отчёт для старого интерфейса. |
| `function remove_old_files($path, $days)` | Удаляет старые файлы по возрасту в днях; служебная файловая операция. |
| `function walk_dir($dir, $callback, $move = 0)` | Legacy-обход каталога с callback для файлов. |
| `function walk_dir2($dir, $callback, $move = 0)` | Вариант обхода каталога с callback и флагом перемещения. |

### Python-сценарии

Реализация: `lib/core/Helpers/Python.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function isItPythonCode($code)` | Определяет, помечен ли код как Python. |
| `function python_make_full_code($code, $object = '')` | Формирует полный Python-код с окружением MajorDoMo и контекстом объекта. |
| `function python_run_code($code, $params = '', $object = '')` | Выполняет Python-код внешним интерпретатором с параметрами и объектом; возвращает и может выводить текст результата. |
| `function python_syntax_error($code)` | Проверяет Python-код внешним механизмом и возвращает описание ошибки по legacy-контракту. |

### Проверка синтаксиса кода

Реализация: `lib/core/Helpers/CodeSyntax.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function code_syntax_error_details($code, $mode = '')` | Возвращает структурированные сведения о синтаксической ошибке для редактора. |
| `function normalize_code_editor_mode($mode)` | Нормализует режим редактора по поддерживаемым языкам. |
| `function php_syntax_error($code)` | Проверяет синтаксис PHP или делегирует Python-код соответствующему механизму. |
| `function python_syntax_error_offset()` | Возвращает смещение строк Python-обёртки для отображения ошибки в редакторе. |

### XML-деревья

Реализация: `lib/core/Helpers/Xml.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function GetChildren($vals, &$i)` | Служебный рекурсивный разбор дочерних узлов XML; индекс обхода передаётся по ссылке. |
| `function GetXMLTree($data)` | Разбирает XML в дерево через legacy XML-парсер. |
| `function XMLTreeToArray($data)` | Преобразует результат legacy XML-парсера в массив. |

### XML и массивы

Реализация: `lib/core/Helpers/XmlData.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function fromXML($str)` | Разбирает XML в массив через сохранённый класс xml_data. |
| `function toXML($hash)` | Сериализует массив в XML через сохранённый класс xml_data. |

### Низкоуровневый доступ к БД

Реализация: `lib/core/Helpers/Database.php`.

| Сигнатура | Назначение и результат |
| --- | --- |
| `function DbSafe($in)` | Legacy-экранирование через драйвер; не заменяет выбор доменного API и валидацию. |
| `function DbSafe1($in)` | Legacy-экранирование вторым режимом драйвера; не взаимозаменяйте с DbSafe без проверки SQL-контекста. |
| `function SQLAffectedRows()` | Возвращает количество затронутых строк, если драйвер поддерживает это, иначе 0. |
| `function SQLDropTable($table)` | Удаляет таблицу через DROP TABLE IF EXISTS; служебная разрушительная операция. |
| `function SQLExec($query, $ignore_errors = false)` | Низкоуровневое выполнение SQL через глобальное соединение. Не используйте для обхода системного API. |
| `function SQLGetFields($table)` | Возвращает описание столбцов таблицы. |
| `function SQLGetIndexes($table)` | Возвращает индексы таблицы. |
| `function SQLGetMysqlClientPath()` | Возвращает путь к клиенту MySQL из конфигурации. |
| `function SQLGetMysqlDumpPath()` | Возвращает путь к mysqldump из конфигурации. |
| `function SQLInsert($table, &$record)` | Вставляет запись; record передаётся по ссылке. Возвращает ID по контракту драйвера. |
| `function SQLInsertUpdate($table, &$record, $ndx = 'ID')` | Алиас SQLUpdateInsert с сохранением передачи record по ссылке. |
| `function SQLMakeDBDump($dump_file, $ignore_tables = 0)` | Создаёт дамп БД с исключением таблиц через штатный механизм резервного копирования. |
| `function SQLMakeTableDump($dump_file, $table_name)` | Создаёт дамп таблицы. |
| `function SQLPing()` | Проверяет соединение с БД через драйвер. |
| `function SQLRestoreDBDump($dump_file)` | Восстанавливает дамп БД; изменяет данные, применяется механизмом восстановления. |
| `function SQLRunExternalCommand($command, $stdin_file = null, $stdout_file = null, &$output = array())` | Служебный запуск внешней команды с перенаправлением файлов; output изменяется по ссылке. |
| `function SQLSelect($query)` | Низкоуровневое чтение списка строк для слоя хранения и собственных таблиц модуля. |
| `function SQLSelectOne($query)` | Низкоуровневое чтение одной строки; формат отсутствия строки определяется драйвером. |
| `function SQLTableExists($table)` | Проверяет существование таблицы через SHOW TABLES. |
| `function SQLTruncateTable($table)` | Очищает таблицу через TRUNCATE; служебная разрушительная операция. |
| `function SQLUpdate($table, $record, $ndx = 'ID')` | Обновляет запись по ключу через драйвер БД. |
| `function SQLUpdateInsert($table, &$record, $ndx = 'ID')` | Обновляет запись при наличии ключа либо вставляет новую, записывая ID в record по ссылке. |
| `function fromDBDate($source, $delim = '-', $dst_delim = '/')` | Преобразует YYYY-MM-DD в MM/DD/YYYY с настраиваемыми разделителями. |
| `function toDBDate($source, $delim = '/', $dst_delim = '-')` | Преобразует MM/DD/YYYY в YYYY-MM-DD с настраиваемыми разделителями. |

## Связанные инструкции

- [Привязки свойств и ONCHANGE](../agents/property-bindings.md).
- [Аудит изменений свойств](../agents/property-audit.md).
- [Системные события](../agents/events.md).
- [Конфигурация и Config](../agents/configuration.md).
- [Точки входа и загрузка системы](../agents/entrypoints.md).
- [Команды терминала](commands.md).

## Создание сущностей административным клиентом

В методе объекта доступны `$this->getProperty($property, $cache_checked = false)`
и `$this->setProperty($property, $value, $no_linked = 0, $source = '')`.
Они делегируют сервису свойств ядра. Здесь указывается локальное имя свойства,
например `status`; `getGlobal` / `setGlobal` принимают полное `Object.status`.
В отдельном сценарии сначала получают объект через `getObject` и проверяют его
наличие. Служебный `$cache_checked` при обычном чтении оставляют по умолчанию.

`MajorDoMo\Core\Core::entities()->create($input)` создаёт одну сущность:
`kind` — `script`, `class`, `object`, `class_property` или `object_property`;
`name` — новое имя, `parent` — существующий класс/объект, `description` — описание,
`code` — PHP-код сценария без тегов. Сервис проверяет синтаксис, не запускает код,
не создаёт родителей неявно и не перезаписывает существующие записи.

Возвращает `id`, `kind`, `name`, `executed=false` и `warning`. Непустое `warning`
означает, что запись уже создана, но обновить кэш метаданных не удалось.
Авторизация и подтверждение пользователя проверяются вызывающим административным
интерфейсом до обращения к сервису.
