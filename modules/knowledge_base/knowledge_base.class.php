<?php
/**
 * Knowledge base.
 *
 * Read-only admin section over the repository `docs/` directory. The module
 * does not own the content: Markdown files stay agent-readable and are rendered
 * in the panel with audience labels and categories.
 *
 * @package MajorDoMo
 */

require_once(ROOT . 'lib/knowledge_base.php');

class knowledge_base extends module
{
    /** @var string */
    public $doc = '';

    /**
     * Module constructor.
     */
    public function __construct()
    {
        $this->name = 'knowledge_base';
        $this->title = '<#LANG_MODULE_KNOWLEDGE_BASE#>';
        $this->module_category = '<#LANG_SECTION_HELP#>';
        $this->checkInstalled();
    }

    /**
     * Save current state in legacy pd links.
     *
     * @param int $data
     * @return string
     */
    public function saveParams($data = 0)
    {
        $params = array();
        if (isset($this->id)) {
            $params['id'] = $this->id;
        }
        if (isset($this->view_mode)) {
            $params['view_mode'] = $this->view_mode;
        }
        if (isset($this->doc) && $this->doc !== '') {
            $params['doc'] = $this->doc;
        }

        return parent::saveParams($params);
    }

    /**
     * Read module params from the request.
     *
     * @return void
     */
    public function getParams()
    {
        global $id;
        global $view_mode;
        global $doc;

        if (isset($id) && is_scalar($id)) {
            $this->id = $id;
        }
        if (isset($view_mode) && is_scalar($view_mode)) {
            $this->view_mode = $view_mode;
        }
        if (isset($doc) && is_scalar($doc)) {
            $this->doc = (string)$doc;
        }
    }

    /**
     * Run the module.
     *
     * @return void
     */
    public function run()
    {
        $out = array();
        $this->admin($out);

        $out['VIEW_MODE'] = $this->view_mode;
        $out['ACTION'] = $this->action;
        $out['DOC_ID'] = isset($out['DOC_ID']) ? $out['DOC_ID'] : '';
        $out['KB_MODE'] = isset($out['KB_MODE']) ? $out['KB_MODE'] : 'list';

        $this->data = $out;
        $parser = new parser(DIR_TEMPLATES . $this->name . '/' . $this->name . '.html', $this->data, $this);
        $this->result = $parser->result;
    }

    /**
     * Backend.
     *
     * @param array $out
     * @return void
     */
    public function admin(&$out)
    {
        $kb = new MDKnowledgeBase(
            ROOT . 'docs',
            ROOTHTML,
            MDKnowledgeBase::moduleSources(ROOT . 'modules', 'isModuleInstalled', $this->moduleTitles())
        );
        $stats = $kb->stats();

        $out['KB_TOTAL'] = $stats['total'];
        $out['KB_USER_TOTAL'] = $stats['user'];
        $out['KB_AGENT_TOTAL'] = $stats['agent'];
        $out['KB_CATEGORY_TOTAL'] = $stats['categories'];
        $out['KB_MODULE_TOTAL'] = isset($stats['modules']) ? (int)$stats['modules'] : 0;
        $documents = $kb->documents();
        $out['KB_HAS_README'] = isset($documents['README']);
        $out['KB_HAS_COMMANDS'] = isset($documents['user/commands']);
        $out['KB_DOCS_ROOT'] = $this->e($kb->root());

        $categories = array();
        foreach ($kb->categories() as $category) {
            $categories[] = array(
                'KEY' => $this->e($category['key']),
                'LABEL' => $this->e($category['label']),
                'ICON' => $this->e($category['icon']),
                'COUNT' => (int)$category['count'],
            );
        }
        $out['CATEGORIES'] = $categories;

        $docId = isset($this->doc) && is_scalar($this->doc) ? trim((string)$this->doc) : '';
        if ($docId !== '') {
            $this->renderDocument($out, $kb, $docId);
            return;
        }

        $out['KB_MODE'] = 'list';
        $out['GROUPS'] = $this->buildGroups($kb);
    }

