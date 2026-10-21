<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Badge — compact status or metadata label.
 *
 * Props: label, tone (neutral|primary|success|warning|danger|info|outline),
 * dot, class, attrs.
 */
$props += array(
    'label' => '',
    'tone' => 'neutral',
    'dot' => false,
    'class' => '',
    'attrs' => array(),
);

$tone = in_array($props['tone'], array('neutral', 'primary', 'success', 'warning', 'danger', 'info', 'outline'), true)
    ? $props['tone']
    : 'neutral';

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-badge', 'md-badge--' . $tone, $props['class']));
?>
<span<?= MDJUI::attrs($attrs) ?>><?php if ($props['dot']): ?><span class="md-badge__dot" aria-hidden="true"></span><?php endif; ?><?= MDJUI::e($props['label']) ?></span>

