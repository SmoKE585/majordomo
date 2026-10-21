<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Choice Group — radio options rendered as selectable tiles.
 *
 * Props: name, options, selected, required, aria_label, class, attrs.
 * Option: value, label, description, icon, disabled (or value => label map).
 */
$props += array(
    'name' => '',
    'options' => array(),
    'selected' => '',
    'required' => false,
    'aria_label' => '',
    'class' => '',
    'attrs' => array(),
);

$selected = is_array($props['selected'])
    ? array_map('strval', $props['selected'])
    : array((string)$props['selected']);

$normalized = array();
foreach ((array)$props['options'] as $key => $option) {
    if (is_array($option)) {
        $value = isset($option['value']) ? (string)$option['value'] : (string)$key;
        $label = isset($option['label']) ? $option['label'] : $value;
        $description = isset($option['description']) ? $option['description'] : '';
        $icon = isset($option['icon']) ? $option['icon'] : '';
        $disabled = !empty($option['disabled']);
    } else {
        $value = (string)$key;
        $label = (string)$option;
        $description = '';
        $icon = '';
        $disabled = false;
    }
    $normalized[] = array(
        'value' => $value,
        'label' => $label,
        'description' => $description,
        'icon' => $icon,
        'disabled' => $disabled,
    );
}

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-choice-group', $props['class']));
$attrs['role'] = 'radiogroup';
if ($props['aria_label'] !== '') {
    $attrs['aria-label'] = $props['aria_label'];
}
if ($props['required']) {
    $attrs['aria-required'] = 'true';
}
?>
<div<?= MDJUI::attrs($attrs) ?>>
    <?php foreach ($normalized as $option): ?>
    <?php $checked = in_array($option['value'], $selected, true); ?>
    <label class="md-choice">
        <input<?= MDJUI::attrs(array(
            'class' => 'md-choice__input',
            'type' => 'radio',
            'name' => $props['name'],
            'value' => $option['value'],
            'checked' => $checked,
            'disabled' => $option['disabled'],
            'required' => $props['required'],
            'data-md-raw-checkbox' => true,
        )) ?>>
        <span class="md-choice__surface">
            <?php if ($option['icon'] !== ''): ?>
            <span class="md-choice__icon"><?= MDJUI::icon($option['icon']) ?></span>
            <?php endif; ?>
            <span class="md-choice__body">
                <span class="md-choice__title"><?= MDJUI::e($option['label']) ?></span>
                <?php if ($option['description'] !== ''): ?>
                <span class="md-choice__desc"><?= MDJUI::e($option['description']) ?></span>
                <?php endif; ?>
            </span>
            <span class="md-choice__marker" aria-hidden="true"></span>
        </span>
    </label>
    <?php endforeach; ?>
</div>
