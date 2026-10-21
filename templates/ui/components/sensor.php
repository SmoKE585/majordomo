<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Sensor Widget — temperature / humidity / illuminance.
 *
 * Props: label, value, unit, icon, tone, trend, trend_dir, hint, class, attrs.
 */
$props += array(
    'label' => '',
    'value' => '',
    'unit' => '',
    'icon' => 'activity',
    'tone' => 'primary',
    'trend' => '',
    'trend_dir' => 'up',
    'hint' => '',
    'class' => '',
    'attrs' => array(),
);

$tone = in_array($props['tone'], array('primary', 'success', 'warning', 'danger', 'info', 'neutral'), true)
    ? $props['tone']
    : 'primary';
$trendDir = $props['trend_dir'] === 'down' ? 'down' : 'up';
$trendIcon = $trendDir === 'down' ? 'arrow-down-right' : 'arrow-up-right';

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-sensor', 'md-sensor--' . $tone, $props['class']));
?>
<article<?= MDJUI::attrs($attrs) ?>>
    <header class="md-sensor__head">
        <span class="md-sensor__icon"><?= MDJUI::icon($props['icon']) ?></span>
        <span class="md-sensor__label"><?= MDJUI::e($props['label']) ?></span>
        <?php if ($props['trend'] !== ''): ?>
            <span class="md-sensor__trend md-sensor__trend--<?= MDJUI::e($trendDir) ?>"><?= MDJUI::icon($trendIcon) ?><?= MDJUI::e($props['trend']) ?></span>
        <?php endif; ?>
    </header>
    <div class="md-sensor__value"><?= MDJUI::e($props['value']) ?><small><?= MDJUI::e($props['unit']) ?></small></div>
    <?php if ($props['hint'] !== ''): ?><div class="md-sensor__hint"><?= MDJUI::e($props['hint']) ?></div><?php endif; ?>
</article>


