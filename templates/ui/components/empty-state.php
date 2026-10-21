<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Empty State.
 *
 * Props: icon, title, text, action (HTML), compact, class, attrs.
 */
$props += array(
    'icon' => 'inbox',
    'title' => 'Нет данных',
    'text' => '',
    'action' => '',
    'compact' => false,
    'class' => '',
    'attrs' => array(),
);

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-empty', $props['compact'] ? 'md-empty--compact' : '', $props['class']));
?>
<div<?= MDJUI::attrs($attrs) ?>>
    <span class="md-empty__icon"><?= MDJUI::icon($props['icon']) ?></span>
    <div class="md-empty__title"><?= MDJUI::e($props['title']) ?></div>
    <?php if ($props['text'] !== ''): ?><p class="md-empty__text"><?= MDJUI::e($props['text']) ?></p><?php endif; ?>
    <?php if ($props['action'] !== ''): ?><div class="md-empty__actions"><?= $props['action'] ?></div><?php endif; ?>
</div>


