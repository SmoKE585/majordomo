<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Toggle switch.
 *
 * Props: label, description, name, id, value, checked, disabled, required,
 * class, attrs, aria_label.
 */
$props += array(
    'label' => '',
    'description' => '',
    'name' => '',
    'id' => '',
    'value' => '1',
    'checked' => false,
    'disabled' => false,
    'required' => false,
    'class' => '',
    'attrs' => array(),
    'aria_label' => '',
);

$id = (string)$props['id'];
if ($id === '') {
    $id = 'md-switch-' . substr(md5($props['name'] . '|' . $props['label'] . '|' . $props['value']), 0, 8);
}

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['type'] = 'checkbox';
$attrs['data-md-raw-checkbox'] = true;
$attrs['id'] = $id;
$attrs['class'] = MDJUI::cx(array('md-switch__input', $props['class']));
if ($props['name'] !== '') {
    $attrs['name'] = $props['name'];
}
if ($props['value'] !== '') {
    $attrs['value'] = $props['value'];
}
if ($props['checked']) {
    $attrs['checked'] = true;
}
if ($props['disabled']) {
    $attrs['disabled'] = true;
}
if ($props['required']) {
    $attrs['required'] = true;
}
if ($props['label'] === '' && $props['aria_label'] !== '') {
    $attrs['aria-label'] = $props['aria_label'];
}
?>
<label class="md-switch" for="<?= MDJUI::e($id) ?>">
    <input<?= MDJUI::attrs($attrs) ?>>
    <span class="md-switch__track" aria-hidden="true"><span class="md-switch__thumb"></span></span>
    <?php if ($props['label'] !== '' || $props['description'] !== ''): ?>
    <span class="md-switch__text">
        <?php if ($props['label'] !== ''): ?><span class="md-switch__label"><?= MDJUI::e($props['label']) ?></span><?php endif; ?>
        <?php if ($props['description'] !== ''): ?><span class="md-switch__desc"><?= MDJUI::e($props['description']) ?></span><?php endif; ?>
    </span>
    <?php endif; ?>
</label>

