<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Схема вызова MajorDoMo.
 *
 * Показывает причинную цепочку: что сработало, что вызвало следующий шаг,
 * какие были ветки условий и что из этого реально выполнилось. В отличие от
 * ленты и trace-drawer здесь важен не список событий, а дерево вызовов с
 * временем и ответвлениями.
 *
 * Props:
 * - title, subtitle, eyebrow, icon, actions, footer;
 * - status + status_label — итог всего вызова;
 * - started_at, duration — общее время;
 * - stats — список `['label' => 'Шаги', 'value' => '8']` или готовые строки;
 * - nodes — дерево узлов (см. ниже);
 * - compact — плотный режим для чата и боковой панели;
 * - legend — легенда использованных типов узлов (по умолчанию true);
 * - empty_title, empty_text, class, attrs, id.
 *
 * Узел:
 * - kind — event, method, script, property, condition, timer, action, module,
 *   room, class, error, note;
 * - title — подпись, если не передан `entity`;
 * - entity — массив для MDJUI::entity(): type, name, label, href;
 * - time, offset, duration, note, expression;
 * - status — ok, error, warning, skipped, pending, running;
 * - detail, payload — раскрываемые подробности;
 * - children — следующие шаги;
 * - branches — ветки: label, note, taken, nodes, empty_text.
 */
$props += array(
    'title' => '',
    'subtitle' => '',
    'eyebrow' => '',
    'icon' => 'route',
    'actions' => '',
    'footer' => '',
    'status' => '',
    'status_label' => '',
    'started_at' => '',
    'duration' => '',
    'stats' => array(),
    'nodes' => array(),
    'compact' => false,
    'legend' => true,
    'empty_title' => 'Нет данных о вызове',
    'empty_text' => 'Схема появится, когда ИИ получит цепочку вызовов.',
    'class' => '',
    'attrs' => array(),
    'id' => '',
);

// Заголовки и подписи выводятся как текст; массив в них — ошибка данных,
// а не повод для предупреждения PHP 8.
foreach (array('title', 'subtitle', 'eyebrow', 'icon', 'actions', 'footer', 'status', 'status_label', 'started_at', 'duration', 'empty_title', 'empty_text', 'class', 'id') as $key) {
    if (!is_scalar($props[$key])) {
        $props[$key] = '';
    }
}

$kinds = array(
    'event' => array('label' => 'Событие', 'icon' => 'bolt', 'tone' => 'info'),
    'method' => array('label' => 'Метод', 'icon' => 'function', 'tone' => 'primary'),
    'script' => array('label' => 'Сценарий', 'icon' => 'file-code', 'tone' => 'warning'),
    'property' => array('label' => 'Свойство', 'icon' => 'variable', 'tone' => 'success'),
    'condition' => array('label' => 'Условие', 'icon' => 'arrows-split-2', 'tone' => 'warning'),
    'timer' => array('label' => 'Таймер', 'icon' => 'clock', 'tone' => 'info'),
    'action' => array('label' => 'Действие', 'icon' => 'player-play', 'tone' => 'primary'),
    'module' => array('label' => 'Модуль', 'icon' => 'puzzle', 'tone' => 'neutral'),
    'room' => array('label' => 'Комната', 'icon' => 'map-pin', 'tone' => 'info'),
    'class' => array('label' => 'Класс', 'icon' => 'hierarchy', 'tone' => 'info'),
    'error' => array('label' => 'Ошибка', 'icon' => 'alert-circle', 'tone' => 'danger'),
    'note' => array('label' => 'Заметка', 'icon' => 'info-circle', 'tone' => 'neutral'),
);

$statuses = array(
    'ok' => array('label' => 'Выполнено', 'tone' => 'success', 'icon' => 'circle-check'),
    'success' => array('label' => 'Выполнено', 'tone' => 'success', 'icon' => 'circle-check'),
    'error' => array('label' => 'Ошибка', 'tone' => 'danger', 'icon' => 'alert-circle'),
    'warning' => array('label' => 'Внимание', 'tone' => 'warning', 'icon' => 'alert-triangle'),
    'skipped' => array('label' => 'Пропущено', 'tone' => 'neutral', 'icon' => 'minus'),
    'pending' => array('label' => 'Ожидание', 'tone' => 'info', 'icon' => 'hourglass'),
    'running' => array('label' => 'Выполняется', 'tone' => 'primary', 'icon' => 'clock-play'),
);

$normalizeStatus = function ($status) use ($statuses) {
    $status = is_scalar($status) ? strtolower(trim((string)$status)) : '';
    return isset($statuses[$status]) ? $status : '';
};

