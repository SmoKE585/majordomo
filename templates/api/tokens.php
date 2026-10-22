<?php
if (!isset($tokens, $csrf) || !class_exists('MDJUI', false)) {
    http_response_code(404);
    return;
}
$e = static fn($value) => MDJUI::e((string)($value ?? ''));
if ($tokens === array()) {
    echo MDJUI::emptyState(array(
        'icon' => 'key',
        'title' => 'Токенов пока нет',
        'text' => 'Выпустите токен для внешней интеграции. В локальной сети он не требуется.',
        'compact' => true,
    ));
    return;
}
$now = time();
?>
<div class="md-table__scroll">
    <table class="md-table md-api-tokens">
        <caption class="visually-hidden">Токены доступа ко всему API</caption>
        <thead>
            <tr>
                <th>Токен</th>
                <th>Состояние</th>
                <th>Создан</th>
                <th>Действует до</th>
                <th>Последний запрос</th>
                <th class="md-api-tokens__actions">Действия</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($tokens as $row):
            $revoked = $row['REVOKED_AT'] !== null;
            $expires = $row['EXPIRES_AT'] !== null ? (int)$row['EXPIRES_AT'] : null;
            $expired = !$revoked && $expires !== null && $expires <= $now;
            $active = !$revoked && !$expired;
            $status = $revoked ? 'Отозван' : ($expired ? 'Истёк' : 'Активен');
            $tone = $revoked ? 'neutral' : ($expired ? 'warning' : 'success');
            ?>
            <tr>
                <td>
                    <div class="md-api-token-name"><?= $e($row['NAME']) ?></div>
                    <div class="md-field__hint"><code><?= $e($row['PREFIX']) ?>…</code> · #<?= (int)$row['ID'] ?></div>
                </td>
                <td>
                    <?= MDJUI::badge($status, $tone, array('dot' => true)) ?>
                    <?php if ($revoked): ?><div class="md-field__hint"><?= $e(date('d.m.Y H:i', (int)$row['REVOKED_AT'])) ?></div><?php endif; ?>
                </td>
                <td><?= $e(date('d.m.Y H:i', (int)$row['CREATED_AT'])) ?></td>
                <td><?= $expires === null ? 'Бессрочно' : $e(date('d.m.Y H:i', $expires)) ?></td>
                <td><?= $row['LAST_USED_AT'] === null ? '<span class="md-api-muted">Ещё не использован</span>' : $e(date('d.m.Y H:i', (int)$row['LAST_USED_AT'])) ?></td>
                <td class="md-api-tokens__actions">
                    <div class="md-api-token-actions">
                        <?php if ($active): ?>
                            <form method="post" action="<?= $e(ROOTHTML) ?>panel/api.html" data-api-action data-api-confirm="Старый токен сразу перестанет работать. Сохраните новый токен после перевыпуска." data-api-confirm-title="Перевыпустить токен?">
                                <input type="hidden" name="_token" value="<?= $e($csrf) ?>"><input type="hidden" name="operation" value="rotate"><input type="hidden" name="id" value="<?= (int)$row['ID'] ?>">
                                <?= MDJUI::button('Перевыпустить', array('type' => 'submit', 'size' => 'sm', 'variant' => 'secondary', 'icon' => 'refresh', 'attrs' => array('aria-label' => 'Перевыпустить токен «' . $row['NAME'] . '»'))) ?>
                            </form>
                            <form method="post" action="<?= $e(ROOTHTML) ?>panel/api.html" data-api-action data-api-confirm="Все интеграции с этим токеном потеряют внешний доступ к API." data-api-confirm-title="Отозвать токен?">
                                <input type="hidden" name="_token" value="<?= $e($csrf) ?>"><input type="hidden" name="operation" value="revoke"><input type="hidden" name="id" value="<?= (int)$row['ID'] ?>">
                                <?= MDJUI::button('Отозвать', array('type' => 'submit', 'size' => 'sm', 'variant' => 'danger', 'icon' => 'ban', 'attrs' => array('aria-label' => 'Отозвать токен «' . $row['NAME'] . '»'))) ?>
                            </form>
                        <?php elseif ($expired): ?>
                            <form method="post" action="<?= $e(ROOTHTML) ?>panel/api.html" data-api-action data-api-confirm="Все интеграции с этим токеном потеряют внешний доступ к API." data-api-confirm-title="Отозвать токен?">
                                <input type="hidden" name="_token" value="<?= $e($csrf) ?>"><input type="hidden" name="operation" value="revoke"><input type="hidden" name="id" value="<?= (int)$row['ID'] ?>">
                                <?= MDJUI::button('Отозвать', array('type' => 'submit', 'size' => 'sm', 'variant' => 'danger', 'icon' => 'ban', 'attrs' => array('aria-label' => 'Отозвать токен «' . $row['NAME'] . '»'))) ?>
                            </form>
                        <?php else: ?>
                            <span class="md-field__hint">Доступ закрыт</span>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
