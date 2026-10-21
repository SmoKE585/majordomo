<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * KPI / Stats Card.
 *
 * Props: label, value, unit, icon, tone, meta, delta, delta_tone, href, value_html,
 * meta_html, class, attrs.
 */
$props += array(
    'label' => '',
    'value' => '',
    'unit' => '',
    'icon' => '',
    'tone' => 'primary',
    'meta' => '',
    'meta_html' => '',
    'delta' => '',
    'delta_tone' => 'neutral',
    'href' => '',
    'value_html' => '',
    'class' => '',
    'attrs' => array(),
);

$tone = in_array($props['tone'], array('primary', 'success', 'warning', 'danger', 'info', 'neutral'), true)
    ? $props['tone']
    : 'primary';
$deltaTone = in_array($props['delta_tone'], array('success', 'danger', 'neutral'), true)
    ? $props['delta_tone']
    : 'neutral';

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-kpi', 'md-kpi--' . $tone, $props['class']));
if ($props['href'] !== '') {
    $attrs['href'] = $props['href'];
}
$tag = $props['href'] !== '' ? 'a' : 'div';
?>
<<?= $tag ?><?= MDJUI::attrs($attrs) ?>>
    <div class="md-kpi__top">
        <?php if ($props['icon']): ?><span class="md-kpi__icon"><?= MDJUI::icon($props['icon']) ?></span><?php endif; ?>
        <span class="md-kpi__label"><?= MDJUI::e($props['label']) ?></span>
        <?php if ($props['delta'] !== ''): ?><span class="md-kpi__delta md-kpi__delta--<?= MDJUI::e($deltaTone) ?>"><?= MDJUI::e($props['delta']) ?></span><?php endif; ?>
    </div>
    <div class="md-kpi__value"><?= $props['value_html'] !== '' ? $props['value_html'] : MDJUI::e($props['value']) ?><?php if ($props['unit'] !== ''): ?><small><?= MDJUI::e($props['unit']) ?></small><?php endif; ?></div>
    <?php if ($props['meta'] !== '' || $props['meta_html'] !== ''): ?>
        <div class="md-kpi__meta"><?= $props['meta_html'] !== '' ? $props['meta_html'] : MDJUI::e($props['meta']) ?></div>
    <?php endif; ?>
</<?= $tag ?>>

