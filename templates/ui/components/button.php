<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Button — canonical action component.
 *
 * Props: variant (primary|secondary|outline|ghost|danger|success), size
 * (sm|md|lg), icon, icon_after, loading, disabled, href, type, name, value,
 * block, class, attrs, label.
 */
$props += array(
    'label' => '',
    'variant' => 'primary',
    'size' => 'md',
    'icon' => '',
    'icon_after' => '',
    'loading' => false,
    'disabled' => false,
    'href' => '',
    'type' => 'button',
    'name' => '',
    'value' => '',
    'block' => false,
    'class' => '',
    'attrs' => array(),
);

$variant = in_array($props['variant'], array('primary', 'secondary', 'outline', 'ghost', 'danger', 'success'), true)
    ? $props['variant']
    : 'primary';
$size = in_array($props['size'], array('sm', 'md', 'lg'), true) ? $props['size'] : 'md';
$isLoading = (bool)$props['loading'];
$isDisabled = (bool)$props['disabled'];

$classes = MDJUI::cx(array(
    'md-btn',
    'md-btn--' . $variant,
    $size !== 'md' ? 'md-btn--' . $size : '',
    $props['block'] ? 'md-btn--block' : '',
    $isLoading ? 'is-loading' : '',
    $isDisabled ? 'is-disabled' : '',
    $props['class'],
));

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = $classes;
if ($props['name'] !== '') {
    $attrs['name'] = $props['name'];
}
if ($props['value'] !== '') {
    $attrs['value'] = $props['value'];
}
if ($isLoading) {
    $attrs['aria-busy'] = 'true';
}
if ($isDisabled) {
    $attrs['aria-disabled'] = 'true';
    $attrs['disabled'] = true;
}

$labelHtml = $props['label'] !== '' ? MDJUI::e($props['label']) : $slot;
$inner = '';
if ($props['icon']) {
    $inner .= MDJUI::icon($props['icon'], array('class' => 'md-btn__icon'));
}
$inner .= '<span class="md-btn__label">' . $labelHtml . '</span>';
if ($props['icon_after']) {
    $inner .= MDJUI::icon($props['icon_after'], array('class' => 'md-btn__icon md-btn__icon--after'));
}
if ($isLoading) {
    $inner .= '<span class="md-spinner md-btn__spinner" aria-hidden="true"></span>';
}
?>
<?php if ($props['href'] !== ''): ?>
<?php $linkAttrs = $attrs; unset($linkAttrs['disabled']); ?>
<a<?= MDJUI::attrs(array_merge($linkAttrs, array('href' => $isDisabled ? null : $props['href'], 'role' => 'button', 'tabindex' => $isDisabled ? '-1' : null))) ?>><?= $inner ?></a>
<?php else: ?>
<button<?= MDJUI::attrs(array_merge($attrs, array('type' => $props['type']))) ?>><?= $inner ?></button>
<?php endif; ?>

