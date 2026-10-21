<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/** Smart home components. */
?>
<section class="md-ui-section" id="smarthome" data-md-doc-section>
    <div class="md-ui-section__intro">
        <?= MDJUI::sectionHeading(array(
            'eyebrow' => '08 · Smart home',
            'title' => 'Компоненты умного дома',
            'description' => 'Устройства, датчики, климат, свет, сцены и энергия используют те же карточки, статусы и токены, что и остальная админка. Отдельного «IoT-стиля» нет.',
            'level' => 2,
        )) ?>
        <span class="md-ui-section__kicker">MDJUI::deviceCard() · sensor() · thermostat()</span>
    </div>

    <?php md_ui_demo(array(
        'title' => 'Device Card и Device Status',
        'desc' => 'Карточка устройства показывает имя, комнату, состояние, ключевое значение, время обновления и качество связи. Офлайн и ошибка — это состояния, а не другой компонент.',
        'api' => 'MDJUI::deviceCard() · MDJUI::status()',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::deviceCard([
  'name' => 'Кухня · Основной свет',
  'room' => 'Кухня',
  'type' => 'Свет',
  'icon' => 'lightbulb',
  'state' => 'online',
  'state_label' => 'Онлайн',
  'value' => '72%',
  'value_label' => 'Яркость',
  'updated' => '12 секунд назад',
  'connection' => 'Zigbee',
  'signal' => 92,
  'actions' => MDJUI::iconButton('power', ['label' => 'Включить']),
]) ?>
CODE,
    ), function () use ($devices) { ?>
        <div class="md-ui-device-grid">
            <?php foreach ($devices as $device): ?>
                <?= MDJUI::deviceCard(array(
                    'name' => $device['name'],
                    'room' => $device['room'],
                    'type' => $device['type'],
                    'icon' => $device['icon'],
                    'state' => $device['state'],
                    'state_label' => $device['state_label'],
                    'value' => $device['value'],
                    'value_label' => $device['value_label'],
                    'tone' => $device['tone'],
                    'updated' => $device['updated'],
                    'connection' => $device['connection'],
                    'signal' => $device['signal'],
                    'actions' => MDJUI::iconButton('power', array('label' => 'Переключить', 'size' => 'sm', 'class' => 'md-device__power')),
                )) ?>
            <?php endforeach; ?>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Sensor Widget',
        'desc' => 'Датчик показывает значение, единицу, тренд и контекст. Тренд — вспомогательная информация, а не единственный способ понять состояние.',
        'api' => 'MDJUI::sensor()',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::sensor([
  'label' => 'Температура',
  'value' => '22,5',
  'unit' => '°C',
  'icon' => 'thermometer-half',
  'tone' => 'primary',
  'trend' => '−0,4°',
  'trend_dir' => 'down',
  'hint' => 'Гостиная · 4 секунды назад',
]) ?>
CODE,
    ), function () use ($sensors) { ?>
        <div class="md-ui-sensor-grid">
            <?php foreach ($sensors as $sensor): ?>
                <?= MDJUI::sensor($sensor) ?>
            <?php endforeach; ?>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Thermostat и Light Control',
        'desc' => 'Оба виджета полностью интерактивны: уставка, режим, яркость, цветовая температура. Состояние локальное, для сохранения отправляйте его на сервер через Livewire или fetch.',
        'api' => 'MDJUI::thermostat() · lightControl()',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::thermostat([
  'name' => 'Гостиная · Термостат',
  'room' => 'Гостиная',
  'current' => 22.5,
  'target' => 23,
  'min' => 16,
  'max' => 28,
]) ?>

<?= MDJUI::lightControl([
  'name' => 'Основной свет',
  'room' => 'Кухня',
  'on' => true,
  'brightness' => 72,
]) ?>
CODE,
    ), function () { ?>
        <div class="md-ui-control-grid">
            <?= MDJUI::thermostat(array('name' => 'Гостиная · Термостат', 'room' => 'Гостиная', 'current' => 22.5, 'target' => 23, 'min' => 16, 'max' => 28, 'step' => 0.5)) ?>
            <?= MDJUI::lightControl(array('name' => 'Основной свет', 'room' => 'Кухня', 'on' => true, 'brightness' => 72, 'temp' => 2700)) ?>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Scene Card и Automation Status',
        'desc' => 'Сценарий — это карточка с расписанием, состоянием и быстрым запуском. Automation status — компактная строка для списка автоматизаций.',
        'api' => 'MDJUI::sceneCard() · automationRow()',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::sceneCard([
  'name' => 'Доброе утро',
  'description' => 'Свет 40%, шторы открыты, кофеварка',
  'icon' => 'sunrise',
  'tone' => 'warning',
  'schedule' => 'Пн–Пт · 07:00',
  'state' => 'online',
  'state_label' => 'Активна',
  'runs' => '18 запусков',
]) ?>
CODE,
    ), function () use ($scenes, $automations) { ?>
        <div class="md-ui-scene-grid">
            <?php foreach ($scenes as $scene): ?>
                <?= MDJUI::sceneCard($scene) ?>
            <?php endforeach; ?>
        </div>
        <div class="md-ui-automation-list">
            <?php foreach ($automations as $automation): ?>
                <?= MDJUI::automationRow($automation) ?>
            <?php endforeach; ?>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Energy Usage Widget, Mini Chart и Sparkline',
        'desc' => 'Энергия показывает сегодняшний расход, структуру и пик. Sparkline — компактные бары без отдельного chart-рантайма; для осей и tooltip используйте библиотеку графиков.',
        'api' => 'MDJUI::energyWidget() · sparkline()',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::energyWidget([
  'today' => '8,4 кВт·ч',
  'yesterday' => '9,1 кВт·ч',
  'delta' => '−7,7%',
  'cost' => '412 ₽',
  'peak' => '19:00–21:00',
  'usage' => $usage,
  'series' => $series,
]) ?>
CODE,
    ), function () use ($energy) { ?>
        <div class="md-ui-energy-grid">
            <?= MDJUI::energyWidget(array(
                'today' => $energy['today'],
                'yesterday' => $energy['yesterday'],
                'delta' => $energy['delta'],
                'cost' => $energy['cost'],
                'peak' => $energy['peak'],
                'usage' => $energy['usage'],
                'series' => $energy['series'],
            )) ?>
            <div class="md-card">
                <div class="md-card__head">
                    <div class="md-card__titles">
                        <h2 class="md-card__title"><?= MDJUI::icon('graph-up', array('class' => 'md-card__title-icon')) ?>Мини-графики</h2>
                        <p class="md-card__subtitle">Одни и те же данные в разных тонах</p>
                    </div>
                </div>
                <div class="md-card__body md-ui-spark-stack">
                    <?= MDJUI::sparkline(array('series' => $energy['series'], 'label' => 'Расход', 'value' => '8,4 кВт·ч', 'tone' => 'primary', 'height' => 38)) ?>
                    <?= MDJUI::sparkline(array('series' => array_reverse($energy['series']), 'label' => 'Температура', 'value' => '22,5 °C', 'tone' => 'warning', 'height' => 38)) ?>
                    <?= MDJUI::sparkline(array('series' => $energy['series'], 'label' => 'Влажность', 'value' => '48%', 'tone' => 'info', 'height' => 38)) ?>
                </div>
            </div>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Connection Status и Last Seen',
        'desc' => 'Связь — это отдельный статус транспорта, а не состояние устройства. Last seen всегда содержит относительное время и точную дату в title.',
        'api' => 'MDJUI::connectionStatus() · lastSeen()',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::connectionStatus([
  'label' => 'MQTT',
  'state' => 'online',
  'state_label' => 'Подключено',
  'detail' => '12 мс',
  'icon' => 'broadcast',
]) ?>

<?= MDJUI::lastSeen([
  'label' => 'Обновлено',
  'value' => '12 секунд назад',
  'datetime' => '2026-10-20 22:01:48',
]) ?>
CODE,
    ), function () use ($connections) { ?>
        <div class="md-ui-connection-grid">
            <?php foreach ($connections as $connection): ?>
                <?= MDJUI::connectionStatus($connection) ?>
            <?php endforeach; ?>
        </div>
        <div class="md-ui-lastseen-row">
            <?= MDJUI::lastSeen(array('label' => 'Обновлено', 'value' => '12 секунд назад', 'datetime' => '2026-10-20 22:01:48')) ?>
            <?= MDJUI::lastSeen(array('label' => 'Последний запуск', 'value' => 'сегодня, 07:00', 'datetime' => '2026-10-20 07:00:00')) ?>
            <?= MDJUI::lastSeen(array('label' => 'Последняя ошибка', 'value' => '2 часа назад', 'datetime' => '2026-10-20 20:02:00')) ?>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Группы устройств и помещений',
        'desc' => 'Комната — контейнер для устройств и климата. Показывает количество онлайн-устройств и активные состояния, но не заменяет карточку устройства.',
        'api' => 'MDJUI::roomCard()',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::roomCard([
  'name' => 'Гостиная',
  'icon' => 'lamp',
  'devices' => 5,
  'online' => 5,
  'temperature' => '22,5 °C',
  'humidity' => '44%',
  'active' => 'Свет 72% · Термостат 23 °C',
  'state' => 'online',
]) ?>
CODE,
    ), function () use ($rooms) { ?>
        <div class="md-ui-room-grid">
            <?php foreach ($rooms as $room): ?>
                <?= MDJUI::roomCard($room) ?>
            <?php endforeach; ?>
        </div>
        <div class="md-ui-note md-ui-note--spaced">
            <?= MDJUI::icon('info-circle') ?>
            <span>Для группировки в таблице используйте фильтр по помещению; для визуальной навигации — Room Card.</span>
        </div>
    <?php }); ?>
</section>


