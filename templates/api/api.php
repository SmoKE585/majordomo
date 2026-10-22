<?php
if (!isset($operations, $tokens, $csrf, $spec) || !class_exists('MDJUI', false)) { http_response_code(404); return; }
$e = static fn($value) => MDJUI::e((string)($value ?? ''));
$json = static fn($value) => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$base = rtrim(ROOTHTML, '/') . '/api/v1';
?>
<script src="<?= $e(ROOTHTML) ?>assets/modules/api/api.js?v=20261022-1" defer></script>
<div class="md-ui-page md-ui-page--stack" data-md-api>
    <?= MDJUI::sectionHeading(array('level' => 1, 'eyebrow' => 'Ядро · v1', 'title' => 'API', 'description' => 'Единый JSON API для интеграций, автоматизации и сторонних модулей.', 'actions' => MDJUI::button('OpenAPI JSON', array('href' => ROOTHTML . 'panel/api.html?api_spec=1', 'variant' => 'secondary', 'icon' => 'download', 'attrs' => array('download' => 'majordomo-openapi.json'))))) ?>
    <ul class="nav nav-tabs md-tabs" role="tablist" aria-label="Разделы API">
        <?php foreach (array('docs' => 'Документация', 'tokens' => 'Токены', 'compatibility' => 'Совместимость') as $tab => $title): ?>
        <li class="nav-item" role="presentation"><button class="nav-link<?= $tab === 'docs' ? ' active' : '' ?>" id="md-api-tab-<?= $tab ?>" data-bs-toggle="tab" data-bs-target="#md-api-<?= $tab ?>" type="button" role="tab" aria-controls="md-api-<?= $tab ?>" aria-selected="<?= $tab === 'docs' ? 'true' : 'false' ?>"><?= $title ?></button></li>
        <?php endforeach; ?>
    </ul>
    <div class="tab-content">
    <section class="tab-pane fade show active" id="md-api-docs" role="tabpanel" aria-labelledby="md-api-tab-docs" tabindex="0"><div class="md-ui-form-stack">
        <?= MDJUI::hint(array('title' => 'Как подключиться', 'text' => 'Базовый адрес: ' . $base, 'points' => array('Из локальной сети — без токена. Извне — Authorization: Bearer <токен> или X-API-Key: <токен>.', 'Тело запроса: application/json. Ответ: ok, data или error, и meta с request_id.', 'Запросы, запускающие действие, могут менять устройства. Повтор запроса повторяет действие.'))) ?>
        <?= MDJUI::input(array('id' => 'md-api-search', 'type' => 'search', 'label' => 'Найти метод', 'placeholder' => 'Свойство, помещение, сценарий…', 'attrs' => array('data-api-search' => '', 'autocomplete' => 'off'))) ?>
        <?php $lastTag = ''; foreach ($operations as $index => $operation):
            if ($operation['tag'] !== $lastTag): $lastTag = $operation['tag']; ?>
                <?= MDJUI::sectionHeading(array('level' => 2, 'title' => $lastTag)) ?>
            <?php endif;
            $entry = $spec['paths'][$operation['path']][strtolower($operation['method'])];
            ob_start(); ?>
            <p><?= $e($entry['description'] ?? $operation['summary']) ?></p>
            <?php if ($entry['parameters'] !== array()): ?>
            <div class="md-table__scroll"><table class="md-table"><caption class="visually-hidden">Параметры запроса</caption><thead><tr><th>Параметр</th><th>Где</th><th>Тип</th><th>Описание</th></tr></thead><tbody>
            <?php foreach ($entry['parameters'] as $parameter): ?><tr><td><code><?= $e($parameter['name']) ?></code><?= !empty($parameter['required']) ? ' *' : '' ?></td><td><?= $e($parameter['in']) ?></td><td><?= $e($parameter['schema']['type']) ?></td><td><?= $e($parameter['description'] ?? 'Подставьте значение в URL.') ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
            <?php if ($operation['body'] !== null): ?>
                <?= MDJUI::codeSample(array('language' => 'json', 'title' => 'JSON запроса', 'code' => $json($operation['example']))) ?>
                <?= MDJUI::codeSample(array('language' => 'json', 'title' => 'Поля тела запроса', 'code' => $json($operation['body']))) ?>
            <?php endif; ?>
            <?= MDJUI::codeSample(array('language' => 'json', 'title' => 'Что возвращает: схема data', 'code' => $json($entry['responses']['200']['content']['application/json']['schema']['properties']['data']))) ?>
            <?php
            $curl = 'curl -X ' . $operation['method'] . ' "https://YOUR_HOST' . $base . $operation['path'] . '"' . " \\\n  -H \"Authorization: Bearer YOUR_TOKEN\"";
            if ($operation['body'] !== null) $curl .= " \\\n  -H \"Content-Type: application/json\" \\\n  --data '" . json_encode($operation['example'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "'";
            echo MDJUI::codeSample(array('language' => 'bash', 'title' => 'Пример cURL', 'code' => $curl, 'wrap' => true));
            $slot = ob_get_clean();
            echo MDJUI::disclosure(array('id' => 'md-api-operation-' . $index, 'title' => $base . $operation['path'], 'description' => $operation['summary'], 'badge' => $operation['method'], 'badge_tone' => $operation['method'] === 'GET' ? 'success' : ($operation['method'] === 'DELETE' ? 'danger' : 'primary'), 'attrs' => array('data-api-operation' => mb_strtolower($operation['method'] . ' ' . $operation['path'] . ' ' . $operation['summary'] . ' ' . $operation['tag']))), $slot);
        endforeach; ?>
        <div data-api-empty hidden><?= MDJUI::emptyState(array('icon' => 'search', 'title' => 'Методы не найдены', 'description' => 'Попробуйте другое название или путь.')) ?></div>
        <?php ob_start(); ?>
            <?= MDJUI::codeSample(array('language' => 'json', 'title' => 'Успешный ответ', 'code' => $json(array('ok' => true, 'data' => 1, 'meta' => array('request_id' => '...', 'timestamp' => 1792674000, 'duration_ms' => 5.2))))) ?>
            <?= MDJUI::codeSample(array('language' => 'json', 'title' => 'Ответ с ошибкой', 'code' => $json(array('ok' => false, 'error' => array('code' => 'validation_failed', 'message' => 'Обязательное поле.', 'details' => array('field' => 'body.data')), 'meta' => array('request_id' => '...', 'timestamp' => 1792674000, 'duration_ms' => 1.1))))) ?>
            <div class="md-table__scroll"><table class="md-table"><caption class="visually-hidden">HTTP-статусы ошибок</caption><thead><tr><th>HTTP</th><th>Код ошибки</th></tr></thead><tbody><?php foreach ($entry['responses'] as $status => $definition): if ((int)$status < 400) continue; ?><tr><td><?= (int)$status ?></td><td><?= $e($definition['description']) ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php echo MDJUI::card(array('title' => 'Ответы и ошибки', 'subtitle' => 'request_id также возвращается в заголовке X-Request-ID.'), ob_get_clean()); ?>
    </div></section>
    <section class="tab-pane fade" id="md-api-tokens" role="tabpanel" aria-labelledby="md-api-tab-tokens" tabindex="0"><div class="md-ui-form-stack">
        <?= MDJUI::hint(array('title' => 'Один токен — всё API', 'text' => 'Токен нужен для внешних запросов. Можно выпустить отдельный токен для каждой интеграции. Скоупов и дополнительных прав нет.')) ?>
        <?php ob_start(); ?>
        <form method="post" action="<?= $e(ROOTHTML) ?>panel/api.html" class="md-ui-form-stack" data-api-action>
            <input type="hidden" name="_token" value="<?= $e($csrf) ?>"><input type="hidden" name="operation" value="issue">
            <div class="md-ui-form-grid">
                <?= MDJUI::input(array('id' => 'md-api-token-name', 'name' => 'name', 'label' => 'Название', 'required' => true, 'placeholder' => 'Домашняя автоматизация', 'attrs' => array('maxlength' => 190, 'autocomplete' => 'off'))) ?>
                <?= MDJUI::input(array('id' => 'md-api-token-expires', 'type' => 'datetime-local', 'name' => 'expires', 'label' => 'Действует до', 'hint' => 'Оставьте пустым для бессрочного токена. Время системы: ' . date_default_timezone_get(), 'optional' => true)) ?>
            </div>
            <div><?= MDJUI::button('Выпустить токен', array('type' => 'submit', 'icon' => 'key')) ?></div>
        </form>
        <?php echo MDJUI::card(array('title' => 'Новый токен'), ob_get_clean()); ?>
        <div data-api-feedback role="alert" hidden></div>
        <div data-api-secret tabindex="-1" hidden></div>
        <?php ob_start(); ?><div data-api-token-list><?php require ROOT . 'templates/api/tokens.php'; ?></div><?php echo MDJUI::card(array('title' => 'Выданные токены', 'subtitle' => 'Полный токен показывается только при выпуске и перевыпуске.'), ob_get_clean()); ?>
    </div></section>
    <section class="tab-pane fade" id="md-api-compatibility" role="tabpanel" aria-labelledby="md-api-tab-compatibility" tabindex="0"><div class="md-ui-form-stack">
        <?= MDJUI::hint(array('title' => 'Старые интеграции продолжают работать', 'points' => array('URL /api/* и /api.php/* сохраняют прежние названия маршрутов, query/form-параметры и поля ответа.', 'Из локальной сети токен не требуется. Внешним клиентам старых URL нужно добавить Bearer или X-API-Key.', 'callAPI() и callAPISync() обращаются к ядру напрямую. Фоновые вызовы выполняются существующей очередью.', 'Обработчики сторонних модулей api($params) и propertySetHandle() поддерживаются.', 'Удалённый devices API возвращает 410. В v1 действия используют POST, PUT и DELETE.'))) ?>
        <?= MDJUI::hint(array('tone' => 'warning', 'title' => 'Сети и reverse proxy', 'text' => 'HOME_NETWORK задаёт локальные подсети. Если настройка не задана, используются частные IPv4, IPv6 ULA/link-local и loopback. TRUSTED_PROXIES перечисляет только адреса ваших прокси. X-Forwarded-For принимается только от доверенного прокси; неопределённый источник требует токен. Межсайтовые браузерные запросы запрещены.')) ?>
    </div></section>
    </div>
    <?= MDJUI::modal(array('id' => 'md-api-confirm', 'title' => 'Изменить доступ к API?', 'footer' => MDJUI::button('Отмена', array('variant' => 'secondary', 'attrs' => array('data-bs-dismiss' => 'modal'))) . MDJUI::button('Подтвердить', array('variant' => 'danger', 'attrs' => array('data-api-confirm-submit' => '')))), '<p data-api-confirm-text></p>') ?>
</div>
