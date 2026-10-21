<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Range slider with a live value output.
 *
 * Props: name, id, value, min, max, step, label, unit, hint, show_value, disabled,
 * class, attrs.
 */
$props += array(
    'name' => '',
    'id' => '',
    'value' => 50,
    'min' => 0,
    'max' => 100,
    'step' => 1,
    'label' => '',
    'unit' => '',
    'hint' => '',
    'show_value' => true,
    'disabled' => false,
    'class' => '',
    'attrs' => array(),
);

$id = (string)$props['id'];
if ($id === '') {
    $id = 'md-range-' . substr(md5($props['name'] . '|' . $props['label'] . '|' . $props['value']), 0, 8);
}
$outputId = $id . '-value';

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['type'] = 'range';
$attrs['id'] = $id;
$attrs['class'] = MDJUI::cx(array('md-range', $props['class']));
$attrs['min'] = $props['min'];
$attrs['max'] = $props['max'];
$attrs['step'] = $props['step'];
$attrs['value'] = $props['value'];
$attrs['data-md-range'] = true;
$attrs['data-md-range-output'] = $outputId;
if ($props['unit'] !== '') {
    $attrs['data-md-range-unit'] = $props['unit'];
}
if ($props['name'] !== '') {
    $attrs['name'] = $props['name'];
}
if ($props['disabled']) {
    $attrs['disabled'] = true;
}
?>
<div class="md-range-field <?= MDJUI::e($props['class']) ?>">
    <?php if ($props['label'] !== '' || $props['show_value']): ?>
    <div class="md-range-field__head">
        <?php if ($props['label'] !== ''): ?><label class="md-range-field__label" for="<?= MDJUI::e($id) ?>"><?= MDJUI::e($props['label']) ?></label><?php endif; ?>
        <?php if ($props['show_value']): ?><output class="md-range-field__value" id="<?= MDJUI::e($outputId) ?>" for="<?= MDJUI::e($id) ?>"><?= MDJUI::e($props['value'] . $props['unit']) ?></output><?php endif; ?>
    </div>
    <?php endif; ?>
    <input<?= MDJUI::attrs($attrs) ?>>
    <div class="md-range-field__scale" aria-hidden="true"><span><?= MDJUI::e($props['min']) ?><?= MDJUI::e($props['unit']) ?></span><span><?= MDJUI::e($props['max']) ?><?= MDJUI::e($props['unit']) ?></span></div>
    <?php if ($props['hint'] !== ''): ?><div class="md-field__hint"><?= MDJUI::e($props['hint']) ?></div><?php endif; ?>
</div>

