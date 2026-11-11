<?php
// 2026-11-11 — длинный код раскрывается в чате, кнопки вынесены в штатный футер карточки.
if (!isset($action) || !class_exists('MDJUI', false)) return;
$data = $action['data'];
$kinds = array('script' => 'Сценарий', 'class' => 'Класс', 'object' => 'Объект',
    'class_property' => 'Свойство класса', 'object_property' => 'Свойство объекта',
    'class_method' => 'Метод класса', 'object_method' => 'Метод объекта');
$editing = ($data['operation'] ?? 'create') === 'update';
ob_start();
if ($data['parent'] !== '') echo '<p>Родитель: ' . MDJUI::e($data['parent']) . '</p>';
if ($data['description'] !== '') echo '<p>' . MDJUI::e($data['description']) . '</p>';
if ($editing && !empty($action['before_html'])) {
    echo '<details><summary>Исходный код</summary>' . $action['before_html'] . '</details>';
    echo '<p>Новый код' . ($data['code'] === '' ? ' пуст: тело метода будет очищено.' : ':') . '</p>';
}
if (in_array($data['kind'], array('class_method', 'object_method'), true)) {
    echo '<p>Вызов родителя: ' . MDJUI::e(array('0' => 'не вызывать', '1' => 'до кода', '2' => 'после кода', '' => $editing ? 'сохранить текущий' : 'не вызывать')[$data['call_parent'] ?? '']) . '.</p>';
    if ($editing && $action['source_script'] !== '') echo '<p>Вместо сценария «' . MDJUI::e($action['source_script']) . '» будет выполняться код этого метода. Сценарий не изменяется.</p>';
}
if (!empty($action['code_preview_html'])) {
    echo '<div class="md-ai-action__code"><div class="md-ai-action__preview">' . $action['code_preview_html'] . '</div>';
    echo '<details data-ai-full-code><summary>Показать полный код (' . (int)$action['code_lines'] . ' строк)</summary>' . $action['code_html'] . '</details></div>';
} else echo $action['code_html'];
$body = ob_get_clean();
ob_start();
$labels = array('pending' => 'Требуется ваше подтверждение', 'applying' => 'Создание выполняется…',
    'created' => 'Создано', 'updated' => 'Сохранено', 'rejected' => 'Отменено', 'failed' => 'Не удалось применить');
?>
<p class="md-ai-action__status" role="status"><?= MDJUI::e($action['result'] ?: ($labels[$action['status']] ?? $action['status'])) ?></p>
<div class="md-ai-action__buttons" <?= $action['status'] === 'pending' ? '' : 'hidden' ?>>
    <?= MDJUI::button($editing ? 'Сохранить' : 'Создать', array('type' => 'button', 'attrs' => array('data-ai-action-decision' => 'approve'))) ?>
    <?= MDJUI::button('Отменить', array('type' => 'button', 'variant' => 'secondary', 'attrs' => array('data-ai-action-decision' => 'reject'))) ?>
</div>
<?php
$footer = ob_get_clean();
echo MDJUI::card(array('title' => ($editing ? 'Правка · ' : '') . ($kinds[$data['kind']] ?? 'Создание') . ': ' . $data['name'],
    'compact' => true, 'class' => 'md-ai-action', 'body_class' => 'md-ai-action__body',
    'footer' => $footer,
    'attrs' => array('data-ai-action' => $action['id'])), $body);
