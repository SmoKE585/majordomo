<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Skeleton loader.
 *
 * Props: variant (text|circle|block), width, height, lines, class, attrs.
 */
$props += array(
    'variant' => 'text',
    'width' => '',
    'height' => '',
    'lines' => 1,
    'class' => '',
    'attrs' => array(),
);

$variant = in_array($props['variant'], array('text', 'circle', 'block'), true) ? $props['variant'] : 'text';
$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-skeleton', 'md-skeleton--' . $variant, $props['class']));
if ($props['width'] !== '') {
    $attrs['style'] = '--md-skeleton-width:' . $props['width'];
}
if ($props['height'] !== '') {
    $attrs['style'] = (isset($attrs['style']) ? $attrs['style'] . ';' : '') . '--md-skeleton-height:' . $props['height'];
}
$attrs['aria-hidden'] = 'true';

$lines = max(1, (int)$props['lines']);
?>
<?php if ($variant === 'text' && $lines > 1): ?>
<div class="md-skeleton-stack <?= MDJUI::e($props['class']) ?>" aria-hidden="true">
    <?php for ($i = 1; $i <= $lines; $i++): ?>
        <span class="<?= MDJUI::cx(array('md-skeleton', 'md-skeleton--text', $i === $lines ? 'md-skeleton--short' : '')) ?>"></span>
    <?php endfor; ?>
</div>
<?php else: ?>
<span<?= MDJUI::attrs($attrs) ?>></span>
<?php endif; ?>