// Статистику принимаем и как готовые подписи, и как пары label/value.
$stats = array();
foreach ((array)$props['stats'] as $key => $stat) {
    if (is_array($stat)) {
        $label = isset($stat['label']) && is_scalar($stat['label']) ? trim((string)$stat['label']) : '';
        $value = isset($stat['value']) && is_scalar($stat['value']) ? trim((string)$stat['value']) : '';
        if ($label === '' && $value === '') {
            continue;
        }
        $stats[] = array('label' => $label, 'value' => $value);
    } elseif (is_int($key)) {
        $value = is_scalar($stat) ? trim((string)$stat) : '';
        if ($value !== '') {
            $stats[] = array('label' => '', 'value' => $value);
        }
    } else {
        $value = is_scalar($stat) ? trim((string)$stat) : '';
        $stats[] = array('label' => trim((string)$key), 'value' => $value);
    }
}

$nodes = isset($props['nodes']) && is_array($props['nodes']) ? $props['nodes'] : array();

$flowStatusKey = $normalizeStatus($props['status']);
$flowStatusLabel = trim((string)$props['status_label']);
if ($flowStatusLabel === '' && $flowStatusKey !== '') {
    $flowStatusLabel = $statuses[$flowStatusKey]['label'];
}
// Кастомная подпись без известного статуса остаётся нейтральной, а не падает
// на отсутствующем ключе.
$flowStatusTone = $flowStatusKey !== '' ? $statuses[$flowStatusKey]['tone'] : 'neutral';

$usedKinds = array();
$step = 0;

/**
 * Рисует один узел и его потомков.
 *
 * Замыкание рекурсивное: дерево вызовов может быть глубоким, поэтому
 * ограничиваем только отображение, но не обрезаем данные.
 */
