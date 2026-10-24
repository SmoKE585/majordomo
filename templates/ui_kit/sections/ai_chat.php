<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/** Компоненты ответов ИИ: бейджи сущностей и схема вызова. */
$callFlowOn = isset($data['call_flow_on']) ? $data['call_flow_on'] : array();
$callFlowOff = isset($data['call_flow_off']) ? $data['call_flow_off'] : array();

$entityDemo = array(
    array('type' => 'object', 'name' => 'Light_Toilet'),
    array('type' => 'class', 'name' => 'Light'),
    array('type' => 'room', 'name' => 'Toilet'),
    array('type' => 'property', 'name' => 'Light_Toilet.status', 'label' => 'status'),
    array('type' => 'method', 'name' => 'Motion.data'),
    array('type' => 'script', 'name' => 'Свет по движению'),
    array('type' => 'module', 'name' => 'mqtt'),
    array('type' => 'topic', 'name' => 'Light/TualetON/IP'),
);

$entityText = <<<'TEXT'
[[object:Light_Toilet]] (класс [[class:Light]], комната [[room:Toilet]]) — свойства:
[[property:Light_Toilet.status|status]] (обработчик [[method:Light.statusChanges|statusChanges]],
привязки [[module:mqtt|mqtt]], [[module:yandexhome|yandexhome]]), [[property:Light_Toilet.state|state]],
[[property:Light_Toilet.ip|ip]] (ссылка на топик [[topic:Light/TualetON/IP]]),
[[property:Light_Toilet.last_turnon|last_turnon]] / [[property:Light_Toilet.last_turnoff|last_turnoff]].

Включение по движению: [[method:MS_Toilet.data]] (объектный метод, вызывает родительский
[[method:Motion.data]]) → если [[property:Toilet.turnOnWhenMotion]] == 1,
[[property:Security.status]] == 0 и пришло NEW_VALUE == 1, то при
[[property:Toilet.nightMode_status]] == 1 включается [[object:Light_ToiletNochnik]], иначе
[[code:sg('Light_Toilet.status', '1')]].

Выключение: [[property:Toilet.someBodyHere]] ставит таймер [[code:someBodyToilet]] на
[[property:Toilet.idle_delay]], а [[method:Toilet.onIdle]] делает
[[code:sg('Light_Toilet.status', '0')]].
TEXT;
?>
<section class="md-ui-section" id="ai_chat" data-md-doc-section>
    <div class="md-ui-section__intro">
        <?= MDJUI::sectionHeading(array(
            'eyebrow' => '11 · AI chat',
            'title' => 'ИИ-чат: сущности и схема вызова',
            'description' => 'Два компонента для ответов помощника: бейджи, которые отделяют объекты и методы от обычного текста, и наглядная схема вызова с ветками и временем. К самому чату пока не подключены — это контракт разметки и данных.',
            'level' => 2,
        )) ?>
        <span class="md-ui-section__kicker">md-entity · md-entity-text · md-call-flow</span>
    </div>

    <?php md_ui_demo(array(
        'title' => 'Бейджи сущностей',
        'desc' => 'Тип сущности задаёт иконку, цвет и подпись, а ссылка ведёт в нужный раздел админки. В плотном тексте компактный режим оставляет только имя, а легенда над абзацем объясняет цвета.',
        'api' => 'MDJUI::entity() · MDJUI::entityText()',
        'badge' => 'компонент',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::entity('Light_Toilet', 'object') ?>
<?= MDJUI::entity('Light_Toilet.status', 'property', ['label' => 'status']) ?>

<?= MDJUI::entityText(
    '[[object:Light_Toilet]] — свойство [[property:Light_Toilet.status|status]].',
    ['compact' => true, 'legend' => true]
) ?>
CODE,
    ), function () use ($entityDemo, $entityText) {
        ?>
        <div class="md-ui-demo__stack">
            <div>
                <div class="md-ui-demo__label">Все типы — полный бейдж</div>
                <div class="md-ui-demo__row">
                    <?php foreach ($entityDemo as $entity): ?>
                        <?= MDJUI::entity($entity['name'], $entity['type'], array(
                            'label' => isset($entity['label']) ? $entity['label'] : '',
                        )) ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <div class="md-ui-demo__label">Компактно — тип в подсказке</div>
                <div class="md-ui-demo__row">
                    <?php foreach ($entityDemo as $entity): ?>
                        <?= MDJUI::entity($entity['name'], $entity['type'], array(
                            'label' => isset($entity['label']) ? $entity['label'] : '',
                            'compact' => true,
                        )) ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <div class="md-ui-demo__label">Тот же текст в чате</div>
                <?= MDJUI::entityText($entityText, array('compact' => true, 'legend' => true)) ?>
            </div>
            <div class="md-ui-note">
                <?= MDJUI::icon('info-circle') ?>
                <span>Токены вида <code>[[property:Light_Toilet.status|status]]</code> не ломают историю и API: в базе остаётся обычный текст, а бейджи строятся при показе.</span>
            </div>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Схема вызова: ветки и время',
        'desc' => 'Дерево вызовов вместо портянки: видно, кто кого вызвал, какие условия проверялись, какая ветка выполнилась и сколько занял каждый шаг. Пропущенные ветки остаются в схеме пунктиром, чтобы решение было понятно.',
        'api' => 'MDJUI::callFlow()',
        'badge' => 'компонент',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::callFlow([
    'title' => 'Свет в туалете по движению',
    'status' => 'ok',
    'started_at' => '23:41:07.120',
    'duration' => '64 мс',
    'nodes' => [
        [
            'kind' => 'event',
            'entity' => ['type' => 'method', 'name' => 'MS_Toilet.data'],
            'time' => '23:41:07.120',
            'note' => 'Датчик движения · NEW_VALUE = 1',
            'children' => [
                [
                    'kind' => 'condition',
                    'title' => 'Можно включать свет',
                    'expression' => 'Toilet.turnOnWhenMotion == 1 && Security.status == 0',
                    'branches' => [
                        [
                            'label' => 'да',
                            'taken' => true,
                            'nodes' => [
                                [
                                    'kind' => 'action',
                                    'entity' => ['type' => 'code', 'name' => "sg('Light_Toilet.status', '1')"],
                                ],
                            ],
                        ],
                        ['label' => 'нет', 'taken' => false, 'empty_text' => 'Свет не включается'],
                    ],
                ],
            ],
        ],
    ],
]) ?>
CODE,
    ), function () use ($callFlowOn) {
        ?>
        <?= MDJUI::callFlow($callFlowOn) ?>
        <div class="md-ui-note">
            <?= MDJUI::icon('info-circle') ?>
            <span>Задержка между узлами показывается как <code>+60,005 с</code>, а у таймера — его длительность. Ветки «да/нет» описывают условие, а не отдельные вызовы.</span>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Компактная схема для чата',
        'desc' => 'Тот же компонент в плотном режиме: меньше отступы и точки, но сохраняются ветки, статусы и таймер. Подходит для drawer и узкой колонки чата.',
        'api' => 'MDJUI::callFlow([... , "compact" => true])',
        'badge' => 'компонент',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => '<?= MDJUI::callFlow($flow + ["compact" => true]) ?>',
    ), function () use ($callFlowOff) {
        ?>
        <div class="md-ui-demo__narrow">
            <?= MDJUI::callFlow(array_merge($callFlowOff, array('compact' => true))) ?>
        </div>
    <?php }); ?>
</section>
