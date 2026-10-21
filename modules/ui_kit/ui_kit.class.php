<?php
if (!defined('ROOT')) {
    http_response_code(404);
    exit;
}

/**
 * UI Kit — design system reference page for the MajorDoMo admin.
 *
 * The module itself is intentionally thin: it registers the page in the
 * admin, prepares demo data and renders the PHP view. All visual primitives
 * live in lib/ui/MDJUI.php and templates/ui/components so other modules can
 * reuse them instead of copying markup.
 */

require_once(ROOT . 'lib/ui/MDJUI.php');

class ui_kit extends module
{
    public $title = 'UI Kit';
    public $module_category = '<#LANG_SECTION_SYSTEM#>';

    public function __construct()
    {
        $this->name = 'ui_kit';
        $this->checkInstalled();
    }

    public function run()
    {
        $data = MDJUI::demoData();
        $this->data = $data;

        ob_start();
        include(DIR_TEMPLATES . 'ui_kit/ui_kit.php');
        $this->result = ob_get_clean();
    }
}