    /**
     * Titles of installed modules, used as knowledge-base category labels.
     *
     * @return array<string,string>
     */
    private function moduleTitles()
    {
        $titles = array();
        if (!function_exists('SQLSelect')) {
            return $titles;
        }
        $rows = SQLSelect("SELECT NAME, TITLE FROM project_modules");
        if (!is_array($rows)) {
            return $titles;
        }
        foreach ($rows as $row) {
            $name = isset($row['NAME']) ? trim((string)$row['NAME']) : '';
            $title = isset($row['TITLE']) ? trim((string)$row['TITLE']) : '';
            if ($name !== '' && $title !== '') {
                $titles[$name] = $title;
            }
        }

        return $titles;
    }

    /**
     * Prepare the article view.
     *
     * @param array $out
     * @param MDKnowledgeBase $kb
     * @param string $docId
     * @return void
     */
    private function renderDocument(&$out, MDKnowledgeBase $kb, $docId)
    {
        $document = $kb->document($docId);
        if ($document === null) {
            http_response_code(404);
            $out['KB_MODE'] = 'missing';
            $out['DOC_ID'] = $this->e($docId);
            return;
        }

        $out['KB_MODE'] = 'doc';
        $out['DOC_ID'] = $this->e($document['id']);
        $out['DOC_TITLE'] = $this->e($document['title']);
        $out['DOC_DESCRIPTION'] = $this->e($document['description']);
        $out['DOC_HTML'] = $document['html'];
        $out['DOC_READING'] = (int)$document['reading_minutes'];
        $out['DOC_WORDS'] = (int)$document['words'];
        $out['DOC_UPDATED'] = $document['updated'] > 0 ? date('d.m.Y H:i', $document['updated']) : '';
        $out['DOC_UPDATED_ISO'] = $document['updated'] > 0 ? date('c', $document['updated']) : '';
        $out['DOC_CATEGORY_KEY'] = $this->e($document['category_key']);
        $out['DOC_CATEGORY_QUERY'] = rawurlencode($document['category_key']);
        $out['DOC_CATEGORY_LABEL'] = $this->e($document['category_label']);
        $out['DOC_CATEGORY_ICON'] = $this->e($document['category_icon']);
        $out['DOC_SOURCE_URL'] = $this->e(isset($document['source_url']) ? $document['source_url'] : $kb->sourceUrl($document['relative_path']));
        $out['DOC_SOURCE_LABEL'] = $this->e(isset($document['source_label']) ? $document['source_label'] : 'docs/' . $document['relative_path']);
        $out['DOC_IS_MODULE'] = !empty($document['is_module']) ? 1 : 0;
        $out['DOC_MODULE_TITLE'] = $this->e(isset($document['module_title']) ? $document['module_title'] : '');
        $out['DOC_AUDIENCE_USER'] = in_array('user', $document['audience'], true) ? 1 : 0;
        $out['DOC_AUDIENCE_AGENT'] = in_array('agent', $document['audience'], true) ? 1 : 0;
        $out['DOC_AUDIENCE_LABEL'] = $this->audienceLabel($document['audience']);

        $tags = array();
        foreach ($document['tags'] as $tag) {
            $tags[] = array('TAG' => $this->e($tag));
        }
        $out['DOC_TAGS'] = $tags;

        $toc = array();
        foreach ($document['toc'] as $item) {
            $toc[] = array(
                'LEVEL' => (int)$item['level'],
                'ID' => $this->e($item['id']),
                'TITLE' => $this->e($item['title']),
                'IS_SUB' => (int)$item['level'] === 3 ? 1 : 0,
            );
        }
        $out['DOC_TOC'] = $toc;

        $neighbors = $kb->neighbors($document['id']);
        $out['HAS_PREV'] = $neighbors['prev'] !== null ? 1 : 0;
        $out['HAS_NEXT'] = $neighbors['next'] !== null ? 1 : 0;
        $out['PREV_TITLE'] = $neighbors['prev'] !== null ? $this->e($neighbors['prev']['title']) : '';
        $out['PREV_URL'] = $neighbors['prev'] !== null
            ? '?view_mode=view_doc&amp;doc=' . rawurlencode($neighbors['prev']['id'])
            : '';
        $out['NEXT_TITLE'] = $neighbors['next'] !== null ? $this->e($neighbors['next']['title']) : '';
        $out['NEXT_URL'] = $neighbors['next'] !== null
            ? '?view_mode=view_doc&amp;doc=' . rawurlencode($neighbors['next']['id'])
            : '';
    }

