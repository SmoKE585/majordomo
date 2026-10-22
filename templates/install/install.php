<?php
/**
 * Мастер первичной настройки.
 * 2026-10-22 — фирменные логотипы для обеих тем и новый favicon.
 *
 * @var \MajorDoMo\Core\Install\Installer $installer
 * @var array<string,string> $values
 * @var array<string,string> $errors
 * @var string[] $warnings
 * @var bool $needsAdmin
 * @var int $startStep
 * @var array<string,array<string,mixed>> $checks
 * @var array<string,int> $checkSummary
 * @var bool $isSuccess
 * @var array<string,mixed> $summary
 * @var string[] $successWarnings
 * @var string $csrfToken
 * @var string $installUrl
 * @var string $panelUrl
 * @var string $rootHtml
 * @var string $adminCss
 * @var string $installCss
 * @var string $installJs
 * @var string $projectTitle
 * @var string $pageTitle
 */

use MajorDoMo\Core\Install\InstallFormRenderer;
use MajorDoMo\Core\Install\InstallSchema;

$steps = InstallSchema::steps();
$stepCount = count($steps);
$secrets = $installer->secretFlags();
$dbStatus = $installer->databaseStatus();
$blocking = (int)($checkSummary['blocking'] ?? 0);
$hasAdmin = $installer->hasAdmin();
$globalErrors = array();
if (isset($errors['_form'])) {
    $globalErrors[] = (string)$errors['_form'];
}
if (isset($errors['_database']) && !empty($errors['_database'])) {
    $globalErrors[] = (string)$errors['_database'];
}

