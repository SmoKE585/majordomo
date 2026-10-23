<?php
// 2026-10-23 — компактная карточка помощника на общих компонентах MDJUI.
if (!function_exists('isModuleInstalled') || !isModuleInstalled('ai_assistant')) return;
if (!class_exists('MDJUI', false)) require_once ROOT . 'lib/ui/MDJUI.php';
try { $aiDashboard = \MajorDoMo\Modules\AiAssistant\Dashboard::shared()->status(); }
catch (Throwable $failure) { error_log('MajorDoMo AI dashboard: ' . get_class($failure)); return; }
$isReady = !empty($aiDashboard['ready']);
$aiHref = static fn(string $path): string => ROOTHTML . ltrim($path, '/');
$actions = MDJUI::button($isReady ? 'Спросить помощника' : 'Настроить', array(
    'href' => $aiHref($isReady ? $aiDashboard['chat_url'] : ($aiDashboard['next_step_url'] ?? $aiDashboard['setup_url'])),
    'icon' => $isReady ? 'messages' : 'settings',
    'attrs' => $isReady ? array('data-ai-open' => '', 'aria-haspopup' => 'dialog') : array(),
));
ob_start(); ?>
<div class="md-ai-overview">
    <p><?= $isReady ? 'Объекты, автоматизации и помощь с кодом — в боковой панели из любого раздела.' : 'Подключите провайдера, выберите модель и назначьте её агенту.' ?></p>
    <?= MDJUI::badge($isReady ? 'Готов помочь' : 'Настройка: ' . (int)($aiDashboard['completed_steps'] ?? 0) . ' из 3', $isReady ? 'success' : 'warning', array('dot' => true)) ?>
</div>
<?php $body = ob_get_clean();
echo MDJUI::card(array('title' => 'ИИ-помощник', 'icon' => 'sparkles', 'actions' => $actions, 'compact' => true, 'class' => 'md-ai-overview-card'), $body);