    /**
     * Build category groups with display-ready card data.
     *
     * @param MDKnowledgeBase $kb
     * @return array<int,array<string,mixed>>
     */
    private function buildGroups(MDKnowledgeBase $kb)
    {
        $groups = array();
        foreach ($kb->groups() as $group) {
            $documents = array();
            foreach ($group['docs'] as $document) {
                $documents[] = $this->cardData($document);
            }
            $groups[] = array(
                'KEY' => $this->e($group['key']),
                'LABEL' => $this->e($group['label']),
                'ICON' => $this->e($group['icon']),
                'COUNT' => count($documents),
                'DOCS' => $documents,
            );
        }

        return $groups;
    }

    /**
     * Display-ready card for one document.
     *
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    private function cardData(array $document)
    {
        $tags = array();
        foreach ($document['tags'] as $tag) {
            $tags[] = array('TAG' => $this->e($tag));
        }

        return array(
            'ID' => $this->e($document['id']),
            'TITLE' => $this->e($document['title']),
            'DESCRIPTION' => $this->e($document['description']),
            'URL' => '?view_mode=view_doc&amp;doc=' . rawurlencode($document['id']),
            'CATEGORY_KEY' => $this->e($document['category_key']),
            'CATEGORY_LABEL' => $this->e($document['category_label']),
            'ICON' => $this->e($document['icon']),
            'AUDIENCE_DATA' => $this->e(implode(' ', $document['audience'])),
            'AUDIENCE_LABEL' => $this->e($this->audienceLabel($document['audience'])),
            'AUDIENCE_USER' => in_array('user', $document['audience'], true) ? 1 : 0,
            'AUDIENCE_AGENT' => in_array('agent', $document['audience'], true) ? 1 : 0,
            'UPDATED' => $document['updated'] > 0 ? date('d.m.Y', $document['updated']) : '',
            'UPDATED_ISO' => $document['updated'] > 0 ? date('c', $document['updated']) : '',
            'READING' => (int)$document['reading_minutes'],
            'SEARCH' => $this->e($document['search']),
            'TAG_TEXT' => $this->e(implode(' · ', $document['tags'])),
            'TAGS' => $tags,
            'IS_MODULE' => !empty($document['is_module']) ? 1 : 0,
            'MODULE_TITLE' => $this->e(isset($document['module_title']) ? $document['module_title'] : ''),
        );
    }

    /**
     * Human label for an audience set.
     *
     * @param array<int,string> $audience
     * @return string
     */
    private function audienceLabel(array $audience)
    {
        $hasUser = in_array('user', $audience, true);
        $hasAgent = in_array('agent', $audience, true);
        if ($hasUser && $hasAgent) {
            return 'Пользователю и агентам';
        }
        if ($hasUser) {
            return 'Пользователю';
        }

        return 'Агентам';
    }

    /**
     * HTML-escape a value.
     *
     * @param string $value
     * @return string
     */
    private function e($value)
    {
        $escaped = htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // Keep `$(` out of partLoad content: it is treated as inline JS.
        return str_replace('$', '&#36;', $escaped);
    }
}
