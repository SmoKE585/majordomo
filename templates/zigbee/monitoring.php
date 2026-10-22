<?php
if (!isset($healthSettings, $hidden, $accordion)) return;
$s = $healthSettings;
$number = static function (string $key, string $label, string $hint = '', int $max = 525600) use ($s): string {
    return MDJUI::input(array('name' => 'health_' . $key, 'label' => $label, 'type' => 'number', 'value' => $s[$key], 'hint' => $hint, 'required' => true, 'attrs' => array('min' => 0, 'max' => $max, 'step' => 1)));
};
$scopeName = $healthDevice ? ($healthDevice['TITLE'] ?: $healthDevice['FRIENDLY_NAME']) : 'Все устройства';
?>
<?= MDJUI::alert('info', $healthDevice ? 'Настройки: ' . $scopeName : 'Общие настройки мониторинга', 'Проверка раз в минуту для добавленных устройств. Say и PHP-код настраиваются независимо. При потере MQTT или шлюза предупреждения о зависимых датчиках приостанавливаются.') ?>
<?php if ($healthDevice): ?>
<div class="md-ui-form-actions">
    <?= MDJUI::button('К устройству', array('href' => $url('/device/' . $healthDevice['ID']), 'variant' => 'secondary', 'icon' => 'arrow-left')) ?>
    <?= MDJUI::button('Общие настройки', array('href' => $url('/monitoring'), 'variant' => 'ghost', 'icon' => 'adjustments-horizontal')) ?>
