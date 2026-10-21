<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Avatar Group — overlapping avatars with a +N counter.
 *
 * Props: people (array of name/src/tone), max (default 4), size, class, attrs.
 */
$props += array(
    'people' => array(),
    'max' => 4,
    'size' => 'md',
    'class' => '',
    'attrs' => array(),
);

$people = is_array($props['people']) ? $props['people'] : array();
$max = max(1, (int)$props['max']);
$visible = array_slice($people, 0, $max);
$rest = max(0, count($people) - $max);

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-avatar-group', $props['class']));
?>
<div<?= MDJUI::attrs($attrs) ?>>
    <?php foreach ($visible as $person): ?>
        <?= MDJUI::avatar(isset($person['name']) ? $person['name'] : '', array(
            'src' => isset($person['src']) ? $person['src'] : '',
            'tone' => isset($person['tone']) ? $person['tone'] : 'neutral',
            'size' => $props['size'],
        )) ?>
    <?php endforeach; ?>
    <?php if ($rest > 0): ?>
        <span class="md-avatar md-avatar--<?= MDJUI::e($props['size']) ?> md-avatar--neutral md-avatar--more">+<?= (int)$rest ?></span>
    <?php endif; ?>
</div>

