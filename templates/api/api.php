<?php
require_once ROOT . 'modules/api/lib/ApiDocs.php';
if (!isset($operations, $tokens, $csrf, $spec) || !class_exists('MDJUI', false)) { http_response_code(404); return; }
$e = static fn($value) => MDJUI::e((string)($value ?? ''));
$json = static fn($value) => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$base = rtrim(ROOTHTML, '/') . '/api/v1';
$stats = ApiDocs::tokenStats($tokens);
$groups = array();
foreach ($operations as $index => $operation) {
    $groups[$operation['tag']][$index] = $operation;
}
$groupCount = count($groups);
$methodCount = count($operations);
$tab = isset($_GET['tab']) && is_string($_GET['tab']) ? $_GET['tab'] : 'docs';
$tabDefinitions = array(
    'docs' => array('label' => 'Документация', 'icon' => 'book-2', 'count' => $methodCount),
    'tokens' => array('label' => 'Токены', 'icon' => 'key', 'count' => $stats['total']),
    'compatibility' => array('label' => 'Совместимость', 'icon' => 'arrows-exchange', 'count' => 0),
);
if (!isset($tabDefinitions[$tab])) {
    $tab = 'docs';
}
$methodCounts = array_count_values(array_column($operations, 'method'));
$resultLabel = $methodCount . ' ' . MDJUI::plural($methodCount, 'метод', 'метода', 'методов');
$description = $methodCount . ' ' . MDJUI::plural($methodCount, 'метод', 'метода', 'методов')
    . ' · ' . $groupCount . ' ' . MDJUI::plural($groupCount, 'раздел', 'раздела', 'разделов');
