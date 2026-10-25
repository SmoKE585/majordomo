<?php
/*
* @version 0.1 (auto-set)
*/

/**
 * 2026-10-25 — схема метаданных уведомлений для новых установок модуля.
 * @package project
 * @author Serge Dzheigalo <jey@unit.local>
 */
// modules installed control
class control_modules extends module
{
    var $modules; // all modules list

// --------------------------------------------------------------------
    function __construct()
    {
        // setting module name
        $this->name = "control_modules";
        $this->title = "<#LANG_MODULE_MODULES#>";
        $this->module_category = "<#LANG_SECTION_SYSTEM#>";
        $this->checkInstalled();
    }

// --------------------------------------------------------------------
    function saveParams($data = 1)
    {
        // saving current module data and data of all parent modules
        $p = array();
        return parent::saveParams($p);
    }

    function getParams()
    {
        global $action; // getting param
        global $mode;
        $this->mode = $mode;
        $this->action = $action;
    }

// --------------------------------------------------------------------
    function run()
    {
        // running current module
        global $mode;
        global $name;

        $rep_ext = "";
        if (preg_match('/\.dev/is', $_SERVER['HTTP_HOST'])) $rep_ext = '.dev';
        if (preg_match('/\.jbk/is', $_SERVER['HTTP_HOST'])) $rep_ext = '.jbk';
        if (preg_match('/\.bk/is', $_SERVER['HTTP_HOST'])) $rep_ext = '.bk';

        if ($rep_ext) {
            $out['LOCAL_PROJECT'] = 1;
            $out['REP_EXT'] = $rep_ext;
            $out['HOST'] = $_SERVER['HTTP_HOST'];
            $out['DOCUMENT_ROOT'] = dirname($_SERVER['SCRIPT_FILENAME']);
        }

        if ($mode == "edit") {
            include_once(DIR_MODULES . $this->name . "/module_edit.inc.php");
        }

        if ($mode == 'repository_uninstall') {
            global $module;
            $out['MODULE'] = $module;
        }

        $out["MODE"] = $mode;

        $this->getModulesList();
        $lst = $this->modules;
        $lstCnt = count($lst);

        for ($i = 0; $i < $lstCnt; $i++) {
            $rec = SQLSelectOne("SELECT *, DATE_FORMAT(ADDED, '%d.%m.%Y %H:%i') AS DAT FROM project_modules WHERE NAME='" . $lst[$i]['FILENAME'] . "'");
            if (isset($rec['ID'])) {
                outHash($rec, $lst[$i]);
            }
            $ignored = SQLSelectOne("SELECT ID FROM ignore_updates WHERE NAME LIKE '" . DBSafe($lst[$i]['FILENAME']) . "'");
            if (isset($ignored['ID'])) {
                $lst[$i]['IGNORED'] = 1;
            }
        }

        $categories = array();
        $visible_total = 0;
        $hidden_total = 0;
        foreach ($lst as $i => $row) {
            $title = isset($row['TITLE']) && trim((string)$row['TITLE']) !== ''
                ? trim((string)$row['TITLE'])
                : $row['FILENAME'];
            $title = $this->resolveLanguageString($title);

            $category = isset($row['CATEGORY']) ? trim((string)$row['CATEGORY']) : '';
            $category = $category !== '' ? $this->resolveLanguageString($category) : 'Без категории';

            $added = isset($row['ADDED']) ? trim((string)$row['ADDED']) : '';
            $added_ts = $added !== '' ? strtotime($added) : false;

            $is_hidden = !empty($row['HIDDEN']) ? 1 : 0;
            if ($is_hidden) {
                $hidden_total++;
            } else {
                $visible_total++;
            }

            $icon_path = ROOT . 'public/assets/img/modules/' . $row['FILENAME'] . '.png';
            $has_icon = is_file($icon_path) ? 1 : 0;

            $lst[$i]['TITLE_PLAIN'] = $title;
            $lst[$i]['TITLE_HTML'] = htmlspecialchars($title, ENT_QUOTES);
            $lst[$i]['NAME_HTML'] = htmlspecialchars($row['FILENAME'], ENT_QUOTES);
            $lst[$i]['FILENAME_URL'] = rawurlencode($row['FILENAME']);
            $lst[$i]['CATEGORY_PLAIN'] = $category;
            $lst[$i]['CATEGORY_HTML'] = htmlspecialchars($category, ENT_QUOTES);
            $lst[$i]['CATEGORY_KEY'] = 'cat_' . substr(md5($category), 0, 8);
            $lst[$i]['IS_HIDDEN'] = $is_hidden;
            $lst[$i]['HIDDEN_STATE'] = $is_hidden ? 'hidden' : 'visible';
            $lst[$i]['HIDDEN_LABEL'] = $is_hidden ? 'Скрыт' : 'В меню';
            $lst[$i]['ADDED_FORMATTED'] = $added_ts ? date('d.m.Y', $added_ts) : '';
            $lst[$i]['ADDED_ISO'] = $added_ts ? date('c', $added_ts) : '';
            $lst[$i]['HAS_ICON'] = $has_icon;
            $lst[$i]['ICON_URL'] = $has_icon ? ROOTHTML . 'assets/img/modules/' . rawurlencode($row['FILENAME']) . '.png' : '';
            $lst[$i]['SEARCH_TEXT_HTML'] = htmlspecialchars(
                $title . ' ' . $row['FILENAME'] . ' ' . $category . ' ' . ($added_ts ? date('d.m.Y', $added_ts) : ''),
                ENT_QUOTES
            );

            if (!isset($categories[$category])) {
                $categories[$category] = array(
                    'KEY' => $lst[$i]['CATEGORY_KEY'],
                    'TITLE' => $category,
                    'TITLE_HTML' => htmlspecialchars($category, ENT_QUOTES),
                    'TOTAL' => 0,
                );
            }
            $categories[$category]['TOTAL']++;
        }

        usort($lst, function ($a, $b) {
            $category_cmp = strcasecmp($a['CATEGORY_PLAIN'], $b['CATEGORY_PLAIN']);
            if ($category_cmp !== 0) {
                return $category_cmp;
            }
            return strcasecmp($a['TITLE_PLAIN'], $b['TITLE_PLAIN']);
        });
        uasort($categories, function ($a, $b) {
            return strcasecmp($a['TITLE'], $b['TITLE']);
        });

        $out["MODULES"] = $lst;
        $out["MODULES_TOTAL"] = count($lst);
        $out["MODULES_VISIBLE"] = $visible_total;
        $out["MODULES_HIDDEN"] = $hidden_total;
        $out["MODULES_CATEGORIES"] = count($categories);
        $out["CATEGORIES"] = array_values($categories);

        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . "/" . $this->name . ".html", $this->data, $this);
        $this->result = $p->result;

    }

    /**
     * Resolve legacy language placeholders stored in project_modules.
     *
     * Titles/categories are stored as "<#LANG_...#>" strings and normally get
     * replaced by the template parser. The redesigned UI also needs plain text
     * for search attributes, KPI counters and filter options.
     */
    function resolveLanguageString($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^<#([A-Z0-9_]+)#>$/', $value, $matches) && defined($matches[1])) {
            return constant($matches[1]);
        }
        if (preg_match_all('/<#([A-Z0-9_]+)#>/', $value, $matches)) {
            foreach ($matches[1] as $index => $constant_name) {
                if (defined($constant_name)) {
                    $value = str_replace($matches[0][$index], constant($constant_name), $value);
                }
            }
        }
        return $value;
    }

