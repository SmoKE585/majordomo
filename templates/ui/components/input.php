<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Text / number / password / date / time input.
 *
 * Props: type, name, id, value, placeholder, label, description, hint, error,
 * required, optional, disabled, readonly, size (sm|md|lg), icon, prefix, suffix,
 * password_toggle, autocomplete, inputmode, min, max, step, class, attrs, wrap.
 */
$props += array(
    'type' => 'text',
    'name' => '',
    'id' => '',
    'value' => '',
    'placeholder' => '',
    'label' => '',
    'description' => '',
    'hint' => '',
    'error' => '',
    'required' => false,
    'optional' => false,
    'disabled' => false,
    'readonly' => false,
    'size' => 'md',
    'icon' => '',
    'prefix' => '',
    'suffix' => '',
    'password_toggle' => false,
    'autocomplete' => '',
    'inputmode' => '',
    'min' => null,
    'max' => null,
    'step' => null,
    'class' => '',
    'attrs' => array(),
    'wrap' => null,
);

$size = in_array($props['size'], array('sm', 'md', 'lg'), true) ? $props['size'] : 'md';
$hasError = $props['error'] !== '';
$id = (string)$props['id'];
if ($id === '') {
    $id = 'md-input-' . substr(md5($props['name'] . '|' . $props['label'] . '|' . $props['value'] . '|' . $props['type']), 0, 8);
}

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$inputAttrs = $attrs;
$inputAttrs['type'] = $props['type'];
$inputAttrs['id'] = $id;
$inputAttrs['class'] = MDJUI::cx(array('md-input', $size !== 'md' ? 'md-input--' . $size : '', $hasError ? 'is-invalid' : '', $props['class']));
if ($props['name'] !== '') {
    $inputAttrs['name'] = $props['name'];
}
if ($props['value'] !== '') {
    $inputAttrs['value'] = $props['value'];
}
if ($props['placeholder'] !== '') {
    $inputAttrs['placeholder'] = $props['placeholder'];
}
if ($props['autocomplete'] !== '') {
    $inputAttrs['autocomplete'] = $props['autocomplete'];
}
if ($props['inputmode'] !== '') {
    $inputAttrs['inputmode'] = $props['inputmode'];
}
foreach (array('min', 'max', 'step') as $numeric) {
    if ($props[$numeric] !== null && $props[$numeric] !== '') {
        $inputAttrs[$numeric] = $props[$numeric];
    }
}
if ($props['required']) {
    $inputAttrs['required'] = true;
}
if ($props['disabled']) {
    $inputAttrs['disabled'] = true;
}
if ($props['readonly']) {
    $inputAttrs['readonly'] = true;
}
if ($hasError) {
    $inputAttrs['aria-invalid'] = 'true';
    $inputAttrs['aria-describedby'] = $id . '-help';
}

$hasShell = $props['icon'] !== '' || $props['prefix'] !== '' || $props['suffix'] !== '' || $props['password_toggle'];
$control = '';
if ($hasShell) {
    $control .= '<div class="' . MDJUI::cx(array('md-input-shell', $hasError ? 'is-invalid' : '', $size !== 'md' ? 'md-input-shell--' . $size : '')) . '">';
    if ($props['prefix'] !== '') {
        $control .= '<span class="md-input-addon md-input-addon--prefix">' . MDJUI::e($props['prefix']) . '</span>';
    }
    if ($props['icon'] !== '') {
        $control .= '<span class="md-input-icon">' . MDJUI::icon($props['icon']) . '</span>';
    }
    $control .= '<input' . MDJUI::attrs($inputAttrs) . '>';
    if ($props['password_toggle'] && $props['type'] === 'password') {
        $control .= '<button type="button" class="md-input-action" data-md-password-toggle="' . MDJUI::e($id) . '" aria-label="Показать пароль" aria-pressed="false">' . MDJUI::icon('eye') . '</button>';
    }
    if ($props['suffix'] !== '') {
        $control .= '<span class="md-input-addon md-input-addon--suffix">' . MDJUI::e($props['suffix']) . '</span>';
    }
    $control .= '</div>';
} else {
    $control = '<input' . MDJUI::attrs($inputAttrs) . '>';
}

$wrap = $props['wrap'];
if ($wrap === null) {
    $wrap = $props['label'] !== '' || $props['hint'] !== '' || $hasError || $props['description'] !== '';
}
if ($wrap) {
    echo MDJUI::field(array(
        'label' => $props['label'],
        'for' => $id,
        'description' => $props['description'],
        'hint' => $props['hint'],
        'error' => $props['error'],
        'required' => $props['required'],
        'optional' => $props['optional'],
    ), $control);
} else {
    echo $control;
}
?>

