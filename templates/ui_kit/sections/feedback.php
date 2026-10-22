<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/** Feedback, dialogs and notifications. */
?>
<section class="md-ui-section" id="feedback" data-md-doc-section>
    <div class="md-ui-section__intro">
        <?= MDJUI::sectionHeading(array(
            'eyebrow' => '07 · Feedback',
            'title' => 'Уведомления и диалоги',
            'description' => 'Alert — встроенное сообщение, toast — короткое подтверждение, modal — решение, drawer — контекстная панель. Не используйте alert() и не блокируйте страницу без причины.',
            'level' => 2,
        )) ?>
        <span class="md-ui-section__kicker">md-alert · md-toast · Bootstrap modal</span>
    </div>

    <?php md_ui_demo(array(
        'title' => 'Alert: info, success, warning, error',
        'desc' => 'Alert остаётся в потоке страницы и объясняет последствия. Dismissible нужен только для второстепенных сообщений.',
        'api' => 'MDJUI::alert()',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::alert('info', 'Синхронизация', 'Данные обновятся в фоне.') ?>
<?= MDJUI::alert('success', 'Сценарий сохранён', 'Изменения применены.') ?>
<?= MDJUI::alert('warning', 'Низкий заряд', 'Замените батарею в течение 3 дней.') ?>
<?= MDJUI::alert('danger', 'Нет связи', 'Устройство недоступно 18 минут.', [
  'actions' => MDJUI::button('Проверить', ['variant' => 'secondary', 'size' => 'sm']),
]) ?>
CODE,
    ), function () { ?>
        <div class="md-ui-alert-stack">
            <?= MDJUI::alert('info', 'Синхронизация', 'Данные обновятся в фоне, страницу перезагружать не нужно.') ?>
            <?= MDJUI::alert('success', 'Сценарий сохранён', 'Изменения применены и вступят в силу при следующем запуске.', array('dismissible' => true)) ?>
            <?= MDJUI::alert('warning', 'Низкий заряд батареи', 'Датчик в спальне проработает ещё около 3 дней.', array('actions' => MDJUI::button('Заменить батарею', array('variant' => 'secondary', 'size' => 'sm')))) ?>
            <?= MDJUI::alert('danger', 'Нет связи с устройством', 'Кабинет · CO₂ недоступен 2 часа. Проверьте питание и сеть.', array('actions' => MDJUI::button('Проверить соединение', array('variant' => 'secondary', 'size' => 'sm')))) ?>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Toast notifications',
        'desc' => 'Toast подтверждает действие и исчезает сам. Показывайте его после успешной операции; ошибку лучше оставить в alert рядом с формой.',
        'api' => 'MDJUIToast.show() · $store.toasts.push()',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<button class="md-btn md-btn--primary"
        @click="$store.toasts.push({
          type: 'success',
          title: 'Сохранено',
          text: 'Сценарий обновлён'
        })">
  Показать toast
</button>