</div>
<?php endif; ?>
<form method="post" class="md-ui-form-stack" x-data="<?= $e(json_encode(array('inherit' => (bool)$healthInherit, 'source' => $s['battery_source'], 'profile' => $s['voltage_profile']))) ?>">
    <?php $hidden(array('operation' => 'health_settings', 'health_device_id' => $healthDevice['ID'] ?? 0)); ?>
    <?php if ($healthDevice): ?>
        <?= MDJUI::checkbox(array('name' => 'health_inherit', 'value' => '1', 'label' => 'Использовать общие настройки', 'checked' => (bool)$healthInherit, 'attrs' => array('x-model' => 'inherit'))) ?>
    <?php endif; ?>
    <fieldset class="md-ui-form-stack" x-show="!inherit" :disabled="inherit" aria-label="Настройки мониторинга">
    <?php $card('Контроль состояния', static function () use ($s, $number): void { ?>
        <div class="md-ui-form-stack">
            <?= MDJUI::checkbox(array('name' => 'health_enabled', 'label' => 'Мониторинг включён', 'value' => '1', 'checked' => (bool)$s['enabled'])) ?>
            <div class="md-ui-form-grid">
                <?= $number('offline_delay', 'Подтверждение потери связи, секунд', 'Краткие отключения не вызывают оповещений.', 86400) ?>
                <?= $number('timeout_mains', 'Без сообщений: сетевые устройства, минут', '0 — только availability Zigbee2MQTT.') ?>
                <?= $number('timeout_battery', 'Без сообщений: батарейные устройства, минут', '0 — только availability. Учитывайте длительный сон датчиков.') ?>
            </div>
            <p class="md-field__hint">Тайм-аут отсчитывается от последнего принятого сообщения. Retained-сообщение без исходного времени не доказывает свежий контакт с датчиком. Первое нормальное состояние не озвучивается.</p>
        </div>
    <?php }); ?>
    <?php $card('Батарея', static function () use ($s, $number, $e): void { ?>
        <div class="md-ui-form-stack">
            <div class="md-ui-form-grid">
                <?= MDJUI::select(array('name' => 'health_battery_source', 'label' => 'Источник заряда', 'selected' => $s['battery_source'], 'attrs' => array('x-model' => 'source'), 'options' => array('auto' => 'Процент устройства; иначе напряжение батарейного датчика', 'voltage' => 'Рассчитывать из выбранного напряжения', 'off' => 'Не контролировать батарею'))) ?>
                <?= MDJUI::select(array('name' => 'health_voltage_profile', 'label' => 'Оценка по напряжению', 'selected' => $s['voltage_profile'], 'attrs' => array('x-model' => 'profile'), 'options' => array('none' => 'Не рассчитывать', 'linear3v' => '3 В: линейно от 2,1 до 3,0 В', 'alkaline' => '1,5 В: линейно от 0,9 до 1,5 В', 'custom' => 'Свои точки напряжение → процент'))) ?>
            </div>
            <div x-show="source !== 'off' && profile !== 'none'" class="md-ui-form-stack">
                <div class="md-ui-form-grid">
                    <?= MDJUI::input(array('name' => 'health_voltage_path', 'label' => 'Свойство напряжения', 'value' => $s['voltage_path'], 'hint' => 'Например voltage или battery_voltage.')) ?>
                    <?= MDJUI::select(array('name' => 'health_voltage_unit', 'label' => 'Единицы исходного напряжения', 'selected' => $s['voltage_unit'], 'options' => array('mV' => 'Милливольты (например 2950)', 'V' => 'Вольты (например 2.95)'))) ?>
                </div>
                <div x-show="profile === 'custom'">
                    <?= MDJUI::textarea(array('name' => 'health_voltage_points', 'label' => 'Точки кривой (JSON)', 'rows' => 5, 'class' => 'font-monospace', 'value' => json_encode($s['voltage_points'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 'hint' => 'По возрастанию. Например [{"voltage":2.1,"percent":0},{"voltage":2.7,"percent":40},{"voltage":3.0,"percent":100}]. Напряжение здесь всегда в вольтах.')) ?>
                </div>
                <p class="md-field__hint">Это приблизительная оценка: профиль выбирается под батарею и число элементов. Напряжение сохраняется, расчётный процент появляется отдельно в battery_estimated при следующем сообщении. Готовый процент устройства имеет приоритет в автоматическом режиме.</p>
            </div>
            <div class="md-ui-form-grid" x-show="source !== 'off'">
                <?= $number('low', 'Низкий заряд, %', '', 100) ?>
                <?= $number('critical', 'Критический заряд, %', '', 100) ?>
                <?= $number('recovery', 'Возврат в норму, %', 'Выше низкого порога — защита от колебаний.', 100) ?>
            </div>
        </div>
    <?php }); ?>
    <?php foreach (\MajorDoMo\Modules\Zigbee\HealthSettings::EVENTS as $event => $label):
        $rule = $s['events'][$event];
        $accordion('md-zigbee-health-' . $event, $label, static function () use ($event, $label, $rule, $e, $healthDevice): void {
            $prefix = 'health_' . $event . '_'; ?>
            <div class="md-ui-form-stack">
                <?= MDJUI::checkbox(array('name' => $prefix . 'enabled', 'label' => 'Оповещения для события включены', 'value' => '1', 'checked' => (bool)$rule['enabled'])) ?>
                <div class="md-ui-form-grid">
                    <?= MDJUI::input(array('name' => $prefix . 'repeat_hours', 'label' => 'Повторять каждые, часов', 'type' => 'number', 'value' => $rule['repeat_hours'], 'attrs' => array('min' => 0, 'max' => 8760, 'step' => 1), 'hint' => '0 — один раз на событие. Восстановление всегда сообщается один раз.')) ?>
                    <?= MDJUI::input(array('name' => $prefix . 'level', 'label' => 'Уровень сообщения say', 'type' => 'number', 'value' => $rule['level'], 'attrs' => array('min' => 0, 'max' => 10, 'step' => 1))) ?>
                </div>
                <?= MDJUI::checkbox(array('name' => $prefix . 'say', 'label' => 'Отправлять через say', 'value' => '1', 'checked' => (bool)$rule['say'])) ?>
                <?= MDJUI::textarea(array('name' => $prefix . 'message', 'label' => 'Текст say', 'value' => $rule['message'], 'rows' => 2, 'hint' => 'Подстановки: {device}, {room}, {value}, {event}.')) ?>
                <p class="md-field__hint">Свой PHP-код выполняется независимо от say, без PHP-тегов. Доступны $event и $params: EVENT, KIND (device/bridge/mqtt), DEVICE_ID, DEVICE_TITLE, IEEE_ADDRESS, BRIDGE_ID, LOCATION_ID, ROOM, OLD_STATE, NEW_STATE, VALUE, REASON, REPEAT, INCIDENT_ID. Пустое поле отключает этот канал.</p>
                <div class="md-code-editor" data-code-editor data-code-editor-mode="php" data-code-editor-key="zigbee_health_<?= (int)($healthDevice['ID'] ?? 0) ?>_<?= $e($event) ?>" data-code-editor-autosave="0" data-code-editor-autofocus="0" data-code-editor-min-lines="6" data-code-editor-max-lines="22" data-code-editor-validate-url="" data-code-editor-autosave-url="">
                    <div class="md-code-editor__toolbar" data-code-editor-toolbar>
                        <span class="md-code-editor__lang-badge">PHP · <?= $e($label) ?></span>
                        <span class="md-code-editor__toolbar-spacer"></span>
                        <span class="md-code-editor__status" data-code-editor-status aria-live="polite"></span>
                        <button type="button" class="md-code-editor__icon-btn" data-code-editor-action="search" aria-label="Найти в коде"><?= MDJUI::icon('search') ?></button>
                        <button type="button" class="md-code-editor__icon-btn" data-code-editor-action="fullscreen" aria-label="Редактор на весь экран"><?= MDJUI::icon('maximize') ?></button>
                    </div>
                    <label class="md-visually-hidden" for="<?= $e($prefix) ?>code">PHP-обработчик: <?= $e($label) ?></label>
                    <textarea class="md-textarea" id="<?= $e($prefix) ?>code" name="<?= $e($prefix) ?>code" rows="6" spellcheck="false"><?= $e($rule['code']) ?></textarea>
                </div>
            </div>
        <?php }); endforeach; ?>
    </fieldset>
    <div class="md-ui-form-actions">
        <?= MDJUI::button('Сохранить мониторинг', array('type' => 'submit', 'icon' => 'check')) ?>
        <?= MDJUI::button('Журнал событий', array('href' => $url('/journal'), 'variant' => 'ghost', 'icon' => 'list-details')) ?>
    </div>
</form>