// --------------------------------------------------------------------
    function getModulesList()
    {
        $dir = openDir(DIR_MODULES);
        $lst = array();
        while ($file = readDir($dir)) {
            if ((Is_Dir(DIR_MODULES . $file)) && ($file != ".") && ($file != "..")) {
                $rec = array();
                $rec['FILENAME'] = $file;
                $lst[] = $rec;
            }
        }

        usort($lst, function ($a, $b) {
            return strcmp($a["FILENAME"], $b["FILENAME"]);
        });

        $this->modules = $lst;
        return $lst;
    }

    function install($parent_name = "")
    {
        parent::install($parent_name);

        global $db;
        if (!is_object($db) || !$db->Connect()) {
            return false;
        }

        $this->getModulesList();

        $lst = $this->modules;

        $prelist = array('settings', 'objects');
        $prelist = array_reverse($prelist);
        foreach ($prelist as $v) {
            $rec = array('FILENAME' => $v);
            array_unshift($lst, $rec);
        }

        $lstCnt = count($lst);

        SQLExec("ALTER TABLE `project_modules` CHANGE `ID` `ID` INT( 10 ) UNSIGNED NOT NULL AUTO_INCREMENT");

        for ($i = 0; $i < $lstCnt; $i++) {
            if (file_exists(DIR_MODULES . $lst[$i]['FILENAME'] . "/" . $lst[$i]['FILENAME'] . ".class.php")) {
                if ($lst[$i]['FILENAME'] == 'control_modules')
                    continue;

                $installedFile = ROOT . 'cms/modules_installed/' . $lst[$i]['FILENAME'] . ".installed";
                $errorFile = ROOT . 'cms/modules_installed/' . $lst[$i]['FILENAME'] . ".error";

                if (file_exists($installedFile)) unlink($installedFile);
                if (file_exists($errorFile) && (time()-filemtime($errorFile))>1*60*60) {
                    // reset error file in about an hour
                    unlink($errorFile);
                }

                if (!file_exists($errorFile)) {

                    startMeasure('Installing ' . $lst[$i]['FILENAME']);
                    if (!isset($_SERVER['REQUEST_METHOD'])) {
                        echo 'Installing ' . $lst[$i]['FILENAME'] . " ...";
                    }

                    DebMes('Installing ' . $lst[$i]['FILENAME'] . " ...", 'reinstall');
                    SaveFile($errorFile, date('Y-m-d H:i:s'));
                    include_once(DIR_MODULES . $lst[$i]['FILENAME'] . "/" . $lst[$i]['FILENAME'] . ".class.php");
                    $obj = "\$object$i";
                    $code = "$obj=new " . $lst[$i]['FILENAME'] . ";\n";
                    setEvalCode($code);
                    @eval("$code");
                    setEvalCode();
                    endMeasure('Installing ' . $lst[$i]['FILENAME']);
                    if (!isset($_SERVER['REQUEST_METHOD'])) {
                        echo " OK\n";
                    }
                    if (file_exists($errorFile)) {
                        // all good, removing error file
                        unlink($errorFile);
                    }
                }
            }
        }


        \MajorDoMo\Core\Core::migrations()->run();
        SQLExec("UPDATE project_modules SET HIDDEN=0 WHERE NAME LIKE '" . $this->name . "'");
    }

