<?php
/**
 * Safe, dependency-free Markdown renderer for the admin knowledge base.
 *
 * The knowledge base reads the same `docs/*.md` files that agents use, so the
 * renderer intentionally supports a conservative GitHub-flavoured subset:
 *
 *   - headings, paragraphs, lists (nested, ordered, task lists);
 *   - fenced and indented code blocks;
 *   - GFM tables, blockquotes, horizontal rules;
 *   - inline code, emphasis, strikethrough, links, images and autolinks.
 *
 * Raw HTML is always escaped. There is no "trusted HTML" mode: documentation
 * is data, not executable markup. Relative links to other Markdown files are
 * rewritten to knowledge-base URLs, and relative file/image links are
 * rewritten to `/docs/...` so they keep working inside the admin shell.
 *
 * Usage:
 *   $renderer = new MDMarkdownRenderer($docId, $documents, $docsRoot, ROOTHTML);
 *   $result = $renderer->render($markdown);
 *   // $result = ['html' => ..., 'toc' => [['level' => 2, 'id' => ..., 'title' => ...]]]
 *
 * @package MajorDoMo
 */

final class MDMarkdownRenderer
{
    /** @var string Current document id (relative path without `.md`). */
    private $docId;

    /** @var array<string,array<string,mixed>> All known documents keyed by id. */
    private $documents;

    /** @var string Absolute path to the docs root. */
    private $docsRoot;

    /** @var string Project root URL prefix (`ROOTHTML`). */
    private $rootHtml;

    /** @var array<int,array{level:int,id:string,title:string}> */
    private $toc = array();

    /** @var array<string,bool> */
    private $slugCounts = array();

    /** @var bool */
    private $titleStripped = false;

    /** @var int */
    private $inlineDepth = 0;

    /** @var array<int,string> Shared inline token storage (nested labels). */
    private $inlineTokens = array();

    /**
     * @param string $docId Current document id.
     * @param array<string,array<string,mixed>> $documents Known documents.
     * @param string $docsRoot Absolute docs directory.
     * @param string $rootHtml Project root URL.
     */
    public function __construct($docId, array $documents = array(), $docsRoot = '', $rootHtml = '/')
    {
        $this->docId = str_replace('\\', '/', trim((string)$docId));
        $this->documents = $documents;
        $this->docsRoot = $docsRoot !== '' ? rtrim(str_replace('\\', '/', (string)$docsRoot), '/') : '';
        $this->rootHtml = $rootHtml !== '' ? (string)$rootHtml : '/';
    }

    /**
     * Render Markdown source.
     *
     * @param string $markdown
     * @return array{html:string,toc:array<int,array{level:int,id:string,title:string}>}
     */
    public function render($markdown)
    {
        $markdown = str_replace(array("\r\n", "\r"), "\n", (string)$markdown);
        if (strncmp($markdown, "\xEF\xBB\xBF", 3) === 0) {
            $markdown = substr($markdown, 3);
        }

        $this->toc = array();
        $this->slugCounts = array();
        $this->titleStripped = false;
        $this->inlineDepth = 0;
        $this->inlineTokens = array();

        $lines = explode("\n", $markdown);
        $html = $this->renderBlocks($lines, true);

        return array('html' => $html, 'toc' => $this->toc);
    }

    /**
     * Render a block-level sequence.
     *
     * @param array<int,string> $lines
     * @param bool $topLevel
     * @return string
     */
    private function renderBlocks(array $lines, $topLevel = false)
    {
        $out = '';
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;
                continue;
            }

