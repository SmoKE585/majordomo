<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Hint / contextual note.
 *
 * A hint explains how something works and stays close to the related control.
 * Use it instead of a plain alert when the message is educational rather than
 * a status report.
 *
 * Props: tone (tip|info|success|warning|danger), title, text, eyebrow, icon,
 * points (list of strings), steps (numbered list of strings), actions (HTML),
 * link + link_label, dismissible, compact, slot (rich body), class, attrs, id.
 */
$props += array(
    'tone' => 'tip',
    'title' => '',
    'text' => '',
    'eyebrow' => '',
    'icon' => '',
    'points' => array(),
    'steps' => array(),
    'actions' => '',
    'link' => '',
    'link_label' => 'Подробнее',
    'dismissible' => false,
    'compact' => false,
    'class' => '',
    'attrs' => array(),
    'id' => '',
);

$toneIcons = array(
    'tip' => 'bulb',
    'info' => 'info-circle',
    'success' => 'circle-check',
    'warning' => 'alert-triangle',
    'danger' => 'alert-circle',
);
$tones = array_keys($toneIcons);
$tone = in_array($props['tone'], $tones, true) ? $props['tone'] : 'tip';
$labels = array(
    'tip' => 'Подсказка',
    'info' => 'Как это работает',
    'success' => 'Готово',
    'warning' => 'Обратите внимание',
    'danger' => 'Важно',
);
$icon = $props['icon'] !== '' ? $props['icon'] : $toneIcons[$tone];
$eyebrow = $props['eyebrow'] !== '' ? $props['eyebrow'] : $labels[$tone];

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array(
    'md-hint',
    'md-hint--' . $tone,
    $props['compact'] ? 'md-hint--compact' : '',
    $props['class'],
));
$attrs['role'] = 'note';
if ($props['id'] !== '') {
    $attrs['id'] = $props['id'];
}

$points = is_array($props['points']) ? array_values(array_filter(array_map('strval', $props['points']), 'strlen')) : array();
$steps = is_array($props['steps']) ? array_values(array_filter(array_map('strval', $props['steps']), 'strlen')) : array();
?>
<aside<?= MDJUI::attrs($attrs) ?>>
    <span class="md-hint__icon"><?= MDJUI::icon($icon) ?></span>
    <div class="md-hint__body">
        <?php if ($eyebrow !== ''): ?><div class="md-hint__eyebrow"><?= MDJUI::e($eyebrow) ?></div><?php endif; ?>
        <?php if ($props['title'] !== ''): ?><div class="md-hint__title"><?= MDJUI::e($props['title']) ?></div><?php endif; ?>
        <?php if ($props['text'] !== ''): ?><p class="md-hint__text"><?= MDJUI::e($props['text']) ?></p><?php endif; ?>
        <?php if ($slot !== ''): ?><div class="md-hint__content"><?= $slot ?></div><?php endif; ?>
        <?php if ($points): ?>
            <ul class="md-hint__points">
                <?php foreach ($points as $point): ?><li><?= MDJUI::e($point) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($steps): ?>
            <ol class="md-hint__steps">
                <?php foreach ($steps as $step): ?><li><?= MDJUI::e($step) ?></li><?php endforeach; ?>
            </ol>
        <?php endif; ?>
        <?php if ($props['actions'] !== '' || $props['link'] !== ''): ?>
            <div class="md-hint__actions">
                <?= $props['actions'] ?>
                <?php if ($props['link'] !== ''): ?>
                    <?= MDJUI::button($props['link_label'], array(
                        'variant' => 'ghost',
                        'size' => 'sm',
                        'href' => $props['link'],
                        'icon_after' => 'arrow-right',
                    )) ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($props['dismissible']): ?>
        <button type="button" class="md-hint__close" data-md-dismiss="hint" aria-label="Скрыть подсказку"><?= MDJUI::icon('x') ?></button>
    <?php endif; ?>
</aside>
