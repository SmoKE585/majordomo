<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Card — canonical surface/container.
 *
 * Props: title, subtitle, eyebrow, icon, actions (HTML), footer (HTML),
 * flush, compact, interactive, class, body_class, id, attrs. Slot is the body.
 */
$props += array(
    'title' => '',
    'subtitle' => '',
    'eyebrow' => '',
    'icon' => '',
    'actions' => '',
    'footer' => '',
    'flush' => false,
    'compact' => false,
    'interactive' => false,
    'class' => '',
    'body_class' => '',
    'id' => '',
    'heading_level' => 3,
    'attrs' => array(),
);

$headingLevel = in_array((int)$props['heading_level'], array(1, 2, 3, 4), true) ? (int)$props['heading_level'] : 3;
$hasHead = $props['title'] !== '' || $props['subtitle'] !== '' || $props['eyebrow'] !== '' || $props['actions'] !== '';

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array(
    'md-card',
    $props['compact'] ? 'md-card--compact' : '',
    $props['interactive'] ? 'md-card--interactive' : '',
    $props['class'],
));
if ($props['id'] !== '') {
    $attrs['id'] = $props['id'];
}
?>
<section<?= MDJUI::attrs($attrs) ?>>
    <?php if ($hasHead): ?>
    <header class="md-card__head">
        <div class="md-card__titles">
            <?php if ($props['eyebrow'] !== ''): ?><div class="md-card__eyebrow"><?= MDJUI::e($props['eyebrow']) ?></div><?php endif; ?>
            <?php if ($props['title'] !== ''): ?>
                <h<?= $headingLevel ?> class="md-card__title">
                    <?php if ($props['icon']): ?><?= MDJUI::icon($props['icon'], array('class' => 'md-card__title-icon')) ?><?php endif; ?>
                    <?= MDJUI::e($props['title']) ?>
                </h<?= $headingLevel ?>>
            <?php endif; ?>
            <?php if ($props['subtitle'] !== ''): ?><p class="md-card__subtitle"><?= MDJUI::e($props['subtitle']) ?></p><?php endif; ?>
        </div>
        <?php if ($props['actions'] !== ''): ?><div class="md-card__actions"><?= $props['actions'] ?></div><?php endif; ?>
    </header>
    <?php endif; ?>
    <div class="<?= MDJUI::cx(array('md-card__body', $props['flush'] ? 'md-card__body--flush' : '', $props['body_class'])) ?>"><?= $slot ?></div>
    <?php if ($props['footer'] !== ''): ?><footer class="md-card__footer"><?= $props['footer'] ?></footer><?php endif; ?>
</section>

