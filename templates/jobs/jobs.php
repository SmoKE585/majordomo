<?php
// 2026-10-25 — панель заданий на общих компонентах MDJUI.
if (!isset($data['rows'], $token, $draft, $states) || !class_exists('MDJUI', false)) return;
$e = static fn($value) => MDJUI::e((string)($value ?? ''));
$url = ROOTHTML . 'panel/jobs.html';
$pageUrl = static fn($page) => $url . '?' . http_build_query(array('status' => $status, 'q' => $search, 'page' => $page));
$dateLabel = static fn($value) => $value ? date('d.m.Y H:i:s', strtotime($value)) : '—';
?>
<link rel="stylesheet" href="<?= $e(ROOTHTML) ?>assets/modules/jobs/jobs.css?v=20261025">
<div class="md-ui-page md-ui-page--stack md-jobs-page">
    <header class="md-ui-hero">
        <div>
            <div class="md-ui-hero__eyebrow"><?= MDJUI::icon('clock') ?> Автоматизация</div>
            <h1 class="md-ui-hero__title">Задания</h1>
            <p class="md-ui-hero__lede">Отложенные команды, результаты выполнения и цепочки изменений.</p>
        </div>
        <div class="md-ui-hero__actions">
            <?= MDJUI::button('Обновить', array('href' => $pageUrl($data['page']), 'variant' => 'secondary', 'icon' => 'refresh')) ?>
            <?= MDJUI::button('Новое задание', array('href' => '#md-jobs-create', 'icon' => 'plus')) ?>
        </div>
    </header>
    <?php if ($error !== ''): ?><div class="md-alert md-alert--danger" role="alert"><?= $e($error) ?></div><?php endif; ?>
    <?php if (isset($_GET['saved'])): ?><div class="md-alert md-alert--success" role="status">Задание поставлено.</div><?php endif; ?>
    <?php if (isset($_GET['cancelled'])): ?><div class="md-alert md-alert--success" role="status">Задание удалено из расписания. Уже выполняющаяся команда может завершиться.</div><?php endif; ?>
    <div class="md-jobs-kpis">
        <?= MDJUI::kpi(array('label' => 'Ожидают', 'value' => $data['counts']['waiting'] + $data['counts']['retry'], 'icon' => 'clock', 'meta' => 'Повторов: ' . $data['counts']['retry'])) ?>
        <?= MDJUI::kpi(array('label' => 'Выполняются', 'value' => $data['counts']['running'], 'icon' => 'player-play', 'tone' => 'info')) ?>
        <?= MDJUI::kpi(array('label' => 'Ошибки', 'value' => $data['counts']['failed'], 'icon' => 'alert-triangle', 'tone' => 'danger', 'meta' => 'Исчерпаны попытки или срок')) ?>
        <?= MDJUI::kpi(array('label' => 'Выполнены', 'value' => $data['counts']['done'], 'icon' => 'check', 'tone' => 'success')) ?>
    </div>
    <?php ob_start(); ?>
        <form method="get" action="<?= $e($url) ?>" class="md-jobs-filters">
            <?= MDJUI::input(array('name' => 'q', 'label' => 'Поиск по названию', 'value' => $search, 'icon' => 'search', 'placeholder' => 'Например, выключение света')) ?>
            <?= MDJUI::select(array('name' => 'status', 'label' => 'Состояние', 'selected' => $status,
                'options' => array('' => 'Все состояния') + array_map(static fn($state) => $state[0], $states))) ?>
            <?= MDJUI::button('Показать', array('type' => 'submit', 'variant' => 'secondary', 'icon' => 'filter')) ?>
        </form>
        <?php if (!$data['rows']): ?>
            <?= MDJUI::emptyState(array('icon' => 'calendar-time', 'title' => 'Заданий нет', 'text' => $search !== '' || $status !== '' ? 'Измените фильтры или поиск.' : 'Здесь появятся таймеры из методов, сценариев и формы ниже.')) ?>
        <?php else: ?>
        <div class="md-table__scroll">
            <table class="md-table md-jobs-table">
                <thead><tr><th scope="col">Задание и команда</th><th scope="col">Время запуска</th><th scope="col">Попытки</th><th scope="col">Состояние</th><th scope="col">Действия</th></tr></thead>
                <tbody>
                <?php foreach ($data['rows'] as $job): $state = $states[$job['STATE']]; ?>
                <tr>
                    <td>
                        <strong><?= $e($job['TITLE']) ?></strong><span class="md-jobs-muted">#<?= (int)$job['ID'] ?></span>
                        <details class="md-jobs-command"><summary>PHP-команда</summary><pre><code><?= $e($job['COMMANDS']) ?></code></pre></details>
                    </td>
                    <td><time><?= $e($dateLabel($job['RUNTIME'])) ?></time>
                        <?php if ($job['STARTED']): ?><small class="md-jobs-muted">Начато: <?= $e($dateLabel($job['STARTED'])) ?></small><?php endif; ?>
                    </td>
                    <td><?= (int)$job['ATTEMPTS'] ?> / <?= (int)$maxAttempts ?></td>
                    <td><?= MDJUI::badge($state[0], $state[1]) ?>
                        <?php if ($job['LAST_ERROR']): ?><details class="md-jobs-error"><summary>Причина сбоя</summary><p><?= $e($job['LAST_ERROR']) ?></p></details><?php endif; ?>
                    </td>
                    <td><div class="md-jobs-actions">
                        <?php if ($job['TRACE_ID'] !== ''): ?>
                        <?= MDJUI::iconButton('route', array('label' => 'Цепочка изменений: ' . $job['TITLE'], 'variant' => 'secondary', 'attrs' => array(
                            'data-md-trace-open' => '', 'data-md-trace-target' => '#md-jobs-trace', 'data-trace-title' => $job['TITLE'],
                            'data-trace-url' => ROOTHTML . 'admin.php?action=jobs&md=jobs&ajax=1&op=trace&id=' . (int)$job['ID']))) ?>
                        <?php else: ?><span class="md-jobs-muted">До обновления</span><?php endif; ?>
                        <form method="post" action="<?= $e($url) ?>">
                            <input type="hidden" name="_token" value="<?= $e($token) ?>"><input type="hidden" name="operation" value="cancel"><input type="hidden" name="id" value="<?= (int)$job['ID'] ?>">
                            <?= MDJUI::iconButton('trash', array('type' => 'submit', 'label' => ($job['STATE'] === 'done' || $job['STATE'] === 'failed' ? 'Удалить запись: ' : 'Отменить задание: ') . $job['TITLE'],
                                'variant' => 'ghost', 'attrs' => array('data-md-confirm' => 'Удалить задание из расписания? Уже выполняющаяся команда может завершиться.'))) ?>
                        </form>
                    </div></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="md-jobs-pagination">
            <span class="md-jobs-muted">Найдено: <?= (int)$data['total'] ?> · Страница <?= (int)$data['page'] ?> из <?= (int)$data['pages'] ?></span>
            <nav aria-label="Страницы заданий"><ul class="pagination md-pagination">
                <?php if ($data['page'] > 1): ?><li class="page-item"><a class="page-link" href="<?= $e($pageUrl($data['page'] - 1)) ?>">Назад</a></li><?php endif; ?>
                <?php if ($data['page'] < $data['pages']): ?><li class="page-item"><a class="page-link" href="<?= $e($pageUrl($data['page'] + 1)) ?>">Далее</a></li><?php endif; ?>
            </ul></nav>
        </div>
        <?php endif; ?>
    <?php echo MDJUI::card(array('title' => 'Расписание', 'subtitle' => 'При ошибке — до ' . $maxAttempts . ' попыток. Между первыми повторами — 5 и 10 секунд.'), ob_get_clean()); ?>
    <section id="md-jobs-create" aria-label="Новое задание">
    <?php ob_start(); ?>
        <form method="post" action="<?= $e($url) ?>" class="md-ui-form-stack">
            <input type="hidden" name="_token" value="<?= $e($token) ?>"><input type="hidden" name="operation" value="add">
            <div class="md-jobs-create-fields">
                <?= MDJUI::input(array('name' => 'title', 'label' => 'Название', 'value' => $draft['title'], 'required' => true, 'attrs' => array('maxlength' => '255'), 'hint' => 'Новое задание с тем же названием заменит предыдущее.')) ?>
                <?= MDJUI::input(array('name' => 'runin', 'label' => 'Запустить через', 'type' => 'number', 'value' => $draft['runin'], 'min' => 0, 'max' => 31536000, 'required' => true)) ?>
                <?= MDJUI::select(array('name' => 'runtype', 'label' => 'Единица времени', 'selected' => $draft['runtype'], 'options' => array('s' => 'Секунды', 'm' => 'Минуты', 'h' => 'Часы'))) ?>
            </div>
            <div class="md-field"><label class="md-field__label" for="md-jobs-code">PHP-команда</label>
                <div class="md-code-editor" data-code-editor data-code-editor-key="jobs-create" data-code-editor-mode="php" data-code-editor-theme="auto" data-code-editor-autosave="0" data-code-editor-min-lines="5" data-code-editor-max-lines="14">
                    <textarea id="md-jobs-code" name="commands" aria-describedby="md-jobs-code-hint"><?= $e($draft['commands']) ?></textarea>
                </div>
                <p id="md-jobs-code-hint" class="md-field__hint"><span>Без &lt;?php. Для повтора при отказе возвращайте результат: <code>return cm('Lamp.turnOn');</code> Исключение или <code>return false;</code> запускает повтор. Постановка команды в MQTT не подтверждает ответ устройства.</span></p>
            </div>
            <div><?= MDJUI::button('Поставить задание', array('type' => 'submit', 'icon' => 'plus')) ?></div>
        </form>
    <?php echo MDJUI::card(array('title' => 'Новое задание', 'subtitle' => 'Команда выполнится один раз. Повторы нужны только после ошибки.'), ob_get_clean()); ?>
    </section>
    <?= MDJUI::traceDrawer(array('id' => 'md-jobs-trace', 'title' => 'Цепочка задания', 'default_range' => 'all', 'ranges' => array('all' => 'Вся цепочка'))) ?>
</div>