if ($stats['total'] > 0) {
    $description .= ' · ' . $stats['total'] . ' ' . MDJUI::plural($stats['total'], 'токен', 'токена', 'токенов');
}
$entryFor = static function (array $operation) use ($spec): array {
    return $spec['paths'][$operation['path']][strtolower($operation['method'])];
};
$renderFields = static function (array $fields) use ($e): string {
    if ($fields === array()) {
        return '';
    }
    ob_start(); ?>
    <div class="md-table__scroll">
        <table class="md-table md-api-table">
            <thead><tr><th>Поле</th><th>Тип</th><th>Обяз.</th><th>Пример</th><th>Описание</th></tr></thead>
            <tbody>
            <?php foreach ($fields as $field): ?>
                <tr>
                    <td><code><?= $e($field['name']) ?></code></td>
                    <td><?= $e($field['type']) ?></td>
                    <td><?= $field['required'] ? 'Да' : '—' ?></td>
                    <td><?= $field['example'] !== '' ? '<code>' . $e($field['example']) . '</code>' : '—' ?></td>
                    <td><?= $field['description'] !== '' ? $e($field['description']) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php return (string)ob_get_clean();
};
?>
<link rel="stylesheet" href="<?= $e(ROOTHTML) ?>assets/modules/api/api.css?v=20261022-8">
<script src="<?= $e(ROOTHTML) ?>assets/modules/api/api.js?v=20261022-6" defer></script>
<div class="md-ui-page md-ui-page--stack md-api-page" data-md-api>
    <?= MDJUI::sectionHeading(array(
        'level' => 1,
        'eyebrow' => 'Ядро · API v1',
        'title' => 'API',
        'description' => $description,
        'class' => 'md-api-hero',
        'actions' => MDJUI::button('OpenAPI JSON', array(
            'href' => ROOTHTML . 'panel/api.html?api_spec=1',
            'variant' => 'secondary',
            'icon' => 'download',
            'attrs' => array('download' => 'majordomo-openapi.json'),
        )),
    )) ?>
    <ul class="nav nav-tabs md-tabs md-api-tabs" role="tablist" aria-label="Разделы API">
        <?php foreach ($tabDefinitions as $key => $definition): ?>
            <li class="nav-item" role="presentation">
                <a class="nav-link<?= $tab === $key ? ' active' : '' ?>"
                   id="md-api-tab-<?= $e($key) ?>"
                   href="<?= $e(ROOTHTML) ?>panel/api.html?tab=<?= $e($key) ?>"
                   data-bs-toggle="tab"
                   data-bs-target="#md-api-<?= $e($key) ?>"
                   data-api-tab="<?= $e($key) ?>"
                   role="tab"
                   aria-controls="md-api-<?= $e($key) ?>"
                   aria-selected="<?= $tab === $key ? 'true' : 'false' ?>">
                    <?= MDJUI::icon($definition['icon']) ?>
                    <span><?= $e($definition['label']) ?></span>
                    <?php if ($definition['count'] > 0 || $key === 'tokens'): ?><span class="md-badge md-badge--neutral"<?= $key === 'tokens' ? ' data-api-tab-count' : '' ?><?= $definition['count'] === 0 ? ' hidden' : '' ?>><?= (int)$definition['count'] ?></span><?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
    <div class="tab-content">
    <section class="tab-pane fade<?= $tab === 'docs' ? ' show active' : '' ?>" id="md-api-docs" role="tabpanel" aria-labelledby="md-api-tab-docs" tabindex="0">
        <div class="md-ui-form-stack">
            <?= MDJUI::hint(array(
                'title' => 'Как подключиться',
                'text' => 'Базовый адрес: ' . $base,
                'points' => array(
                    'Из локальной сети — без токена. Извне — Authorization: Bearer <токен> или X-API-Key: <токен>.',
                    'Тело запроса: application/json. Ответ: ok, data или error, и meta с request_id.',
                    'Запросы, запускающие действие, могут менять устройства. Повтор запроса повторяет действие.',
                ),
            )) ?>
            <div class="md-api-toolbar">
                <div class="md-api-toolbar__search">
                    <?= MDJUI::input(array(
                        'id' => 'md-api-search',
                        'type' => 'search',
                        'label' => 'Найти метод',
                        'placeholder' => 'Название, путь, параметр…',
                        'attrs' => array('data-api-search' => '', 'autocomplete' => 'off', 'aria-controls' => 'md-api-docs'),
                    )) ?>
                </div>
                <div class="md-api-result" data-api-result data-total-label="<?= $e($resultLabel) ?>" aria-live="polite"><?= $e($resultLabel) ?></div>
            </div>
            <div class="md-api-layout">
                <aside class="md-api-index" aria-label="Разделы API">
                    <nav class="md-sidenav" aria-label="Разделы документации">
                        <div class="md-sidenav__label">Разделы</div>
                        <?php $navIndex = 0; foreach ($groups as $tag => $group): ?>
                            <a class="md-sidenav__item" href="#md-api-group-<?= $navIndex ?>" data-api-nav="<?= $navIndex ?>" data-count="<?= count($group) ?>">
                                <?= MDJUI::icon(ApiDocs::groupIcon($tag)) ?>
                                <span><?= $e($tag) ?></span>
                                <span class="md-badge md-badge--neutral" data-api-nav-count><?= count($group) ?></span>
                            </a>
                        <?php $navIndex++; endforeach; ?>
                    </nav>
                    <div class="md-api-legend" aria-label="Методы запросов">
                        <?php foreach (array('GET', 'POST', 'PUT', 'PATCH', 'DELETE') as $method): if (empty($methodCounts[$method])) continue; ?>
                            <?= MDJUI::badge($method . ' ' . $methodCounts[$method], ApiDocs::methodTone($method), array('class' => 'md-api-legend__badge')) ?>
                        <?php endforeach; ?>
                    </div>
                </aside>
                <div class="md-api-content">
                    <?php $groupIndex = 0; foreach ($groups as $tag => $group): ?>
                        <section class="md-api-group" id="md-api-group-<?= $groupIndex ?>" data-api-group="<?= $groupIndex ?>" aria-labelledby="md-api-group-title-<?= $groupIndex ?>">
                            <?= MDJUI::sectionHeading(array(
                                'level' => 2,
                                'title' => $tag,
                                'description' => count($group) . ' ' . MDJUI::plural(count($group), 'метод', 'метода', 'методов'),
                                'id' => 'md-api-group-title-' . $groupIndex,
                                'class' => 'md-api-group__head',
                            )) ?>
                            <div class="md-api-ops">
                                <?php foreach ($group as $index => $operation):
                                    $entry = $entryFor($operation);
                                    $parameters = $entry['parameters'] ?? array();
                                    $example = is_array($operation['example']) ? $operation['example'] : array();
                                    $bodyFields = $operation['body'] !== null ? ApiDocs::fields($operation['body'], $example) : array();
                                    $dataSchema = $entry['responses']['200']['content']['application/json']['schema']['properties']['data'];
                                    $responseFields = ApiDocs::fields($dataSchema, ApiDocs::sample($dataSchema));
                                    $operationDescription = trim((string)($entry['description'] ?? ''));
                                    ob_start(); ?>
                                    <?php if ($operationDescription !== '' && $operationDescription !== $operation['summary']): ?>
                                        <p class="md-api-summary"><?= $e($operationDescription) ?></p>
                                    <?php endif; ?>
                                    <?php if ($parameters !== array()): ?>
                                        <div class="md-api-block">
                                            <div class="md-api-block__label">Параметры запроса</div>
                                            <div class="md-table__scroll">
                                                <table class="md-table md-api-table">
                                                    <caption class="visually-hidden">Параметры запроса</caption>
                                                    <thead><tr><th>Параметр</th><th>Где</th><th>Тип</th><th>Обяз.</th><th>Пример</th><th>Описание</th></tr></thead>
                                                    <tbody>
                                                    <?php foreach ($parameters as $parameter): ?>
                                                        <tr>
                                                            <td><code><?= $e($parameter['name']) ?></code></td>
                                                            <td><?= $e($parameter['in']) ?></td>
                                                            <td><?= $e(ApiDocs::typeLabel($parameter['schema'] ?? array())) ?></td>
                                                            <td><?= !empty($parameter['required']) ? 'Да' : '—' ?></td>
                                                            <td><code><?= $e(ApiDocs::parameterExample($parameter)) ?></code></td>
                                                            <td><?= $e(ApiDocs::parameterDescription($parameter)) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($operation['body'] !== null): ?>
                                        <div class="md-api-block">
                                            <div class="md-api-block__label">Тело запроса</div>
                                            <?= MDJUI::codeSample(array('language' => 'json', 'title' => 'JSON запроса', 'code' => $json($operation['example']), 'line_numbers' => false)) ?>
                                            <?= $renderFields($bodyFields) ?>
                                            <?php if ($bodyFields === array()): ?><p class="md-api-note">Свободная структура: ключи и значения определяет вызывающий код.</p><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="md-api-block">
                                        <div class="md-api-block__label">Ответ · data</div>
                                        <?= $renderFields($responseFields) ?>
                                        <?php if ($responseFields === array()): ?><p class="md-api-note">Структура зависит от запроса: объект или массив с произвольными полями.</p><?php endif; ?>
                                    </div>
                                    <?= MDJUI::codeSample(array('language' => 'bash', 'title' => 'Пример cURL', 'code' => ApiDocs::curl($operation, $parameters, $base), 'wrap' => true)) ?>
                                    <?php $slot = ob_get_clean();
                                    echo MDJUI::disclosure(array(
                                        'id' => 'md-api-operation-' . $index,
                                        'title' => $base . $operation['path'],
                                        'description' => $operation['summary'],
                                        'badge' => $operation['method'],
                                        'badge_tone' => ApiDocs::methodTone($operation['method']),
                                        'attrs' => array('data-api-operation' => ApiDocs::searchText($operation, $entry)),
                                    ), $slot);
                                    ?>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php $groupIndex++; endforeach; ?>
                    <div data-api-empty hidden><?= MDJUI::emptyState(array('icon' => 'search', 'title' => 'Методы не найдены', 'text' => 'Попробуйте другое название, путь или параметр.', 'compact' => true)) ?></div>
                    <?php
                    $errorResponses = array();
                    foreach ($operations as $operation) {
                        foreach ($entryFor($operation)['responses'] as $status => $definition) {
                            if ((int)$status >= 400) {
                                $errorResponses[(int)$status] = $definition;
                            }
                        }
                    }
                    ksort($errorResponses);
                    ob_start(); ?>
                    <p class="md-api-summary">Единый конверт ответа: полезная нагрузка в <code>data</code>, служебные поля в <code>meta</code>.</p>
                    <?= MDJUI::codeSample(array('language' => 'json', 'title' => 'Успешный ответ', 'code' => $json(array('ok' => true, 'data' => 1, 'meta' => array('request_id' => '...', 'timestamp' => 1792674000, 'duration_ms' => 5.2))))) ?>
                    <?= MDJUI::codeSample(array('language' => 'json', 'title' => 'Ответ с ошибкой', 'code' => $json(array('ok' => false, 'error' => array('code' => 'validation_failed', 'message' => 'Обязательное поле.', 'details' => array('field' => 'body.data')), 'meta' => array('request_id' => '...', 'timestamp' => 1792674000, 'duration_ms' => 1.1))))) ?>
                    <div class="md-table__scroll">
                        <table class="md-table md-api-table">
                            <caption class="visually-hidden">HTTP-статусы ошибок</caption>
                            <thead><tr><th>HTTP</th><th>Код ошибки</th></tr></thead>
                            <tbody>
                            <?php foreach ($errorResponses as $status => $definition): ?>
                                <tr><td><?= MDJUI::badge((string)$status, $status >= 500 ? 'danger' : 'warning') ?></td><td><?= $e($definition['description']) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php echo MDJUI::card(array('title' => 'Ответы и ошибки', 'subtitle' => 'request_id также возвращается в заголовке X-Request-ID.'), ob_get_clean()); ?>
                </div>
            </div>
        </div>
    </section>
    <section class="tab-pane fade<?= $tab === 'tokens' ? ' show active' : '' ?>" id="md-api-tokens" role="tabpanel" aria-labelledby="md-api-tab-tokens" tabindex="0">
        <div class="md-ui-form-stack">
            <?= MDJUI::hint(array('title' => 'Один токен — всё API', 'text' => 'Токен нужен для внешних запросов. Можно выпустить отдельный токен для каждой интеграции. Скоупов и дополнительных прав нет.')) ?>
            <?php $unlimitedLabel = $stats['unlimited'] > 0 ? 'Из них ' . $stats['unlimited'] . ' ' . MDJUI::plural($stats['unlimited'], 'бессрочный', 'бессрочных', 'бессрочных') : ($stats['active'] > 0 ? 'Действуют сейчас' : 'Нет активных токенов'); ?>
            <div class="md-ui-kpi-grid">
                <?= MDJUI::kpi(array('label' => 'Всего токенов', 'value' => $stats['total'], 'icon' => 'key', 'meta' => 'Для внешних интеграций', 'attrs' => array('data-api-stat' => 'total'))) ?>
                <?= MDJUI::kpi(array('label' => 'Активные', 'value' => $stats['active'], 'tone' => 'success', 'icon' => 'circle-check', 'meta' => $unlimitedLabel, 'attrs' => array('data-api-stat' => 'active'))) ?>
                <?= MDJUI::kpi(array('label' => 'Истекли', 'value' => $stats['expired'], 'tone' => $stats['expired'] > 0 ? 'warning' : 'neutral', 'icon' => 'clock-exclamation', 'meta' => 'Нужен новый токен', 'attrs' => array('data-api-stat' => 'expired'))) ?>
                <?= MDJUI::kpi(array('label' => 'Отозваны', 'value' => $stats['revoked'], 'tone' => 'neutral', 'icon' => 'ban', 'meta' => 'Доступ закрыт', 'attrs' => array('data-api-stat' => 'revoked'))) ?>
            </div>
            <?php ob_start(); ?>
            <form method="post" action="<?= $e(ROOTHTML) ?>panel/api.html" class="md-ui-form-stack" data-api-action>
                <input type="hidden" name="_token" value="<?= $e($csrf) ?>"><input type="hidden" name="operation" value="issue">
                <div class="md-ui-form-grid">
                    <?= MDJUI::input(array('id' => 'md-api-token-name', 'name' => 'name', 'label' => 'Название', 'required' => true, 'placeholder' => 'Домашняя автоматизация', 'attrs' => array('maxlength' => 190, 'autocomplete' => 'off'))) ?>
                    <?= MDJUI::input(array('id' => 'md-api-token-expires', 'type' => 'datetime-local', 'name' => 'expires', 'label' => 'Действует до', 'min' => date('Y-m-d\TH:i'), 'hint' => 'Пусто — без срока. Часовой пояс: ' . ApiDocs::timezoneLabel(), 'optional' => true)) ?>
                </div>
                <div><?= MDJUI::button('Выпустить токен', array('type' => 'submit', 'icon' => 'key')) ?></div>
            </form>
            <?php echo MDJUI::card(array('title' => 'Новый токен', 'subtitle' => 'Токен действует на всё API. Отдельных прав и скоупов нет.'), ob_get_clean()); ?>
            <div class="md-ui-note md-ui-note--warning md-api-feedback" data-api-feedback role="alert" tabindex="-1" hidden>
                <?= MDJUI::icon('alert-circle') ?><span data-api-feedback-text></span>
            </div>
            <div data-api-secret tabindex="-1" role="region" aria-label="Новый токен" hidden></div>
            <div class="visually-hidden" data-api-status role="status" aria-live="polite"></div>
            <?php ob_start(); ?>
            <div class="md-api-token-list" data-api-token-list role="region" aria-label="Выданные токены" tabindex="-1">
                <?php require ROOT . 'templates/api/tokens.php'; ?>
            </div>
            <?php echo MDJUI::card(array('title' => 'Выданные токены', 'subtitle' => 'Полный токен показывается только при выпуске и перевыпуске.'), ob_get_clean()); ?>
        </div>
    </section>
    <section class="tab-pane fade<?= $tab === 'compatibility' ? ' show active' : '' ?>" id="md-api-compatibility" role="tabpanel" aria-labelledby="md-api-tab-compatibility" tabindex="0">
        <div class="md-ui-form-stack">
            <?php ob_start(); ?>
            <div class="md-table__scroll">
                <table class="md-table md-api-table">
                    <caption class="visually-hidden">Параметры подключения к API v1</caption>
                    <thead><tr><th>Что</th><th>Значение</th></tr></thead>
                    <tbody>
                        <tr><td>Базовый URL</td><td><code><?= $e($base) ?></code></td></tr>
                        <tr><td>Авторизация</td><td><code>Authorization: Bearer &lt;токен&gt;</code> или <code>X-API-Key: &lt;токен&gt;</code></td></tr>
                        <tr><td>Тело запроса</td><td><code>Content-Type: application/json</code></td></tr>
                        <tr><td>Успех</td><td><code>ok</code>, <code>data</code>, <code>meta.request_id</code></td></tr>
                        <tr><td>Ошибка</td><td><code>ok: false</code>, <code>error.code</code>, <code>error.message</code>, <code>error.details</code></td></tr>
                    </tbody>
                </table>
            </div>
            <?php echo MDJUI::card(array('title' => 'Как обращаться к v1', 'icon' => 'route'), ob_get_clean()); ?>
            <?php ob_start(); ?>
            <ul class="md-api-list">
                <li>URL <code>/api/*</code> и <code>/api.php/*</code> сохраняют прежние названия маршрутов, query/form-параметры и поля ответа.</li>
                <li>Из локальной сети токен не требуется. Внешним клиентам старых URL нужно добавить Bearer или X-API-Key.</li>
                <li><code>callAPI()</code> и <code>callAPISync()</code> обращаются к ядру напрямую. Фоновые вызовы выполняются существующей очередью.</li>
                <li>Обработчики сторонних модулей <code>api($params)</code> и <code>propertySetHandle()</code> поддерживаются.</li>
                <li>Удалённый devices API возвращает 410. В v1 действия используют POST, PUT и DELETE.</li>
            </ul>
            <?php echo MDJUI::card(array('title' => 'Старые интеграции продолжают работать', 'icon' => 'history'), ob_get_clean()); ?>
            <?= MDJUI::hint(array(
                'tone' => 'warning',
                'title' => 'Сети и reverse proxy',
                'text' => 'HOME_NETWORK задаёт локальные подсети. Если настройка не задана, используются частные IPv4, IPv6 ULA/link-local и loopback. TRUSTED_PROXIES перечисляет только адреса ваших прокси. X-Forwarded-For принимается только от доверенного прокси; неопределённый источник требует токен. Межсайтовые браузерные запросы запрещены.',
            )) ?>
        </div>
    </section>
    </div>
    <?= MDJUI::modal(array('id' => 'md-api-confirm', 'title' => 'Изменить доступ к API?', 'footer' => MDJUI::button('Отмена', array('variant' => 'secondary', 'attrs' => array('data-bs-dismiss' => 'modal'))) . MDJUI::button('Подтвердить', array('variant' => 'danger', 'attrs' => array('data-api-confirm-submit' => '')))), '<p data-api-confirm-text></p>') ?>
</div>
