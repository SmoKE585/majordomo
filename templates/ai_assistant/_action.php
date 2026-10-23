<?php
// 2026-10-23 — предложение Neuron на штатных карточке, кнопках и блоке кода.
if (!isset($action) || !class_exists('MDJUI', false)) return;
$data = $action['data'];
$kinds = array('script' => 'Сценарий', 'class' => 'Класс', 'object' => 'Объект',
    'class_property' => 'Свойство класса', 'object_property' => 'Свойство объекта');
ob_start();
if ($data['parent'] !== '') echo '<p>Родитель: ' . MDJUI::e($data['parent']) . '</p>';
if ($data['description'] !== '') echo '<p>' . MDJUI::e($data['description']) . '</p>';
echo $action['code_html'];
$labels = array('pending' => 'Требуется ваше подтверждение', 'applying' => 'Создание выполняется…',
    'created' => 'Создано', 'rejected' => 'Отменено', 'failed' => 'Не удалось создать');
?>
<p class="md-ai-action__status" role="status"><?= MDJUI::e($action['result'] ?: ($labels[$action['status']] ?? $action['status'])) ?></p>
<div class="md-ai-action__buttons" <?= $action['status'] === 'pending' ? '' : 'hidden' ?>>
    <?= MDJUI::button('Создать', array('type' => 'button', 'attrs' => array('data-ai-action-decision' => 'approve'))) ?>
    <?= MDJUI::button('Отменить', array('type' => 'button', 'variant' => 'secondary', 'attrs' => array('data-ai-action-decision' => 'reject'))) ?>
</div>
<?php
$body = ob_get_clean();
echo MDJUI::card(array('title' => ($kinds[$data['kind']] ?? 'Создание') . ': ' . $data['name'],
    'compact' => true, 'class' => 'md-ai-action', 'body_class' => 'md-ai-action__body',
    'attrs' => array('data-ai-action' => $action['id'])), $body);
