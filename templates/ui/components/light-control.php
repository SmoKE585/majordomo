<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Light Control — on/off, brightness and colour temperature.
 *
 * Props: name, room, on, brightness, temp, icon, class, attrs.
 * Interactive via Alpine (`mdLight`).
 */
$props += array(
    'name' => 'Основной свет',
    'room' => 'Гостиная',
    'on' => true,
    'brightness' => 72,
    'temp' => 2700,
    'icon' => 'bulb',
    'class' => '',
    'attrs' => array(),
);

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-light', $props['class']));
?>
<article<?= MDJUI::attrs($attrs) ?> x-data="mdLight({ on: <?= $props['on'] ? 'true' : 'false' ?>, brightness: <?= (int)$props['brightness'] ?>, temp: <?= (int)$props['temp'] ?> })" :class="{ 'is-off': !on }">
    <header class="md-light__head">
        <span class="md-light__icon"><?= MDJUI::icon($props['icon']) ?></span>
        <div class="md-light__identity">
            <h3 class="md-light__name"><?= MDJUI::e($props['name']) ?></h3>
            <div class="md-light__room"><?= MDJUI::e($props['room']) ?></div>
        </div>
        <label class="md-switch md-switch--sm" aria-label="Питание">
            <input type="checkbox" class="md-switch__input" data-md-raw-checkbox x-model="on">
            <span class="md-switch__track" aria-hidden="true"><span class="md-switch__thumb"></span></span>
        </label>
    </header>
    <div class="md-light__body">
        <div class="md-light__value"><span x-text="on ? brightness : 0"><?= (int)$props['brightness'] ?></span><small>%</small></div>
        <div class="md-light__hint" x-text="on ? 'Тёплый белый' : 'Выключен'">Тёплый белый</div>
        <input type="range" class="md-range md-light__range" min="1" max="100" step="1" x-model.number="brightness" :disabled="!on" aria-label="Яркость">
        <div class="md-light__presets">
            <button type="button" class="md-light__preset" @click="on = true; brightness = 25">25%</button>
            <button type="button" class="md-light__preset" @click="on = true; brightness = 50">50%</button>
            <button type="button" class="md-light__preset" @click="on = true; brightness = 75">75%</button>
            <button type="button" class="md-light__preset" @click="on = true; brightness = 100">100%</button>
        </div>
        <div class="md-light__temps" role="group" aria-label="Цветовая температура">
            <button type="button" class="md-light__temp" :class="{ 'is-active': temp === 2200 }" @click="temp = 2200">2200K</button>
            <button type="button" class="md-light__temp" :class="{ 'is-active': temp === 2700 }" @click="temp = 2700">2700K</button>
            <button type="button" class="md-light__temp" :class="{ 'is-active': temp === 4000 }" @click="temp = 4000">4000K</button>
            <button type="button" class="md-light__temp" :class="{ 'is-active': temp === 5600 }" @click="temp = 5600">5600K</button>
        </div>
    </div>
</article>



