<?php
if (!class_exists('MDJUI', false)) { http_response_code(404); return; }
$props += array('title' => '', 'description' => '', 'badge' => '', 'badge_tone' => 'neutral', 'open' => false, 'id' => '', 'class' => '', 'attrs' => array());
$attrs = $props['attrs'];
$attrs['class'] = trim('md-disclosure ' . $props['class']);
$attrs['open'] = (bool)$props['open'];
if ($props['id'] !== '') $attrs['id'] = $props['id'];
?>
<details<?= MDJUI::attrs($attrs) ?>>
    <summary class="md-disclosure__summary">
        <?php if ($props['badge'] !== '') echo MDJUI::badge($props['badge'], $props['badge_tone']); ?>
        <span class="md-disclosure__heading"><span class="md-disclosure__title"><?= MDJUI::e($props['title']) ?></span><?php if ($props['description'] !== ''): ?><span class="md-disclosure__description"><?= MDJUI::e($props['description']) ?></span><?php endif; ?></span>
        <?= MDJUI::icon('chevron-down', array('class' => 'md-disclosure__chevron')) ?>
    </summary>
    <div class="md-disclosure__body"><?= $slot ?></div>
</details>
