<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Checkbox.
 *
 * Props: label, description, name, id, value, checked, disabled, required,
 * class, attrs.
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
);

$id = (string)$props['id'];
if ($id === '') {
    $id = 'md-check-' . substr(md5($props['name'] . '|' . $props['label'] . '|' . $props['value']), 0, 8);
}

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['type'] = 'checkbox';
$attrs['data-md-raw-checkbox'] = true;
$attrs['id'] = $id;
$attrs['class'] = MDJUI::cx(array('md-check__input', $props['class']));
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
?>
<label class="md-check" for="<?= MDJUI::e($id) ?>">
    <input<?= MDJUI::attrs($attrs) ?>>
    <span class="md-check__box" aria-hidden="true"><?= MDJUI::icon('check2') ?></span>
    <?php if ($props['label'] !== '' || $props['description'] !== ''): ?>
    <span class="md-check__text">
        <?php if ($props['label'] !== ''): ?><span class="md-check__label"><?= MDJUI::e($props['label']) ?></span><?php endif; ?>
        <?php if ($props['description'] !== ''): ?><span class="md-check__desc"><?= MDJUI::e($props['description']) ?></span><?php endif; ?>
    </span>
    <?php endif; ?>
</label>

