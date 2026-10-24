<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Бейдж сущности для ИИ-чата и текстовых описаний.
 *
 * Слева — иконка и тип (объект, свойство, метод, сценарий, модуль, комната),
 * справа — имя. Тип важен: одинаковые на вид `status` и `Motion.data` читаются
 * по-разному, а цвет и подпись помогают не путать свойства с методами.
 *
 * Props:
 * - name — точное имя сущности (в подсказке показывается полностью);
 * - type — object/class/property/method/script/module/room/topic/code;
 * - label — короткая подпись вместо полного имени;
 * - href — точная ссылка из инструмента ИИ; без неё открывается раздел;
 * - compact — только иконка и имя, тип остаётся в подсказке и для скринридера;
 * - show_type, tone, icon, external, title, class, attrs.
 */
$props += array(
    'name' => '',
    'type' => 'object',
    'label' => '',
    'href' => '',
    'compact' => false,
    'show_type' => true,
    'tone' => '',
    'icon' => '',
    'external' => false,
    'title' => '',
    'class' => '',
    'attrs' => array(),
);

// Данные приходят из ответа модели, поэтому не доверяем даже типу значения:
// массив в имени дал бы предупреждение PHP 8 вместо читаемой ошибки.
$asText = function ($value) {
    return is_scalar($value) ? trim((string)$value) : '';
};

$types = MDJUI::entityTypes();
$type = strtolower($asText($props['type']));
if (!isset($types[$type])) {
    $type = 'reference';
}
$meta = $types[$type];

$name = $asText($props['name']);
$label = $asText($props['label']);
if ($label === '') {
    $label = $name;
}
if ($label === '') {
    return;
}

$compact = !empty($props['compact']);
$showType = !$compact && !empty($props['show_type']);

// Тон можно переопределить точечно (например, ошибка в узле схемы), но
// значение по умолчанию берём из типа, чтобы не раскрашивать бейджи вручную.
$tone = $asText($props['tone']);
$toneOverride = in_array($tone, array('primary', 'success', 'warning', 'danger', 'info', 'neutral'), true);

$icon = $asText($props['icon']);
if ($icon === '') {
    $icon = $meta['icon'];
}

$href = $asText($props['href']);
if ($href === '') {
    $href = $meta['href'];
}
// Ссылку может предложить модель или инструмент, поэтому оставляем только
// админские пути и http(s). javascript:/data: в чате недопустимы.
$href = MDJUI::safeHref($href);

$title = $asText($props['title']);
if ($title === '') {
    $title = $meta['label'] . ($name !== '' ? ' · ' . $name : '');
}

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array(
    'md-entity',
    'md-entity--' . $type,
    $toneOverride ? 'md-entity--tone-' . $tone : '',
    $compact ? 'md-entity--compact' : '',
    $props['class'],
));
$attrs['data-md-entity'] = $type;
if ($title !== '') {
    $attrs['title'] = $title;
}

$tag = 'span';
if ($href !== '') {
    $tag = 'a';
    $attrs['href'] = $href;
    if (!empty($props['external'])) {
        $attrs['target'] = '_blank';
        $attrs['rel'] = 'noopener noreferrer';
    }
}
?>
<<?= $tag ?><?= MDJUI::attrs($attrs) ?>>
    <span class="md-entity__icon" aria-hidden="true"><?= MDJUI::icon($icon) ?></span>
    <?php if (!$showType): ?><span class="md-visually-hidden"><?= MDJUI::e($meta['label']) ?>: </span><?php endif; ?>
    <?php if ($showType): ?><span class="md-entity__kind"><?= MDJUI::e($meta['label']) ?></span><?php endif; ?>
    <span class="md-entity__name"><?= MDJUI::e($label) ?></span>
    <?php if ($name !== '' && $label !== $name): ?><span class="md-visually-hidden"><?= MDJUI::e($name) ?></span><?php endif; ?>
</<?= $tag ?>>