// --------------------------------------------------------------------
    function dbInstall($data)
    {
        $data = <<<EOD

   project_modules: ID tinyint(3) unsigned NOT NULL auto_increment
   project_modules: NAME varchar(50)  DEFAULT '' NOT NULL
   project_modules: TITLE varchar(100)  DEFAULT '' NOT NULL
   project_modules: CATEGORY varchar(50)  DEFAULT '' NOT NULL
   project_modules: PARENT_NAME varchar(50)  DEFAULT '' NOT NULL
   project_modules: DATA text
   project_modules: HIDDEN int(3)  DEFAULT '0' NOT NULL
   project_modules: PRIORITY int(10)  DEFAULT '0' NOT NULL
   project_modules: ADDED timestamp

   ignore_updates: ID tinyint(3) unsigned NOT NULL auto_increment
   ignore_updates: NAME varchar(50)  DEFAULT '' NOT NULL

   module_notifications: ID int(10) unsigned NOT NULL auto_increment
   module_notifications: MODULE_NAME char(50) NOT NULL DEFAULT ''
   module_notifications: MESSAGE varchar(255) NOT NULL DEFAULT ''
   module_notifications: TYPE char(20) NOT NULL DEFAULT 'info'
   module_notifications: IS_READ int(3) NOT NULL DEFAULT 0
   module_notifications: ADDED datetime 
   module_notifications: NOTIFICATION_KEY varchar(190) NOT NULL DEFAULT ''
   module_notifications: PAYLOAD text

EOD;
        parent::dbInstall($data);
    }

// --------------------------------------------------------------------
}
