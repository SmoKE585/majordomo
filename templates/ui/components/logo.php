<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Logo — фирменный логотип в текущей теме.
 *
 * Оба варианта (тёмный вордмарк и белый) стоят рядом в разметке, а CSS по
 * `data-bs-theme` оставляет один: при переключении темы картинка не мигает и
 * не грузится повторно. Файлы `*-clean-*` — тот же логотип без мелкой подписи
 * «…твой лучший умный дом»: в интерфейсе она превращалась в нечитаемую полосу.
 *
 * Props: width (px, по умолчанию из --md-logo-width), alt, root (префикс URL),
 * class, attrs.
 */
$props += array(
    'width' => 0,
    'alt' => 'MajorDoMo',
    'root' => defined('ROOTHTML') ? ROOTHTML : '',
    'class' => '',
    'attrs' => array(),
);

$root = (string)$props['root'];
$alt = (string)$props['alt'];
$variants = array(
    'light' => 'logo-black-clean',
    'dark' => 'logo-white-clean',
);

$class = MDJUI::cx(array('md-logo', $props['class']));
$style = (int)$props['width'] > 0 ? '--md-logo-width:' . (int)$props['width'] . 'px' : '';
$attrs = MDJUI::attrs(array_merge(
    is_array($props['attrs']) ? $props['attrs'] : array(),
    array('class' => $class, 'style' => $style !== '' ? $style : null)
));
?>
<span<?= $attrs ?>>
    <?php foreach ($variants as $tone => $base): ?>
        <img class="md-logo__<?= $tone ?>"
             src="<?= MDJUI::e($root . 'assets/img/logos/' . $base . '-small.png') ?>"
             srcset="<?= MDJUI::e($root . 'assets/img/logos/' . $base . '-large.png') ?> 2x"
             width="300" height="79" alt="<?= MDJUI::e($alt) ?>" decoding="async">
    <?php endforeach; ?>
</span>
