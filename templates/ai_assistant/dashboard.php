<?php
// 2026-10-23 — заметный блок ИИ-помощника на главной странице.
if (!function_exists('isModuleInstalled') || !isModuleInstalled('ai_assistant')) return;
if (!class_exists('MDJUI', false)) require_once ROOT . 'lib/ui/MDJUI.php';
if (!class_exists('MDJUI', false)) return;
try {
    $aiDashboard = \MajorDoMo\Modules\AiAssistant\Dashboard::shared()->status();
} catch (Throwable) {
    return;
}
$aiHref = static fn(string $path): string => ROOTHTML . ltrim($path, '/');
?>
<section class="md-ai-spotlight md-ai-spotlight--magic<?= $aiDashboard['ready'] ? ' is-ready' : '' ?>" aria-labelledby="mdAiSpotlightTitle">
    <span class="md-ai-spotlight__aura" aria-hidden="true"></span>
    <span class="md-ai-spotlight__spark md-ai-spotlight__spark--one" aria-hidden="true"><?= MDJUI::icon('sparkles') ?></span>
    <span class="md-ai-spotlight__spark md-ai-spotlight__spark--two" aria-hidden="true"><?= MDJUI::icon('sparkles') ?></span>
    <div class="md-ai-spotlight__icon" aria-hidden="true"><?= MDJUI::icon('robot') ?></div>
    <div class="md-ai-spotlight__body">
        <div class="md-ai-spotlight__eyebrow">
            <?= MDJUI::badge($aiDashboard['ready'] ? 'Готов помочь' : 'Требует настройки',
                $aiDashboard['ready'] ? 'success' : 'warning', array('dot' => true)) ?>
            <span>ИИ-помощник</span>
            <em class="md-ai-spotlight__ai-tag">AI</em>
        </div>
        <h2 class="md-ai-spotlight__title" id="mdAiSpotlightTitle">
            <?= $aiDashboard['ready'] ? 'Спросите систему, а не документацию' : 'Подключите ИИ-помощника за три шага' ?>
        </h2>
        <p class="md-ai-spotlight__text">
            <?= $aiDashboard['ready']
                ? 'Помощник читает реальные объекты, свойства, обработчики и код. Спросите, кто меняет значение, или попросите написать метод.'
                : 'Модуль уже установлен. Осталось добавить провайдера, модель и назначить её агенту — выключателя нет, всё заработает сразу.' ?>
        </p>
        <div class="md-ai-spotlight__chips">
            <?php foreach ($aiDashboard['suggestions'] as $suggestion): ?>
                <a class="md-ai-spotlight__chip" href="<?= MDJUI::e($aiHref($suggestion['url'])) ?>">
                    <?= MDJUI::icon($suggestion['icon']) ?>
                    <span>
                        <strong><?= MDJUI::e($suggestion['title']) ?></strong>
                        <small><?= MDJUI::e($suggestion['text']) ?></small>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="md-ai-spotlight__actions">
        <?php if ($aiDashboard['ready']): ?>
            <?= MDJUI::button('Открыть помощника', array('href' => $aiHref($aiDashboard['chat_url']), 'icon' => 'messages')) ?>
            <?= MDJUI::button('Агенты', array('href' => $aiHref($aiDashboard['agents_url']), 'variant' => 'secondary', 'icon' => 'robot')) ?>
        <?php else: ?>
            <?= MDJUI::button('Настроить', array('href' => $aiHref($aiDashboard['setup_url']), 'icon' => 'settings')) ?>
            <?= MDJUI::button('База знаний', array('href' => $aiHref($aiDashboard['knowledge_url']), 'variant' => 'secondary', 'icon' => 'book-2')) ?>
        <?php endif; ?>
    </div>
</section>
