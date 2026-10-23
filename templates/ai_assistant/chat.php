<?php
// 2026-10-23 — чат с потоковым ответом, выбором агента и историей диалогов.
$readyAgents = array_values(array_filter($agents, static fn(array $agent): bool => !empty($agent['READY'])));
$selectedAgent = (int)($dialog['AGENT_ID'] ?? 0);
if ($selectedAgent < 1 && $readyAgents !== array()) $selectedAgent = (int)$readyAgents[0]['ID'];
$agentJson = array_map(static fn(array $agent): array => array(
    'id' => (int)$agent['ID'], 'title' => $agent['TITLE'], 'model' => $agent['MODEL_TITLE'] ?? '',
), $readyAgents);
?>
<div class="md-ai-chat" data-ai-chat
     data-dialog-id="<?= (int)$dialogId ?>"
     data-selected-agent="<?= $selectedAgent ?>"
     data-agents="<?= $e(json_encode($agentJson, JSON_UNESCAPED_UNICODE)) ?>">

    <aside class="md-ai-chat__side">
        <?php ob_start(); ?>
            <div class="md-ai-dialogs" data-ai-dialogs>
                <?php if ($dialogs === array()): ?>
                    <?= MDJUI::emptyState(array('icon' => 'messages', 'title' => 'Пока нет диалогов',
                        'text' => 'Создайте первый диалог и задайте вопрос.', 'compact' => true)) ?>
                <?php endif; ?>
                <?php foreach ($dialogs as $item): ?>
                    <a class="md-ai-dialogs__item<?= (int)$item['ID'] === (int)$dialogId ? ' is-active' : '' ?>"
                       href="<?= $e($url('/chat/' . (int)$item['ID'])) ?>"
                       data-dialog-id="<?= (int)$item['ID'] ?>">
                        <?= MDJUI::icon($item['CONTEXT_TYPE'] === 'editor' ? 'code' : 'message') ?>
                        <span class="md-ai-dialogs__title"><?= $e($item['TITLE']) ?></span>
                        <?php if ((int)$item['MESSAGES'] > 0): ?>
                            <span class="md-badge md-badge--neutral"><?= (int)$item['MESSAGES'] ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php $dialogList = ob_get_clean(); ?>
        <?= MDJUI::card(array(
            'title' => 'Диалоги',
            'icon' => 'messages',
            'compact' => true,
            'actions' => MDJUI::button('Новый', array('variant' => 'secondary', 'size' => 'sm', 'icon' => 'plus',
                'attrs' => array('data-ai-new-dialog' => '', 'type' => 'button'))),
        ), $dialogList) ?>

        <?= MDJUI::card(array(
            'title' => 'Как это работает',
            'icon' => 'info-circle',
            'compact' => true,
        ), '<ul class="md-ai-help">
            <li>Помощник сначала ищет реальные объекты и статьи, затем отвечает.</li>
            <li>Ссылки на источники открываются в админке.</li>
            <li>Код из ответа можно вставить в редактор кнопкой у блока.</li>
            <li>История хранится в вашей установке; удаление диалога убирает его из списка.</li>
        </ul>') ?>
    </aside>

    <section class="md-ai-chat__main">
        <?php ob_start(); ?>
            <div class="md-ai-chat__top">
                <div class="md-ai-chat__identity">
                    <span class="md-ai-chat__avatar"><?= MDJUI::icon('robot') ?></span>
                    <div>
                        <div class="md-ai-chat__title" data-ai-dialog-title><?= $e($dialog['TITLE'] ?? 'Новый диалог') ?></div>
                        <div class="md-ai-chat__subtitle" data-ai-dialog-subtitle>
                            <?= $dialog !== null ? $e($dialog['CONTEXT_TYPE'] === 'editor' ? 'Контекст редактора' : 'Обычный диалог') : 'Выберите агента и начните разговор' ?>
                        </div>
                    </div>
                </div>
                <div class="md-ai-chat__controls">
                    <label class="md-visually-hidden" for="aiAgentSelect">Агент</label>
                    <select class="md-select md-select--sm" id="aiAgentSelect" data-ai-agent>
                        <?php if ($readyAgents === array()): ?>
                            <option value="">Нет готового агента</option>
                        <?php endif; ?>
                        <?php foreach ($readyAgents as $agent): ?>
                            <option value="<?= (int)$agent['ID'] ?>" <?= (int)$agent['ID'] === $selectedAgent ? 'selected' : '' ?>>
                                <?= $e($agent['TITLE']) ?><?= $agent['MODEL_TITLE'] !== '' ? ' · ' . $e($agent['MODEL_TITLE']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($dialog !== null): ?>
                        <?= MDJUI::iconButton('trash', array('label' => 'Удалить диалог', 'variant' => 'ghost',
                            'attrs' => array('data-ai-delete-dialog' => (int)$dialog['ID']))) ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="md-ai-chat__messages" data-ai-messages aria-live="polite">
                <?php if ($messages === array()): ?>
                    <div class="md-ai-chat__empty" data-ai-empty>
                        <?= MDJUI::emptyState(array('icon' => 'messages', 'title' => 'Начните разговор',
                            'text' => 'Спросите о свойстве, обработчике или попросите написать код для метода.',
                            'compact' => true)) ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($messages as $message): require __DIR__ . '/_message.php'; endforeach; ?>
                <?php endif; ?>
            </div>

            <form class="md-ai-composer" data-ai-composer>
                <label class="md-visually-hidden" for="aiPrompt">Сообщение</label>
                <textarea class="md-textarea md-ai-composer__input" id="aiPrompt" name="prompt" rows="3"
                          placeholder="Спросите о доме или коде…" data-ai-prompt
                          <?= $readyAgents === array() ? 'disabled' : '' ?>></textarea>
                <div class="md-ai-composer__bar">
                    <div class="md-ai-composer__status" data-ai-status>
                        <?= $readyAgents === array()
                            ? 'Enter — отправить, Shift+Enter — новая строка'
                            : 'Сначала назначьте модель включённому агенту' ?>
                    </div>
                    <div class="md-ai-composer__actions">
                        <?= MDJUI::button('Остановить', array('type' => 'button', 'variant' => 'secondary',
                            'icon' => 'player-stop', 'size' => 'sm', 'class' => 'is-hidden',
                            'attrs' => array('data-ai-stop' => '', 'hidden' => true))) ?>
                        <?= MDJUI::button('Отправить', array('type' => 'submit', 'icon' => 'send', 'size' => 'sm',
                            'attrs' => array('data-ai-send' => ''))) ?>
                    </div>
                </div>
            </form>
        <?php $chatBody = ob_get_clean(); ?>
        <?= MDJUI::card(array('flush' => true, 'class' => 'md-ai-chat__card'), $chatBody) ?>
        <div class="md-ai-chat__footnote">
            <?= MDJUI::icon('shield-lock') ?>
            <span>Ответ модели может быть неполным. Проверяйте имена и код; действующий метод сохраняется только вами.</span>
        </div>
    </section>
</div>
