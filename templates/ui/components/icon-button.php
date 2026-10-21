<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Icon Button — square icon-only action with an accessible label.
 *
 * Props: icon (required), label (aria-label), variant, size, loading, disabled,
 * href, type, class, attrs. `label` is also used as a native tooltip by default.
 */
$props += array(
    'icon' => '',
    'label' => '',
    'variant' => 'ghost',
    'size' => 'md',
    'loading' => false,
    'disabled' => false,
    'href' => '',
    'type' => 'button',
    'tooltip' => true,
    'class' => '',
    'attrs' => array(),
);

$variant = in_array($props['variant'], array('primary', 'secondary', 'outline', 'ghost', 'danger', 'success'), true)
    ? $props['variant']
    : 'ghost';
$size = in_array($props['size'], array('sm', 'md', 'lg'), true) ? $props['size'] : 'md';
$isLoading = (bool)$props['loading'];
$isDisabled = (bool)$props['disabled'];

$classes = MDJUI::cx(array(
    'md-icon-btn',
    'md-icon-btn--' . $variant,
    $size !== 'md' ? 'md-icon-btn--' . $size : '',
    $isLoading ? 'is-loading' : '',
    $isDisabled ? 'is-disabled' : '',
    $props['class'],
));

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = $classes;
$attrs['aria-label'] = $props['label'] !== '' ? $props['label'] : $props['icon'];
if ($props['tooltip'] && $props['label'] !== '') {
    $attrs['data-bs-toggle'] = 'tooltip';
    $attrs['data-bs-title'] = $props['label'];
}
if ($isLoading) {
    $attrs['aria-busy'] = 'true';
}
if ($isDisabled) {
    $attrs['aria-disabled'] = 'true';
    $attrs['disabled'] = true;
}

$inner = $isLoading
    ? '<span class="md-spinner" aria-hidden="true"></span>'
    : MDJUI::icon($props['icon']);
?>
<?php if ($props['href'] !== ''): ?>
<?php $linkAttrs = $attrs; unset($linkAttrs['disabled']); ?>
<a<?= MDJUI::attrs(array_merge($linkAttrs, array('href' => $isDisabled ? null : $props['href'], 'role' => 'button', 'tabindex' => $isDisabled ? '-1' : null))) ?>><?= $inner ?></a>
<?php else: ?>
<button<?= MDJUI::attrs(array_merge($attrs, array('type' => $props['type']))) ?>><?= $inner ?></button>
<?php endif; ?>

