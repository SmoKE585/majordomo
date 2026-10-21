<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Progress bar.
 *
 * Props: value, max, tone, label, show_value, size (sm|md), class, attrs.
 */
$props += array(
    'value' => 0,
    'max' => 100,
    'tone' => 'primary',
    'label' => '',
    'show_value' => false,
    'size' => 'md',
    'class' => '',
    'attrs' => array(),
);

$max = max(1, (float)$props['max']);
$value = max(0, min($max, (float)$props['value']));
$percent = round($value / $max * 100, 1);
$tone = in_array($props['tone'], array('primary', 'success', 'warning', 'danger', 'info', 'neutral'), true)
    ? $props['tone']
    : 'primary';

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-progress', 'md-progress--' . $tone, $props['size'] === 'sm' ? 'md-progress--sm' : '', $props['class']));
$attrs['role'] = 'progressbar';
$attrs['aria-valuenow'] = $value;
$attrs['aria-valuemin'] = 0;
$attrs['aria-valuemax'] = $max;
$attrs['aria-label'] = $props['label'] !== '' ? $props['label'] : 'Прогресс';
?>
<div class="md-progress-row">
    <?php if ($props['label'] !== '' || $props['show_value']): ?>
    <div class="md-progress-row__head">
        <span class="md-progress-row__label"><?= MDJUI::e($props['label']) ?></span>
        <?php if ($props['show_value']): ?><span class="md-progress-row__value"><?= MDJUI::e($percent) ?>%</span><?php endif; ?>
    </div>
    <?php endif; ?>
    <div<?= MDJUI::attrs($attrs) ?>><span class="md-progress__bar" style="width:<?= MDJUI::e($percent) ?>%"></span></div>
</div>

