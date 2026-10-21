<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Status — dot + label for connection and device states.
 *
 * Props: state (online|offline|error|warning|idle), label, pulse, class, attrs.
 */
$props += array(
    'state' => 'idle',
    'label' => '',
    'pulse' => false,
    'class' => '',
    'attrs' => array(),
);

$map = array(
    'online' => 'Онлайн',
    'offline' => 'Офлайн',
    'error' => 'Ошибка',
    'warning' => 'Внимание',
    'idle' => 'Неактивно',
);
$state = isset($map[$props['state']]) ? $props['state'] : 'idle';
$label = $props['label'] !== '' ? $props['label'] : $map[$state];

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-status', 'md-status--' . $state, $props['pulse'] ? 'is-pulse' : '', $props['class']));
?>
<span<?= MDJUI::attrs($attrs) ?>><span class="md-status__dot" aria-hidden="true"></span><span class="md-status__label"><?= MDJUI::e($label) ?></span></span>