            // Fenced code block.
            if (preg_match('/^ {0,3}(`{3,}|~{3,})\s*([A-Za-z0-9_+.#-]*)\s*$/', $line, $m)) {
                $fenceChar = $m[1][0];
                $fenceLen = strlen($m[1]);
                $language = $m[2];
                $code = array();
                $i++;
                $closePattern = '/^ {0,3}' . preg_quote($fenceChar, '/') . '{' . $fenceLen . ',}\s*$/';
                while ($i < $n) {
                    if (preg_match($closePattern, $lines[$i])) {
                        $i++;
                        break;
                    }
                    $code[] = $lines[$i];
                    $i++;
                }
                $out .= $this->codeBlock(implode("\n", $code), $language);
                continue;
            }

            // Indented code block (4 spaces, top-level only).
            if (preg_match('/^ {4,}\S/', $line)) {
                $code = array();
                while ($i < $n) {
                    if (trim($lines[$i]) === '') {
                        $code[] = '';
                        $i++;
                        continue;
                    }
                    if (!preg_match('/^ {4,}/', $lines[$i])) {
                        break;
                    }
                    $code[] = preg_replace('/^ {4}/', '', $lines[$i], 1);
                    $i++;
                }
                while ($code && end($code) === '') {
                    array_pop($code);
                }
                $out .= $this->codeBlock(implode("\n", $code), '');
                continue;
            }

            // HTML comments are documentation metadata, not visible content.
            // Handled after code blocks so comments in code examples stay
            // intact; text after a same-line `-->` is processed normally.
            if (strncmp(ltrim($line), '<!--', 4) === 0) {
                $rest = '';
                while ($i < $n) {
                    $position = strpos($lines[$i], '-->');
                    if ($position !== false) {
                        $rest = substr($lines[$i], $position + 3);
                        $i++;
                        break;
                    }
                    $i++;
                }
                if (trim($rest) !== '') {
                    $lines[$i - 1] = $rest;
                    $i--;
                }
                continue;
            }

            // ATX heading.
            if (preg_match('/^ {0,3}(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $m)) {
                $level = strlen($m[1]);
                if ($topLevel && $level === 1 && !$this->titleStripped) {
                    // The article hero already renders the document title.
                    $this->titleStripped = true;
                    $i++;
                    continue;
                }
                if ($topLevel && $level === 1) {
                    // Keep a single h1 per page even when a legacy document
                    // uses several level-1 sections.
                    $level = 2;
                }
                $out .= $this->heading($m[2], $level);
                $i++;
                continue;
            }

            // Setext heading (a single text line underlined with === or ---).
            if ($i + 1 < $n && $this->isSetextUnderline($lines[$i + 1]) && !$this->isBlockStart($lines, $i)) {
                $level = strncmp(trim($lines[$i + 1]), '=', 1) === 0 ? 1 : 2;
                $text = trim($line);
                if ($text !== '') {
                    if ($topLevel && $level === 1 && !$this->titleStripped) {
                        $this->titleStripped = true;
                    } else {
                        if ($topLevel && $level === 1) {
                            $level = 2;
                        }
                        $out .= $this->heading($text, $level);
                    }
                    $i += 2;
                    continue;
                }
            }

            // Horizontal rule.
            if (preg_match('/^ {0,3}((\*\s*){3,}|(-\s*){3,}|(_\s*){3,})$/', $line)) {
                $out .= "<hr>\n";
                $i++;
                continue;
            }

            // Blockquote.
            if (preg_match('/^ {0,3}>/', $line)) {
                $quote = array();
                while ($i < $n) {
                    if (preg_match('/^ {0,3}>\s?(.*)$/', $lines[$i], $m)) {
                        $quote[] = $m[1];
                        $i++;
                        continue;
                    }
                    if (trim($lines[$i]) === '') {
                        $quote[] = '';
                        $i++;
                        continue;
                    }
                    break;
                }
                $out .= "<blockquote>\n" . $this->renderBlocks($quote, false) . "</blockquote>\n";
                continue;
            }

            // GFM table: header + alignment separator + rows.
            if ($i + 1 < $n
                && strpos($line, '|') !== false
                && $this->isTableSeparator($lines[$i + 1])) {
                $header = $this->splitTableRow($line);
                $alignments = array();
                foreach ($this->splitTableRow($lines[$i + 1]) as $cell) {
                    $cell = trim($cell);
                    if (strncmp($cell, ':', 1) === 0 && substr($cell, -1) === ':') {
                        $alignments[] = 'center';
                    } elseif (substr($cell, -1) === ':') {
                        $alignments[] = 'right';
                    } elseif (strncmp($cell, ':', 1) === 0) {
                        $alignments[] = 'left';
                    } else {
                        $alignments[] = '';
                    }
                }
                $i += 2;
                $rows = array();
                while ($i < $n && trim($lines[$i]) !== '' && strpos($lines[$i], '|') !== false) {
                    $rows[] = $this->splitTableRow($lines[$i]);
                    $i++;
                }
                $out .= $this->table($header, $alignments, $rows);
                continue;
            }

            // List.
            if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/', $line, $m)) {
                $indent = strlen($m[1]);
                $out .= $this->renderList($lines, $i, $indent);
                continue;
            }

