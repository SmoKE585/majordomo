<?php
if (!isset($repo, $token) || !class_exists('MDJUI', false)) { http_response_code(404); return; }
$e = static fn($value) => MDJUI::e((string)($value ?? ''));
$url = static fn($path = '') => ROOTHTML . 'panel/zigbee' . $path . '.html';
$hidden = static function (array $fields) use ($e, $token): void {
    echo '<input type="hidden" name="_token" value="' . $e($token) . '">';
    foreach ($fields as $name => $value) echo '<input type="hidden" name="' . $e($name) . '" value="' . $e($value) . '">';
};
$card = static function (string $title, callable $body): void {
    ob_start(); $body(); $slot = ob_get_clean();
    echo MDJUI::card(array('title' => $title), $slot);
};
$accordion = static function (string $id, string $title, callable $body) use ($e): void { ?>
    <div class="accordion md-accordion">
        <div class="accordion-item">
            <h3 class="accordion-header" id="<?= $e($id) ?>-heading">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $e($id) ?>" aria-expanded="false" aria-controls="<?= $e($id) ?>"><?= $e($title) ?></button>
            </h3>
            <div class="accordion-collapse collapse" id="<?= $e($id) ?>" aria-labelledby="<?= $e($id) ?>-heading">
                <div class="accordion-body"><?php $body(); ?></div>
            </div>
        </div>
    </div>
<?php };
$showValue = static fn($encoded) => $encoded === null ? '—' : (string)$encoded;
$deviceImage = static function (array $device, bool $large = false) use ($e): void {
    $image = MajorDoMo\Modules\Zigbee\Service::deviceImageUrl($device['MODEL']); ?>
    <span class="md-table__icon md-zigbee-image<?= $large ? ' md-zigbee-image--large' : '' ?>" data-md-zigbee-image>
        <?php if ($image): ?><img src="<?= $e($image) ?>" alt="" width="<?= $large ? 80 : 44 ?>" height="<?= $large ? 80 : 44 ?>" loading="lazy" decoding="async" referrerpolicy="no-referrer" data-md-zigbee-photo><?php endif; ?>
        <?= MDJUI::icon('access-point') ?>
    </span>
<?php };
$isDiscovery = $view === 'discovered';
$active = in_array($view, array('bridges', 'journal', 'discovered', 'monitoring'), true) ? $view : 'devices';
$listUrl = $url($isDiscovery ? '/discovered' : '');
$locationOptions = array(0 => 'Без комнаты') + array_column($locations, 'TITLE', 'ID');
?>
<link rel="stylesheet" href="<?= $e(ROOTHTML) ?>assets/modules/zigbee/zigbee.css?v=20261022-4">
<script src="<?= $e(ROOTHTML) ?>assets/modules/zigbee/zigbee.js?v=20261022-3" defer></script>
<div class="md-ui-page md-ui-page--stack md-zigbee-page" data-md-zigbee>
    <header class="md-ui-hero">
        <div>
            <div class="md-ui-hero__eyebrow"><?= MDJUI::icon('access-point') ?> Zigbee2MQTT</div>
            <h1 class="md-ui-hero__title"><?= $e($property ? ($property['LABEL'] ?: $property['PATH']) : ($device ? ($device['TITLE'] ?: $device['FRIENDLY_NAME']) : 'Zigbee устройства')) ?></h1>
            <p class="md-ui-hero__lede">Устройства, события и привязки через общий MQTT-брокер.</p>
        </div>
        <div class="md-ui-hero__actions">
            <?= MDJUI::button($device ? 'К устройствам' : 'Обновить', array('href' => $device ? $url($device['STATUS'] === 'discovered' ? '/discovered' : '') : $listUrl, 'variant' => 'secondary', 'icon' => $device ? 'arrow-left' : 'refresh')) ?>
            <?= MDJUI::button('MQTT-брокер', array('href' => ROOTHTML . 'panel/mqtt/settings.html', 'variant' => 'ghost', 'icon' => 'broadcast')) ?>
        </div>
    </header>
    <?php if ($error !== '') echo MDJUI::alert('danger', 'Не удалось выполнить действие', $error); ?>
    <?php if ($success !== '') echo MDJUI::alert('success', 'Готово', $success); ?>
    <nav class="nav md-tabs" aria-label="Разделы Zigbee">
        <?php foreach (array('devices' => 'Устройства', 'discovered' => 'Обнаруженные (' . $stats['discovered'] . ')', 'bridges' => 'Экземпляры', 'monitoring' => 'Мониторинг', 'journal' => 'Журнал') as $key => $label): ?>
            <a class="nav-link <?= $active === $key ? 'active' : '' ?>" href="<?= $e($url($key === 'devices' ? '' : '/' . $key)) ?>" <?= $active === $key ? 'aria-current="page"' : '' ?>><?= $e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($view === 'monitoring'): ?>
        <?php require __DIR__ . '/monitoring.php'; ?>
    <?php elseif ($property): ?>
        <?php if ($device['STATUS'] !== 'added') echo MDJUI::alert('info', 'Устройство ещё не добавлено', 'Значения собираются для просмотра. Привязки и уведомления комнаты начнут работать после добавления в разделе «Обнаруженные».'); ?>
        <?php $expose = MajorDoMo\Modules\Zigbee\ValueTransformer::decode($property['EXPOSE']) ?? array(); $canWrite = (bool)(($expose['access'] ?? 0) & 2); ?>
        <p class="md-zigbee-context"><a href="<?= $e($url('/device/' . $device['ID'])) ?>"><?= $e($device['FRIENDLY_NAME']) ?></a> · <code><?= $e($property['PATH']) ?></code></p>
        <div class="md-ui-widget-grid">
            <?= MDJUI::kpi(array('label' => 'Получено от устройства', 'value' => $showValue($property['RAW_VALUE']), 'icon' => 'arrow-down', 'meta' => $property['UPDATED'] ?: 'Ещё нет данных')) ?>
            <?= MDJUI::kpi(array('label' => 'После преобразования', 'value' => $showValue($property['VALUE']), 'icon' => 'arrows-exchange')) ?>
        </div>
        <?php if (!empty($binding['LAST_ERROR'])) echo MDJUI::alert('danger', 'Ошибка обработки свойства', $binding['LAST_ERROR']); ?>
        <form method="post" class="md-ui-form-stack" data-md-zigbee-binding>
            <?php $hidden(array('operation' => 'binding', 'property_id' => $property['ID'])); ?>
            <?php $card('Привязка к объекту', static function () use ($binding, $linkedControl, $canWrite): void { ?>
                <div class="md-ui-form-stack">
                <div class="md-ui-form-grid">
                    <?= MDJUI::input(array('name' => 'linked_object', 'id' => 'linked_object', 'label' => 'Объект', 'value' => $binding['LINKED_OBJECT'] ?? '', 'attrs' => array('autocomplete' => 'off'))) ?>
                    <?= MDJUI::input(array('name' => 'linked_property', 'id' => 'linked_property', 'label' => 'Свойство объекта', 'value' => $binding['LINKED_PROPERTY'] ?? '', 'hint' => 'Его ONCHANGE вызывается после записи.')) ?>
                    <?= MDJUI::input(array('name' => 'linked_method', 'id' => 'linked_method', 'label' => 'Дополнительный метод', 'value' => $binding['LINKED_METHOD'] ?? '', 'hint' => 'Вызывается после записи свойства. Оставьте пустым, если достаточно ONCHANGE.')) ?>
                    <?= MDJUI::select(array('name' => 'event_mode', 'label' => 'Когда обрабатывать', 'selected' => $binding['EVENT_MODE'] ?? 'always', 'options' => array('always' => 'Каждое сообщение, включая повторы', 'change' => 'Только изменение преобразованного значения'))) ?>
                </div>
                <?= MDJUI::checkbox(array('name' => 'enabled', 'label' => 'Привязка включена', 'value' => '1', 'checked' => (bool)($binding['ENABLED'] ?? true))) ?>
                <?= MDJUI::checkbox(array('name' => 'write_enabled', 'label' => 'Отправлять изменения свойства объекта обратно устройству', 'value' => '1', 'checked' => $canWrite && (bool)($binding['WRITE_ENABLED'] ?? false), 'disabled' => !$canWrite)) ?>
                <?php if (!$canWrite): ?><p class="md-field__hint">Свойство доступно только для чтения. Отправка команд не поддерживается устройством.</p><?php endif; ?>
                <?= $linkedControl ?>
                </div>
            <?php }); ?>
            <?php foreach (array('incoming' => 'Приём: устройство → объект', 'outgoing' => 'Отправка: объект → устройство') as $direction => $label):
                $rule = MajorDoMo\Modules\Zigbee\ValueTransformer::decode($binding[strtoupper($direction)] ?? null) ?? array();
                $rule += array('mode' => 'none', 'pairs' => array(), 'factor' => 1, 'offset' => 0, 'precision' => '', 'case_sensitive' => true);
                if ($direction === 'outgoing' && !$canWrite) echo '<div hidden>';
                $card($label, static function () use ($rule, $direction, $e, $accordion, $property): void { ?>
                    <div x-data="<?= $e(MajorDoMo\Modules\Zigbee\ValueTransformer::json(array('mode' => $rule['mode']))) ?>" class="md-ui-form-stack" data-md-zigbee-rule="<?= $e($direction) ?>">
                        <?= MDJUI::select(array('name' => $direction . '_mode', 'label' => 'Преобразование', 'selected' => $rule['mode'], 'attrs' => array('x-model' => 'mode'), 'options' => array('none' => 'Без изменений', 'binary' => 'ON/OFF, true/false ↔ 1/0', 'exposes' => 'Бинарное значение по описанию устройства (exposes)', 'map' => 'Своя таблица замен', 'number' => 'Число: масштаб, смещение, округление', 'invert' => 'Инверсия 1 ↔ 0'))) ?>
                        <div x-show="mode === 'map'" class="md-ui-form-stack">
                            <p class="md-field__hint">Числа и true/false сохраняют тип. Строку «0» укажите как "0", пустую строку — как "". Неуказанные значения передаются без изменений.</p>
                            <div data-md-zigbee-pairs>
                                <?php $pairs = $rule['pairs'] ?: array(array('from' => 'ON', 'to' => 1), array('from' => 'OFF', 'to' => 0));
                                foreach ($pairs as $index => $pair): ?>
                                    <div class="md-zigbee-pair" data-md-zigbee-pair>
                                        <?= MDJUI::input(array('id' => $direction . '-from-' . $index, 'name' => $direction . '_from[]', 'label' => 'Из', 'value' => MajorDoMo\Modules\Zigbee\ValueTransformer::json($pair['from']))) ?>
                                        <?= MDJUI::input(array('id' => $direction . '-to-' . $index, 'name' => $direction . '_to[]', 'label' => 'В', 'value' => MajorDoMo\Modules\Zigbee\ValueTransformer::json($pair['to']))) ?>
                                        <?= MDJUI::iconButton('x', array('label' => 'Удалить замену', 'attrs' => array('data-md-zigbee-remove-pair' => true))) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div><?= MDJUI::button('Добавить замену', array('variant' => 'secondary', 'size' => 'sm', 'icon' => 'plus', 'attrs' => array('data-md-zigbee-add-pair' => true))) ?></div>
                            <?= MDJUI::checkbox(array('name' => $direction . '_case', 'label' => 'Учитывать регистр строк', 'value' => '1', 'checked' => (bool)$rule['case_sensitive'])) ?>
                        </div>
                        <div x-show="mode === 'number'" class="md-ui-form-grid">
                            <?= MDJUI::input(array('name' => $direction . '_factor', 'label' => 'Умножить на', 'value' => $rule['factor'], 'type' => 'number', 'attrs' => array('step' => 'any'))) ?>
                            <?= MDJUI::input(array('name' => $direction . '_offset', 'label' => 'Прибавить', 'value' => $rule['offset'], 'type' => 'number', 'attrs' => array('step' => 'any'))) ?>
                            <?= MDJUI::input(array('name' => $direction . '_precision', 'label' => 'Знаков после запятой', 'value' => $rule['precision'], 'type' => 'number', 'hint' => 'Пусто — не округлять.', 'attrs' => array('min' => 0, 'max' => 8))) ?>
                        </div>
                        <?php $accordion('md-zigbee-preview-' . $direction . '-' . $property['ID'], 'Проверить преобразование', static function () use ($direction): void { ?>
                            <div class="md-ui-form-stack">
                                <?= MDJUI::input(array('name' => 'preview_' . $direction, 'label' => 'Пример значения', 'value' => 'ON', 'hint' => 'Проверка без сохранения и отправки команды устройству.')) ?>
                                <div><?= MDJUI::button('Проверить', array('type' => 'submit', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'player-play', 'attrs' => array('name' => 'operation', 'value' => 'preview_' . $direction))) ?></div>
                            </div>
                        <?php }); ?>
                    </div>
                <?php }); if ($direction === 'outgoing' && !$canWrite) echo '</div>'; endforeach; ?>
            <div class="md-ui-form-actions">
                <?= MDJUI::button('Сохранить привязку', array('type' => 'submit', 'icon' => 'check')) ?>
                <?= MDJUI::button('Отмена', array('href' => $url('/device/' . $device['ID']), 'variant' => 'ghost')) ?>
            </div>
        </form>
        <?php if ($canWrite): $card('Отправить значение устройству', static function () use ($hidden, $property): void { ?>
            <form method="post" class="md-ui-form-stack">
                <?php $hidden(array('operation' => 'write', 'property_id' => $property['ID'])); ?>
                <?= MDJUI::input(array('name' => 'value', 'label' => 'Значение', 'required' => true, 'hint' => 'Используется сохранённое преобразование отправки. JSON сохраняет тип значения.')) ?>
                <div class="md-ui-form-actions"><?= MDJUI::button('Отправить', array('type' => 'submit', 'variant' => 'secondary', 'icon' => 'send', 'attrs' => array('data-md-confirm' => 'Отправить команду устройству?'))) ?></div>
            </form>
        <?php }); endif; ?>
        <?php $accordion('md-zigbee-exposes-' . $property['ID'], 'Описание свойства (exposes)', static function () use ($expose, $property): void {
            echo MDJUI::textarea(array('id' => 'md-zigbee-exposes-json-' . $property['ID'], 'label' => 'Данные Zigbee2MQTT', 'value' => json_encode($expose, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 'rows' => 8, 'readonly' => true, 'class' => 'font-monospace'));
        }); ?>

    <?php elseif ($device): ?>
        <?php $deviceBridge = null; foreach ($bridges as $bridge) if ((int)$bridge['ID'] === (int)$device['BRIDGE_ID']) $deviceBridge = $bridge;
        $card('Устройство', static function () use ($device, $deviceBridge, $hidden, $e, $locationOptions, $url, $deviceImage): void { ?>
            <div class="md-ui-form-stack">
            <div class="d-flex align-items-center gap-3">
            <?php $deviceImage($device, true); ?>
            <div class="md-ui-form-grid flex-grow-1">
                <div class="md-field"><span class="md-field__label">IEEE-адрес</span><span class="md-table__signal font-monospace"><?= $e($device['IEEE_ADDRESS'] ?: 'Ещё не получен') ?></span></div>
                <div class="md-field"><span class="md-field__label">Модель</span><span><?= $e(trim($device['VENDOR'] . ' ' . $device['MODEL']) ?: 'Нет данных') ?></span></div>
            </div>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <?= MDJUI::status(empty($deviceBridge['ENABLED']) ? 'idle' : (($deviceBridge['STATE'] ?? '') === 'offline' ? 'offline' : $device['AVAILABLE']), empty($deviceBridge['ENABLED']) ? 'Экземпляр выключен' : ($device['AVAILABLE'] === 'unknown' ? 'Нет данных' : '')) ?>
                <span class="md-field__hint"><?= $e($device['DESCRIPTION']) ?></span>
                <?php if (isset($device['_BATTERY'])): ?><?= MDJUI::badge(($device['_BATTERY_ESTIMATED'] ? '≈ ' : '') . $device['_BATTERY'] . '%', $device['_BATTERY_LEVEL'] === 'critical' ? 'danger' : ($device['_BATTERY_LEVEL'] === 'low' ? 'warning' : 'outline'), array('icon' => 'battery')) ?><?php endif; ?>
            </div>
            <form method="post" class="md-ui-form-stack">
                <?php $hidden(array('operation' => 'device_settings', 'device_id' => $device['ID'])); ?>
                <div class="md-ui-form-grid">
                <?= MDJUI::input(array('name' => 'title', 'label' => 'Название в MajorDoMo', 'value' => $device['TITLE'], 'attrs' => array('maxlength' => 190))) ?>
                <?= MDJUI::select(array('name' => 'location_id', 'label' => 'Комната', 'selected' => $device['LOCATION_ID'], 'options' => $locationOptions, 'attrs' => array('data-md-searchable' => true), 'hint' => 'Каждое сообщение свойства вызывает deviceChanged у объекта комнаты.')) ?>
                </div>
                <div class="md-ui-form-actions">
                    <?= MDJUI::button('Сохранить', array('type' => 'submit', 'variant' => 'secondary', 'icon' => 'check')) ?>
                    <?php if ($device['STATUS'] === 'discovered'): ?><?= MDJUI::button('Добавить устройство', array('type' => 'submit', 'icon' => 'plus', 'attrs' => array('name' => 'operation', 'value' => 'adopt'))) ?><?php endif; ?>
                    <?php if ($device['LOCATION_ID']): ?><a class="md-btn md-btn--ghost" href="<?= $e(ROOTHTML . 'panel/locations/' . (int)$device['LOCATION_ID'] . '/logic.html?method=deviceChanged') ?>">Логика комнаты</a><?php endif; ?>
                    <?= MDJUI::button('Мониторинг и батарея', array('href' => $url('/monitoring') . '?device_id=' . (int)$device['ID'], 'variant' => 'ghost', 'icon' => 'activity-heartbeat')) ?>
                </div>
            </form>
            </div>
        <?php }); ?>
        <section class="md-table-wrap" aria-label="Свойства и привязки" data-md-table data-md-table-unit-one="свойство" data-md-table-unit-few="свойства" data-md-table-unit-many="свойств">
            <div class="md-table__toolbar">
                <div class="md-table__search">
                    <?= MDJUI::icon('search') ?><input type="search" data-md-table-search placeholder="Свойство или привязка" aria-label="Поиск свойств" autocomplete="off">
                </div>
                <span class="md-table__meta" data-md-table-meta>Свойств: <?= count($properties) ?></span>
            </div>
            <div class="md-table__scroll"><table class="md-table"><thead><tr>
                <th scope="col"><button type="button" class="md-table__sort" data-md-table-sort="name">Свойство <?= MDJUI::icon('arrows-up-down') ?></button></th>
                <th scope="col">Исходное → результат</th><th scope="col">Привязка</th><th scope="col">Обновлено</th><th scope="col" class="text-end" aria-label="Действия"></th>
            </tr></thead><tbody data-md-table-body>
            <?php foreach ($properties as $row): ?><tr data-md-row data-row-id="<?= (int)$row['ID'] ?>" data-sort-name="<?= $e($row['LABEL'] ?: $row['PATH']) ?>" data-search="<?= $e(($row['LABEL'] ?: '') . ' ' . $row['PATH'] . ' ' . ($row['LINKED_OBJECT'] ?? '') . ' ' . ($row['LINKED_PROPERTY'] ?? '') . ' ' . ($row['LINKED_METHOD'] ?? '')) ?>">
                <td><div class="md-table__name"><span class="md-table__icon"><?= MDJUI::icon('adjustments-horizontal') ?></span><div><a href="<?= $e($url('/property/' . $row['ID'])) ?>"><strong><?= $e($row['LABEL'] ?: $row['PATH']) ?></strong></a><small><?= $e($row['PATH']) ?></small></div></div></td>
                <td class="md-zigbee-value"><?= $e($showValue($row['RAW_VALUE'])) ?> → <?= $e($showValue($row['VALUE'])) ?></td>
                <td><?php if (!empty($row['LINKED_OBJECT'])): ?><?= MDJUI::badge($row['LINKED_OBJECT'] . (($row['LINKED_PROPERTY'] ?? '') !== '' ? '.' . $row['LINKED_PROPERTY'] : ''), 'outline') ?><?php else: ?><span class="md-table__signal">—</span><?php endif; ?><?php if ($row['LAST_ERROR']): ?> <?= MDJUI::badge('Ошибка', 'danger', array('attrs' => array('title' => (string)$row['LAST_ERROR']))) ?><?php endif; ?></td>
                <td><span class="md-table__signal"><?= $e($row['UPDATED'] ?: '—') ?></span></td>
                <td><div class="md-table__actions"><?= MDJUI::iconButton('pencil', array('label' => 'Настроить ' . ($row['LABEL'] ?: $row['PATH']), 'size' => 'sm', 'href' => $url('/property/' . $row['ID']))) ?></div></td>
            </tr><?php endforeach; ?></tbody></table></div>
            <div class="md-table__empty" data-md-table-empty><?= MDJUI::emptyState(array('title' => 'Свойства не найдены', 'text' => 'Измените поиск или дождитесь сообщения устройства.', 'compact' => true)) ?></div>
            <div class="md-table__footer"><span data-md-table-count>Свойств: <?= count($properties) ?></span></div>
        </section>

    <?php elseif ($view === 'bridges'): ?>
        <?= MDJUI::alert('info', 'Один брокер — несколько сетей', 'Настройки соединения берутся из MQTT. Для каждого экземпляра Zigbee2MQTT укажите свой корневой топик, например zigbee2mqtt и zigbee2mqtt-garage. Корни не должны пересекаться.') ?>
        <div class="md-zigbee-grid">
        <?php foreach (array_merge($bridges, array(array('ID' => 0, 'TITLE' => '', 'BASE_TOPIC' => '', 'ENABLED' => 1, 'STATE' => 'unknown'))) as $bridge):
            $card($bridge['ID'] ? $bridge['TITLE'] : 'Добавить экземпляр', static function () use ($bridge, $hidden): void { ?>
                <form method="post" class="md-ui-form-stack">
                    <?php $hidden(array('operation' => 'bridge', 'bridge_id' => $bridge['ID'])); ?>
                    <?= MDJUI::input(array('name' => 'title', 'id' => 'bridge-title-' . $bridge['ID'], 'label' => 'Название', 'value' => $bridge['TITLE'], 'required' => true, 'attrs' => array('maxlength' => 190))) ?>
                    <?= MDJUI::input(array('name' => 'base_topic', 'id' => 'bridge-topic-' . $bridge['ID'], 'label' => 'Корневой топик', 'value' => $bridge['BASE_TOPIC'], 'required' => true, 'hint' => 'Без /# в конце.', 'attrs' => array('maxlength' => 190, 'readonly' => (bool)$bridge['ID']))) ?>
                    <?= MDJUI::checkbox(array('name' => 'enabled', 'id' => 'bridge-enabled-' . $bridge['ID'], 'label' => 'Принимать сообщения', 'value' => '1', 'checked' => (bool)$bridge['ENABLED'])) ?>
                    <div><?= MDJUI::status(!$bridge['ID'] || !$bridge['ENABLED'] ? 'idle' : $bridge['STATE'], !$bridge['ID'] ? 'Ещё не настроен' : (!$bridge['ENABLED'] ? 'Выключен' : ($bridge['STATE'] === 'unknown' ? 'Ожидание соединения' : ''))) ?></div>
                    <div class="md-ui-form-actions"><?= MDJUI::button($bridge['ID'] ? 'Сохранить' : 'Добавить', array('type' => 'submit', 'variant' => $bridge['ID'] ? 'secondary' : 'primary', 'icon' => $bridge['ID'] ? 'check' : 'plus')) ?></div>
                </form>
            <?php }); endforeach; ?>
        </div>
        <?php if ($bridges): $card('Управление Zigbee2MQTT', static function () use ($bridges, $hidden): void { ?>
            <form method="post" class="md-ui-form-stack">
                <?php $hidden(array('operation' => 'command')); ?>
                <?= MDJUI::select(array('name' => 'bridge_id', 'label' => 'Экземпляр', 'options' => array_column($bridges, 'TITLE', 'ID'))) ?>
                <?= MDJUI::select(array('name' => 'command', 'label' => 'Действие', 'options' => array('permit_join' => 'Разрешить / закрыть подключение устройств', 'device/rename' => 'Переименовать устройство', 'device/remove' => 'Удалить устройство из сети', 'device/configure' => 'Повторно настроить устройство', 'device/interview' => 'Повторно опросить возможности', 'device/options' => 'Изменить параметры устройства', 'health_check' => 'Проверить состояние сети', 'networkmap' => 'Запросить карту сети'))) ?>
                <?= MDJUI::textarea(array('name' => 'command_data', 'label' => 'Параметры команды (JSON)', 'value' => '{"time":120}', 'hint' => 'Подключение: {"time":120}; закрыть: {"time":0}; переименование: {"from":"старое имя","to":"новое имя"}; устройство: {"id":"IEEE-адрес"}; проверка сети: {}.')) ?>
                <div class="md-ui-form-actions"><?= MDJUI::button('Выполнить', array('type' => 'submit', 'variant' => 'secondary', 'icon' => 'send', 'attrs' => array('data-md-confirm' => 'Выполнить команду Zigbee2MQTT?'))) ?></div>
            </form>
        <?php }); endif; ?>

    <?php elseif ($view !== 'journal'): ?>
        <div class="md-ui-kpi-grid">
            <?= MDJUI::kpi(array('label' => 'Устройства', 'value' => $stats['devices'], 'icon' => 'devices')) ?>
            <?= MDJUI::kpi(array('label' => 'Обнаруженные', 'value' => $stats['discovered'], 'icon' => 'radar')) ?>
            <?= MDJUI::kpi(array('label' => 'Привязки', 'value' => $stats['bindings'], 'icon' => 'link')) ?>
            <?= MDJUI::kpi(array('label' => 'Ошибки привязок', 'value' => $stats['errors'], 'icon' => 'alert-triangle', 'tone' => $stats['errors'] ? 'danger' : 'success', 'href' => $stats['errors'] && !$isDiscovery ? $listUrl . '?errors=1' : '', 'meta' => $stats['errors'] && !$isDiscovery ? 'Показать устройства' : '')) ?>
        </div>
        <?php if ($isDiscovery) echo MDJUI::alert('info', 'Устройства найдены через MQTT', 'Список заполняется автоматически из bridge/devices и сообщений устройств в настроенных корневых топиках. Выберите комнату и добавьте одно, несколько или все найденные устройства. До добавления их привязки не выполняются.'); ?>
        <?php if ($errorsOnly && !$isDiscovery): ?>
            <?= MDJUI::alert('warning', 'Только устройства с ошибками привязок', 'Откройте устройство: у проблемного свойства будет отметка «Ошибка», а на странице свойства — текст ошибки. <a href="' . $e($listUrl) . '">Показать все устройства</a>.') ?>
        <?php endif; ?>
        <section class="md-table-wrap" aria-label="<?= $isDiscovery ? 'Обнаруженные устройства Zigbee' : 'Устройства Zigbee' ?>">
        <form method="get" action="<?= $e($listUrl) ?>" class="md-table__toolbar">
            <div class="md-table__search"><?= MDJUI::icon('search') ?><input type="search" name="q" value="<?= $e($search) ?>" placeholder="Название, IEEE или модель" aria-label="Поиск устройств" autocomplete="off"></div>
            <select class="md-table__filter" name="bridge_id" aria-label="Фильтр по экземпляру">
                <option value="0">Все экземпляры</option>
                <?php foreach ($bridges as $bridge): ?><option value="<?= (int)$bridge['ID'] ?>" <?= $bridgeId === (int)$bridge['ID'] ? 'selected' : '' ?>><?= $e($bridge['TITLE']) ?></option><?php endforeach; ?>
            </select>
            <?php if ($errorsOnly): ?><input type="hidden" name="errors" value="1"><?php endif; ?>
            <?= MDJUI::button('Найти', array('type' => 'submit', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'search')) ?>
            <?php if ($search !== '' || $bridgeId || $errorsOnly): ?><?= MDJUI::button('Сбросить', array('href' => $listUrl, 'variant' => 'ghost', 'size' => 'sm')) ?><?php endif; ?>
            <span class="md-table__meta">Устройств: <?= $count ?></span>
        </form>
        <?php if (!$devices): echo MDJUI::emptyState(array('icon' => 'access-point', 'title' => $search !== '' || $bridgeId ? 'Устройства не найдены' : ($isDiscovery ? 'Новых устройств пока нет' : 'Добавленных устройств пока нет'), 'text' => $search !== '' || $bridgeId ? 'Измените поиск или фильтр.' : ($isDiscovery ? 'Проверьте корневые топики и цикл MQTT. Устройства появятся после получения списка или первого сообщения.' : 'Откройте «Обнаруженные», чтобы выбрать устройства из MQTT.'), 'action' => MDJUI::button($isDiscovery ? 'Экземпляры Zigbee2MQTT' : 'Обнаруженные устройства', array('href' => $url($isDiscovery ? '/bridges' : '/discovered'), 'icon' => 'plus'))));
        else: ?>
            <?php if ($isDiscovery): ?>
            <form method="post" data-md-zigbee-adopt>
                <?php $hidden(array('operation' => 'adopt', 'filter_bridge_id' => $bridgeId, 'filter_q' => $search)); ?>
                <div class="md-table__toolbar md-zigbee-adopt">
                    <?= MDJUI::select(array('name' => 'location_id', 'id' => 'md-zigbee-adopt-location', 'label' => 'Комната для добавляемых устройств', 'options' => $locationOptions, 'attrs' => array('data-md-searchable' => true))) ?>
                    <?= MDJUI::button('Добавить выбранные', array('type' => 'submit', 'variant' => 'primary', 'size' => 'sm', 'icon' => 'plus')) ?>
                    <?= MDJUI::button('Добавить все найденные (' . $count . ')', array('type' => 'submit', 'variant' => 'secondary', 'size' => 'sm', 'attrs' => array('name' => 'operation', 'value' => 'adopt_all'))) ?>
                </div>
            <?php endif; ?>
            <div class="md-table__scroll"><table class="md-table"><thead><tr><?php if ($isDiscovery): ?><th scope="col"><?= MDJUI::checkbox(array('id' => 'md-zigbee-select-all', 'label' => '', 'attrs' => array('data-md-zigbee-select-all' => true, 'aria-label' => 'Выбрать все устройства на странице'))) ?></th><?php endif; ?><th scope="col">Устройство</th><th scope="col">Экземпляр</th><th scope="col"><?= $isDiscovery ? 'Модель' : 'Комната' ?></th><th scope="col">Доступность</th><th scope="col">Последнее сообщение</th><th scope="col" class="text-end" aria-label="Действия"></th></tr></thead><tbody>
            <?php foreach ($devices as $row): ?><tr>
                <?php if ($isDiscovery): ?><td><?= MDJUI::checkbox(array('id' => 'md-zigbee-select-' . $row['ID'], 'name' => 'device_ids[]', 'value' => $row['ID'], 'label' => '', 'attrs' => array('aria-label' => 'Выбрать ' . ($row['TITLE'] ?: $row['FRIENDLY_NAME'])))) ?></td><?php endif; ?>
                <td><div class="md-table__name"><?php $deviceImage($row); ?><div><a href="<?= $e($url('/device/' . $row['ID'])) ?>"><strong><?= $e($row['TITLE'] ?: $row['FRIENDLY_NAME']) ?></strong></a><small><?= $e($row['IEEE_ADDRESS'] ?: $row['FRIENDLY_NAME']) ?></small></div></div></td>
                <td><?= $e($row['BRIDGE_TITLE']) ?></td><td><?php if ($isDiscovery): ?><div class="md-table__name"><div><strong><?= $e($row['MODEL'] ?: '—') ?></strong><small><?= $e($row['VENDOR']) ?></small></div></div><?php elseif ($row['LOCATION_ID'] && $row['LOCATION_TITLE']): ?><a href="<?= $e(ROOTHTML . 'panel/locations/' . (int)$row['LOCATION_ID'] . '/logic.html?method=deviceChanged') ?>"><?= $e($row['LOCATION_TITLE']) ?></a><?php else: ?><span class="md-table__signal">Не назначена</span><?php endif; ?></td>
                <td><div class="d-flex flex-wrap align-items-center gap-2"><?= MDJUI::status(!$row['BRIDGE_ENABLED'] ? 'idle' : ($row['BRIDGE_STATE'] === 'offline' ? 'offline' : $row['AVAILABLE']), !$row['BRIDGE_ENABLED'] ? 'Выключен' : ($row['BRIDGE_STATE'] === 'offline' ? 'Шлюз недоступен' : ($row['AVAILABLE'] === 'unknown' ? 'Нет данных' : ''))) ?><?php if (isset($row['_BATTERY'])): ?><?= MDJUI::badge(($row['_BATTERY_ESTIMATED'] ? '≈ ' : '') . $row['_BATTERY'] . '%', $row['_BATTERY_LEVEL'] === 'critical' ? 'danger' : ($row['_BATTERY_LEVEL'] === 'low' ? 'warning' : 'outline'), array('icon' => 'battery')) ?><?php endif; ?></div></td>
                <td><span class="md-table__signal"><?= $e($row['LAST_SEEN'] ?: '—') ?></span></td>
                <td><div class="md-table__actions"><?php if ($isDiscovery): ?><?= MDJUI::button('Добавить', array('type' => 'submit', 'size' => 'sm', 'icon' => 'plus', 'attrs' => array('name' => 'device_id', 'value' => $row['ID']))) ?><?php endif; ?><?= MDJUI::iconButton('arrow-right', array('label' => 'Открыть ' . ($row['TITLE'] ?: $row['FRIENDLY_NAME']), 'href' => $url('/device/' . $row['ID']), 'size' => 'sm')) ?></div></td>
            </tr><?php endforeach; ?></tbody></table></div>
            <?php if ($isDiscovery): ?></form><?php endif; ?>
        <?php endif; ?>
        <div class="md-table__footer">
            <span>Показано <?= $count ? (($page - 1) * 40 + 1) : 0 ?>–<?= min($count, $page * 40) ?> из <?= $count ?></span>
            <?php if ($pages > 1): ?><nav aria-label="Страницы устройств"><ul class="pagination md-pagination mb-0">
                <li class="page-item <?= $page === 1 ? 'disabled' : '' ?>"><?php if ($page > 1): ?><a class="page-link" aria-label="Предыдущая страница" href="<?= $e($listUrl . '?' . http_build_query(array('q' => $search, 'bridge_id' => $bridgeId, 'errors' => $errorsOnly ? 1 : 0, 'page' => $page - 1))) ?>"><?= MDJUI::icon('chevron-left') ?></a><?php else: ?><span class="page-link"><?= MDJUI::icon('chevron-left') ?></span><?php endif; ?></li>
                <li class="page-item active"><span class="page-link" aria-current="page"><?= $page ?></span></li>
                <li class="page-item <?= $page === $pages ? 'disabled' : '' ?>"><?php if ($page < $pages): ?><a class="page-link" aria-label="Следующая страница" href="<?= $e($listUrl . '?' . http_build_query(array('q' => $search, 'bridge_id' => $bridgeId, 'errors' => $errorsOnly ? 1 : 0, 'page' => $page + 1))) ?>"><?= MDJUI::icon('chevron-right') ?></a><?php else: ?><span class="page-link"><?= MDJUI::icon('chevron-right') ?></span><?php endif; ?></li>
            </ul></nav><?php endif; ?>
        </div>
        </section>
    <?php endif; ?>

    <?php if ($view === 'journal'): ?>
        <form method="get" action="<?= $e($url('/journal')) ?>" class="md-zigbee-inline">
            <?= MDJUI::select(array('name' => 'bridge_id', 'label' => 'Экземпляр', 'selected' => $bridgeId, 'options' => array(0 => 'Все экземпляры') + array_column($bridges, 'TITLE', 'ID'))) ?>
            <?= MDJUI::button('Показать', array('type' => 'submit', 'variant' => 'secondary')) ?>
        </form>
    <?php endif; ?>
    <?php $bridgeNames = array_column($bridges, 'TITLE', 'ID');
    if ($view === 'journal' || $device): $card('Последние события', static function () use ($events, $accordion, $bridgeNames): void { ?>
        <?php if (!$events) echo MDJUI::emptyState(array('title' => 'Журнал пуст', 'text' => 'Здесь будут ответы Zigbee2MQTT, обработанные привязки и ошибки.', 'compact' => true)); ?>
        <div class="md-ui-form-stack">
            <?php foreach ($events as $event):
                $title = ($event['LEVEL'] === 'error' ? 'Ошибка · ' : '') . $event['ADDED'] . ' · ' . ($bridgeNames[$event['BRIDGE_ID']] ?? $event['BRIDGE_ID']) . ' · ' . $event['TOPIC'];
                $accordion('md-zigbee-event-' . $event['ID'], $title, static function () use ($event): void {
                    echo MDJUI::textarea(array('id' => 'md-zigbee-event-message-' . $event['ID'], 'label' => 'Сообщение', 'value' => $event['MESSAGE'], 'readonly' => true, 'rows' => 4, 'class' => 'font-monospace'));
                });
            endforeach; ?>
        </div>
    <?php }); endif; ?>
</div>
