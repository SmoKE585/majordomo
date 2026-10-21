<?php
/**
 * Knowledge base registry.
 *
 * The knowledge base deliberately has no database table: the source of truth is
 * the same `docs/` directory that agents already read. Add or edit a Markdown
 * file and it appears in the admin section on the next request.
 *
 * Metadata is optional. It can be declared in the file's front matter:
 *
 *   ---
 *   title: Доступные команды
 *   audience: user, agent
 *   category: user
 *   tags: команды, cli, установка
 *   order: 10
 *   ---
 *
 * Defaults are derived from the path:
 *   docs/user/**   -> audience user,  category user
 *   docs/agents/** -> audience agent, category agents
 *   docs/deploy/** -> audience agent, category deploy
 *   docs/dev/**    -> audience agent, category dev
 *   docs/archive/**-> audience agent, category archive
 *   docs/*.md      -> audience user + agent, category general
 *
 * @package MajorDoMo
 */

require_once __DIR__ . '/markdown.php';

final class MDKnowledgeBase
{
    /** @var string Absolute docs directory without a trailing slash. */
    private $root;

    /** @var string Project root URL. */
    private $rootHtml;

    /** @var array<string,array<string,mixed>>|null */
    private $documents = null;

    /**
     * @param string $root Absolute docs directory.
     * @param string $rootHtml Project root URL (`ROOTHTML`).
     */
    public function __construct($root, $rootHtml = '/')
    {
        $this->root = rtrim(str_replace('\\', '/', (string)$root), '/');
        $this->rootHtml = $rootHtml !== '' ? (string)$rootHtml : '/';
    }

    /**
     * Absolute docs directory.
     *
     * @return string
     */
    public function root()
    {
        return $this->root;
    }

    /**
     * All documents keyed by relative id (path without `.md`).
     *
     * @return array<string,array<string,mixed>>
     */
    public function documents()
    {
        if ($this->documents !== null) {
            return $this->documents;
        }

        $documents = array();
        if (is_dir($this->root)) {
            try {
                $directory = new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS);
                $filter = new RecursiveCallbackFilterIterator(
                    $directory,
                    function (SplFileInfo $file) {
                        if ($file->isDir()) {
                            $name = $file->getFilename();
                            return $name === '' || $name[0] !== '.';
                        }
                        return strtolower($file->getExtension()) === 'md';
                    }
                );

                foreach (new RecursiveIteratorIterator($filter) as $file) {
                    if (!$file->isFile() || strtolower($file->getExtension()) !== 'md') {
                        continue;
                    }
                    $path = str_replace('\\', '/', $file->getPathname());
                    if (strncmp($path, $this->root . '/', strlen($this->root) + 1) !== 0) {
                        continue;
                    }
                    $relative = substr($path, strlen($this->root) + 1);
                    $id = substr($relative, 0, -3);
                    $document = $this->readDocument($relative, $id, $path);
                    if ($document !== null) {
                        $documents[$id] = $document;
                    }
                }
            } catch (Exception $e) {
                $documents = array();
            } catch (Throwable $e) {
                $documents = array();
            }
        }

        uasort($documents, function ($left, $right) {
            if ($left['category_order'] !== $right['category_order']) {
                return $left['category_order'] < $right['category_order'] ? -1 : 1;
            }
            if ($left['order'] !== $right['order']) {
                return $left['order'] < $right['order'] ? -1 : 1;
            }
            return strnatcasecmp($left['title'], $right['title']);
        });

        $this->documents = $documents;