<!-- Из JS -->
MDJUIToast.show({ type: 'success', title: 'Сохранено' });
CODE,
    ), function () { ?>
        <div class="md-ui-toast-buttons">
            <button type="button" class="md-btn md-btn--secondary" @click="$store.toasts.push({type: 'success', title: 'Сохранено', text: 'Настройки устройства обновлены'})">
                <?= MDJUI::icon('check-circle') ?><span class="md-btn__label">Success</span>
            </button>
            <button type="button" class="md-btn md-btn--secondary" @click="$store.toasts.push({type: 'info', title: 'Синхронизация', text: 'Данные обновятся в фоне'})">
                <?= MDJUI::icon('info-circle') ?><span class="md-btn__label">Info</span>
            </button>
            <button type="button" class="md-btn md-btn--secondary" @click="$store.toasts.push({type: 'warning', title: 'Низкий заряд', text: 'Датчик в спальне: 12%'})">
                <?= MDJUI::icon('exclamation-triangle') ?><span class="md-btn__label">Warning</span>
            </button>
            <button type="button" class="md-btn md-btn--secondary" @click="$store.toasts.push({type: 'danger', title: 'Ошибка', text: 'Не удалось отправить команду'})">
                <?= MDJUI::icon('x-octagon') ?><span class="md-btn__label">Danger</span>
            </button>
        </div>
        <div class="md-ui-note md-ui-note--spaced">
            <?= MDJUI::icon('info-circle') ?>
            <span>Toast появляется справа вверху под шапкой и не перекрывает навигацию. Одновременно показывайте не больше трёх.</span>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Modal dialog и confirmation dialog',
        'desc' => 'Modal — для формы или выбора. Confirmation — короткий вопрос с явными последствиями; destructive-кнопка справа и danger.',
        'api' => 'Bootstrap modal · md-modal',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<button class="md-btn md-btn--primary" data-bs-toggle="modal" data-bs-target="#deviceModal">
  Открыть modal
</button>

<div class="modal fade md-modal" id="deviceModal" tabindex="-1" aria-labelledby="deviceModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title" id="deviceModalTitle">Новое устройство</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">...</div>
      <div class="modal-footer">...</div>
    </div>
  </div>
</div>
CODE,
    ), function () { ?>
        <div class="md-ui-dialog-buttons">
            <button type="button" class="md-btn md-btn--primary" data-bs-toggle="modal" data-bs-target="#uiKitDeviceModal">
                <?= MDJUI::icon('plus-lg') ?><span class="md-btn__label">Открыть modal</span>
            </button>
            <button type="button" class="md-btn md-btn--danger" data-bs-toggle="modal" data-bs-target="#uiKitConfirmModal">
                <?= MDJUI::icon('trash3') ?><span class="md-btn__label">Удалить устройство</span>
            </button>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Drawer: overlay shell',
        'desc' => 'Компонент MDJUI::drawer — тот же shell, что у консоли и глобального drawer host: inset-панель со скруглением, компактная шапка с eyebrow, заголовком и подзаголовком, прокручиваемое тело и футер с действиями. На мобильном занимает весь экран.',
        'api' => 'MDJUI::drawer() · md-drawer__header · md-drawer__body · md-drawer__footer',
        'badge' => 'предпочтительно',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<div x-data="{ drawerOpen: false }" @keydown.escape.window="drawerOpen = false">
  <button class="md-btn md-btn--secondary" @click="drawerOpen = true">Открыть drawer</button>

  <?= MDJUI::drawer([
      'eyebrow' => 'Устройство',
      'title' => 'Кухня · Основной свет',
      'subtitle' => 'Zigbee · 92% сигнала',
      'width' => '440px',
      'attrs' => ['x-bind:class' => '{"is-open": drawerOpen}'],
      'backdrop_attrs' => [
          'x-bind:class' => '{"is-open": drawerOpen}',
          '@click' => 'drawerOpen = false',
      ],
      'close_attrs' => ['@click' => 'drawerOpen = false'],
      'footer' => $footer,
  ], $body) ?>
</div>
CODE,
    ), function () {
        $drawerBody = '<div class="md-ui-form-stack">'
            . MDJUI::input(array('label' => 'Название', 'name' => 'drawer_name', 'value' => 'Кухня · Основной свет'))
            . MDJUI::select(array('label' => 'Помещение', 'name' => 'drawer_room', 'options' => array('kitchen' => 'Кухня', 'living' => 'Гостиная'), 'selected' => 'kitchen'))
            . MDJUI::range(array('label' => 'Яркость', 'name' => 'drawer_brightness', 'value' => 72, 'unit' => '%'))
            . MDJUI::switchControl(array('name' => 'drawer_auto', 'label' => 'Автоматизация', 'description' => 'Реагировать на движение', 'checked' => true))
            . '</div>';
        $drawerFooter = MDJUI::button('Отмена', array('variant' => 'secondary', 'attrs' => array('@click' => 'drawerOpen = false')))
            . MDJUI::button('Сохранить', array('variant' => 'primary', 'attrs' => array('@click' => 'drawerOpen = false')));
        ?>
        <div x-data="{ drawerOpen: false }" @keydown.escape.window="drawerOpen = false">
            <button type="button" class="md-btn md-btn--secondary" @click="drawerOpen = true">
                <?= MDJUI::icon('layout-sidebar-right') ?><span class="md-btn__label">Открыть drawer</span>
            </button>
            <?= MDJUI::drawer(array(
                'id' => 'uiKitShellDrawer',
                'eyebrow' => 'Устройство',
                'title' => 'Кухня · Основной свет',
                'subtitle' => 'Zigbee · 92% сигнала · обновлено 12 секунд назад',
                'width' => '440px',
                'footer' => $drawerFooter,
                'attrs' => array('x-bind:class' => '{"is-open": drawerOpen}'),
                'backdrop_attrs' => array(
                    'x-bind:class' => '{"is-open": drawerOpen}',
                    '@click' => 'drawerOpen = false',
                ),
                'close_attrs' => array('@click' => 'drawerOpen = false'),
            ), $drawerBody) ?>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'History drawer: история значения',
        'desc' => 'Готовый сценарий для истории свойства: период, текущее значение, статистика, график и журнал изменений в общем drawer shell. Компонент сам не загружает данные — источник задаётся атрибутами триггера, поэтому его можно подключить к любому модулю.',
        'api' => 'MDJUI::historyDrawer() · [data-md-history-open] · MDJCharts',
        'badge' => 'компонент',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<button type="button"
        class="md-btn md-btn--secondary"
        data-md-history-open
        data-md-history-target="historyDrawer"
        data-history-object-id="12"
        data-history-property-id="34"
        data-history-title="Kitchen.Temperature"
        data-history-subtitle="Температура воздуха на кухне">
  Открыть историю
</button>

<?= MDJUI::historyDrawer([
    'id' => 'historyDrawer',
    'eyebrow' => 'История свойства',
    'default_range' => '24h',
]) ?>
CODE,
    ), function () {
        $historyDemo = array(
            'status' => 'ok',
            'meta' => array(
                'object_title' => 'Kitchen',
                'property_name' => 'Kitchen.Temperature',
                'description' => 'Температура воздуха на кухне',
                'keep_history' => 30,
                'is_numeric' => true,
            ),
            'range' => array('key' => '24h', 'label' => '24 часа'),
            'current' => array(
                'value' => '23.4',
                'updated_label' => '21.10.2026 12:30:00',
                'source' => 'sensor_kitchen',
            ),
            'stats' => array('changes' => 18, 'min' => 20.1, 'avg' => 22.7, 'max' => 25.8),
            'chart' => array(
                'has_data' => true,
                'is_numeric' => true,
                'min' => 20.1,
                'max' => 25.8,
                'first_label' => '21.10 00:00',
                'last_label' => '21.10 12:00',
                'points' => array(
                    array('timestamp' => strtotime('2026-10-21 00:00:00'), 'value' => 20.1, 'label' => '21.10 00:00'),
                    array('timestamp' => strtotime('2026-10-21 02:00:00'), 'value' => 20.8, 'label' => '21.10 02:00'),
                    array('timestamp' => strtotime('2026-10-21 04:00:00'), 'value' => 21.4, 'label' => '21.10 04:00'),
                    array('timestamp' => strtotime('2026-10-21 06:00:00'), 'value' => 22.9, 'label' => '21.10 06:00'),
                    array('timestamp' => strtotime('2026-10-21 08:00:00'), 'value' => 24.6, 'label' => '21.10 08:00'),
                    array('timestamp' => strtotime('2026-10-21 10:00:00'), 'value' => 25.8, 'label' => '21.10 10:00'),
                    array('timestamp' => strtotime('2026-10-21 12:00:00'), 'value' => 23.4, 'label' => '21.10 12:00'),
                ),
            ),
            'history' => array(
                array('added_label' => '21.10.2026 12:30:00', 'value' => '23.4', 'source' => 'sensor_kitchen'),
                array('added_label' => '21.10.2026 10:00:00', 'value' => '25.8', 'source' => 'sensor_kitchen'),
                array('added_label' => '21.10.2026 08:00:00', 'value' => '24.6', 'source' => 'sensor_kitchen'),
                array('added_label' => '21.10.2026 06:00:00', 'value' => '22.9', 'source' => 'scenario_morning'),
            ),
            'history_pagination' => array(
                'page' => 1, 'per_page' => 50, 'total' => 4, 'pages' => 1,
                'from' => 1, 'to' => 4, 'has_prev' => false, 'has_next' => false,
            ),
        );
        ?>
        <div class="md-ui-dialog-buttons">
            <button type="button"
                    class="md-btn md-btn--secondary"
                    data-md-history-open
                    data-md-history-target="uiKitHistoryDrawer"
                    data-history-title="Kitchen.Temperature"
                    data-history-subtitle="Температура воздуха на кухне"
                    data-history-eyebrow="История свойства"
                    aria-haspopup="dialog">
                <?= MDJUI::icon('history') ?><span class="md-btn__label">Открыть историю</span>
            </button>
        </div>
        <?= MDJUI::historyDrawer(array(
            'id' => 'uiKitHistoryDrawer',
            'eyebrow' => 'История свойства',
            'title' => 'Kitchen.Temperature',
            'subtitle' => 'Демо-данные: график и журнал изменений.',
            'default_range' => '24h',
            'demo_data' => $historyDemo,
        )) ?>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Trace drawer: цепочка изменений свойства',
        'desc' => 'Always-on журнал: запуск, методы и сценарии, параметры на входе и выходе, старое и новое значение, ссылки на метод/свойство/сценарий. Компонент использует общий drawer shell и не загружает данные без триггера.',
        'api' => 'MDJUI::traceDrawer() · [data-md-trace-open]',
        'badge' => 'компонент',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<button type="button"
        class="md-btn md-btn--secondary"
        data-md-trace-open
        data-md-trace-target="traceDrawer"
        data-trace-object-id="12"
        data-trace-property-id="34"
        data-trace-title="Kitchen.Temperature">
  Цепочка
</button>

<?= MDJUI::traceDrawer([
    'id' => 'traceDrawer',
    'eyebrow' => 'Цепочка изменений',
    'default_range' => '24h',
]) ?>
CODE,
    ), function () {
        $traceDemo = array(
            'status' => 'ok',
            'meta' => array(
                'object_id' => 12,
                'property_id' => 34,
                'object_title' => 'Kitchen',
                'property_title' => 'Temperature',
                'property_name' => 'Kitchen.Temperature',
                'description' => 'Температура воздуха на кухне',
                'retention_days' => 30,
            ),
            'range' => array('key' => '24h', 'label' => '24 часа'),
            'runs' => array(
                array(
                    'id' => 128,
                    'trace_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
                    'started_label' => '22.10.2026 07:00:02',
                    'finished_label' => '22.10.2026 07:00:02',
                    'duration_seconds' => 1,
                    'status' => 'done',
                    'status_label' => 'Завершён',
                    'trigger_type' => 'job',
                    'trigger_label' => 'Задача',
                    'trigger_title' => 'Утренний сценарий',
                    'source' => 'Job: Утренний сценарий',
                    'actor' => 'CLI: www-data',
                    'request_uri' => '',
                    'params' => array('mode' => 'morning'),
                    'result' => array('ok' => true),
                    'error' => '',
                    'events' => array(
                        array(
                            'id' => 1, 'type' => 'method', 'type_label' => 'Метод', 'status' => 'done',
                            'title' => 'Kitchen.onMorning', 'time_label' => '07:00:02', 'duration_ms' => 24,
                            'method_id' => 7, 'object_id' => 12,
                            'params' => array('mode' => 'morning'), 'out_params' => array('mode' => 'morning', 'ready' => true),
                            'result' => true, 'links' => array(array('label' => 'Метод', 'icon' => 'braces', 'url' => '#')),
                        ),
                        array(
                            'id' => 2, 'type' => 'script', 'type_label' => 'Сценарий', 'status' => 'done',
                            'title' => 'Morning lights', 'time_label' => '07:00:02', 'duration_ms' => 12,
                            'script_id' => 4, 'params' => array('brightness' => 40), 'result' => true,
                            'links' => array(array('label' => 'Сценарий', 'icon' => 'file-code', 'url' => '#')),
                        ),
                        array(
                            'id' => 3, 'type' => 'property', 'type_label' => 'Свойство', 'status' => 'done',
                            'title' => 'Kitchen.Temperature', 'time_label' => '07:00:02',
                            'property_id' => 34, 'object_id' => 12, 'old_value' => '21.4', 'new_value' => '23.0',
                            'is_target' => true, 'chain' => array('property:Kitchen.Temperature'),
                            'links' => array(array('label' => 'Свойство', 'icon' => 'adjustments-horizontal', 'url' => '#')),
                        ),
                    ),
                ),
            ),
            'pagination' => array('page' => 1, 'per_page' => 10, 'total' => 1, 'pages' => 1,
                'from' => 1, 'to' => 1, 'has_prev' => false, 'has_next' => false),
        );
        ?>
        <div class="md-ui-dialog-buttons">
            <button type="button"
                    class="md-btn md-btn--secondary"
                    data-md-trace-open
                    data-md-trace-target="uiKitTraceDrawer"
                    data-trace-title="Kitchen.Temperature"
                    data-trace-subtitle="Цепочка запуска «Утренний сценарий»"
                    data-trace-eyebrow="Цепочка изменений"
                    aria-haspopup="dialog">
                <?= MDJUI::icon('route') ?><span class="md-btn__label">Открыть цепочку</span>
            </button>
        </div>
        <?= MDJUI::traceDrawer(array(
            'id' => 'uiKitTraceDrawer',
            'eyebrow' => 'Цепочка изменений',
            'title' => 'Kitchen.Temperature',
            'subtitle' => 'Демо-данные: запуск, метод, сценарий и изменение свойства.',
            'default_range' => '24h',
            'demo_data' => $traceDemo,
        )) ?>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Popover, tooltip и inline validation',
        'desc' => 'Popover — для пояснения с действием, tooltip — для короткой подсказки. Ошибка поля показывается под контролом и не дублируется только toast.',
        'api' => 'Bootstrap popover/tooltip · md-field__error',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<button class="md-btn md-btn--secondary"
        data-bs-toggle="popover"
        data-bs-title="Что такое уставка?"
        data-bs-content="Целевая температура...">
  Подсказка
</button>

<?= MDJUI::input([
  'label' => 'E-mail',
  'value' => 'bad@',
  'error' => 'Проверьте формат e-mail',
]) ?>
CODE,
    ), function () { ?>
        <div class="md-ui-feedback-row">
            <button type="button" class="md-btn md-btn--secondary" data-bs-toggle="popover" data-bs-title="Что такое уставка?" data-bs-content="Целевая температура, к которой термостат ведёт помещение. Диапазон — 16–28 °C.">
                <?= MDJUI::icon('question-circle') ?><span class="md-btn__label">Popover</span>
            </button>
            <button type="button" class="md-btn md-btn--secondary" data-bs-toggle="tooltip" data-bs-title="Перезапустить сценарий">
                <?= MDJUI::icon('arrow-repeat') ?><span class="md-btn__label">Tooltip</span>
            </button>
            <span class="md-ui-demo__divider" aria-hidden="true"></span>
            <?= MDJUI::input(array('label' => 'E-mail', 'name' => 'feedback_email', 'value' => 'bad@', 'error' => 'Проверьте формат e-mail', 'class' => 'md-ui-inline-field')) ?>
        </div>
        <div class="md-ui-note md-ui-note--warning md-ui-note--spaced">
            <?= MDJUI::icon('exclamation-triangle') ?>
            <span>Inline-валидация запускается после попытки отправки и обновляется при вводе. Полный пример — в разделе «Формы».</span>
        </div>
    <?php }); ?>

    <!-- Modal -->
    <div class="modal fade md-modal" id="uiKitDeviceModal" tabindex="-1" aria-labelledby="uiKitDeviceModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title" id="uiKitDeviceModalTitle">Новое устройство</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                </div>
                <div class="modal-body">
                    <div class="md-ui-form-grid">
                        <?= MDJUI::input(array('label' => 'Название', 'name' => 'modal_title', 'placeholder' => 'Кухня · Свет', 'required' => true)) ?>
                        <?= MDJUI::select(array('label' => 'Помещение', 'name' => 'modal_room', 'options' => array('kitchen' => 'Кухня', 'living' => 'Гостиная'), 'placeholder' => 'Выберите помещение')) ?>
                    </div>
                    <div class="md-ui-note md-ui-note--spaced">
                        <?= MDJUI::icon('info-circle') ?>
                        <span>После создания устройство появится в таблице и в сценариях.</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <?= MDJUI::button('Отмена', array('variant' => 'secondary', 'attrs' => array('data-bs-dismiss' => 'modal'))) ?>
                    <?= MDJUI::button('Создать устройство', array('variant' => 'primary', 'icon' => 'plus-lg', 'attrs' => array('data-bs-dismiss' => 'modal'))) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirmation -->
    <div class="modal fade md-modal" id="uiKitConfirmModal" tabindex="-1" aria-labelledby="uiKitConfirmModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title" id="uiKitConfirmModalTitle">Удалить устройство?</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                </div>
                <div class="modal-body">
                    <p>Устройство «Кухня · Основной свет» будет отвязано от сценариев. Действие нельзя отменить.</p>
                </div>
                <div class="modal-footer">
                    <?= MDJUI::button('Отмена', array('variant' => 'secondary', 'attrs' => array('data-bs-dismiss' => 'modal'))) ?>
                    <button type="button"
                            class="md-btn md-btn--danger"
                            data-bs-dismiss="modal"
                            @click="$store.toasts.push({type: 'danger', title: 'Устройство удалено', text: 'Кухня · Основной свет'})">
                        <?= MDJUI::icon('trash3') ?><span class="md-btn__label">Удалить</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</section>


