<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Alert.
 *
 * Props: type (info|success|warning|danger), title, text, actions (HTML),
 * dismissible, icon, class, attrs.
 */
$props += array(
    'type' => 'info',
    'title' => '',
    'text' => '',
    'actions' => '',
    'dismissible' => false,
    'icon' => '',
    'class' => '',
    'attrs' => array(),
);

$type = in_array($props['type'], array('info', 'success', 'warning', 'danger'), true) ? $props['type'] : 'info';
$icons = array(
    'info' => 'info-circle',
    'success' => 'check-circle',
    'warning' => 'exclamation-triangle',
    'danger' => 'x-octagon',
);
$icon = $props['icon'] !== '' ? $props['icon'] : $icons[$type];

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-alert', 'md-alert--' . $type, $props['class']));
$attrs['role'] = $type === 'danger' ? 'alert' : 'status';
?>
<div<?= MDJUI::attrs($attrs) ?>>
    <span class="md-alert__icon"><?= MDJUI::icon($icon) ?></span>
    <div class="md-alert__body">
        <?php if ($props['title'] !== ''): ?><div class="md-alert__title"><?= MDJUI::e($props['title']) ?></div><?php endif; ?>
        <?php if ($props['text'] !== ''): ?><div class="md-alert__text"><?= MDJUI::e($props['text']) ?></div><?php endif; ?>
        <?php if ($props['actions'] !== ''): ?><div class="md-alert__actions"><?= $props['actions'] ?></div><?php endif; ?>
    </div>
    <?php if ($props['dismissible']): ?>
        <button type="button" class="md-alert__close" data-md-dismiss="alert" aria-label="Закрыть"><?= MDJUI::icon('x-lg') ?></button>
    <?php endif; ?>
</div>

