<?php
/** Сводка последнего сбоя. 2026-10-25 — сначала факт, последствия и действие. */
require_once ROOT . 'lib/ui/MDJUI.php';
$diagnostic = $data['DIAGNOSTIC'] ?? array();
if (!$diagnostic) return;
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
ob_start();
?>
<dl class="md-errors-summary">
    <?php foreach (array('SUMMARY' => 'Что произошло', 'SOURCE' => 'Источник', 'ENTITY' => 'Объект / операция', 'IMPACT' => 'Последствия', 'CAUSE' => 'Возможная причина', 'ACTION' => 'Что делать') as $key => $label): ?>
        <?php if (empty($diagnostic[$key])) continue; ?>
        <div class="md-errors-summary__item<?php if ($key === 'ACTION'): ?> md-errors-summary__item--action<?php endif; ?>">
            <dt><?= $escape($label) ?></dt><dd><?= $escape($diagnostic[$key]) ?></dd>
        </div>
    <?php endforeach; ?>
</dl>
<?php
echo MDJUI::card(array('title' => 'Разбор последнего сбоя', 'icon' => 'alert-triangle', 'heading_level' => 2), ob_get_clean());
