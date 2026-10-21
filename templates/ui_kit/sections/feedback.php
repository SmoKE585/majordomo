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
            <span>Toast появляется в правом нижнем углу и не перекрывает навигацию. Одновременно показывайте не больше трёх.</span>
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