$renderNode = function ($node, $depth = 0) use (&$renderNode, &$step, &$usedKinds, $kinds, $statuses) {
    // Глубина ограничена, чтобы повреждённые данные не уронили рендер.
    if (!is_array($node) || $depth > 24) {
        return;
    }

    $asText = function ($value) {
        return is_scalar($value) ? trim((string)$value) : '';
    };

    $kind = strtolower($asText($node['kind'] ?? 'note'));
    if (!isset($kinds[$kind])) {
        $kind = 'note';
    }
    $kindMeta = $kinds[$kind];
    $usedKinds[$kind] = true;
    $step++;

    $statusKey = strtolower($asText($node['status'] ?? ''));
    $statusKey = isset($statuses[$statusKey]) ? $statusKey : '';

    $classes = array(
        'md-call-flow__node',
        'md-call-flow__node--' . $kind,
        $statusKey === 'error' ? 'is-error' : '',
        $statusKey === 'skipped' ? 'is-skipped' : '',
        // На телефоне глубокие уровни перестают накапливать отступ, иначе
        // текст в ветке сжимается до одного символа в строке.
        $depth >= 3 ? 'is-deep' : '',
    );

    $title = $asText($node['title'] ?? '');
    $href = MDJUI::safeHref($node['href'] ?? '');
    $entity = isset($node['entity']) && is_array($node['entity']) ? $node['entity'] : null;
    if ($entity !== null && $asText($entity['name'] ?? '') === '') {
        $entity = null;
    }

    $time = $asText($node['time'] ?? '');
    $offset = $asText($node['offset'] ?? '');
    $duration = $asText($node['duration'] ?? '');
    $note = $asText($node['note'] ?? '');
    $expression = $asText($node['expression'] ?? '');
    $detail = $asText($node['detail'] ?? '');
    $detailLabel = $asText($node['detail_label'] ?? 'Подробности');
    $payload = $node['payload'] ?? null;
    $payloadLabel = $asText($node['payload_label'] ?? 'Данные');
    $hasPayload = is_array($payload) ? $payload !== array() : (is_scalar($payload) && trim((string)$payload) !== '');
    $children = isset($node['children']) && is_array($node['children']) ? $node['children'] : array();
    $branches = isset($node['branches']) && is_array($node['branches']) ? $node['branches'] : array();
    ?>
    <li class="<?= MDJUI::e(MDJUI::cx($classes)) ?>" data-flow-kind="<?= MDJUI::e($kind) ?>">
        <div class="md-call-flow__row">
            <span class="md-call-flow__rail" aria-hidden="true">
                <span class="md-call-flow__dot"><?= MDJUI::icon($kindMeta['icon']) ?></span>
            </span>
            <div class="md-call-flow__main">
                <div class="md-call-flow__main-head">
                    <div class="md-call-flow__title">
                        <?php if ($entity): ?>
                            <?= MDJUI::entity(
                                (string)($entity['name'] ?? ''),
                                (string)($entity['type'] ?? 'object'),
                                array(
                                    'label' => (string)($entity['label'] ?? ''),
                                    'href' => (string)($entity['href'] ?? ''),
                                    'compact' => true,
                                )
                            ) ?>
                        <?php elseif ($title !== ''): ?>
                            <?php if ($href !== ''): ?>
                                <a href="<?= MDJUI::e($href) ?>"><?= MDJUI::e($title) ?></a>
                            <?php else: ?>
                                <?= MDJUI::e($title) ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="md-call-flow__title-muted"><?= MDJUI::e($kindMeta['label']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($time !== '' || $offset !== ''): ?>
                        <div class="md-call-flow__time">
                            <?php if ($time !== ''): ?>
                                <span class="md-call-flow__stamp"><?= MDJUI::icon('clock') ?><?= MDJUI::e($time) ?></span>
                            <?php endif; ?>
                            <?php if ($offset !== ''): ?><span class="md-call-flow__offset"><?= MDJUI::e($offset) ?></span><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="md-call-flow__meta">
                    <span class="md-call-flow__step" title="Порядок выполнения">#<?= (int)$step ?></span>
                    <span class="md-call-flow__kind"><?= MDJUI::e($kindMeta['label']) ?></span>
                    <?php if ($duration !== ''): ?>
                        <span class="md-call-flow__duration"><?= MDJUI::icon('stopwatch') ?><?= MDJUI::e($duration) ?></span>
                    <?php endif; ?>
                    <?php if ($statusKey !== ''): ?>
                        <span class="md-call-flow__status md-call-flow__status--<?= MDJUI::e($statuses[$statusKey]['tone']) ?>">
                            <?= MDJUI::icon($statuses[$statusKey]['icon']) ?><?= MDJUI::e($statuses[$statusKey]['label']) ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($note !== ''): ?><span class="md-call-flow__note"><?= MDJUI::e($note) ?></span><?php endif; ?>
                </div>
                <?php if ($expression !== ''): ?>
                    <code class="md-call-flow__expression"><?= MDJUI::e($expression) ?></code>
                <?php endif; ?>
                <?php if ($detail !== '' || $hasPayload): ?>
                    <div class="md-call-flow__extras">
                        <?php if ($detail !== ''): ?>
                            <details class="md-call-flow__detail">
                                <summary><?= MDJUI::e($detailLabel) ?></summary>
                                <div class="md-call-flow__detail-body"><?= nl2br(MDJUI::e($detail), false) ?></div>
                            </details>
                        <?php endif; ?>
                        <?php if ($hasPayload): ?>
                            <?php
                            if (is_array($payload)) {
                                $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                $payloadText = $encoded === false ? '' : $encoded;
                            } else {
                                $payloadText = is_scalar($payload) ? (string)$payload : '';
                            }
                            ?>
                            <?php if ($payloadText !== ''): ?>
                                <details class="md-call-flow__detail">
                                    <summary><?= MDJUI::e($payloadLabel) ?></summary>
                                    <pre class="md-call-flow__payload"><?= MDJUI::e($payloadText) ?></pre>
                                </details>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($children): ?>
            <ol class="md-call-flow__children">
                <?php foreach ($children as $child) { $renderNode($child, $depth + 1); } ?>
            </ol>
        <?php endif; ?>
        <?php if ($branches): ?>
            <div class="md-call-flow__branches">
                <?php foreach ($branches as $branch): ?>
                    <?php
                    if (!is_array($branch)) {
                        continue;
                    }
                    $taken = !array_key_exists('taken', $branch) || (bool)$branch['taken'];
                    $branchLabel = is_scalar($branch['label'] ?? null) ? trim((string)$branch['label']) : '';
                    if ($branchLabel === '') {
                        $branchLabel = $taken ? 'да' : 'нет';
                    }
                    $branchNote = is_scalar($branch['note'] ?? null) ? trim((string)$branch['note']) : '';
                    $branchEmpty = is_scalar($branch['empty_text'] ?? null) ? trim((string)$branch['empty_text']) : '';
                    $branchNodes = isset($branch['nodes']) && is_array($branch['nodes']) ? $branch['nodes'] : array();
                    ?>
                    <div class="md-call-flow__branch <?= $taken ? 'is-taken' : 'is-skipped' ?>">
                        <div class="md-call-flow__branch-head">
                            <span class="md-call-flow__branch-label"><?= MDJUI::e($branchLabel) ?></span>
                            <?php if ($branchNote !== ''): ?><span class="md-call-flow__branch-note"><?= MDJUI::e($branchNote) ?></span><?php endif; ?>
                            <span class="md-call-flow__branch-state"><?= $taken ? 'выполнено' : 'не выполнялось' ?></span>
                        </div>
                        <?php if ($branchNodes): ?>
                            <ol class="md-call-flow__children">
                                <?php foreach ($branchNodes as $branchNode) { $renderNode($branchNode, $depth + 1); } ?>
                            </ol>
                        <?php elseif ($branchEmpty !== ''): ?>
                            <p class="md-call-flow__branch-empty"><?= MDJUI::e($branchEmpty) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </li>
    <?php
};

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array(
    'md-card',
    'md-call-flow',
    !empty($props['compact']) ? 'md-call-flow--compact' : '',
    $props['class'],
));
if ($props['id'] !== '') {
    $attrs['id'] = $props['id'];
}