        return $this->documents;
    }

    /**
     * Render one document, including HTML and table of contents.
     *
     * @param string $id
     * @return array<string,mixed>|null
     */
    public function document($id)
    {
        $id = trim(str_replace('\\', '/', (string)$id), '/');
        if ($id === '' || strpos($id, '..') !== false) {
            return null;
        }

        $documents = $this->documents();
        if (!isset($documents[$id])) {
            return null;
        }

        $document = $documents[$id];
        $renderer = new MDMarkdownRenderer($id, $documents, $this->root, $this->rootHtml);
        $rendered = $renderer->render($document['body']);
        $document['html'] = $rendered['html'];
        $document['toc'] = $rendered['toc'];

        return $document;
    }

    /**
     * Documents grouped by category, ordered for the list page.
     *
     * @return array<int,array<string,mixed>>
     */
    public function groups()
    {
        $groups = array();
        foreach ($this->documents() as $document) {
            $key = $document['category_key'];
            if (!isset($groups[$key])) {
                $groups[$key] = array(
                    'key' => $key,
                    'label' => $document['category_label'],
                    'icon' => $document['category_icon'],
                    'order' => $document['category_order'],
                    'docs' => array(),
                );
            }
            $groups[$key]['docs'][] = $document;
        }

        uasort($groups, function ($left, $right) {
            if ($left['order'] !== $right['order']) {
                return $left['order'] < $right['order'] ? -1 : 1;
            }
            return strnatcasecmp($left['label'], $right['label']);
        });

        return array_values($groups);
    }

    /**
     * Category descriptors with document counts.
     *
     * @return array<int,array<string,mixed>>
     */
    public function categories()
    {
        $categories = array();
        foreach ($this->documents() as $document) {
            $key = $document['category_key'];
            if (!isset($categories[$key])) {
                $categories[$key] = array(
                    'key' => $key,
                    'label' => $document['category_label'],
                    'icon' => $document['category_icon'],
                    'order' => $document['category_order'],
                    'count' => 0,
                );
            }
            $categories[$key]['count']++;
        }

        uasort($categories, function ($left, $right) {
            if ($left['order'] !== $right['order']) {
                return $left['order'] < $right['order'] ? -1 : 1;
            }
            return strnatcasecmp($left['label'], $right['label']);
        });

        return array_values($categories);
    }

    /**
     * Aggregate counters for the page hero/KPI.
     *
     * @return array{total:int,user:int,agent:int,categories:int}
     */
    public function stats()
    {
        $stats = array('total' => 0, 'user' => 0, 'agent' => 0, 'categories' => 0);
        $categories = array();
        foreach ($this->documents() as $document) {
            $stats['total']++;
            if (in_array('user', $document['audience'], true)) {
                $stats['user']++;
            }
            if (in_array('agent', $document['audience'], true)) {
                $stats['agent']++;
            }
            $categories[$document['category_key']] = true;
        }
        $stats['categories'] = count($categories);

        return $stats;
    }

    /**
     * Previous/next documents in the rendered order.
     *
     * @param string $id
     * @return array{prev:array<string,mixed>|null,next:array<string,mixed>|null}
     */
    public function neighbors($id)
    {
        $ordered = array_values($this->documents());
        $result = array('prev' => null, 'next' => null);
        $count = count($ordered);
        for ($i = 0; $i < $count; $i++) {
            if ($ordered[$i]['id'] !== $id) {
                continue;
            }
            if ($i > 0) {
                $result['prev'] = $ordered[$i - 1];
            }
            if ($i + 1 < $count) {
                $result['next'] = $ordered[$i + 1];
            }
            break;
        }

        return $result;
    }

    /**
     * Pretty URL for one document.
     *
     * @param string $id
     * @return string
     */
    public function prettyUrl($id)
    {
        return rtrim($this->rootHtml, '/') . '/panel/knowledge_base/' . $this->encodePath($id) . '.html';
    }

    /**
     * Public URL of the source Markdown file.
     *
     * @param string $relativePath Path relative to the docs root.
     * @return string
     */
    public function sourceUrl($relativePath)
    {
        return rtrim($this->rootHtml, '/') . '/docs/' . $this->encodePath($relativePath);
    }

    /**
     * Percent-encode a relative path.
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
     * Read and normalise one Markdown file.
     *
     * @param string $relative
     * @param string $id
     * @param string $absolutePath
     * @return array<string,mixed>|null
     */
    private function readDocument($relative, $id, $absolutePath)
    {
        $raw = @file_get_contents($absolutePath);
        if (!is_string($raw)) {
            return null;
        }

        list($meta, $body) = $this->splitFrontMatter($raw);

        $top = strtok($relative, '/');
        $isRootDocument = ($top === $relative);
        $metaCategory = isset($meta['category']) && is_string($meta['category']) ? trim($meta['category']) : '';
        $categoryKey = $metaCategory !== ''
            ? $this->normalizeKey($metaCategory)
            : ($isRootDocument ? 'general' : $this->normalizeKey((string)$top));
        if ($categoryKey === '') {
            $categoryKey = 'general';
        }
        $category = $this->categoryInfo($categoryKey, $meta);

        $metaTitle = isset($meta['title']) && is_string($meta['title']) ? trim($meta['title']) : '';
        $title = $metaTitle !== '' ? $metaTitle : $this->extractTitle($body);
        if ($title === '') {
            $title = $this->humanize(basename($relative, '.md'));
        }

        $metaDescription = isset($meta['description']) && is_string($meta['description'])
            ? trim($meta['description'])
            : '';
        $description = $metaDescription !== '' ? $metaDescription : $this->extractDescription($body);

        $audience = $this->resolveAudience($meta, $relative, $isRootDocument);
        $tags = $this->resolveTags($meta, $relative, $isRootDocument);

        $plainText = $this->plainText($body);
        $words = $this->wordCount($plainText);
        $headings = $this->extractHeadings($body);
        $readingMinutes = max(1, (int)ceil($words / 180));

        $order = isset($meta['order']) && is_numeric($meta['order'])
            ? (int)$meta['order']
            : $this->defaultOrder($id);

        $search = mb_strtolower(
            $title . ' ' . $description . ' ' . $category['label'] . ' '
            . implode(' ', $tags) . ' ' . implode(' ', $headings) . ' '
            . mb_substr($body, 0, 4000, 'UTF-8'),
            'UTF-8'
        );
        $search = preg_replace('/\s+/u', ' ', $search);

        $updated = @filemtime($absolutePath);

        return array(
            'id' => $id,
            'relative_path' => $relative,
            'absolute_path' => $absolutePath,
            'title' => $title,
            'description' => $description,
            'body' => $body,
            'category_key' => $categoryKey,
            'category_label' => $category['label'],
            'category_icon' => $category['icon'],
            'category_order' => $category['order'],
            'audience' => $audience,
            'tags' => $tags,
            'order' => $order,
            'updated' => $updated !== false ? (int)$updated : 0,
            'words' => $words,
            'reading_minutes' => $readingMinutes,
            'headings' => $headings,
            'search' => $search,
            'icon' => isset($meta['icon']) && is_string($meta['icon']) && $meta['icon'] !== ''
                ? $meta['icon']
                : $category['icon'],
        );
    }

    /**
     * Split optional YAML front matter from the Markdown body.
     *
     * @param string $raw
     * @return array{0:array<string,mixed>,1:string}
     */
    private function splitFrontMatter($raw)
    {
        $raw = str_replace(array("\r\n", "\r"), "\n", (string)$raw);
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }

        if (preg_match('/^---[ \t]*\n(.*?)\n---[ \t]*(?:\n|$)/s', $raw, $match)) {
            return array($this->parseMeta($match[1]), substr($raw, strlen($match[0])));
        }

        return array(array(), $raw);
    }

    /**
     * Parse the small, deliberately flat front-matter subset.
     *
     * @param string $block
     * @return array<string,mixed>
     */
    private function parseMeta($block)
    {
        $meta = array();
        foreach (preg_split('/\R/', (string)$block) as $line) {
            $line = trim($line);
            if ($line === '' || strncmp($line, '#', 1) === 0) {
                continue;
            }
            if (!preg_match('/^([A-Za-z0-9_-]+)\s*:\s*(.*)$/', $line, $match)) {
                continue;
            }
            $key = strtolower($match[1]);
            $value = trim($match[2]);
            if ($value === '') {
                $meta[$key] = '';
                continue;
            }
            if (in_array($key, array('audience', 'audiences', 'tags', 'tag'), true)) {
                if (strncmp($value, '[', 1) === 0 && substr($value, -1) === ']') {
                    $value = substr($value, 1, -1);
                }
                $items = array();
                foreach (preg_split('/\s*,\s*/', $value) as $item) {
                    $item = trim($item, " \t\"'");
                    if ($item !== '') {
                        $items[] = $item;
                    }
                }
                $meta[$key] = $items;
                continue;
            }
            $meta[$key] = trim($value, " \t\"'");
        }

        return $meta;
    }

    /**
     * Resolve audience labels for one document.
     *
     * @param array<string,mixed> $meta
     * @param string $relative
     * @param bool $isRootDocument
     * @return array<int,string>
     */
    private function resolveAudience(array $meta, $relative, $isRootDocument)
    {
        $raw = array();
        if (isset($meta['audience'])) {
            $raw = is_array($meta['audience']) ? $meta['audience'] : array($meta['audience']);
        } elseif (isset($meta['audiences'])) {
            $raw = is_array($meta['audiences']) ? $meta['audiences'] : array($meta['audiences']);
        }

        $audience = array();
        foreach ($raw as $value) {
            $value = strtolower(trim((string)$value));
            if ($value === 'all' || $value === 'both' || $value === 'any') {
                $audience[] = 'user';
                $audience[] = 'agent';
            } elseif ($value === 'user' || $value === 'users' || $value === 'пользователь') {
                $audience[] = 'user';
            } elseif ($value === 'agent' || $value === 'agents' || $value === 'агент') {
                $audience[] = 'agent';
            }
        }

        if ($audience === array()) {
            if ($isRootDocument) {
                $audience = array('user', 'agent');
            } else {
                $top = strtok($relative, '/');
                $map = array(
                    'user' => array('user'),
                    'agents' => array('agent'),
                    'deploy' => array('agent'),
                    'dev' => array('agent'),
                    'archive' => array('agent'),
                );
                $audience = isset($map[$top]) ? $map[$top] : array('agent');
            }
        }

        $audience = array_values(array_unique($audience));
        $ordered = array();
        foreach (array('user', 'agent') as $value) {
            if (in_array($value, $audience, true)) {
                $ordered[] = $value;
            }
        }

        return $ordered !== array() ? $ordered : array('agent');
    }

    /**
     * Resolve tags from front matter plus path segments.
     *
     * @param array<string,mixed> $meta
     * @param string $relative
     * @param bool $isRootDocument
     * @return array<int,string>
     */
    private function resolveTags(array $meta, $relative, $isRootDocument)
    {
        $tags = array();
        $raw = array();
        if (isset($meta['tags'])) {
            $raw = is_array($meta['tags']) ? $meta['tags'] : array($meta['tags']);
        } elseif (isset($meta['tag'])) {
            $raw = is_array($meta['tag']) ? $meta['tag'] : array($meta['tag']);
        }
        foreach ($raw as $tag) {
            $tag = trim((string)$tag);
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }

        if (!$isRootDocument) {
            $segments = explode('/', $relative);
            array_pop($segments); // file name
            if (count($segments) > 1) {
                // Add subsection names (for example `blog` or `api`).
                for ($i = 1, $count = count($segments); $i < $count; $i++) {
                    $tags[] = $segments[$i];
                }
            }
        }

        $unique = array();
        $seen = array();
        foreach ($tags as $tag) {
            $key = mb_strtolower($tag, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $tag;
        }

        return $unique;
    }

    /**
     * Category label/icon/order.
     *
     * @param string $key
     * @param array<string,mixed> $meta
     * @return array{label:string,icon:string,order:int}
     */
    private function categoryInfo($key, array $meta)
    {
        $defaults = array(
            'general' => array('label' => 'Общие материалы', 'icon' => 'book', 'order' => 0),
            'user' => array('label' => 'Для пользователя', 'icon' => 'user', 'order' => 10),
            'agents' => array('label' => 'Для агентов', 'icon' => 'robot', 'order' => 20),
            'deploy' => array('label' => 'Развёртывание', 'icon' => 'server', 'order' => 30),
            'dev' => array('label' => 'Разработка', 'icon' => 'code', 'order' => 40),
            'archive' => array('label' => 'Архив', 'icon' => 'archive', 'order' => 90),
        );

        $info = isset($defaults[$key])
            ? $defaults[$key]
            : array(
                'label' => $this->humanize($key),
                'icon' => 'folder',
                'order' => 50,
            );

        if (isset($meta['category_label']) && is_string($meta['category_label']) && $meta['category_label'] !== '') {
            $info['label'] = $meta['category_label'];
        }
        if (isset($meta['category_icon']) && is_string($meta['category_icon']) && $meta['category_icon'] !== '') {
            $info['icon'] = $meta['category_icon'];
        }
        if (isset($meta['category_order']) && is_numeric($meta['category_order'])) {
            $info['order'] = (int)$meta['category_order'];
        }

        return $info;
    }

    /**
     * Extract the first level-1 heading as a fallback title.
     *
     * @param string $body
     * @return string
     */
    private function extractTitle($body)
    {
        $lines = preg_split('/\n/', (string)$body);
        $inFence = false;
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line)) {
                $inFence = !$inFence;
                continue;
            }
            if ($inFence) {
                continue;
            }
            if (preg_match('/^ {0,3}#\s+(.+?)\s*#*\s*$/', $line, $match)) {
                return $this->stripInline($match[1]);
            }
            if ($i + 1 < $count
                && trim($line) !== ''
                && strpos($line, '|') === false
                && !preg_match('/^ {0,3}([-*+]|\d+[.)])\s+/', $line)
                && preg_match('/^ {0,3}=+\s*$/', $lines[$i + 1])) {
                return $this->stripInline(trim($line));
            }
        }

        return '';
    }

    /**
     * First meaningful paragraph for the document card.
     *
     * @param string $body
     * @return string
     */
    private function extractDescription($body)
    {
        $inFence = false;
        $inComment = false;
        $paragraph = array();
        foreach (preg_split('/\n/', (string)$body) as $line) {
            if ($inComment) {
                if (strpos($line, '-->') !== false) {
                    $inComment = false;
                }
                continue;
            }
            if (strncmp(trim($line), '<!--', 4) === 0) {
                if (strpos($line, '-->') === false) {
                    $inComment = true;
                }
                continue;
            }
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line)) {
                $inFence = !$inFence;
                continue;
            }
            if ($inFence) {
                continue;
            }

            $trimmed = trim($line);
            if ($trimmed === '') {
                if ($paragraph) {
                    break;
                }
                continue;
            }
            if (preg_match('/^ {0,3}#{1,6}\s+/', $trimmed)
                || preg_match('/^ {0,3}([-*+]|\d+[.)])\s+/', $trimmed)
                || preg_match('/^ {0,3}>/', $trimmed)
                || preg_match('/^ {0,3}((\*\s*){3,}|(-\s*){3,}|(_\s*){3,})$/', $trimmed)
                || preg_match('/^(-{3,}|\|)/', $trimmed)
                || strpos($trimmed, '|') !== false) {
                if ($paragraph) {
                    break;
                }
                continue;
            }

            $paragraph[] = $trimmed;
        }

        if (!$paragraph) {
            return '';
        }

        $text = $this->stripInline(implode(' ', $paragraph));
        if (mb_strlen($text, 'UTF-8') > 220) {
            $text = mb_substr($text, 0, 217, 'UTF-8');
            $lastSpace = mb_strrpos($text, ' ', 0, 'UTF-8');
            if ($lastSpace !== false) {
                $text = mb_substr($text, 0, $lastSpace, 'UTF-8');
            }
            $text .= '…';
        }

        return $text;
    }

    /**
     * Plain text used for reading time and summaries.
     *
     * @param string $body
     * @return string
     */
    private function plainText($body)
    {
        $body = (string)$body;
        $body = preg_replace('/<!--.*?-->/s', '', $body);
        $body = preg_replace('/^ {0,3}(`{3,}|~{3,}).*?^ {0,3}\1\s*$/ms', '', $body);
        $body = preg_replace('/^ {4,}.*$/m', '', $body);
        $body = preg_replace('/!\[([^\]]*)\]\([^)]*\)/', '$1', $body);
        $body = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $body);
        $body = preg_replace('/^ {0,3}#{1,6}\s+/m', '', $body);
        $body = preg_replace('/^ {0,3}([-*+]|\d+[.)])\s+/m', '', $body);
        $body = preg_replace('/^ {0,3}>\s?/m', '', $body);
        $body = preg_replace('/^\s*\|.*\|\s*$/m', '', $body);
        $body = preg_replace('/`([^`]+)`/', '$1', $body);
        $body = preg_replace('/\*\*(.+?)\*\*/s', '$1', $body);
        $body = preg_replace('/__(.+?)__/s', '$1', $body);
        $body = preg_replace('/(?<!\*)\*(.+?)\*(?!\*)/s', '$1', $body);
        $body = preg_replace('/(?<![\p{L}\p{N}_])_(.+?)_(?![\p{L}\p{N}_])/su', '$1', $body);
        $body = preg_replace('/~~(.+?)~~/s', '$1', $body);
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = preg_replace('/\s+/u', ' ', $body);

        return trim($body);
    }

    /**
     * Extract h2/h3 headings for client-side search.
     *
     * @param string $body
     * @return array<int,string>
     */
    private function extractHeadings($body)
    {
        $headings = array();
        $inFence = false;
        foreach (preg_split('/\n/', (string)$body) as $line) {
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line)) {
                $inFence = !$inFence;
                continue;
            }
            if ($inFence) {
                continue;
            }
            if (preg_match('/^ {0,3}#{2,3}\s+(.+?)\s*#*\s*$/', $line, $match)) {
                $headings[] = $this->stripInline($match[1]);
            }
        }

        return $headings;
    }

    /**
     * Strip inline Markdown markers from a short string.
     *
     * @param string $text
     * @return string
     */
    private function stripInline($text)
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
     * Word count that also understands Cyrillic.
     *
     * @param string $text
     * @return int
     */
    private function wordCount($text)
    {
        if (preg_match_all('/[\p{L}\p{N}]+/u', (string)$text, $matches)) {
            return count($matches[0]);
        }

        return 0;
    }

    /**
     * Default document order inside a category.
     *
     * @param string $id
     * @return int
     */
    private function defaultOrder($id)
    {
        $name = strtolower(basename((string)$id));
        if ($name === 'readme') {
            return 0;
        }
        if ($name === 'commands' || $name === 'команды') {
            return 1;
        }
        if ($name === 'agents') {
            return 5;
        }
        if ($name === 'install') {
            return 10;
        }

        return 100;
    }

    /**
     * Normalise a category key.
     *
     * @param string $value
     * @return string
     */
    private function normalizeKey($value)
    {
        $value = mb_strtolower(trim((string)$value), 'UTF-8');
        $value = preg_replace('/[^a-z0-9_-]+/u', '-', $value);
        return trim((string)$value, '-');
    }

    /**
     * Humanise a slug.
     *
     * @param string $value
     * @return string
     */
    private function humanize($value)
    {
        $value = str_replace(array('-', '_'), ' ', (string)$value);
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return 'Без названия';
        }

        return mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8')
            . mb_substr($value, 1, null, 'UTF-8');
    }
}
