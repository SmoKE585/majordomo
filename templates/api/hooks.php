<?php
// 2026-10-23 — назначение webhook показывается без встраивания пользовательского кода в список.
if (!isset($hooks, $csrf) || !class_exists('MDJUI', false)) {
    http_response_code(404);
    return;
}
$e = static fn($value) => MDJUI::e((string)($value ?? ''));
if ($hooks === array()) {
    echo MDJUI::emptyState(array(
        'icon' => 'webhook',
        'title' => 'Входящих webhook пока нет',
        'text' => 'Создайте вход для модуля, сценария или собственного PHP-кода. Внешний сервис будет вызывать его по секретной ссылке.',
        'compact' => true,
    ));
    return;
}
?>
<div class="md-table__scroll">
    <table class="md-table md-api-hooks">
        <caption class="visually-hidden">Публичные входящие webhook</caption>
        <thead>
            <tr>
                <th>Вход</th>
                <th>Обработчик</th>
                <th>Методы</th>
                <th>Состояние</th>
                <th>Последнее обращение</th>
                <th class="md-api-hooks__actions">Действия</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($hooks as $hook):
            $enabled = (int)$hook['ENABLED'] === 1;
            $available = !empty($hook['AVAILABLE']);
            $status = $hook['LAST_STATUS'] ?? null;
            ?>
            <tr>
                <td>
                    <div class="md-api-hook-name"><?= $e($hook['NAME']) ?></div>
                    <div class="md-field__hint">#<?= (int)$hook['ID'] ?><?php if (!empty($hook['HEADER_NAME'])): ?> · заголовок <code><?= $e($hook['HEADER_NAME']) ?></code><?php endif; ?></div>
                </td>
                <td>
                    <div><?= MDJUI::badge($hook['TYPE_LABEL'], 'neutral') ?></div>
                    <div class="md-field__hint"><code><?= $e($hook['TARGET_LABEL']) ?></code></div>
                    <?php if (!$available): ?><div class="md-field__hint"><?= MDJUI::badge('Обработчик недоступен', 'warning') ?></div><?php endif; ?>
                </td>
                <td>
                    <div class="md-api-methods">
                        <?php foreach (($hook['METHODS_LIST'] ?? array()) as $method): ?>
                            <?= MDJUI::badge((string)$method, 'neutral') ?>
                        <?php endforeach; ?>
                    </div>
                </td>
                <td>
                    <?= MDJUI::badge($enabled ? 'Включён' : 'Отключён', $enabled ? 'success' : 'neutral', array('dot' => true)) ?>
                    <div class="md-field__hint">
                        <?= MDJUI::badge((string)($hook['STATUS_LABEL'] ?? 'Нет обращений'), (string)($hook['STATUS_TONE'] ?? 'neutral')) ?>
                    </div>
                </td>
                <td>
                    <div><?= $e($hook['LAST_USED_LABEL'] ?? 'Ещё не вызывался') ?></div>
                    <?php if (!empty($hook['LAST_METHOD'])): ?><div class="md-field__hint"><?= $e($hook['LAST_METHOD']) ?></div><?php endif; ?>
                </td>
                <td class="md-api-hooks__actions">
                    <div class="md-api-hook-actions">
                        <?= MDJUI::button('Изменить', array(
                            'type' => 'button',
                            'size' => 'sm',
                            'variant' => 'secondary',
                            'icon' => 'edit',
                            'attrs' => array(
                                'data-hook-edit' => '',
                                'data-hook-id' => (int)$hook['ID'],
                                'aria-label' => 'Изменить webhook «' . $hook['NAME'] . '»',
                            ),
                        )) ?>
                        <form method="post" action="<?= $e(ROOTHTML) ?>panel/api.html" data-api-action>
                            <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                            <input type="hidden" name="operation" value="hook_toggle">
                            <input type="hidden" name="id" value="<?= (int)$hook['ID'] ?>">
                            <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
                            <?= MDJUI::button($enabled ? 'Отключить' : 'Включить', array(
                                'type' => 'submit',
                                'size' => 'sm',
                                'variant' => 'secondary',
                                'icon' => $enabled ? 'player-pause' : 'player-play',
                                'attrs' => array('aria-label' => ($enabled ? 'Отключить' : 'Включить') . ' webhook «' . $hook['NAME'] . '»'),
                            )) ?>
                        </form>
                        <form method="post" action="<?= $e(ROOTHTML) ?>panel/api.html" data-api-action data-api-confirm="Старая ссылка сразу перестанет работать. Укажите новую ссылку во внешнем сервисе." data-api-confirm-title="Перевыпустить ссылку?">
                            <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                            <input type="hidden" name="operation" value="hook_rotate">
                            <input type="hidden" name="id" value="<?= (int)$hook['ID'] ?>">
                            <?= MDJUI::button('Перевыпустить', array(
                                'type' => 'submit',
                                'size' => 'sm',
                                'variant' => 'secondary',
                                'icon' => 'refresh',
                                'attrs' => array('aria-label' => 'Перевыпустить ссылку webhook «' . $hook['NAME'] . '»'),
                            )) ?>
                        </form>
                        <?php if (!empty($hook['HEADER_NAME'])): ?>
                            <form method="post" action="<?= $e(ROOTHTML) ?>panel/api.html" data-api-action data-api-confirm="Старый секрет заголовка сразу перестанет работать. Обновите его во внешнем сервисе." data-api-confirm-title="Перевыпустить секрет заголовка?">
                                <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="operation" value="hook_rotate_header">
                                <input type="hidden" name="id" value="<?= (int)$hook['ID'] ?>">
                                <?= MDJUI::button('Секрет заголовка', array(
                                    'type' => 'submit',
                                    'size' => 'sm',
                                    'variant' => 'secondary',
                                    'icon' => 'key',
                                    'attrs' => array('aria-label' => 'Перевыпустить секрет заголовка webhook «' . $hook['NAME'] . '»'),
                                )) ?>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?= $e(ROOTHTML) ?>panel/api.html" data-api-action data-api-confirm="Вход и его ссылка будут удалены без возможности восстановления." data-api-confirm-title="Удалить webhook?">
                            <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                            <input type="hidden" name="operation" value="hook_delete">
                            <input type="hidden" name="id" value="<?= (int)$hook['ID'] ?>">
                            <?= MDJUI::button('Удалить', array(
                                'type' => 'submit',
                                'size' => 'sm',
                                'variant' => 'danger',
                                'icon' => 'trash',
                                'attrs' => array('aria-label' => 'Удалить webhook «' . $hook['NAME'] . '»'),
                            )) ?>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