$hasHead = $props['title'] !== ''
    || $props['subtitle'] !== ''
    || $props['eyebrow'] !== ''
    || $props['actions'] !== ''
    || $props['started_at'] !== ''
    || $props['duration'] !== ''
    || $flowStatusLabel !== '';

$tree = '';
if (!$nodes) {
    $tree = MDJUI::emptyState(array(
        'icon' => 'route',
        'title' => $props['empty_title'],
        'text' => $props['empty_text'],
        'compact' => true,
    ));
} else {
    ob_start();
    ?>
    <ol class="md-call-flow__tree">
        <?php foreach ($nodes as $node) { $renderNode($node); } ?>
    </ol>
    <?php
    $tree = ob_get_clean();
}

$legend = '';
if (!empty($props['legend']) && $usedKinds) {
    ob_start();
    ?>
    <div class="md-call-flow__legend">
        <?php foreach (array_keys($usedKinds) as $kind): ?>
            <span class="md-call-flow__legend-item" data-flow-kind="<?= MDJUI::e($kind) ?>">
                <?= MDJUI::icon($kinds[$kind]['icon']) ?><span><?= MDJUI::e($kinds[$kind]['label']) ?></span>
            </span>
        <?php endforeach; ?>
    </div>
    <?php
    $legend = ob_get_clean();
}

$hasFooter = $legend !== '' || $props['footer'] !== '';
?>
<section<?= MDJUI::attrs($attrs) ?>>
    <?php if ($hasHead): ?>
        <header class="md-card__head md-call-flow__head">
            <div class="md-card__titles">
                <?php if ($props['eyebrow'] !== ''): ?><div class="md-card__eyebrow"><?= MDJUI::e($props['eyebrow']) ?></div><?php endif; ?>
                <?php if ($props['title'] !== ''): ?>
                    <h3 class="md-card__title">
                        <?= MDJUI::icon($props['icon'], array('class' => 'md-card__title-icon')) ?>
                        <?= MDJUI::e($props['title']) ?>
                    </h3>
                <?php endif; ?>
                <?php if ($props['subtitle'] !== ''): ?><p class="md-card__subtitle"><?= MDJUI::e($props['subtitle']) ?></p><?php endif; ?>
                <?php if ($props['started_at'] !== '' || $props['duration'] !== '' || $flowStatusLabel !== ''): ?>
                    <div class="md-call-flow__head-meta">
                        <?php if ($props['started_at'] !== ''): ?>
                            <span class="md-call-flow__started"><?= MDJUI::icon('clock') ?><?= MDJUI::e($props['started_at']) ?></span>
                        <?php endif; ?>
                        <?php if ($props['duration'] !== ''): ?>
                            <span class="md-call-flow__duration"><?= MDJUI::icon('stopwatch') ?><?= MDJUI::e($props['duration']) ?></span>
                        <?php endif; ?>
                        <?php if ($flowStatusLabel !== ''): ?>
                            <?= MDJUI::badge($flowStatusLabel, $flowStatusTone, array('dot' => true)) ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($props['actions'] !== ''): ?><div class="md-card__actions"><?= $props['actions'] ?></div><?php endif; ?>
        </header>
    <?php endif; ?>
    <?php if ($stats): ?>
        <div class="md-call-flow__stats">
            <?php foreach ($stats as $stat): ?>
                <span class="md-call-flow__stat">
                    <?php if ($stat['value'] !== ''): ?><b><?= MDJUI::e($stat['value']) ?></b><?php endif; ?>
                    <?php if ($stat['label'] !== ''): ?><span><?= MDJUI::e($stat['label']) ?></span><?php endif; ?>
                </span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="md-card__body md-call-flow__body"><?= $tree ?></div>
    <?php if ($hasFooter): ?>
        <footer class="md-card__footer md-call-flow__footer">
            <?= $legend ?>
            <?php if ($props['footer'] !== ''): ?><div class="md-call-flow__footer-extra"><?= $props['footer'] ?></div><?php endif; ?>
        </footer>
    <?php endif; ?>
</section>
