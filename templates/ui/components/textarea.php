<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Textarea.
 *
 * Props: name, id, value, placeholder, label, description, hint, error, rows,
 * required, optional, disabled, readonly, size, class, attrs.
 */
$props += array(
    'name' => '',
    'id' => '',
    'value' => '',
    'placeholder' => '',
    'label' => '',
    'description' => '',
    'hint' => '',
    'error' => '',
    'rows' => 4,
    'required' => false,
    'optional' => false,
    'disabled' => false,
    'readonly' => false,
    'size' => 'md',
    'class' => '',
    'attrs' => array(),
);

$hasError = $props['error'] !== '';
$id = (string)$props['id'];
if ($id === '') {
    $id = 'md-textarea-' . substr(md5($props['name'] . '|' . $props['label']), 0, 8);
}
$size = in_array($props['size'], array('sm', 'md', 'lg'), true) ? $props['size'] : 'md';

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['id'] = $id;
$attrs['class'] = MDJUI::cx(array('md-textarea', $size !== 'md' ? 'md-textarea--' . $size : '', $hasError ? 'is-invalid' : '', $props['class']));
$attrs['rows'] = max(2, (int)$props['rows']);
if ($props['name'] !== '') {
    $attrs['name'] = $props['name'];
}
if ($props['placeholder'] !== '') {
    $attrs['placeholder'] = $props['placeholder'];
}
if ($props['required']) {
    $attrs['required'] = true;
}
if ($props['disabled']) {
    $attrs['disabled'] = true;
}
if ($props['readonly']) {
    $attrs['readonly'] = true;
}
if ($hasError) {
    $attrs['aria-invalid'] = 'true';
    $attrs['aria-describedby'] = $id . '-help';
}

$control = '<textarea' . MDJUI::attrs($attrs) . '>' . MDJUI::e($props['value']) . '</textarea>';

if ($props['label'] !== '' || $props['hint'] !== '' || $props['description'] !== '' || $hasError) {
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

