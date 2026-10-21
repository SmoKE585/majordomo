<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Last Updated / Last Seen.
 *
 * Props: label, value, datetime, class, attrs.
 */
$props += array(
    'label' => 'Обновлено',
    'value' => '',
    'datetime' => '',
    'class' => '',
    'attrs' => array(),
);

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-lastseen', $props['class']));
if ($props['datetime'] !== '') {
    $attrs['title'] = $props['datetime'];
}
?>
<span<?= MDJUI::attrs($attrs) ?>><?= MDJUI::icon('clock') ?><span class="md-lastseen__label"><?= MDJUI::e($props['label']) ?></span><time<?= $props['datetime'] !== '' ? ' datetime="' . MDJUI::e($props['datetime']) . '"' : '' ?>><?= MDJUI::e($props['value']) ?></time></span>

