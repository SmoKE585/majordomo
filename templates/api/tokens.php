<?php
if (!isset($tokens, $csrf) || !class_exists('MDJUI', false)) { http_response_code(404); return; }
$e = static fn($value) => MDJUI::e((string)($value ?? ''));
if ($tokens === array()) {
    echo MDJUI::emptyState(array('icon' => 'key', 'title' => 'Токенов пока нет', 'description' => 'Создайте токен для внешней интеграции. В локальной сети он не требуется.'));
    return;
}
?>
<div class="md-table__scroll"><table class="md-table"><caption class="visually-hidden">Токены доступа ко всему API</caption><thead><tr><th>Название</th><th>Состояние</th><th>Срок действия</th><th>Последний запрос</th><th>Действия</th></tr></thead><tbody>
<?php foreach ($tokens as $row):
    $active = $row['REVOKED_AT'] === null && ($row['EXPIRES_AT'] === null || (int)$row['EXPIRES_AT'] > time());
    $status = $row['REVOKED_AT'] !== null ? 'Отозван' : ($active ? 'Активен' : 'Истёк');
?>
<tr>
    <td><strong><?= $e($row['NAME']) ?></strong><div class="md-field__hint"><code><?= $e($row['PREFIX']) ?>…</code> · #<?= (int)$row['ID'] ?></div></td>
    <td><?= MDJUI::badge($status, $active ? 'success' : 'neutral') ?></td>
    <td><?= $row['EXPIRES_AT'] === null ? 'Бессрочно' : $e(date('d.m.Y H:i', (int)$row['EXPIRES_AT'])) ?></td>
    <td><?= $row['LAST_USED_AT'] === null ? 'Ещё не использован' : $e(date('d.m.Y H:i', (int)$row['LAST_USED_AT'])) ?></td>
    <td><div class="d-flex flex-wrap gap-2">
    <?php foreach (array('rotate' => 'Перевыпустить', 'revoke' => 'Отозвать') as $action => $label): if (!$active && ($action === 'rotate' || $row['REVOKED_AT'] !== null)) continue; ?>
        <form method="post" action="<?= $e(ROOTHTML) ?>panel/api.html" data-api-action data-api-confirm="<?= $e($action === 'rotate' ? 'Старый токен сразу перестанет работать. Сохраните новый токен после перевыпуска.' : 'Все интеграции с этим токеном потеряют внешний доступ к API.') ?>">
            <input type="hidden" name="_token" value="<?= $e($csrf) ?>"><input type="hidden" name="operation" value="<?= $action ?>"><input type="hidden" name="id" value="<?= (int)$row['ID'] ?>">
            <?= MDJUI::button($label, array('type' => 'submit', 'size' => 'sm', 'variant' => $action === 'revoke' ? 'danger' : 'secondary', 'icon' => $action === 'revoke' ? 'ban' : 'refresh', 'attrs' => array('aria-label' => $label . ' токен «' . $row['NAME'] . '»'))) ?>
        </form>
    <?php endforeach; ?>
    <?php if ($row['REVOKED_AT'] !== null): ?><span class="md-field__hint"><?= $e(date('d.m.Y H:i', (int)$row['REVOKED_AT'])) ?></span><?php endif; ?>
    </div></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
