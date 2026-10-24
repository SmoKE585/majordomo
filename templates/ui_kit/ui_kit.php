<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * UI Kit — design system reference.
 *
 * This page is documentation and a living demo at the same time. Every
 * interactive pattern here is produced by the same components and tokens that
 * new admin pages should use.
 */

$devices = isset($data['devices']) ? $data['devices'] : array();
$scenes = isset($data['scenes']) ? $data['scenes'] : array();
$tableRows = isset($data['table_rows']) ? $data['table_rows'] : array();
$rooms = isset($data['rooms']) ? $data['rooms'] : array();
$paletteItems = isset($data['palette_items']) ? $data['palette_items'] : array();
$automations = isset($data['automations']) ? $data['automations'] : array();
$activity = isset($data['activity']) ? $data['activity'] : array();
$energy = isset($data['energy']) ? $data['energy'] : array();
$sensors = isset($data['sensors']) ? $data['sensors'] : array();
$connections = isset($data['connections']) ? $data['connections'] : array();
$icons = isset($data['icons']) ? $data['icons'] : array();

$paletteJson = json_encode($paletteItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

/**
 * Renders one documentation demo block.
 *
 * @param array    $opts title, desc, api, code, canvas_class, id
 * @param callable $body Canvas body renderer
 */
function md_ui_demo(array $opts, callable $body)
{
    $title = isset($opts['title']) ? $opts['title'] : '';
    $desc = isset($opts['desc']) ? $opts['desc'] : '';
    $api = isset($opts['api']) ? $opts['api'] : '';
    $code = isset($opts['code']) ? $opts['code'] : '';
    $canvasClass = isset($opts['canvas_class']) ? $opts['canvas_class'] : '';
    $badge = isset($opts['badge']) ? $opts['badge'] : '';
    $id = isset($opts['id']) ? $opts['id'] : '';

    echo '<article class="md-ui-demo"' . ($id !== '' ? ' id="' . MDJUI::e($id) . '"' : '') . '>';
    echo '<div class="md-ui-demo__head"><div>';
    echo '<div class="md-ui-demo__title">' . MDJUI::e($title);
    if ($badge !== '') {
        echo ' <span class="md-badge md-badge--success">' . MDJUI::e($badge) . '</span>';
    }
    echo '</div>';
    if ($desc !== '') {
        echo '<p class="md-ui-demo__desc">' . MDJUI::e($desc) . '</p>';
    }
    echo '</div>';
    if ($api !== '') {
        echo '<span class="md-ui-demo__api">' . MDJUI::e($api) . '</span>';
    }
    echo '</div>';
    echo '<div class="md-ui-demo__canvas ' . MDJUI::e($canvasClass) . '">';
    $body();
    echo '</div>';
    if ($code !== '') {
        // Escape literal jTemplate tags so the admin shell's second parse pass
        // does not execute examples such as [#module name="linkedobject"#].
        $code = str_replace(array('[#', '#]', '{#', '#}'), array('&#091;#', '#&#093;', '&#123;#', '#&#125;'), MDJUI::e(trim($code)));
        echo '<details class="md-ui-demo__code"><summary>' . MDJUI::icon('code-slash') . 'Пример использования</summary>';
        echo '<pre><code>' . $code . '</code></pre></details>';
    }
    echo '</article>';
}

function md_ui_label($text)
{
    echo '<div class="md-ui-demo__label">' . MDJUI::e($text) . '</div>';
}
?>
<div class="md-ui-page" data-md-ui-kit x-data="{}">
    <header class="md-ui-hero">
        <div>
            <div class="md-ui-hero__eyebrow"><?= MDJUI::icon('palette') ?>Design system · v1.0</div>
            <h1 class="md-ui-hero__title">UI Kit MajorDoMo</h1>
            <p class="md-ui-hero__lede">
                Единая библиотека компонентов админки умного дома. Токены, состояния, тёмная тема и
                готовые паттерны для страниц устройств, сценариев и данных.
            </p>
        </div>
        <div class="md-ui-hero__actions">
            <div class="md-ui-theme" role="group" aria-label="Тема оформления">
                <input class="btn-check" type="radio" name="uiKitTheme" id="uiKitThemeLight" value="light" data-md-theme-choice="light">
                <label for="uiKitThemeLight" title="Светлая тема"><?= MDJUI::icon('sun') ?><span>Светлая</span></label>
                <input class="btn-check" type="radio" name="uiKitTheme" id="uiKitThemeDark" value="dark" data-md-theme-choice="dark">
                <label for="uiKitThemeDark" title="Тёмная тема"><?= MDJUI::icon('moon-stars') ?><span>Тёмная</span></label>
            </div>
            <a class="md-btn md-btn--secondary" href="docs/agents/DESIGN_SYSTEM.md" target="_blank" rel="noopener">
                <?= MDJUI::icon('file-text') ?><span class="md-btn__label">Документация</span>
            </a>
            <button type="button" class="md-btn md-btn--primary" data-bs-toggle="modal" data-bs-target="#mdCommandPalette">
                <?= MDJUI::icon('command') ?><span class="md-btn__label">Командная палитра</span>
            </button>
        </div>
    </header>

    <div class="md-ui-docs">
        <nav class="md-ui-docs__nav" aria-label="Разделы UI Kit">
            <div class="md-ui-docs__nav-title">Разделы</div>
            <a class="md-ui-docs__nav-link is-active" href="#foundations" data-md-doc-link><?= MDJUI::icon('grid-1x2') ?>Основы</a>
            <a class="md-ui-docs__nav-link" href="#buttons" data-md-doc-link><?= MDJUI::icon('hand-index-thumb') ?>Кнопки</a>
            <a class="md-ui-docs__nav-link" href="#forms" data-md-doc-link><?= MDJUI::icon('input-cursor-text') ?>Формы</a>
            <a class="md-ui-docs__nav-link" href="#linkedobject" data-md-doc-link><?= MDJUI::icon('link') ?>Связи объектов</a>
            <a class="md-ui-docs__nav-link" href="#navigation" data-md-doc-link><?= MDJUI::icon('signpost-split') ?>Навигация</a>
            <a class="md-ui-docs__nav-link" href="#cards" data-md-doc-link><?= MDJUI::icon('layout-text-window') ?>Карточки</a>
            <a class="md-ui-docs__nav-link" href="#data" data-md-doc-link><?= MDJUI::icon('table') ?>Таблицы и данные</a>
            <a class="md-ui-docs__nav-link" href="#feedback" data-md-doc-link><?= MDJUI::icon('chat-square-text') ?>Уведомления</a>
            <a class="md-ui-docs__nav-link" href="#blocks" data-md-doc-link><?= MDJUI::icon('code') ?>Лента и код</a>
            <a class="md-ui-docs__nav-link" href="#smarthome" data-md-doc-link><?= MDJUI::icon('house-gear') ?>Умный дом</a>
            <a class="md-ui-docs__nav-link" href="#patterns" data-md-doc-link><?= MDJUI::icon('rulers') ?>Паттерны</a>
            <a class="md-ui-docs__nav-link" href="#ai_chat" data-md-doc-link><?= MDJUI::icon('robot') ?>ИИ-чат</a>
        </nav>

        <main class="md-ui-docs__main">
            <?php include __DIR__ . '/sections/foundations.php'; ?>
            <?php include __DIR__ . '/sections/buttons.php'; ?>
            <?php include __DIR__ . '/sections/forms.php'; ?>
            <?php include __DIR__ . '/sections/linkedobject.php'; ?>
            <?php include __DIR__ . '/sections/navigation.php'; ?>
            <?php include __DIR__ . '/sections/cards.php'; ?>
            <?php include __DIR__ . '/sections/data.php'; ?>
            <?php include __DIR__ . '/sections/feedback.php'; ?>
            <?php include __DIR__ . '/sections/blocks.php'; ?>
            <?php include __DIR__ . '/sections/smarthome.php'; ?>
            <?php include __DIR__ . '/sections/patterns.php'; ?>
            <?php include __DIR__ . '/sections/ai_chat.php'; ?>
        </main>
    </div>

    <footer class="md-ui-page-footer">
        <span>MajorDoMo Design System · UI Kit v1.0 · <?= date('d.m.Y') ?></span>
        <span>
            <a href="docs/agents/DESIGN_SYSTEM.md" target="_blank" rel="noopener">docs/agents/DESIGN_SYSTEM.md</a>
            <span aria-hidden="true"> · </span>
            <a href="docs/agents/AGENTS.md" target="_blank" rel="noopener">docs/agents/AGENTS.md</a>
        </span>
    </footer>

    <!-- Command palette -->
    <div class="modal fade md-modal md-command" id="mdCommandPalette" tabindex="-1" aria-labelledby="mdCommandPaletteTitle" aria-hidden="true" data-md-palette="<?= htmlspecialchars($paletteJson, ENT_QUOTES, 'UTF-8') ?>" x-data="mdCommandPalette()">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <h2 class="md-visually-hidden" id="mdCommandPaletteTitle">Командная палитра</h2>
                <div class="md-command__search">
                    <?= MDJUI::icon('search') ?>
                    <input type="search"
                           data-md-command-input
                           x-model="query"
                           @input="activeIndex = 0"
                           @keydown.arrow-down.prevent="move(1)"
                           @keydown.arrow-up.prevent="move(-1)"
                           @keydown.enter.prevent="runActive()"
                           placeholder="Поиск команд, разделов и действий"
                           aria-label="Поиск команд"
                           autocomplete="off">
                    <kbd>Esc</kbd>
                </div>
                <div class="md-command__list" role="listbox" aria-label="Команды">
                    <template x-for="group in groups" :key="group.name">
                        <div class="md-command__group">
                            <div class="md-command__label" x-text="group.name"></div>
                            <template x-for="item in group.items" :key="item.index">
                                <button type="button"
                                        class="md-command__item"
                                        :class="{ 'is-active': item.index === activeIndex }"
                                        @click="run(item)"
                                        @mouseenter="setActive(item.index)"
                                        role="option"
                                        :aria-selected="item.index === activeIndex">
                                    <i :class="'ti ti-' + item.icon" aria-hidden="true"></i>
                                    <span class="md-command__text">
                                        <span x-text="item.label"></span>
                                        <small x-text="item.hint"></small>
                                    </span>
                                    <kbd x-show="item.kbd" x-text="item.kbd"></kbd>
                                </button>
                            </template>
                        </div>
                    </template>
                    <div class="md-command__empty" x-show="flatItems.length === 0">Ничего не найдено</div>
                </div>
                <div class="md-command__footer">
                    <span><kbd>↑</kbd><kbd>↓</kbd> навигация · <kbd>↵</kbd> выбрать</span>
                    <span>Ctrl K — глобальный поиск</span>
                </div>
            </div>
        </div>
    </div>

</div>
<script src="<?= ROOTHTML ?>assets/js/admin/ui_kit.js?v=2026-10-21-2"></script>


