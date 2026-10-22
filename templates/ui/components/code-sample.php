<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Read-only code sample with syntax highlighting and copy.
 *
 * Props:
 * - language + code — single-language sample;
 * - tabs — alternative: list of ['language' => 'php', 'label' => 'PHP',
 *   'code' => '...']. The first tab is active;
 * - title, eyebrow, caption, footer;
 * - copy (bool, default true), line_numbers (bool, default true),
 *   max_height (CSS value), show_language (bool), wrap (bool);
 * - class, attrs, id.
 *
 * Highlighting is a separate lazy chunk (assets/admin/codehighlight.js); the
 * code stays readable without JavaScript and the copy buttons work from the
 * shared shell bundle.
 */
$props += array(
    'language' => 'php',
    'code' => '',
    'tabs' => array(),
    'title' => '',
    'eyebrow' => '',
    'caption' => '',
    'footer' => '',
    'copy' => true,
    'line_numbers' => true,
    'max_height' => '',
    'show_language' => true,
    'wrap' => false,
    'class' => '',
    'attrs' => array(),
    'id' => '',
);

$languageLabels = array(
    'php' => 'PHP',
    'python' => 'Python',
    'py' => 'Python',
    'javascript' => 'JavaScript',
    'js' => 'JavaScript',
    'typescript' => 'TypeScript',
    'ts' => 'TypeScript',
    'html' => 'HTML',
    'xml' => 'XML',
    'css' => 'CSS',
    'scss' => 'SCSS',
    'bash' => 'Bash',
    'sh' => 'Bash',
    'shell' => 'Bash',
    'json' => 'JSON',
    'sql' => 'SQL',
    'yaml' => 'YAML',
    'yml' => 'YAML',
    'ini' => 'INI',
    'text' => 'Текст',
    'plaintext' => 'Текст',
);

$normalizeLanguage = function ($language) {
    $language = strtolower(trim((string)$language));
    $map = array(
        'py' => 'python',
        'js' => 'javascript',
        'ts' => 'typescript',
        'sh' => 'bash',
        'shell' => 'bash',
        'html' => 'xml',
        'htm' => 'xml',
        'yml' => 'yaml',
        'c#' => 'csharp',
        'c++' => 'cpp',
    );
    return isset($map[$language]) ? $map[$language] : ($language !== '' ? $language : 'plaintext');
};

$tabs = array();
if (!empty($props['tabs']) && is_array($props['tabs'])) {
    foreach ($props['tabs'] as $tab) {
        if (!is_array($tab)) {
            continue;
        }
        $code = isset($tab['code']) ? (string)$tab['code'] : '';
        if ($code === '') {
            continue;
        }
        $language = $normalizeLanguage(isset($tab['language']) ? $tab['language'] : $props['language']);
        $tabs[] = array(
            'language' => $language,
            'label' => isset($tab['label']) && $tab['label'] !== ''
                ? (string)$tab['label']
                : (isset($languageLabels[$language]) ? $languageLabels[$language] : strtoupper($language)),
            'code' => str_replace(array("\r\n", "\r"), "\n", $code),
        );
    }
} elseif ((string)$props['code'] !== '') {
    $language = $normalizeLanguage($props['language']);
    $tabs[] = array(
        'language' => $language,
        'label' => isset($languageLabels[$language]) ? $languageLabels[$language] : strtoupper($language),
        'code' => str_replace(array("\r\n", "\r"), "\n", (string)$props['code']),
    );
}

$baseId = 'mdCodeSample' . substr(md5(uniqid('', true)), 0, 8);
if ($props['id'] !== '') {
    $baseId = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$props['id']);
    if ($baseId === '') {
        $baseId = 'mdCodeSample';
    }
}

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array(
    'md-code-sample',
    count($tabs) > 1 ? 'md-code-sample--tabs' : 'md-code-sample--single',
    $props['wrap'] ? 'md-code-sample--wrap' : '',
    $props['class'],
));
$attrs['data-md-code-sample'] = '1';
if ($props['id'] !== '') {
    $attrs['id'] = $props['id'];
}
if ($props['max_height'] !== '') {
    $attrs['style'] = '--md-code-sample-max-height:' . $props['max_height'] . ';';
}

