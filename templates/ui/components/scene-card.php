<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Scene Card — automation / scene with schedule and quick run.
 *
 * Props: name, description, icon, tone, schedule, state, state_label, runs,
 * enabled, class, attrs.
 */
$props += array(
    'name' => '',
    'description' => '',
    'icon' => 'wand',
    'tone' => 'primary',
    'schedule' => '',
    'state' => 'online',
    'state_label' => '',
    'runs' => '',
    'enabled' => true,
    'class' => '',
    'attrs' => array(),
);

$tone = in_array($props['tone'], array('primary', 'success', 'warning', 'danger', 'info', 'neutral'), true)
    ? $props['tone']
    : 'primary';

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-scene', 'md-scene--' . $tone, $props['class']));
?>
<article<?= MDJUI::attrs($attrs) ?> data-scene-name="<?= MDJUI::e($props['name']) ?>" x-data="mdScene({ enabled: <?= $props['enabled'] ? 'true' : 'false' ?> })" :class="{ 'is-disabled': !enabled, 'is-running': running }">
    <header class="md-scene__head">
        <span class="md-scene__icon"><?= MDJUI::icon($props['icon']) ?></span>
        <label class="md-switch md-switch--sm" aria-label="Сцена включена">
            <input type="checkbox" class="md-switch__input" data-md-raw-checkbox x-model="enabled">
            <span class="md-switch__track" aria-hidden="true"><span class="md-switch__thumb"></span></span>
        </label>
    </header>
    <div class="md-scene__body">
        <h3 class="md-scene__name"><?= MDJUI::e($props['name']) ?></h3>
        <p class="md-scene__description"><?= MDJUI::e($props['description']) ?></p>
        <?= MDJUI::status($props['state'], $props['state_label']) ?>
    </div>
    <footer class="md-scene__footer">
        <span class="md-scene__schedule"><?= MDJUI::icon('calendar-check') ?><?= MDJUI::e($props['schedule']) ?></span>
        <span class="md-scene__runs"><?= MDJUI::e($props['runs']) ?></span>
        <button type="button" class="md-btn md-btn--ghost md-btn--sm md-scene__run" @click="run()" :disabled="!enabled || running" aria-label="Запустить сцену">
            <span x-show="!running"><?= MDJUI::icon('play-fill') ?><span class="md-btn__label">Запустить</span></span>
            <span x-show="running" x-cloak><?= MDJUI::icon('arrow-clockwise', array('class' => 'md-spin')) ?><span class="md-btn__label">Запуск…</span></span>
        </button>
    </footer>
</article>



