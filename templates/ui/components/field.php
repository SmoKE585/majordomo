<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Field Group — label, description, control slot and validation message.
 *
 * Props: label, for, description, hint, error, required, optional, class, attrs.
 * Slot is the control markup.
 */
$props += array(
    'label' => '',
    'for' => '',
    'description' => '',
    'hint' => '',
    'error' => '',
    'required' => false,
    'optional' => false,
    'class' => '',
    'attrs' => array(),
);

$hasError = $props['error'] !== '';
$help = $props['error'] !== '' ? $props['error'] : ($props['hint'] !== '' ? $props['hint'] : $props['description']);
$helpId = $props['for'] !== '' ? $props['for'] . '-help' : '';

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-field', $hasError ? 'is-invalid' : '', $props['class']));
?>
<div<?= MDJUI::attrs($attrs) ?>>
    <?php if ($props['label'] !== ''): ?>
    <label class="md-field__label"<?= $props['for'] !== '' ? ' for="' . MDJUI::e($props['for']) . '"' : '' ?>>
        <?= MDJUI::e($props['label']) ?>
        <?php if ($props['required']): ?><span class="md-field__req" aria-hidden="true">*</span><?php endif; ?>
        <?php if ($props['optional']): ?><span class="md-field__optional">необязательно</span><?php endif; ?>
    </label>
    <?php endif; ?>
    <div class="md-field__control"><?= $slot ?></div>
    <?php if ($help !== ''): ?>
        <div class="<?= $hasError ? 'md-field__error' : 'md-field__hint' ?>"<?= $hasError ? ' role="alert"' : '' ?><?= $helpId !== '' ? ' id="' . MDJUI::e($helpId) . '"' : '' ?>>
            <?php if ($hasError): ?><?= MDJUI::icon('exclamation-circle') ?><?php endif; ?>
            <span><?= MDJUI::e($help) ?></span>
        </div>
    <?php endif; ?>
</div>

