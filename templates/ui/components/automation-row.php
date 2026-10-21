<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Automation Status row.
 *
 * Props: name, description, icon, state, state_label, last, runs, class, attrs.
 */
$props += array(
    'name' => '',
    'description' => '',
    'icon' => 'bolt',
    'state' => 'online',
    'state_label' => '',
    'last' => '',
    'runs' => null,
    'class' => '',
    'attrs' => array(),
);

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-automation', 'md-automation--' . $props['state'], $props['class']));
?>
<div<?= MDJUI::attrs($attrs) ?>>
    <span class="md-automation__icon"><?= MDJUI::icon($props['icon']) ?></span>
    <div class="md-automation__text">
        <span class="md-automation__name"><?= MDJUI::e($props['name']) ?></span>
        <?php if ($props['description'] !== ''): ?><span class="md-automation__description"><?= MDJUI::e($props['description']) ?></span><?php endif; ?>
    </div>
    <div class="md-automation__meta">
        <?php if ($props['runs'] !== null): ?><span><?= (int)$props['runs'] ?> <?= MDJUI::e(MDJUI::plural((int)$props['runs'], 'запуск', 'запуска', 'запусков')) ?></span><?php endif; ?>
        <?php if ($props['last'] !== ''): ?><?= MDJUI::lastSeen(array('label' => 'Последний', 'value' => $props['last'])) ?><?php endif; ?>
    </div>
    <?= MDJUI::status($props['state'], $props['state_label']) ?>
</div>


