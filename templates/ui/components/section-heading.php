<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Section heading — page/section title with optional actions.
 *
 * Props: eyebrow, title, description, actions (HTML), level (1|2|3), id, class.
 */
$props += array(
    'eyebrow' => '',
    'title' => '',
    'description' => '',
    'actions' => '',
    'level' => 2,
    'id' => '',
    'class' => '',
);

$tag = in_array((int)$props['level'], array(1, 2, 3), true) ? 'h' . (int)$props['level'] : 'h2';
?>
<header class="<?= MDJUI::cx(array('md-section-head', $props['class'])) ?>"<?= $props['id'] !== '' ? ' id="' . MDJUI::e($props['id']) . '"' : '' ?>>
    <div class="md-section-head__text">
        <?php if ($props['eyebrow'] !== ''): ?><div class="md-section-head__eyebrow"><?= MDJUI::e($props['eyebrow']) ?></div><?php endif; ?>
        <<?= $tag ?> class="md-section-head__title"><?= MDJUI::e($props['title']) ?></<?= $tag ?>>
        <?php if ($props['description'] !== ''): ?><p class="md-section-head__description"><?= MDJUI::e($props['description']) ?></p><?php endif; ?>
    </div>
    <?php if ($props['actions'] !== ''): ?><div class="md-section-head__actions"><?= $props['actions'] ?></div><?php endif; ?>
</header>

