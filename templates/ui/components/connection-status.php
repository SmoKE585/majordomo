<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Connection Status — transport/protocol health row.
 *
 * Props: label, state, state_label, detail, icon, class, attrs.
 */
$props += array(
    'label' => '',
    'state' => 'online',
    'state_label' => '',
    'detail' => '',
    'icon' => 'broadcast',
    'class' => '',
    'attrs' => array(),
);

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-connection', 'md-connection--' . $props['state'], $props['class']));
?>
<div<?= MDJUI::attrs($attrs) ?>>
    <span class="md-connection__icon"><?= MDJUI::icon($props['icon']) ?></span>
    <div class="md-connection__text">
        <span class="md-connection__label"><?= MDJUI::e($props['label']) ?></span>
        <?php if ($props['detail'] !== ''): ?><span class="md-connection__detail"><?= MDJUI::e($props['detail']) ?></span><?php endif; ?>
    </div>
    <?= MDJUI::status($props['state'], $props['state_label']) ?>
</div>


