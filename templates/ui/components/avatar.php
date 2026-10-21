<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Avatar — user or entity initials/image.
 *
 * Props: name, src, size (sm|md|lg), tone (neutral|primary|success|warning|
 * danger|info), class, attrs.
 */
$props += array(
    'name' => '',
    'src' => '',
    'size' => 'md',
    'tone' => 'neutral',
    'class' => '',
    'attrs' => array(),
);

$size = in_array($props['size'], array('sm', 'md', 'lg'), true) ? $props['size'] : 'md';
$tone = in_array($props['tone'], array('neutral', 'primary', 'success', 'warning', 'danger', 'info'), true)
    ? $props['tone']
    : 'neutral';

$initials = '';
$parts = preg_split('/\s+/u', trim((string)$props['name']));
if (is_array($parts)) {
    foreach (array_slice($parts, 0, 2) as $part) {
        if ($part !== '') {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8');
        }
    }
}
if ($initials === '') {
    $initials = '?';
}

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-avatar', 'md-avatar--' . $size, 'md-avatar--' . $tone, $props['class']));
$attrs['title'] = $props['name'];
?>
<span<?= MDJUI::attrs($attrs) ?>><?php if ($props['src'] !== ''): ?><img src="<?= MDJUI::e($props['src']) ?>" alt="<?= MDJUI::e($props['name']) ?>" loading="lazy"><?php else: ?><?= MDJUI::e($initials) ?><?php endif; ?></span>