            // Paragraph.
            $paragraph = array();
            while ($i < $n && trim($lines[$i]) !== '') {
                if ($paragraph && $this->isBlockStart($lines, $i)) {
                    break;
                }
                $paragraphLine = $lines[$i];
                // Preserve two trailing spaces: they are a hard line break.
                $paragraph[] = preg_match('/ {2,}$/', $paragraphLine)
                    ? rtrim($paragraphLine) . '  '
                    : rtrim($paragraphLine);
                $i++;
            }
            if ($paragraph) {
                $out .= '<p>' . $this->inline(implode("\n", $paragraph)) . "</p>\n";
            } else {
                // Defensive: never loop forever on an unknown construct.
                $i++;
            }
        }

        return $out;
    }

    /**
     * Detect a Setext heading underline.
     *
     * @param string $line
     * @return bool
     */
    private function isSetextUnderline($line)
    {
        return (bool)preg_match('/^ {0,3}(=+|-+)\s*$/', (string)$line);
    }

    /**
     * Detect a GFM table alignment row (including a one-column table).
     *
     * @param string $line
     * @return bool
     */
    private function isTableSeparator($line)
    {
        $line = (string)$line;
        if (preg_match('/^ {0,3}\|\s*:?-{1,}:?\s*\|$/', $line)) {
            return true;
        }

        return (bool)preg_match(
            '/^ {0,3}\|?\s*:?-{1,}:?\s*(\|\s*:?-{1,}:?\s*)+\|?\s*$/',
            $line
        );
    }

    /**
     * Detect a block that can start a new paragraph.
     *
     * @param array<int,string> $lines
     * @param int $i
     * @return bool
     */
    private function isBlockStart(array $lines, $i)
    {
        if (!isset($lines[$i])) {
            return false;
        }
        $line = $lines[$i];
        if (preg_match('/^ {0,3}(#{1,6})\s+/', $line)) {
            return true;
        }
        if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line)) {
            return true;
        }
        if (preg_match('/^ {0,3}>/', $line)) {
            return true;
        }
        if (preg_match('/^ {0,3}((\*\s*){3,}|(-\s*){3,}|(_\s*){3,})$/', $line)) {
            return true;
        }
        if (preg_match('/^ {0,3}([-*+]|\d+[.)])\s+/', $line)) {
            return true;
        }
        if (preg_match('/^ {4,}\S/', $line)) {
            return true;
        }
        if (strpos($line, '|') !== false && isset($lines[$i + 1])
            && $this->isTableSeparator($lines[$i + 1])) {
            return true;
        }

        return false;
    }

    /**
     * Render one heading and register it in the TOC.
     *
     * @param string $text
     * @param int $level
     * @return string
     */
    private function heading($text, $level)
    {
        $plain = $this->plainInline($text);
        $id = $this->slug($plain);

        if ($level === 2 || $level === 3) {
            $this->toc[] = array('level' => $level, 'id' => $id, 'title' => $plain);
        }

        $tag = 'h' . $level;
        $anchor = '<a class="md-prose__anchor" href="#' . $this->escapeAttr($id)
            . '" aria-label="Ссылка на раздел">#</a>';

        return '<' . $tag . ' id="' . $this->escapeAttr($id) . '">'
            . $this->inline($text) . $anchor
            . '</' . $tag . ">\n";
    }

    /**
     * Build a URL-safe unique heading id.
     *
     * @param string $text
     * @return string
     */
    private function slug($text)
    {
        $slug = mb_strtolower(trim((string)$text), 'UTF-8');
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug);
        $slug = trim((string)$slug, '-');
        if ($slug === '') {
            $slug = 'section';
        }

        $base = $slug;
        $counter = 1;
        while (isset($this->slugCounts[$slug])) {
            $counter++;
            $slug = $base . '-' . $counter;
        }
        $this->slugCounts[$slug] = true;

        return $slug;
    }

    /**
     * Render a fenced/indented code block.
     *
     * @param string $code
     * @param string $language
     * @return string
     */
    private function codeBlock($code, $language)
    {
        $class = '';
        $language = preg_replace('/[^A-Za-z0-9_+.#-]/', '', (string)$language);
        if ($language !== '') {
            $class = ' class="language-' . $this->escapeAttr($language) . '"';
        }

        return '<pre class="md-prose__pre"><code' . $class . '>' . $this->escape($code) . "</code></pre>\n";
    }

    /**
     * Render a GFM table.
     *
     * @param array<int,string> $header
     * @param array<int,string> $alignments
     * @param array<int,array<int,string>> $rows
     * @return string
     */
    private function table(array $header, array $alignments, array $rows)
    {
        $columns = count($header);
        $html = '<div class="md-prose__table-wrap"><table class="md-prose__table"><thead><tr>';
        foreach ($header as $index => $cell) {
            $align = isset($alignments[$index]) && $alignments[$index] !== ''
                ? ' class="md-prose__cell--' . $alignments[$index] . '"'
                : '';
            $html .= '<th' . $align . '>' . $this->inline($cell) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $row = array_slice(array_pad($row, $columns, ''), 0, $columns);
            $html .= '<tr>';
            foreach ($row as $index => $cell) {
                $align = isset($alignments[$index]) && $alignments[$index] !== ''
                    ? ' class="md-prose__cell--' . $alignments[$index] . '"'
                    : '';
                $html .= '<td' . $align . '>' . $this->inline($cell) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= "</tbody></table></div>\n";

        return $html;
    }

    /**
     * Split a table row into cells, keeping escaped pipes (`\|`) intact.
     *
     * @param string $line
     * @return array<int,string>
     */
    private function splitTableRow($line)
    {
        $line = trim((string)$line);
        if (strncmp($line, '|', 1) === 0) {
            $line = substr($line, 1);
        }
        if (substr($line, -1) === '|'
            && (strlen($line) < 2 || substr($line, -2, 1) !== '\\')) {
            $line = substr($line, 0, -1);
        }

        // A manual pass keeps escaped pipes (`\|`) inside one cell.
        $cells = array();
        $buffer = '';
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            if ($char === '\\' && $i + 1 < $length && $line[$i + 1] === '|') {
                $buffer .= '|';
                $i++;
                continue;
            }
            if ($char === '|') {
                $cells[] = trim($buffer);
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        $cells[] = trim($buffer);

        return $cells;
    }

    /**
     * Render a nested list starting at line $i.
     *
     * @param array<int,string> $lines
     * @param int $i
     * @param int $indent
     * @return string
     */
    private function renderList(array $lines, &$i, $indent)
    {
        $n = count($lines);
        if (!preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/', $lines[$i], $first)) {
            return '';
        }

        $ordered = (bool)preg_match('/^\d/', $first[2]);
        $start = $ordered ? (int)$first[2] : 1;
        $html = $ordered
            ? '<ol' . ($start !== 1 ? ' start="' . $start . '"' : '') . ">\n"
            : "<ul>\n";

        while ($i < $n) {
            if (!preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/', $lines[$i], $m)) {
                break;
            }
            $itemIndent = strlen($m[1]);
            if ($itemIndent !== $indent) {
                break;
            }

            $content = $m[3];
            $i++;

            $continuation = array();
            $nested = array();
            $fenceMarker = '';
            $fenceLength = 0;
            $fenceIndent = 0;

            while ($i < $n) {
                $line = $lines[$i];

                if ($fenceMarker !== '') {
                    if (trim($line) === '') {
                        $continuation[] = '';
                        $i++;
                        continue;
                    }
                    $lead = $this->indentOf($line);
                    $dedented = substr($line, min($lead, $fenceIndent));
                    $continuation[] = $dedented;
                    $closePattern = '/^ {0,3}' . preg_quote($fenceMarker, '/') . '{' . $fenceLength . ',}\s*$/';
                    if (preg_match($closePattern, $dedented)) {
                        $fenceMarker = '';
                    }
                    $i++;
                    continue;
                }

                if (trim($line) === '') {
                    $lookahead = $i + 1;
                    while ($lookahead < $n && trim($lines[$lookahead]) === '') {
                        $lookahead++;
                    }
                    if ($lookahead < $n && $this->indentOf($lines[$lookahead]) > $indent) {
                        $continuation[] = '';
                        $i++;
                        continue;
                    }
                    break;
                }

                if (preg_match('/^(\s*)(`{3,}|~{3,})\s*([A-Za-z0-9_+.#-]*)\s*$/', $line, $fenceMatch)
                    && strlen($fenceMatch[1]) > $indent) {
                    $fenceMarker = $fenceMatch[2][0];
                    $fenceLength = strlen($fenceMatch[2]);
                    $fenceIndent = strlen($fenceMatch[1]);
                    $continuation[] = $fenceMatch[2]
                        . ($fenceMatch[3] !== '' ? ' ' . $fenceMatch[3] : '');
                    $i++;
                    continue;
                }

                if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/', $line, $nestedMatch)) {
                    $nestedIndent = strlen($nestedMatch[1]);
                    if ($nestedIndent <= $indent) {
                        break;
                    }
                    $nested[] = $this->renderList($lines, $i, $nestedIndent);
                    continue;
                }

                $lead = $this->indentOf($line);
                if ($lead > $indent) {
                    $strip = min($lead, $indent + 4);
                    $continuation[] = substr($line, $strip);
                    $i++;
                    continue;
                }

                // Lazy continuation: a plain line directly under the item.
                if (!$this->isBlockStart($lines, $i)) {
                    $continuation[] = $line;
                    $i++;
                    continue;
                }

                break;
            }

            $task = null;
            if (preg_match('/^\[([ xX])\]\s+(.*)$/s', $content, $taskMatch)) {
                $task = strtolower($taskMatch[1]) === 'x' ? 'checked' : 'unchecked';
                $content = $taskMatch[2];
            }

            $item = '';
            if ($task !== null) {
                $item .= '<label class="md-prose__task"><input type="checkbox" disabled'
                    . ($task === 'checked' ? ' checked' : '') . '>';
            }
            $item .= $this->inline($content);

            $continuationText = trim(implode("\n", $continuation));
            if ($continuationText !== '') {
                // Continuation lines have already been dedented, so nested
                // fenced code blocks and paragraphs can go through the normal
                // block renderer instead of becoming one flat inline string.
                $item .= $this->renderBlocks(preg_split('/\n/', $continuationText), false);
            }

            if ($nested) {
                $item .= implode('', $nested);
            }
            if ($task !== null) {
                $item .= '</label>';
            }

            $html .= '<li>' . $item . "</li>\n";
        }

        $html .= $ordered ? "</ol>\n" : "</ul>\n";

        return $html;
    }

    /**
     * Number of leading spaces in a line.
     *
     * @param string $line
     * @return int
     */
    private function indentOf($line)
    {
        $line = (string)$line;
        return strlen($line) - strlen(ltrim($line, " \t"));
    }

    /**
     * Render inline Markdown.
     *
     * Protection order matters: code spans and backslash escapes become
     * placeholders before HTML escaping, then links/images are converted to
     * placeholders so their labels can be rendered recursively.
     *
     * @param string $text
     * @return string
     */
    private function inline($text)
    {
        $text = (string)$text;
        if ($text === '') {
            return '';
        }
        if ($this->inlineDepth >= 8) {
            return $this->escape($text);
        }
        $this->inlineDepth++;

        // Shared with nested inline calls (link labels may contain code spans).
        $tokens = &$this->inlineTokens;

        // Inline code spans.
        $text = preg_replace_callback('/`([^`\n]+)`/', function ($m) use (&$tokens) {
            $tokens[] = '<code>' . $this->escape($m[1]) . '</code>';
            return "\x01" . (count($tokens) - 1) . "\x02";
        }, $text);

        // Backslash escapes.
        $text = preg_replace_callback('/\\\\([\\\\`*_{}\[\]()#+\-.!>~|])/', function ($m) use (&$tokens) {
            $tokens[] = $this->escape($m[1]);
            return "\x01" . (count($tokens) - 1) . "\x02";
        }, $text);

        // Inline HTML comments (block comments are handled in renderBlocks).
        $text = preg_replace('/<!--.*?-->/s', '', $text);

        // Autolinks: <https://example.com>.
        $text = preg_replace_callback('/<((?:https?|mailto):[^>\s]+)>/', function ($m) use (&$tokens) {
            $tokens[] = $this->linkHtml($m[1], $m[1], '', true);
            return "\x01" . (count($tokens) - 1) . "\x02";
        }, $text);

        // Images before links: the link pattern would otherwise match the
        // bracketed part of an image.
        $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/', function ($m) use (&$tokens) {
            $tokens[] = $this->imageHtml($m[1], $m[2], isset($m[3]) ? $m[3] : '');
            return "\x01" . (count($tokens) - 1) . "\x02";
        }, $text);

        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/', function ($m) use (&$tokens) {
            $tokens[] = $this->linkHtml($m[1], $m[2], isset($m[3]) ? $m[3] : '', false);
            return "\x01" . (count($tokens) - 1) . "\x02";
        }, $text);

        $text = $this->escape($text);

        // Hard line breaks (two trailing spaces or a backslash).
        $text = preg_replace('/ {2,}\n/', "<br>\n", $text);
        $text = preg_replace('/\\\\\n/', "<br>\n", $text);

        // Strong before emphasis.
        $text = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(?=\S)(.+?)(?<=\S)__/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/(?<!\*)\*(?=\S)(.+?)(?<=\S)\*(?!\*)/s', '<em>$1</em>', $text);
        $text = preg_replace('/(?<![\p{L}\p{N}_])_(?=\S)(.+?)(?<=\S)_(?![\p{L}\p{N}_])/su', '<em>$1</em>', $text);
        $text = preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/s', '<del>$1</del>', $text);

        $text = preg_replace_callback("/\x01(\d+)\x02/", function ($m) use ($tokens) {
            $index = (int)$m[1];
            return isset($tokens[$index]) ? $tokens[$index] : '';
        }, $text);

        $this->inlineDepth--;

        return $text;
    }

    /**
     * Render a link.
     *
     * @param string $label
     * @param string $url
     * @param string $title
     * @param bool $autolink
     * @return string
     */
    private function linkHtml($label, $url, $title = '', $autolink = false)
    {
        $resolved = $this->resolveUrl($url, false);
        if ($resolved === null) {
            return $this->escape($this->tokensToPlain($label));
        }

        $attrs = ' href="' . $this->escapeAttr($resolved) . '"';
        if ($title !== '') {
            $attrs .= ' title="' . $this->escapeAttr($this->tokensToPlain($title)) . '"';
        }
        if (preg_match('#^https?://#i', $resolved)) {
            $attrs .= ' target="_blank" rel="noopener noreferrer"';
        }

        $labelHtml = $autolink ? $this->escape($label) : $this->inline($label);

        return '<a' . $attrs . '>' . $labelHtml . '</a>';
    }

    /**
     * Render an image.
     *
     * @param string $alt
     * @param string $url
     * @param string $title
     * @return string
     */
    private function imageHtml($alt, $url, $title = '')
    {
        $resolved = $this->resolveUrl($url, true);
        if ($resolved === null) {
            $alt = $this->tokensToPlain($alt);
            if ($alt === '') {
                return '';
            }
            return '<span class="md-prose__missing-image"><i class="ti ti-photo-off" aria-hidden="true"></i>'
                . $this->escape($alt) . '</span>';
        }

        $alt = $this->tokensToPlain($alt);
        $attrs = ' src="' . $this->escapeAttr($resolved) . '" alt="' . $this->escapeAttr($alt) . '" loading="lazy"';
        if ($title !== '') {
            $attrs .= ' title="' . $this->escapeAttr($this->tokensToPlain($title)) . '"';
        }

        return '<img' . $attrs . '>';
    }

    /**
     * Resolve a Markdown URL.
     *
     * @param string $url
     * @param bool $isImage
     * @return string|null Null means "render as plain text".
     */
    private function resolveUrl($url, $isImage)
    {
        $url = trim((string)$url);
        if ($url === '') {
            return null;
        }

        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (preg_match('/^(javascript|vbscript|data):/i', $url)) {
            return null;
        }
        if (strncmp($url, '#', 1) === 0) {
            return $url;
        }
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $schemeMatch)) {
            $scheme = strtolower($schemeMatch[1]);
            if (in_array($scheme, array('http', 'https', 'mailto', 'tel'), true)) {
                return $url;
            }
            return null;
        }

        $path = $url;
        $query = '';
        $fragment = '';
        if (preg_match('/^([^?#]*)(\?[^#]*)?(#.*)?$/', $url, $parts)) {
            $path = $parts[1];
            $query = isset($parts[2]) ? $parts[2] : '';
            $fragment = isset($parts[3]) ? $parts[3] : '';
        }

        if ($path === '') {
            return $url;
        }
        if (strncmp($path, '/', 1) === 0) {
            return $url;
        }

        $base = dirname($this->docId);
        if ($base === '.' || $base === '\\') {
            $base = '';
        }
        $combined = $base !== '' ? $base . '/' . $path : $path;
        $normalized = $this->normalizePath($combined);
        if ($normalized === null) {
            // A link that escapes docs/ points at repository code, not at a
            // browser-reachable file. Keeping it as plain text is clearer
            // than emitting a guaranteed 404.
            return null;
        }

        $hasTrailingSlash = substr($path, -1) === '/';
        $bare = rtrim($normalized, '/');

        // Directory links to known documentation sections open the matching
        // knowledge-base category instead of a raw file listing.
        if ($hasTrailingSlash || ($this->docsRoot !== '' && is_dir($this->docsRoot . '/' . $bare))) {
            $section = strtok($bare, '/');
            if (in_array($section, array('user', 'agents', 'deploy', 'dev', 'archive'), true)) {
                return rtrim($this->rootHtml, '/') . '/panel/knowledge_base.html?cat='
                    . rawurlencode($section) . $fragment;
            }
            return rtrim($this->rootHtml, '/') . '/docs/' . $this->encodePath($bare) . '/' . $fragment;
        }

        if (preg_match('/\.md$/i', $bare)) {
            $id = substr($bare, 0, -3);
            $resolvedId = $this->findDocumentId($id);
            if ($resolvedId !== null) {
                return rtrim($this->rootHtml, '/') . '/panel/knowledge_base/'
                    . $this->encodePath($resolvedId) . '.html' . $fragment;
            }
            return rtrim($this->rootHtml, '/') . '/docs/' . $this->encodePath($bare) . $fragment;
        }

        if ($isImage) {
            if ($this->docsRoot !== '' && !is_file($this->docsRoot . '/' . $bare)) {
                return null;
            }
            return rtrim($this->rootHtml, '/') . '/docs/' . $this->encodePath($bare) . $query . $fragment;
        }

        if ($this->docsRoot !== '' && is_file($this->docsRoot . '/' . $bare)) {
            return rtrim($this->rootHtml, '/') . '/docs/' . $this->encodePath($bare) . $query . $fragment;
        }

        // Keep unknown relative links from resolving against the admin pretty
        // URL, which would be wrong for every reader.
        return null;
    }

    /**
     * Find a known document id, including common "docs/..." prefixes.
     *
     * @param string $id
     * @return string|null
     */
    private function findDocumentId($id)
    {
        $id = trim((string)$id, '/');
        $candidates = array($id);
        if (strncmp($id, 'docs/', 5) === 0) {
            $candidates[] = substr($id, 5);
        }
        $position = strpos($id, '/docs/');
        if ($position !== false) {
            $candidates[] = substr($id, $position + 6);
        }

        foreach ($candidates as $candidate) {
            if (isset($this->documents[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Normalise a relative path; return null when it leaves the docs root.
     *
     * @param string $path
     * @return string|null
     */
    private function normalizePath($path)
    {
        $segments = explode('/', str_replace('\\', '/', (string)$path));
        $result = array();
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (!$result) {
                    return null;
                }
                array_pop($result);
                continue;
            }
            $result[] = $segment;
        }

        return implode('/', $result);
    }

    /**
     * Percent-encode each path segment.
     *
     * @param string $path
     * @return string
     */
    private function encodePath($path)
    {
        $segments = explode('/', str_replace('\\', '/', (string)$path));
        return implode('/', array_map('rawurlencode', $segments));
    }

    /**
     * Strip inline Markdown for TOC labels and plain-text extraction.
     *
     * @param string $text
     * @return string
     */
    private function plainInline($text)
    {
        $text = (string)$text;
        $text = preg_replace('/!\[([^\]]*)\]\([^)]*\)/', '$1', $text);
        $text = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $text);
        $text = preg_replace('/`([^`]+)`/', '$1', $text);
        $text = preg_replace('/\*\*(.+?)\*\*/s', '$1', $text);
        $text = preg_replace('/__(.+?)__/s', '$1', $text);
        $text = preg_replace('/(?<!\*)\*(.+?)\*(?!\*)/s', '$1', $text);
        $text = preg_replace('/(?<![\p{L}\p{N}_])_(.+?)_(?![\p{L}\p{N}_])/su', '$1', $text);
        $text = preg_replace('/~~(.+?)~~/s', '$1', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Replace inline placeholders with their plain-text content.
     *
     * Used for attribute values (image alt/title) where nested HTML would be
     * invalid.
     *
     * @param string $text
     * @return string
     */
    private function tokensToPlain($text)
    {
        return preg_replace_callback("/\x01(\d+)\x02/", function ($match) {
            $index = (int)$match[1];
            if (!isset($this->inlineTokens[$index])) {
                return '';
            }
            $plain = strip_tags($this->inlineTokens[$index]);
            return html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }, (string)$text);
    }

    /**
     * Escape text for HTML body content.
     *
     * @param string $text
     * @return string
     */
    private function escape($text)
    {
        return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escape text for an HTML attribute.
     *
     * @param string $text
     * @return string
     */
    private function escapeAttr($text)
    {
        return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
