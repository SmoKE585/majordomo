<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Sparkline — compact bar chart for a 24-point series.
 *
 * Props: series (array of numbers), tone, height, label, value, class, attrs.
 * Bars are rendered with CSS so the dashboard does not need a chart runtime.
 */
$props += array(
    'series' => array(),
    'tone' => 'primary',
    'height' => 44,
    'label' => '',
    'value' => '',
    'class' => '',
    'attrs' => array(),
);

$series = array_values(array_filter(array_map('floatval', (array)$props['series']), function ($v) {
    return $v >= 0;
}));
$max = $series ? max($series) : 1;
$max = $max > 0 ? $max : 1;
$tone = in_array($props['tone'], array('primary', 'success', 'warning', 'danger', 'info', 'neutral'), true)
    ? $props['tone']
    : 'primary';

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-spark', 'md-spark--' . $tone, $props['class']));
$attrs['role'] = 'img';
$attrs['aria-label'] = $props['label'] !== '' ? $props['label'] : 'График';
?>
<div<?= MDJUI::attrs($attrs) ?>>
    <?php if ($props['label'] !== '' || $props['value'] !== ''): ?>
    <div class="md-spark__head">
        <span class="md-spark__label"><?= MDJUI::e($props['label']) ?></span>
        <span class="md-spark__value"><?= MDJUI::e($props['value']) ?></span>
    </div>
    <?php endif; ?>
    <div class="md-spark__bars" style="height:<?= (int)$props['height'] ?>px">
        <?php foreach ($series as $index => $point): ?>
            <span class="md-spark__bar" style="height:<?= MDJUI::e(max(4, round($point / $max * 100, 1))) ?>%" title="<?= MDJUI::e($point) ?>"></span>
        <?php endforeach; ?>
    </div>
</div>