$fieldsByStep = array();
foreach ($steps as $stepIndex => $step) {
    $fieldsByStep[$stepIndex] = InstallSchema::fieldsForStep((int)$stepIndex);
}
$fieldsBySection = static function (array $fields): array {
    $result = array();
    foreach ($fields as $field) {
        $result[(string)$field['section']][] = $field;
    }
    return $result;
};
$renderFields = static function (array $fields) use ($values, $errors, $secrets): string {
    $html = '<div class="md-install-fields">';
    foreach ($fields as $field) {
        $html .= InstallFormRenderer::field($field, $values[$field['id']] ?? '', array(
            'errors' => $errors,
            'secrets' => $secrets,
        ));
    }
    return $html . '</div>';
};
$statusMeta = array(
    'ok' => array('label' => 'Готово', 'tone' => 'success', 'icon' => 'circle-check'),
    'warn' => array('label' => 'Внимание', 'tone' => 'warning', 'icon' => 'alert-triangle'),
    'fail' => array('label' => 'Ошибка', 'tone' => 'danger', 'icon' => 'x-octagon'),
    'info' => array('label' => 'Инфо', 'tone' => 'neutral', 'icon' => 'info-circle'),
);
$themeLabel = static function (string $theme): string {
    return $theme === 'light' ? 'Светлая' : 'Тёмная';
};
$languageLabel = static function (string $language): string {
    $options = InstallSchema::languageOptions();
    return isset($options[$language]) ? (string)$options[$language] : $language;
};
$displayField = static function (array $field, string $value) use ($secrets): string {
    $id = (string)$field['id'];
    if (!empty($field['secret'])) {
        if ($value !== '') {
            return '•••••••• (новый)';
        }
        return !empty($secrets[$id]) ? '•••••••• (сохранён)' : 'не задано';
    }
    return InstallFormRenderer::displayValue($field, $value);
};
?>
<!DOCTYPE html>
<html lang="ru" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= \MDJUI::e($pageTitle) ?></title>
    <link rel="icon" href="<?= \MDJUI::e($rootHtml) ?>favicon.ico?v=2026-10-22-logo" sizes="any">
    <link rel="apple-touch-icon" href="<?= \MDJUI::e($rootHtml) ?>assets/img/logos/favicon.png">
    <script>
    (function () {
        document.documentElement.classList.add('md-install-js');
        var match = document.cookie.match(/(?:^|;\s*)md_install_theme=([^;]+)/);
        var theme = match ? decodeURIComponent(match[1]) : 'dark';
        document.documentElement.setAttribute('data-bs-theme', theme === 'light' ? 'light' : 'dark');
    })();
    window.MDInstallConfig = <?= json_encode(array(
        'url' => $installUrl,
        'startStep' => (int)$startStep,
        'lastStep' => $stepCount - 1,
        'themeCookie' => 'md_install_theme',
        'blocking' => $blocking,
    ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <link rel="stylesheet" href="<?= \MDJUI::e($adminCss) ?>">
    <link rel="stylesheet" href="<?= \MDJUI::e($installCss) ?>">
</head>
<body class="md-admin-shell md-install-shell">
<div class="md-install<?= $isSuccess ? ' md-install--success' : '' ?>">
    <header class="md-install__topbar">
        <a class="md-install__brand" href="<?= \MDJUI::e($installUrl) ?>">
            <?= \MDJUI::logo(array('root' => $rootHtml)) ?>
            <span class="md-install__brand-text">
                <small><?= $isSuccess ? 'Настройка завершена' : 'Первичная настройка' ?></small>
            </span>
        </a>
        <div class="md-install__topbar-meta">
            <span class="md-badge md-badge--outline md-install__step-badge" data-install-step-label>
                <?= $isSuccess ? 'Готово' : 'Шаг ' . ((int)$startStep + 1) . ' из ' . $stepCount ?>
            </span>
            <button type="button" class="md-install__icon-btn" data-install-theme-toggle aria-label="Переключить светлую или тёмную тему" title="Сменить тему">
                <i class="ti ti-sun" data-install-theme-icon aria-hidden="true"></i>
            </button>
        </div>
    </header>

    <div class="md-install__layout">
        <?php if (!$isSuccess): ?>
        <aside class="md-install__aside" aria-label="Шаги настройки">
            <div class="md-install__intro">
                <div class="md-install__eyebrow"><?= \MDJUI::icon('rocket') ?> Запуск системы</div>
                <h1>Настроим дом<br>за несколько минут</h1>
                <p>Мастер проверит окружение, подключит базу и запишет понятные настройки. Вручную лезть в <code>.env</code> не придётся.</p>
            </div>

            <ol class="md-install__steps">
                <?php foreach ($steps as $index => $step): ?>
                <li>
                    <button type="button" class="md-install__step-link<?= $index === (int)$startStep ? ' is-active' : '' ?><?= $index < (int)$startStep ? ' is-done' : '' ?>" data-install-goto="<?= (int)$index ?>" aria-current="<?= $index === (int)$startStep ? 'step' : 'false' ?>">
                        <span class="md-install__step-num"><?= \MDJUI::e($step['number']) ?></span>
                        <span class="md-install__step-text">
                            <strong><?= \MDJUI::e($step['title']) ?></strong>
                            <small><?= \MDJUI::e($step['description']) ?></small>
                        </span>
                        <span class="md-install__step-check"><?= \MDJUI::icon('check') ?></span>
                    </button>
                </li>
                <?php endforeach; ?>
            </ol>

            <div class="md-install__aside-note">
                <span class="md-install__aside-note-icon"><?= \MDJUI::icon('shield-lock') ?></span>
                <div>
                    <strong>Безопасно по шагам</strong>
                    <p>Пароли не показываются и не попадают в журналы. Перед изменением <code>.env</code> создаётся резервная копия.</p>
                </div>
            </div>
        </aside>
        <?php endif; ?>

        <main class="md-install__main<?= $isSuccess ? ' md-install__main--success' : '' ?>">
            <?php if ($isSuccess): ?>
            <section class="md-install-success">
                <div class="md-install-success__glow" aria-hidden="true"></div>
                <div class="md-install-success__icon"><?= \MDJUI::icon('circle-check') ?></div>
                <div class="md-install-success__eyebrow">Всё готово</div>
                <h1>Система настроена</h1>
                <p class="md-install-success__lead">MajorDoMo готова к работе. Настройки сохранены в <code>.env</code> и базе данных, мастер больше не будет перехватывать страницы.</p>

                <div class="md-install-success__grid">
                    <div class="md-install-success__stat">
                        <span class="md-install-success__stat-label">Система</span>
                        <strong><?= \MDJUI::e((string)($summary['system_name'] ?? $summary['project_title'] ?? $projectTitle)) ?></strong>
                        <small><?= \MDJUI::e((string)($summary['project_title'] ?? $projectTitle)) ?></small>
                    </div>
                    <div class="md-install-success__stat">
                        <span class="md-install-success__stat-label">Адрес</span>
                        <strong><?= \MDJUI::e((string)($summary['base_url'] ?? '')) ?></strong>
                        <small><?= \MDJUI::e((string)($summary['root_html'] ?? '/')) ?></small>
                    </div>
                    <div class="md-install-success__stat">
                        <span class="md-install-success__stat-label">База данных</span>
                        <strong><?= \MDJUI::e((string)($summary['db_name'] ?? '')) ?></strong>
                        <small><?= \MDJUI::e((string)($summary['db_server'] ?? '')) ?></small>
                    </div>
                    <div class="md-install-success__stat">
                        <span class="md-install-success__stat-label">Интерфейс</span>
                        <strong><?= \MDJUI::e($themeLabel((string)($summary['theme'] ?? 'dark'))) ?> тема</strong>
                        <small><?= \MDJUI::e($languageLabel((string)($summary['language'] ?? 'ru'))) ?> · <?= \MDJUI::e((string)($summary['timezone'] ?? '')) ?></small>
                    </div>
                </div>

                <div class="md-install-success__checks">
                    <div class="md-install-success__check">
                        <span class="md-install-success__check-icon"><?= \MDJUI::icon('check') ?></span>
                        <span>Конфигурация <code>.env</code> записана</span>
                    </div>
                    <div class="md-install-success__check">
                        <span class="md-install-success__check-icon"><?= \MDJUI::icon('check') ?></span>
                        <span>Настройки интерфейса сохранены</span>
                    </div>
                    <?php if (!empty($summary['schema_created'])): ?>
                    <div class="md-install-success__check">
                        <span class="md-install-success__check-icon"><?= \MDJUI::icon('check') ?></span>
                        <span>Таблицы базы созданы из чистой схемы</span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($summary['admin_created'])): ?>
                    <div class="md-install-success__check">
                        <span class="md-install-success__check-icon"><?= \MDJUI::icon('check') ?></span>
                        <span>Администратор <strong><?= \MDJUI::e((string)($summary['admin_login'] ?? '')) ?></strong> создан</span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($summary['backup'])): ?>
                    <div class="md-install-success__check">
                        <span class="md-install-success__check-icon"><?= \MDJUI::icon('check') ?></span>
                        <span>Резервная копия <code>.env</code> создана</span>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($successWarnings)): ?>
                <div class="md-install-warnings md-install-warnings--success">
                    <div class="md-install-warnings__head"><?= \MDJUI::icon('alert-triangle') ?> На что обратить внимание</div>
                    <ul>
                        <?php foreach ($successWarnings as $warning): ?>
                        <li><?= \MDJUI::e((string)$warning) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <div class="md-install-success__next">
                    <h2>Что дальше</h2>
                    <div class="md-install-success__next-grid">
                        <a class="md-install-next-card" href="<?= \MDJUI::e($panelUrl) ?>">
                            <span class="md-install-next-card__icon"><?= \MDJUI::icon('layout-dashboard') ?></span>
                            <span><strong>Открыть панель</strong><small>Войти и осмотреться</small></span>
                            <?= \MDJUI::icon('arrow-right') ?>
                        </a>
                        <a class="md-install-next-card" href="<?= \MDJUI::e(rtrim($rootHtml, '/') . '/panel/xray.html') ?>">
                            <span class="md-install-next-card__icon"><?= \MDJUI::icon('activity') ?></span>
                            <span><strong>Проверить циклы</strong><small>X-Ray и состояние системы</small></span>
                            <?= \MDJUI::icon('arrow-right') ?>
                        </a>
                        <a class="md-install-next-card" href="<?= \MDJUI::e(rtrim($rootHtml, '/') . '/panel/saverestore.html') ?>">
                            <span class="md-install-next-card__icon"><?= \MDJUI::icon('archive') ?></span>
                            <span><strong>Настроить бэкапы</strong><small>Резервное копирование</small></span>
                            <?= \MDJUI::icon('arrow-right') ?>
                        </a>
                        <a class="md-install-next-card" href="<?= \MDJUI::e(rtrim($rootHtml, '/') . '/panel/settings.html') ?>">
                            <span class="md-install-next-card__icon"><?= \MDJUI::icon('settings') ?></span>
                            <span><strong>Проверить настройки</strong><small>Язык, тема, доступ</small></span>
                            <?= \MDJUI::icon('arrow-right') ?>
                        </a>
                    </div>
                </div>

                <div class="md-install-success__actions">
                    <?= \MDJUI::button('Открыть панель управления', array('variant' => 'primary', 'size' => 'lg', 'icon' => 'layout-dashboard', 'href' => $panelUrl)) ?>
                    <a class="md-btn md-btn--ghost" href="<?= \MDJUI::e(rtrim($rootHtml, '/') . '/panel/settings.html') ?>">Настройки</a>
                </div>

                <details class="md-install-success__details">
                    <summary>Если нужно пройти мастер заново</summary>
                    <p>Выполните в каталоге проекта команду ниже — маркер установки сбросится, данные и база останутся на месте.</p>
                    <div class="md-install-code">
                        <code>php tools/install.php reset</code>
                        <button type="button" class="md-install-code__copy" data-install-copy="php tools/install.php reset"><?= \MDJUI::icon('copy') ?> Копировать</button>
                    </div>
                    <?php if (!empty($summary['backup'])): ?>
                    <p class="md-install-success__backup">Резервная копия: <code><?= \MDJUI::e((string)$summary['backup']) ?></code></p>
                    <?php endif; ?>
                </details>
            </section>
            <?php else: ?>
            <form class="md-install-form" method="post" action="<?= \MDJUI::e($installUrl) ?>" data-install-form data-start-step="<?= (int)$startStep ?>" data-install-blocked="<?= $blocking > 0 ? '1' : '0' ?>" novalidate>
                <input type="hidden" name="_token" value="<?= \MDJUI::e($csrfToken) ?>">
                <?= InstallFormRenderer::timezoneDatalist() ?>

                <noscript>
                    <div class="md-install-note md-install-note--warning">
                        <?= \MDJUI::icon('info-circle') ?>
                        <span>JavaScript выключен. Все шаги показаны сразу: заполните нужные поля и нажмите «Завершить настройку». Проверка подключения к базе в этом режиме недоступна.</span>
                    </div>
                </noscript>

                <?php if ($globalErrors !== array()): ?>
                <div class="md-install-global-errors">
                    <?php foreach ($globalErrors as $globalError): ?>
                    <?= \MDJUI::alert('danger', 'Не удалось продолжить', (string)$globalError) ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($warnings !== array()): ?>
                <div class="md-install-warnings md-install-warnings--top">
                    <div class="md-install-warnings__head"><?= \MDJUI::icon('alert-triangle') ?> Проверьте перед завершением</div>
                    <ul>
                        <?php foreach ($warnings as $warning): ?>
                        <li><?= \MDJUI::e((string)$warning) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- Шаг 1. Проверка окружения ---------------------------------- -->
                <section class="md-install-step<?= (int)$startStep === 0 ? ' is-active' : '' ?>" data-install-step="0" data-install-title="Проверка">
                    <header class="md-install-step__head">
                        <div class="md-install-step__eyebrow">Шаг 01</div>
                        <h2>Проверим, всё ли готово</h2>
                        <p>Это read-only проверка. Ничего не меняется: мастер только смотрит версию PHP, расширения, права на файлы и доступность базы.</p>
                    </header>

                    <?php if ($blocking > 0): ?>
                        <?= \MDJUI::alert('danger', 'Есть блокирующие проблемы', 'Исправьте отмеченные пункты и обновите страницу. Продолжать с ними нельзя.') ?>
                    <?php elseif (($checkSummary['fail'] ?? 0) > 0): ?>
                        <?= \MDJUI::alert('warning', 'Есть проблемы, которые нужно решить', 'Продолжить можно, чтобы настроить доступы, но финальное сохранение потребует исправить отмеченные пункты.') ?>
                    <?php elseif (($checkSummary['warn'] ?? 0) > 0): ?>
                        <?= \MDJUI::alert('warning', 'Есть предупреждения', 'Продолжить можно, но лучше прочитать пояснения: они помогут избежать сюрпризов позже.') ?>
                    <?php else: ?>
                        <?= \MDJUI::alert('success', 'Всё готово', 'Сервер соответствует требованиям. Переходите к подключению базы данных.') ?>
                    <?php endif; ?>

                    <div class="md-install-checks">
                        <?php foreach ($checks as $groupKey => $group): ?>
                        <section class="md-install-check-group">
                            <header class="md-install-check-group__head">
                                <span class="md-install-check-group__icon"><?= \MDJUI::icon((string)$group['icon']) ?></span>
                                <h3><?= \MDJUI::e((string)$group['title']) ?></h3>
                            </header>
                            <ul class="md-install-check-list">
                                <?php foreach ($group['items'] as $item): ?>
                                <?php $status = (string)($item['status'] ?? 'info'); $meta = $statusMeta[$status] ?? $statusMeta['info']; ?>
                                <li class="md-install-check md-install-check--<?= \MDJUI::e($status) ?>">
                                    <span class="md-install-check__icon"><?= \MDJUI::icon((string)$meta['icon']) ?></span>
                                    <div class="md-install-check__body">
                                        <div class="md-install-check__line">
                                            <span class="md-install-check__label"><?= \MDJUI::e((string)($item['label'] ?? '')) ?></span>
                                            <?= \MDJUI::badge((string)$meta['label'], (string)$meta['tone']) ?>
                                        </div>
                                        <div class="md-install-check__value"><?= \MDJUI::e((string)($item['value'] ?? '')) ?></div>
                                        <?php if (!empty($item['hint'])): ?>
                                        <p class="md-install-check__hint"><?= \MDJUI::e((string)$item['hint']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </section>
                        <?php endforeach; ?>
                    </div>

                    <div class="md-install-note">
                        <?= \MDJUI::icon('info-circle') ?>
                        <span>Если база ещё не настроена — это нормально для первого шага. Подключение проверим и сохраним на следующем шаге.</span>
                    </div>
                </section>

                <!-- Шаг 2. База данных ---------------------------------------- -->
                <?php $databaseFields = $fieldsByStep[InstallSchema::STEP_DATABASE] ?? array(); ?>
                <section class="md-install-step<?= (int)$startStep === 1 ? ' is-active' : '' ?>" data-install-step="1" data-install-title="База данных">
                    <header class="md-install-step__head">
                        <div class="md-install-step__eyebrow">Шаг 02</div>
                        <h2>Подключение к базе данных</h2>
                        <p>MajorDoMo хранит объекты, историю и настройки в MySQL или MariaDB. Укажите доступы — мастер сразу проверит соединение и таблицы.</p>
                    </header>

                    <?php if (isset($errors['_database'])): ?>
                        <?= \MDJUI::alert('danger', 'База данных пока недоступна', (string)$errors['_database']) ?>
                    <?php endif; ?>

                    <div class="md-install-db-card<?= !empty($dbStatus['ok']) ? ' is-online' : '' ?>" data-install-db-card>
                        <span class="md-install-db-card__icon"><?= \MDJUI::icon(!empty($dbStatus['ok']) ? 'database-check' : 'database-off') ?></span>
                        <div class="md-install-db-card__body">
                            <div class="md-install-db-card__line">
                                <strong data-install-db-title><?= !empty($dbStatus['ok']) ? 'Соединение установлено' : 'Соединение ещё не проверено' ?></strong>
                                <span data-install-db-badge><?= \MDJUI::badge(!empty($dbStatus['ok']) ? 'онлайн' : 'ожидает', !empty($dbStatus['ok']) ? 'success' : 'neutral') ?></span>
                            </div>
                            <p data-install-db-message><?= \MDJUI::e((string)($dbStatus['message'] ?? 'Заполните поля и нажмите «Проверить подключение».')) ?></p>
                            <div class="md-install-db-card__meta" data-install-db-meta>
                                <?php if (!empty($dbStatus['server'])): ?><span>MySQL <?= \MDJUI::e((string)$dbStatus['server']) ?></span><?php endif; ?>
                                <?php if (!empty($dbStatus['tables'])): ?><span>Таблиц: <?= count($dbStatus['tables']) ?></span><?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="md-install-toolbar">
                        <?= \MDJUI::button('Проверить подключение', array(
                            'variant' => 'secondary',
                            'icon' => 'plug-connected',
                            'type' => 'button',
                            'attrs' => array('data-install-test-db' => true),
                        )) ?>
                        <span class="md-install-toolbar__hint">Проверка ничего не сохраняет.</span>
                    </div>

                    <?= $renderFields($databaseFields) ?>

                    <?php if (!empty($dbStatus['ok']) && !empty($dbStatus['empty'])): ?>
                        <?= \MDJUI::alert('info', 'База пустая', 'Оставьте «Создать таблицы автоматически» включённым: мастер импортирует чистую схему со структурой, но без демо-данных, старых свойств и пользователей. Администратор будет создан на шаге «Система».') ?>
                    <?php elseif (!empty($dbStatus['ok']) && !empty($dbStatus['missing'])): ?>
                        <?= \MDJUI::alert('warning', 'База не пустая, но схема неполная', 'Автоматический импорт доступен только для пустой базы, чтобы не затронуть чужие таблицы. Создайте отдельную пустую БД или импортируйте схему вручную.') ?>
                    <?php endif; ?>

                    <div class="md-install-note md-install-note--warning">
                        <?= \MDJUI::icon('info-circle') ?>
                        <span>Базу нужно создать заранее: мастер не создаёт и не удаляет саму базу. Автоматическое создание таблиц работает только для пустой базы; на существующей базе ничего не перезаписывается.</span>
                    </div>
                </section>

                <!-- Шаг 3. Система -------------------------------------------- -->
                <?php
                $systemFields = $fieldsByStep[InstallSchema::STEP_SYSTEM] ?? array();
                $systemSections = $fieldsBySection($systemFields);
                ?>
                <section class="md-install-step<?= (int)$startStep === 2 ? ' is-active' : '' ?>" data-install-step="2" data-install-title="Система">
                    <header class="md-install-step__head">
                        <div class="md-install-step__eyebrow">Шаг 03</div>
                        <h2>Как будет называться и выглядеть система</h2>
                        <p>Здесь настраиваются и <code>.env</code> (адрес, часовой пояс), и параметры из базы: имя системы, язык, тема. Всё можно поменять позже в настройках.</p>
                    </header>

                    <section class="md-install-section">
                        <header class="md-install-section__head">
                            <span class="md-install-section__icon"><?= \MDJUI::icon('home') ?></span>
                            <div>
                                <h3>Название и адрес</h3>
                                <p><?= \MDJUI::e((string)(InstallSchema::sections()['identity']['description'] ?? '')) ?></p>
                            </div>
                        </header>
                        <?= $renderFields($systemSections['identity'] ?? array()) ?>
                    </section>

                    <section class="md-install-section">
                        <header class="md-install-section__head">
                            <span class="md-install-section__icon"><?= \MDJUI::icon('palette') ?></span>
                            <div>
                                <h3>Интерфейс и поведение</h3>
                                <p><?= \MDJUI::e((string)(InstallSchema::sections()['behavior']['description'] ?? '')) ?></p>
                            </div>
                        </header>
                        <?= $renderFields($systemSections['behavior'] ?? array()) ?>
                    </section>

                    <?php if ($needsAdmin): ?>
                    <section class="md-install-section md-install-section--admin">
                        <header class="md-install-section__head">
                            <span class="md-install-section__icon"><?= \MDJUI::icon('user-shield') ?></span>
                            <div>
                                <h3>Администратор</h3>
                                <p>Создайте администратора — без логина и пароля в панель не войти.</p>
                            </div>
                        </header>
                        <?= \MDJUI::alert('warning', 'Нужен администратор', 'Логин и пароль будут сохранены в базе. Пароль хранится в виде хеша, поэтому запомните его или сохраните в менеджере паролей.') ?>
                        <?= $renderFields($systemSections['admin'] ?? array()) ?>
                    </section>
                    <?php elseif ($hasAdmin === true): ?>
                    <div class="md-install-note md-install-note--success">
                        <?= \MDJUI::icon('circle-check') ?>
                        <span>Администратор уже существует — создавать нового не нужно. Войти можно будет сразу после завершения.</span>
                    </div>
                    <?php else: ?>
                    <div class="md-install-note">
                        <?= \MDJUI::icon('info-circle') ?>
                        <span>Создание администратора станет доступно, когда база данных подключится. Если запись уже есть, мастер её не тронет.</span>
                    </div>
                    <?php endif; ?>
                </section>

                <!-- Шаг 4. Дополнительно -------------------------------------- -->
                <?php
                $advancedFields = $fieldsByStep[InstallSchema::STEP_ADVANCED] ?? array();
                $advancedSections = $fieldsBySection($advancedFields);
                $advancedOrder = array('performance', 'logging', 'redis', 'security', 'integrations');
                $sectionsMeta = InstallSchema::sections();
                ?>
                <section class="md-install-step<?= (int)$startStep === 3 ? ' is-active' : '' ?>" data-install-step="3" data-install-title="Дополнительно">
                    <header class="md-install-step__head">
                        <div class="md-install-step__eyebrow">Шаг 04</div>
                        <h2>Дополнительные возможности</h2>
                        <p>Этот шаг можно пропустить: значения по умолчанию безопасны. Откройте только те разделы, которые собираетесь использовать.</p>
                    </header>

                    <?= \MDJUI::alert('info', 'Необязательный шаг', 'Redis можно включить позже. Журналы и внешний доступ тоже настраиваются потом — мастер не требует заполнять всё сразу.') ?>

                    <div class="md-install-accordions">
                        <?php foreach ($advancedOrder as $sectionKey): ?>
                            <?php if (empty($advancedSections[$sectionKey])) continue; ?>
                            <?php $section = $sectionsMeta[$sectionKey] ?? array(); ?>
                            <details class="md-install-accordion"<?= $sectionKey === 'performance' ? ' open' : '' ?>>
                                <summary class="md-install-accordion__summary">
                                    <span class="md-install-accordion__icon"><?= \MDJUI::icon((string)($section['icon'] ?? 'adjustments')) ?></span>
                                    <span class="md-install-accordion__text">
                                        <strong><?= \MDJUI::e((string)($section['title'] ?? $sectionKey)) ?></strong>
                                        <small><?= \MDJUI::e((string)($section['description'] ?? '')) ?></small>
                                    </span>
                                    <span class="md-badge md-badge--outline">необязательно</span>
                                    <span class="md-install-accordion__chevron"><?= \MDJUI::icon('chevron-down') ?></span>
                                </summary>
                                <div class="md-install-accordion__body">
                                    <?= $renderFields($advancedSections[$sectionKey]) ?>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </section>

                <!-- Шаг 5. Проверка и завершение ------------------------------- -->
                <?php
                $reviewGroups = array(
                    'Основное' => array('PROJECT_TITLE', 'GENERAL_ALICE_NAME', 'BASE_URL', 'ROOTHTML', 'APP_ENV', 'TIMEZONE', 'SITE_TIMEZONE', 'SITE_LANGUAGE', 'THEME', 'DEBUG_MODE', 'GIT_URL', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER'),
                    'Дополнительно' => array(),
                );
                foreach ($advancedFields as $advancedField) {
                    $reviewGroups['Дополнительно'][] = (string)$advancedField['id'];
                }
                $allFields = InstallSchema::fields();
                ?>
                <section class="md-install-step<?= (int)$startStep === 4 ? ' is-active' : '' ?>" data-install-step="4" data-install-title="Готово">
                    <header class="md-install-step__head">
                        <div class="md-install-step__eyebrow">Шаг 05</div>
                        <h2>Проверьте и завершите</h2>
                        <p>Сейчас мастер запишет значения в <code>.env</code>, сохранит настройки интерфейса в базе и поставит маркер завершённой установки.</p>
                    </header>

                    <?php foreach ($reviewGroups as $groupTitle => $groupIds): ?>
                        <?php
                        $reviewRows = array();
                        foreach ($groupIds as $fieldId) {
                            if (!isset($allFields[$fieldId])) {
                                continue;
                            }
                            $field = $allFields[$fieldId];
                            if ($field['target'] === InstallSchema::TARGET_META) {
                                continue;
                            }
                            if (!$installer->fieldIncluded($field, $values)) {
                                continue;
                            }
                            $rawValue = (string)($values[$fieldId] ?? '');
                            $display = $displayField($field, $rawValue);
                            $secretSet = !empty($field['secret'])
                                && (!empty($secrets[$fieldId]) || $rawValue !== '');
                            // The review must stay readable: skip empty optional
                            // values, keep switches and configured secrets explicit.
                            if (($display === 'не задано' || $display === 'по умолчанию')
                                && empty($field['required'])
                                && ($field['type'] ?? '') !== 'switch'
                                && !$secretSet) {
                                continue;
                            }
                            $reviewRows[] = array('field' => $field, 'display' => $display);
                        }
                        if ($reviewRows === array()) {
                            continue;
                        }
                        ?>
                    <section class="md-install-review">
                        <header class="md-install-review__head">
                            <h3><?= \MDJUI::e($groupTitle) ?></h3>
                            <span class="md-badge md-badge--neutral"><?= count($reviewRows) ?> параметров</span>
                        </header>
                        <dl class="md-install-review__list">
                            <?php foreach ($reviewRows as $reviewRow): ?>
                            <div class="md-install-review__row">
                                <dt><?= \MDJUI::e((string)$reviewRow['field']['label']) ?></dt>
                                <dd><?= \MDJUI::e((string)$reviewRow['display']) ?></dd>
                            </div>
                            <?php endforeach; ?>
                        </dl>
                    </section>
                    <?php endforeach; ?>
                    <p class="md-install-review__hint">Остальные необязательные параметры останутся по умолчанию — их можно изменить позже в настройках.</p>

                    <section class="md-install-review">
                        <header class="md-install-review__head">
                            <h3>Возможности</h3>
                            <span class="md-badge md-badge--neutral">кратко</span>
                        </header>
                        <dl class="md-install-review__list">
                            <div class="md-install-review__row">
                                <dt>Redis</dt>
                                <dd><?= ($values['redis_enabled'] ?? '0') === '1' ? 'включён · ' . \MDJUI::e((string)($values['REDIS_HOST'] ?? '')) : 'выключен · SQL-кэш' ?></dd>
                            </div>
                            <div class="md-install-review__row">
                                <dt>WebSockets</dt>
                                <dd>встроены в ядро · /ws на порте сайта</dd>
                            </div>
                            <?php if ($needsAdmin): ?>
                            <div class="md-install-review__row">
                                <dt>Администратор</dt>
                                <dd>будет создан: <?= \MDJUI::e((string)($values['admin_login'] ?? '')) ?></dd>
                            </div>
                            <?php endif; ?>
                            <div class="md-install-review__row">
                                <dt>Резервная копия .env</dt>
                                <dd>создастся автоматически перед записью</dd>
                            </div>
                        </dl>
                    </section>

                    <?php if (!empty($values['DEBUG_MODE']) && $values['DEBUG_MODE'] === '1'): ?>
                    <div class="md-install-note md-install-note--warning">
                        <?= \MDJUI::icon('alert-triangle') ?>
                        <span>Режим отладки включён. Для постоянной работы его лучше выключить на шаге «Система».</span>
                    </div>
                    <?php endif; ?>

                    <div class="md-install-final">
                        <span class="md-install-final__icon"><?= \MDJUI::icon('shield-check') ?></span>
                        <div>
                            <strong>Готово к сохранению</strong>
                            <p>После нажатия кнопки система перестанет перенаправлять на мастер и откроет панель управления.</p>
                        </div>
                    </div>
                </section>

                <div class="md-install-actions">
                    <button type="button" class="md-btn md-btn--ghost md-install-actions__prev" data-install-prev>
                        <?= \MDJUI::icon('arrow-left') ?> Назад
                    </button>
                    <div class="md-install-actions__progress">
                        <span data-install-step-counter>Шаг <?= (int)$startStep + 1 ?> из <?= $stepCount ?></span>
                        <div class="md-install-progress" aria-hidden="true"><span data-install-progress style="width: <?= (int)round(((int)$startStep + 1) / $stepCount * 100) ?>%"></span></div>
                    </div>
                    <button type="button" class="md-btn md-btn--primary md-install-actions__next" data-install-next<?= $blocking > 0 ? ' disabled' : '' ?>>
                        Продолжить <?= \MDJUI::icon('arrow-right') ?>
                    </button>
                    <button type="submit" class="md-btn md-btn--primary md-btn--lg md-install-actions__finish" data-install-finish>
                        <?= \MDJUI::icon('check') ?> Завершить настройку
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </main>
    </div>
</div>

<script src="<?= \MDJUI::e($installJs) ?>"></script>
</body>
</html>
