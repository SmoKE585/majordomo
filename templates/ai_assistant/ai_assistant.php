<?php
// 2026-10-23 — единый раздел ИИ-помощника: чат, модели, агенты, знания, диагностика.
if (!isset($panel, $view, $token) || !class_exists('MDJUI', false)) {
    http_response_code(404);
    return;
}
$e = static fn($value): string => MDJUI::e((string)$value);
$url = static fn(string $path = ''): string => ROOTHTML . 'panel/ai_assistant' . $path . '.html';
$tabs = array(
    'chat' => array('label' => 'Чат', 'icon' => 'messages'),
    'agents' => array('label' => 'Агенты', 'icon' => 'robot'),
    'models' => array('label' => 'Модели', 'icon' => 'cpu'),
    'providers' => array('label' => 'Провайдеры', 'icon' => 'server'),
    'knowledge' => array('label' => 'База знаний', 'icon' => 'book-2'),
    'diagnostics' => array('label' => 'Диагностика', 'icon' => 'activity'),
);
$activeTab = in_array($view, array('agent_edit'), true) ? 'agents'
    : (in_array($view, array('model_edit'), true) ? 'models'
    : (in_array($view, array('provider_edit'), true) ? 'providers' : $view));
if (!empty($drawer)) {
    ?>
    <div class="md-ai-page md-ai-page--drawer" data-md-ai-panel data-ai-drawer
         data-ai-endpoint="<?= $e(ROOTHTML) ?>panel/ai_assistant.html?ajax=1" data-ai-url="<?= $e(ROOTHTML) ?>panel/ai_assistant">
        <?php require __DIR__ . '/chat.php'; ?>
    </div>
    <?php
    return;
}
?>
<link rel="stylesheet" href="<?= $e(ROOTHTML) ?>assets/modules/ai_assistant/ai_assistant.css?v=20261023-11">
<script src="<?= $e(ROOTHTML) ?>assets/modules/ai_assistant/ai_assistant.js?v=20261023-11" defer></script>

<div class="md-ui-page md-ui-page--stack md-ai-page" data-md-ai-panel
     data-ai-endpoint="<?= $e(ROOTHTML) ?>panel/ai_assistant.html?ajax=1"
     data-ai-csrf="<?= $e($csrf ?? '') ?>"
     data-ai-url="<?= $e(ROOTHTML) ?>panel/ai_assistant">
    <header class="md-ui-hero">
        <div>
            <div class="md-ui-hero__eyebrow"><?= MDJUI::icon('robot') ?> Искусственный интеллект <span class="md-ai-hero__tag">AI</span></div>
            <h1 class="md-ui-hero__title">ИИ-помощник</h1>
            <p class="md-ui-hero__lede">Помощник по дому и редактору кода. Инструменты читают реальную установку; изменения кода сохраняете вы.</p>
            <div class="md-ui-hero__meta">
                <span class="md-ui-hero__meta-item">
                    <?= MDJUI::badge($configured ? 'Готов к работе' : 'Требует настройки', $configured ? 'success' : 'warning', array('dot' => true)) ?>
                </span>
                <span class="md-ui-hero__meta-item"><?= MDJUI::icon('shield-lock') ?>Токены шифруются, в чат не попадают</span>
            </div>
        </div>
        <div class="md-ui-hero__actions">
            <?php if ($configured): ?>
                <?= MDJUI::button('Открыть помощника', array('href' => $url(), 'icon' => 'messages', 'attrs' => array('data-ai-open' => ''))) ?>
            <?php else: ?>
                <?= MDJUI::button('Настроить помощника', array('href' => $url('/models'), 'icon' => 'settings')) ?>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($error !== ''): ?>
        <?= MDJUI::alert('danger', 'Не удалось выполнить действие', $error) ?>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
        <?= MDJUI::alert('success', 'Готово', $success) ?>
    <?php endif; ?>
    <?php if (!$configured): ?>
        <?= MDJUI::hint(array(
            'tone' => 'info',
            'title' => 'Осталось подключить модель',
            'text' => 'Модуль уже работает. Добавьте OpenAI-совместимого провайдера, модель и назначьте её агенту — после этого чат и помощь в редакторе включатся автоматически.',
            'steps' => array('Добавьте провайдера с адресом API.', 'Создайте модель и проверьте соединение.', 'Назначьте модель одному из агентов.'),
            'actions' => MDJUI::button('К моделям', array('href' => $url('/models'), 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'cpu')),
        )) ?>
    <?php endif; ?>

    <nav class="md-tabs md-ai-tabs" aria-label="Разделы ИИ-помощника">
        <?php foreach ($tabs as $key => $tab): ?>
            <a href="<?= $e($url($key === 'chat' ? '' : '/' . $key)) ?>"
               class="nav-link<?= $activeTab === $key ? ' active' : '' ?>"
               <?= $activeTab === $key ? 'aria-current="page"' : '' ?>>
                <?= MDJUI::icon($tab['icon']) ?><span><?= $e($tab['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php
    $template = match ($view) {
        'agents' => 'agents',
        'agent_edit' => 'agent_edit',
        'providers' => 'providers',
        'provider_edit' => 'provider_edit',
        'models' => 'models',
        'model_edit' => 'model_edit',
        'knowledge' => 'knowledge',
        'diagnostics' => 'diagnostics',
        default => 'chat',
    };
    require __DIR__ . '/' . $template . '.php';
    ?>
</div>
