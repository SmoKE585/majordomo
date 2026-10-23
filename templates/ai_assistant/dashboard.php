<?php
// Блок ИИ-помощника на главной странице дашборда
if (!function_exists('isModuleInstalled') || !isModuleInstalled('ai_assistant')) return;
if (!class_exists('MDJUI', false)) require_once ROOT . 'lib/ui/MDJUI.php';
if (!class_exists('MDJUI', false)) return;
try {
    $aiDashboard = \MajorDoMo\Modules\AiAssistant\Dashboard::shared()->status();
} catch (Throwable) {
    return;
}
$aiHref = static fn(string $path): string => ROOTHTML . ltrim($path, '/');
$isReady = !empty($aiDashboard['ready']);
$steps = !empty($aiDashboard['steps']) ? $aiDashboard['steps'] : array();
$completedSteps = (int)($aiDashboard['completed_steps'] ?? 0);
?>
<section class="md-ai-spotlight<?= $isReady ? ' is-ready' : ' is-setup' ?>" aria-labelledby="mdAiSpotlightTitle">
    <div class="md-ai-spotlight__header">
        <div class="md-ai-spotlight__main">
            <div class="md-ai-spotlight__icon" aria-hidden="true">
                <?= MDJUI::icon($isReady ? 'sparkles' : 'robot') ?>
            </div>
            <div class="md-ai-spotlight__content">
                <div class="md-ai-spotlight__eyebrow">
                    <?= MDJUI::badge(
                        $isReady ? 'Готов помочь' : 'Настройка (' . $completedSteps . ' из 3)',
                        $isReady ? 'success' : 'warning',
                        array('dot' => true)
                    ) ?>
                    <span class="md-ai-spotlight__label">ИИ-помощник</span>
                    <span class="md-ai-spotlight__tag">AI</span>
                </div>
                <h2 class="md-ai-spotlight__title" id="mdAiSpotlightTitle">
                    <?= $isReady ? 'Спросите систему, а не документацию' : 'Подключите ИИ-помощника за три шага' ?>
                </h2>
                <p class="md-ai-spotlight__text">
                    <?= $isReady
                        ? 'Помощник читает реальные объекты, свойства, обработчики и код. Спросите, кто меняет значение, или попросите написать метод.'
                        : 'Модуль уже установлен. Добавьте провайдера, укажите модель и свяжите её с агентом — умный дом начнёт понимать естественный язык.' ?>
                </p>
            </div>
        </div>
        <div class="md-ai-spotlight__actions">
            <?php if ($isReady): ?>
                <?= MDJUI::button('Открыть помощника', array('href' => $aiHref($aiDashboard['chat_url']), 'icon' => 'messages')) ?>
                <?= MDJUI::button('Агенты', array('href' => $aiHref($aiDashboard['agents_url']), 'variant' => 'secondary', 'icon' => 'robot')) ?>
            <?php else: ?>
                <?= MDJUI::button($aiDashboard['next_step_title'] ?? 'Настроить', array('href' => $aiHref($aiDashboard['next_step_url'] ?? $aiDashboard['setup_url']), 'icon' => 'arrow-right')) ?>
                <?= MDJUI::button('База знаний', array('href' => $aiHref($aiDashboard['knowledge_url']), 'variant' => 'secondary', 'icon' => 'book-2')) ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$isReady && !empty($steps)): ?>
        <div class="md-ai-steps" aria-label="Шаги подключения ИИ-помощника">
            <?php foreach ($steps as $step): ?>
                <?php
                $stepClass = 'md-ai-step';
                if ($step['done']) {
                    $stepClass .= ' is-done';
                } elseif ($step['current']) {
                    $stepClass .= ' is-current';
                } else {
                    $stepClass .= ' is-pending';
                }
                ?>
                <a class="<?= $stepClass ?>" href="<?= MDJUI::e($aiHref($step['url'])) ?>" title="<?= MDJUI::e($step['title']) ?>">
                    <span class="md-ai-step__number">
                        <?php if ($step['done']): ?>
                            <i class="ti ti-check" aria-hidden="true"></i>
                        <?php else: ?>
                            <?= (int)$step['number'] ?>
                        <?php endif; ?>
                    </span>
                    <span class="md-ai-step__info">
                        <span class="md-ai-step__title"><?= MDJUI::e($step['title']) ?></span>
                        <span class="md-ai-step__text"><?= MDJUI::e($step['text']) ?></span>
                    </span>
                    <i class="ti ti-chevron-right md-ai-step__arrow" aria-hidden="true"></i>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($isReady && !empty($aiDashboard['suggestions'])): ?>
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
    <?php endif; ?>
</section>
