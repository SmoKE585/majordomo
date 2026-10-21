<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Thermostat Widget — current temperature and adjustable setpoint.
 *
 * Props: name, room, current, target, min, max, step, mode, class, attrs.
 * Interactive via Alpine; no server round-trip in the demo.
 */
$props += array(
    'name' => 'Термостат',
    'room' => '',
    'current' => 22.5,
    'target' => 23,
    'min' => 16,
    'max' => 28,
    'step' => 0.5,
    'mode' => 'heat',
    'class' => '',
    'attrs' => array(),
);

$min = (float)$props['min'];
$max = (float)$props['max'];
$step = (float)$props['step'];
$current = (float)$props['current'];
$target = (float)$props['target'];
$range = max(0.1, $max - $min);
$fill = max(0, min(100, ($target - $min) / $range * 100));

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-thermostat', $props['class']));
?>
<article<?= MDJUI::attrs($attrs) ?> x-data="{ target: <?= (float)$target ?>, current: <?= (float)$current ?>, min: <?= $min ?>, max: <?= $max ?>, step: <?= $step ?>, mode: '<?= MDJUI::e($props['mode']) ?>' }">
    <header class="md-thermostat__head">
        <div>
            <h3 class="md-thermostat__name"><?= MDJUI::e($props['name']) ?></h3>
            <?php if ($props['room'] !== ''): ?><div class="md-thermostat__room"><?= MDJUI::e($props['room']) ?></div><?php endif; ?>
        </div>
        <?= MDJUI::status('online', 'Онлайн') ?>
    </header>
    <div class="md-thermostat__dial" :style="'--md-thermo-fill:' + Math.max(0, Math.min(100, (target - min) / (max - min) * 100)) + '%'">
        <div class="md-thermostat__dial-inner">
            <span class="md-thermostat__current" x-text="current.toFixed(1).replace('.', ',') + '°'"></span>
            <span class="md-thermostat__target-label">Уставка</span>
            <span class="md-thermostat__target" x-text="target.toFixed(1).replace('.', ',') + '°'"></span>
        </div>
    </div>
    <div class="md-thermostat__controls">
        <button type="button" class="md-thermostat__step" @click="target = Math.max(min, Math.round((target - step) * 10) / 10)" aria-label="Уменьшить температуру"><?= MDJUI::icon('dash-lg') ?></button>
        <div class="md-thermostat__modes" role="group" aria-label="Режим">
            <button type="button" class="md-thermostat__mode" :class="{ 'is-active': mode === 'heat' }" @click="mode = 'heat'" title="Обогрев" aria-label="Обогрев"><?= MDJUI::icon('fire') ?></button>
            <button type="button" class="md-thermostat__mode" :class="{ 'is-active': mode === 'cool' }" @click="mode = 'cool'" title="Охлаждение" aria-label="Охлаждение"><?= MDJUI::icon('snow') ?></button>
            <button type="button" class="md-thermostat__mode" :class="{ 'is-active': mode === 'auto' }" @click="mode = 'auto'" title="Авто" aria-label="Автоматический режим"><?= MDJUI::icon('arrow-repeat') ?></button>
        </div>
        <button type="button" class="md-thermostat__step" @click="target = Math.min(max, Math.round((target + step) * 10) / 10)" aria-label="Увеличить температуру"><?= MDJUI::icon('plus-lg') ?></button>
    </div>
    <input type="range" class="md-range md-thermostat__range" min="<?= $min ?>" max="<?= $max ?>" step="<?= $step ?>" x-model.number="target" aria-label="Уставка температуры">
</article>


