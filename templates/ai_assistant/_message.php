<?php
// 2026-10-23 — одно сообщение диалога; HTML ответа готовит безопасный Markdown-рендерер.
if (!isset($message) || !is_array($message) || !class_exists('MDJUI', false)) return;
$role = (string)($message['ROLE'] ?? 'assistant');
$content = (string)($message['CONTENT'] ?? '');
$meta = is_array($message['META'] ?? null) ? $message['META'] : array();
$isUser = $role === 'user';
$html = '';
if ($isUser) {
    $html = nl2br(MDJUI::e($content), false);
} elseif ($content !== '') {
    try {
        $html = \MajorDoMo\Modules\AiAssistant\AgentRunner::render($content);
    } catch (Throwable) {
        $html = nl2br(MDJUI::e($content), false);
    }
}
$sources = is_array($meta['sources'] ?? null) ? $meta['sources'] : array();
$tools = is_array($meta['tools'] ?? null) ? $meta['tools'] : array();
$usage = is_array($meta['usage'] ?? null) ? $meta['usage'] : array();
$error = (string)($meta['error'] ?? '');
?>
<article class="md-ai-msg md-ai-msg--<?= $isUser ? 'user' : 'assistant' ?><?= !empty($meta['cancelled']) ? ' is-cancelled' : '' ?>"
         data-message-id="<?= (int)($message['ID'] ?? 0) ?>">
    <span class="md-ai-msg__avatar"><?= MDJUI::icon($isUser ? 'user' : 'robot') ?></span>
    <div class="md-ai-msg__body">
        <span data-ai-copy-source hidden><?= MDJUI::e($content) ?></span>
        <div class="md-ai-msg__head">
            <strong><?= $isUser ? 'Вы' : 'Помощник' ?></strong>
            <?php if (!empty($message['CREATED_AT'])): ?>
                <time datetime="<?= date('c', (int)$message['CREATED_AT']) ?>"><?= date('d.m H:i', (int)$message['CREATED_AT']) ?></time>
            <?php endif; ?>
            <?php if (!empty($meta['incomplete'])): ?><?= MDJUI::badge('Неполный ответ', 'warning') ?><?php endif; ?>
            <?php if (!empty($meta['cancelled'])): ?><?= MDJUI::badge('Остановлено', 'neutral') ?><?php endif; ?>
        </div>
        <?php if ($error !== ''): ?>
            <?= MDJUI::alert('danger', 'Ошибка ответа', $error) ?>
        <?php endif; ?>
        <div class="md-ai-msg__content md-prose"><?= $html ?></div>
        <?php if ($sources !== array()): ?>
            <div class="md-ai-msg__sources">
                <span class="md-ai-msg__label"><?= MDJUI::icon('link') ?> Источники</span>
                <div class="md-ai-msg__chips">
                    <?php foreach ($sources as $source): ?>
                        <?php if (!is_array($source)) continue; ?>
                        <?php $title = (string)($source['title'] ?? ''); $link = (string)($source['url'] ?? ''); ?>
                        <?php if ($link !== '' && preg_match('~^/(?![/\\\\])~', $link)): ?>
                            <a class="md-badge md-badge--outline" href="<?= MDJUI::e(ROOTHTML . ltrim($link, '/')) ?>" target="_blank" rel="noopener"><?= MDJUI::e($title !== '' ? $title : $link) ?></a>
                        <?php elseif ($link !== '' && preg_match('~^https?://~i', $link)): ?>
                            <a class="md-badge md-badge--outline" href="<?= MDJUI::e($link) ?>" target="_blank" rel="noopener noreferrer"><?= MDJUI::e($title !== '' ? $title : $link) ?></a>
                        <?php else: ?>
                            <span class="md-badge md-badge--outline"><?= MDJUI::e($title) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($tools !== array()): ?>
            <details class="md-ai-msg__tools">
                <summary><?= MDJUI::icon('tool') ?> Инструменты: <?= count($tools) ?></summary>
                <ul>
                    <?php foreach ($tools as $tool): ?>
                        <?php if (!is_array($tool)) continue; ?>
                        <li>
                            <code><?= MDJUI::e((string)($tool['name'] ?? '')) ?></code>
                            <?= !empty($tool['status']) ? MDJUI::badge((string)$tool['status'], $tool['status'] === 'done' ? 'success' : 'neutral') : '' ?>
                            <?php if (!empty($tool['result'])): ?><span class="md-ai-msg__tool-result"><?= MDJUI::e(mb_substr((string)$tool['result'], 0, 300)) ?></span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>
        <?php if (!empty($usage['input_tokens']) || !empty($usage['output_tokens'])): ?>
            <div class="md-ai-msg__usage">Токены: <?= (int)($usage['input_tokens'] ?? 0) ?> вход / <?= (int)($usage['output_tokens'] ?? 0) ?> выход</div>
        <?php endif; ?>
    </div>
</article>
