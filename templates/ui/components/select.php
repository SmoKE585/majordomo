<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Select — native select, optionally enhanced by Tom Select.
 *
 * Props: name, id, options (value => label or list of ['value','label']),
 * selected, placeholder, multiple, searchable, label, description, hint, error,
 * required, optional, disabled, size, class, attrs.
 */
$props += array(
    'name' => '',
    'id' => '',
    'options' => array(),
    'selected' => '',
    'placeholder' => '',
    'multiple' => false,
    'searchable' => false,
    'label' => '',
    'description' => '',
    'hint' => '',
    'error' => '',
    'required' => false,
    'optional' => false,
    'disabled' => false,
    'size' => 'md',
    'class' => '',
    'attrs' => array(),
);

$hasError = $props['error'] !== '';
$id = (string)$props['id'];
if ($id === '') {
    $id = 'md-select-' . substr(md5($props['name'] . '|' . $props['label']), 0, 8);
}
$size = in_array($props['size'], array('sm', 'md', 'lg'), true) ? $props['size'] : 'md';
$selected = is_array($props['selected']) ? array_map('strval', $props['selected']) : array((string)$props['selected']);

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['id'] = $id;
$attrs['class'] = MDJUI::cx(array('md-select', $size !== 'md' ? 'md-select--' . $size : '', $hasError ? 'is-invalid' : '', $props['class']));
if ($props['name'] !== '') {
    $attrs['name'] = $props['name'];
}
if ($props['required']) {
    $attrs['required'] = true;
}
if ($props['disabled']) {
    $attrs['disabled'] = true;
}
if ($props['multiple']) {
    $attrs['multiple'] = true;
}
if ($props['searchable']) {
    $attrs['data-md-searchable'] = true;
}
if ($hasError) {
    $attrs['aria-invalid'] = 'true';
    $attrs['aria-describedby'] = $id . '-help';
}

$options = is_array($props['options']) ? $props['options'] : array();
$control = '<select' . MDJUI::attrs($attrs) . '>';
if (!$props['multiple'] && $props['placeholder'] !== '') {
    $control .= '<option value="">' . MDJUI::e($props['placeholder']) . '</option>';
}
foreach ($options as $value => $label) {
    if (is_array($label)) {
        $optionValue = isset($label['value']) ? (string)$label['value'] : (string)$value;
        $optionLabel = isset($label['label']) ? $label['label'] : $optionValue;
        $optionDisabled = !empty($label['disabled']);
    } else {
        $optionValue = (string)$value;
        $optionLabel = $label;
        $optionDisabled = false;
    }
    $isSelected = in_array($optionValue, $selected, true);
    $control .= '<option value="' . MDJUI::e($optionValue) . '"'
        . ($isSelected ? ' selected' : '')
        . ($optionDisabled ? ' disabled' : '')
        . '>' . MDJUI::e($optionLabel) . '</option>';
}
$control .= '</select>';

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