$hasFooter = $props['caption'] !== '' || $props['footer'] !== '';
?>
<section<?= MDJUI::attrs($attrs) ?>>
    <header class="md-code-sample__head">
        <div class="md-code-sample__titles">
            <?php if ($props['eyebrow'] !== ''): ?><div class="md-code-sample__eyebrow"><?= MDJUI::e($props['eyebrow']) ?></div><?php endif; ?>
            <?php if ($props['title'] !== ''): ?>
                <div class="md-code-sample__title">
                    <?= MDJUI::icon('code') ?>
                    <span><?= MDJUI::e($props['title']) ?></span>
                </div>
            <?php endif; ?>
        </div>
        <div class="md-code-sample__actions">
            <?php if (count($tabs) > 1): ?>
                <div class="md-code-sample__tabs" role="tablist" aria-label="Язык примера">
                    <?php foreach ($tabs as $index => $tab): ?>
                        <button type="button"
                                class="md-code-sample__tab<?= $index === 0 ? ' is-active' : '' ?>"
                                role="tab"
                                id="<?= MDJUI::e($baseId . '-tab-' . $index) ?>"
                                aria-controls="<?= MDJUI::e($baseId . '-panel-' . $index) ?>"
                                aria-selected="<?= $index === 0 ? 'true' : 'false' ?>"
                                tabindex="<?= $index === 0 ? '0' : '-1' ?>"
                                data-md-code-sample-tab="<?= $index ?>"><?= MDJUI::e($tab['label']) ?></button>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($props['show_language'] && $tabs): ?>
                <span class="md-code-sample__lang"><?= MDJUI::e($tabs[0]['label']) ?></span>
            <?php endif; ?>
            <?php if ($props['copy'] && $tabs): ?>
                <button type="button" class="md-code-sample__copy" data-md-code-sample-copy aria-label="Скопировать код">
                    <?= MDJUI::icon('copy') ?>
                    <span data-md-code-sample-copy-label>Копировать</span>
                </button>
            <?php endif; ?>
        </div>
    </header>
    <div class="md-code-sample__panels">
        <?php foreach ($tabs as $index => $tab): ?>
            <?php
            $lines = substr_count($tab['code'], "\n") + 1;
            ?>
            <div class="md-code-sample__panel<?= $index === 0 ? ' is-active' : '' ?>"
                 id="<?= MDJUI::e($baseId . '-panel-' . $index) ?>"
                 role="tabpanel"
                 aria-labelledby="<?= MDJUI::e($baseId . '-tab-' . $index) ?>"
                 data-md-code-sample-panel="<?= $index ?>"
                 data-language="<?= MDJUI::e($tab['language']) ?>"
                 <?= $index === 0 ? '' : 'hidden' ?>>
                <div class="md-code-sample__scroll">
                    <?php if ($props['line_numbers'] && !$props['wrap']): ?>
                        <div class="md-code-sample__gutter" aria-hidden="true">
                            <?php for ($line = 1; $line <= $lines; $line++): ?><span><?= $line ?></span><?php endfor; ?>
                        </div>
                    <?php endif; ?>
                    <pre class="md-code-sample__pre"><code class="md-code-sample__code language-<?= MDJUI::e($tab['language']) ?>"><?= MDJUI::e($tab['code']) ?></code></pre>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($hasFooter): ?>
        <footer class="md-code-sample__foot">
            <span class="md-code-sample__caption"><?= $props['caption'] !== '' ? MDJUI::e($props['caption']) : '' ?></span>
            <?php if ($props['footer'] !== ''): ?><span class="md-code-sample__footer"><?= $props['footer'] ?></span><?php endif; ?>
        </footer>
    <?php endif; ?>
</section>
