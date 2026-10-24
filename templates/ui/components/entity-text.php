<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Текст с бейджами сущностей.
 *
 * Модель размечает имена прямо в ответе токенами `[[тип:имя|подпись|ссылка]]`,
 * а компонент превращает их в бейджи и экранирует весь остальной текст. Так
 * ответ остаётся обычным текстом для API и истории, но в чате читается как
 * документ со ссылками.
 *
 * Props:
 * - text — размеченный текст; поддержаны `[[object:Light_Toilet]]`,
 *   `[[property:Light_Toilet.status|status]]` и третий сегмент — ссылка;
 * - compact — не показывать тип в бейдже (тип остаётся в подсказке);
 * - legend — показать легенду использованных типов над текстом;
 * - inline — вернуть только HTML без обёртки, для вставки внутрь абзаца;
 * - class, attrs.
 */
$props += array(
    'text' => '',
    'compact' => false,
    'legend' => false,
    'inline' => false,
    'class' => '',
    'attrs' => array(),
);

$text = is_scalar($props['text']) ? (string)$props['text'] : '';
if ($text === '') {
    return;
}
$types = MDJUI::entityTypes();

// Имя не должно содержать `|`, `]` и перевод строки: иначе токен ломается на
// произвольном тексте, а не на реальной разметке.
$pattern = '/\[\[([a-z][a-z0-9_-]*):([^\]|\r\n]+)(?:\|([^\]|\r\n]*))?(?:\|([^\]\r\n]*))?\]\]/iu';

$html = '';
$used = array();
$offset = 0;
$matches = array();
if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
    foreach ($matches as $match) {
        $full = (string)$match[0][0];
        $position = (int)$match[0][1];
        if ($position > $offset) {
            $html .= nl2br(MDJUI::e(substr($text, $offset, $position - $offset)), false);
        }

        $type = strtolower((string)$match[1][0]);
        $name = trim((string)$match[2][0]);
        $label = isset($match[3]) && trim((string)$match[3][0]) !== '' ? trim((string)$match[3][0]) : '';
        $href = isset($match[4]) ? trim((string)$match[4][0]) : '';

        if ($name === '' || !isset($types[$type])) {
            // Неизвестный тип оставляем как текст: автор разметки сразу увидит
            // опечатку, а не молча потеряет имя сущности.
            $html .= MDJUI::e($full);
        } else {
            $html .= MDJUI::entity($name, $type, array(
                'label' => $label,
                'href' => $href,
                'compact' => !empty($props['compact']),
            ));
            $used[$type] = true;
        }

        $offset = $position + strlen($full);
    }
}
if ($offset < strlen($text)) {
    $html .= nl2br(MDJUI::e(substr($text, $offset)), false);
}

if (!empty($props['inline'])) {
    echo $html;
    return;
}

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-entity-text', $props['class']));
?>
<div<?= MDJUI::attrs($attrs) ?>>
    <?php if (!empty($props['legend']) && $used): ?>
        <div class="md-entity-text__legend" role="group" aria-label="Обозначения сущностей">
            <?php foreach (array_keys($used) as $type): ?>
                <?php $meta = $types[$type]; ?>
                <span class="md-entity-text__legend-item md-entity--<?= MDJUI::e($type) ?>">
                    <?= MDJUI::icon($meta['icon']) ?>
                    <?= MDJUI::e($meta['label']) ?>
                </span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <p class="md-entity-text__body"><?= $html ?></p>
</div>
