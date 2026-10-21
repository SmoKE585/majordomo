<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Button Group — groups related buttons or segmented choices.
 *
 * Props: label (aria-label), class, attrs. Slot contains buttons.
 */
$props += array(
    'label' => 'Группа действий',
    'class' => '',
    'attrs' => array(),
);

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-btn-group', $props['class']));
$attrs['role'] = 'group';
$attrs['aria-label'] = $props['label'];
?>
<div<?= MDJUI::attrs($attrs) ?>><?= $slot ?></div>

