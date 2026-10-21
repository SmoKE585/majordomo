<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Meter / resource usage row.
 *
 * Props: label, value, percent, tone, hint, icon, show_value, class, attrs.
 */
$props += array(
    'label' => '',
    'value' => '',
    'percent' => 0,
    'tone' => 'primary',
    'hint' => '',
    'icon' => '',
    'show_value' => true,
    'class' => '',
    'attrs' => array(),
);

$tone = in_array($props['tone'], array('primary', 'success', 'warning', 'danger', 'info', 'neutral'), true)
    ? $props['tone']
    : 'primary';
$percent = max(0, min(100, (float)$props['percent']));
$value = $props['value'] !== '' ? $props['value'] : number_format($percent, 0, ',', ' ') . '%';

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-meter', 'md-meter--' . $tone, $props['class']));
?>
<div<?= MDJUI::attrs($attrs) ?>>
    <div class="md-meter__head">
        <span class="md-meter__label">
            <?php if ($props['icon']): ?><?= MDJUI::icon($props['icon']) ?><?php endif; ?>
            <?= MDJUI::e($props['label']) ?>
        </span>
        <?php if ($props['show_value']): ?><span class="md-meter__value"><?= MDJUI::e($value) ?></span><?php endif; ?>
    </div>
    <div class="md-progress md-progress--<?= MDJUI::e($tone) ?> md-progress--sm" role="progressbar" aria-label="<?= MDJUI::e($props['label']) ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)round($percent) ?>">
        <span class="md-progress__bar" style="width:<?= MDJUI::e((string)$percent) ?>%"></span>
    </div>
    <?php if ($props['hint'] !== ''): ?><div class="md-meter__hint"><?= MDJUI::e($props['hint']) ?></div><?php endif; ?>
</div>
