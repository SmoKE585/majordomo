<?php
/**
 * Code editor
 *
 * Code editor
 *
 * @package project
 * @author Serge J. <sergejey@gmail.com>
 * @copyright https://majordomohome.com/ (c)
 * @version 0.1 (wizard, 14:09:29 [Sep 01, 2014])
 */
//
//
class blockly_code extends module
{
    var $type;
    var $autofocus;
    var $onlycode;
    var $system_name;

    /**
     * blockly_code
     *
     * Module class constructor
     *
     * @access private
     */
    function __construct()
    {
        $this->name = "blockly_code";
        $this->title = "Code editor";
        $this->module_category = "<#LANG_SECTION_SYSTEM#>";
        $this->checkInstalled();
    }

    function normalizeCodeType($code_type)
    {
        $code_type = (int)$code_type;
        return in_array($code_type, array(0, 1), true) ? $code_type : 0;
    }

    function detectEditorMode($code, $type = 'php')
    {
        $type = strtolower((string)$type);
        if ($type === 'html') {
            return 'htmlmixed';
        }
        if ($type === 'javascript') {
            return 'javascript';
        }
        if ($type === 'css') {
            return 'css';
        }
        if ($type === 'xml') {
            return 'xml';
        }
        if (defined('PYTHON_PATH') && isItPythonCode($code)) {
            return 'python';
        }
        return 'php';
    }

    function buildEditorKey()
    {
        $key = trim((string)$this->system_name);
        if ($key === '') {
            $key = $this->code_field . '_' . (int)$this->id;
        }
        $key = preg_replace('/[^a-zA-Z0-9_\-:.]/', '_', $key);
        return $key !== '' ? $key : 'codeeditor_' . (int)$this->id;
    }

    /**
     * saveParams
     *
     * Saving module parameters
     *
     * @access public
     */
    function saveParams($data = 0)
    {
        $p = array();
        if (isset($this->id)) {
            $p["id"] = $this->id;
        }
        if (isset($this->view_mode)) {
            $p["view_mode"] = $this->view_mode;
        }
        if (isset($this->edit_mode)) {
            $p["edit_mode"] = $this->edit_mode;
        }
        if (isset($this->tab)) {
            $p["tab"] = $this->tab;
        }
        return parent::saveParams($p);
    }

    /**
     * getParams
     *
     * Getting module parameters from query string
     *
     * @access public
     */
    function getParams()
    {
        global $id;
        global $mode;
        global $view_mode;
        global $edit_mode;
        global $tab;
        if (isset($id)) {
            $this->id = $id;
        }
        if (isset($mode)) {
            $this->mode = $mode;
        }
        if (isset($view_mode)) {
            $this->view_mode = $view_mode;
        }
        if (isset($edit_mode)) {
            $this->edit_mode = $edit_mode;
        }
        if (isset($tab)) {
            $this->tab = $tab;
        }
    }

    /**
     * Run
     *
     * Description
     *
     * @access public
     */
    function run()
    {
        global $session;
        $out = array();
        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }
        if (isset($this->owner->action)) {
            $out['PARENT_ACTION'] = $this->owner->action;
        }
        if (isset($this->owner->name)) {
            $out['PARENT_NAME'] = $this->owner->name;
        }
        $out['VIEW_MODE'] = $this->view_mode;
        $out['EDIT_MODE'] = $this->edit_mode;
        $out['MODE'] = $this->mode;
        $out['ACTION'] = $this->action;
        $out['TAB'] = $this->tab;
        if ($this->single_rec) {
            $out['SINGLE_REC'] = 1;
        }
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . "/" . $this->name . ".html", $this->data, $this);
        $this->result = $p->result;
    }

    /**
     * BackEnd
     *
     * Module backend
     *
     * @access public
     */
    function admin(&$out)
    {
        if (!$this->code_field) {
            $this->code_field = 'code';
        }

        if ($this->type) {
            $out['TYPE'] = $this->type;
        } else {
            $out['TYPE'] = 'php';
        }

        if ($this->autofocus) {
            $out['AUTOFOCUS'] = $this->autofocus;
        }

        $out['CODE_FIELD'] = $this->code_field;
        $out['ONLYCODE'] = $this->onlycode;
        $out['CODE_EDITOR_KEY'] = $this->buildEditorKey();
        //Code editor settings
        $settings = array(
            'AUTOCLOSEQUOTES' => 1,
            'WRAPLINES' => 0,
            'SHOWERROR' => 0,
            'UPTOLINE' => 0,
            'THEME' => 'codemirror',
            'MIXLINE' => 20,
            'SHOWLINE' => 20,
            'TURNONSETTINGS' => 0,
            'AUTOSAVE' => 0);

        foreach ($settings as $setting => $default) {
            if (isset($this->{strtolower($setting)})) {
                $out['SETTINGS_CODEEDITOR_' . $setting] = $this->{strtolower($setting)};
            } elseif (defined('SETTINGS_CODEEDITOR_' . $setting)) {
                $out['SETTINGS_CODEEDITOR_' . $setting] = constant('SETTINGS_CODEEDITOR_' . $setting);
            } else {
                $out['SETTINGS_CODEEDITOR_' . $setting] = $default;
            }
        }

        $out['CODE_EDITOR_MODE'] = $this->detectEditorMode('', $out['TYPE']);
        $out['CODE_EDITOR_VALIDATE'] = in_array($out['CODE_EDITOR_MODE'], array('php', 'python'), true) ? 1 : 0;


        /*
		(defined('SETTINGS_CODEEDITOR_AUTOCLOSEQUOTES')) ? $out['SETTINGS_CODEEDITOR_AUTOCLOSEQUOTES'] = SETTINGS_CODEEDITOR_AUTOCLOSEQUOTES : $out['SETTINGS_CODEEDITOR_AUTOCLOSEQUOTES'] = 1;
		(defined('SETTINGS_CODEEDITOR_AUTOCLOSEQUOTES')) ? $out['SETTINGS_CODEEDITOR_AUTOCLOSEQUOTES'] = SETTINGS_CODEEDITOR_AUTOCLOSEQUOTES : $out['SETTINGS_CODEEDITOR_AUTOCLOSEQUOTES'] = 1;
		(defined('SETTINGS_CODEEDITOR_WRAPLINES')) ? $out['SETTINGS_CODEEDITOR_WRAPLINES'] = SETTINGS_CODEEDITOR_WRAPLINES : $out['SETTINGS_CODEEDITOR_WRAPLINES'] = 0;
		(defined('SETTINGS_CODEEDITOR_SHOWERROR')) ? $out['SETTINGS_CODEEDITOR_SHOWERROR'] = SETTINGS_CODEEDITOR_SHOWERROR : $out['SETTINGS_CODEEDITOR_SHOWERROR'] = 0;
		(defined('SETTINGS_CODEEDITOR_UPTOLINE')) ? $out['SETTINGS_CODEEDITOR_UPTOLINE'] = SETTINGS_CODEEDITOR_UPTOLINE : $out['SETTINGS_CODEEDITOR_UPTOLINE'] = 0;
		(defined('SETTINGS_CODEEDITOR_THEME')) ? $out['SETTINGS_CODEEDITOR_THEME'] = SETTINGS_CODEEDITOR_THEME : $out['SETTINGS_CODEEDITOR_THEME'] = 'codemirror';
		(defined('SETTINGS_CODEEDITOR_MIXLINE')) ? $out['SETTINGS_CODEEDITOR_MIXLINE'] = SETTINGS_CODEEDITOR_MIXLINE : $out['SETTINGS_CODEEDITOR_MIXLINE'] = '20';
		(defined('SETTINGS_CODEEDITOR_SHOWLINE')) ? $out['SETTINGS_CODEEDITOR_SHOWLINE'] = SETTINGS_CODEEDITOR_SHOWLINE : $out['SETTINGS_CODEEDITOR_SHOWLINE'] = '20';
		(defined('SETTINGS_CODEEDITOR_TURNONSETTINGS')) ? $out['SETTINGS_CODEEDITOR_TURNONSETTINGS'] = SETTINGS_CODEEDITOR_TURNONSETTINGS : $out['SETTINGS_CODEEDITOR_TURNONSETTINGS'] = '0';
		(defined('SETTINGS_CODEEDITOR_AUTOCOMPLETE')) ? $out['SETTINGS_CODEEDITOR_AUTOCOMPLETE'] = SETTINGS_CODEEDITOR_AUTOCOMPLETE : $out['SETTINGS_CODEEDITOR_AUTOCOMPLETE'] = '0';
		(defined('SETTINGS_CODEEDITOR_AUTOSAVE')) ? $out['SETTINGS_CODEEDITOR_AUTOSAVE'] = SETTINGS_CODEEDITOR_AUTOSAVE : $out['SETTINGS_CODEEDITOR_AUTOSAVE'] = '0';
        */
        $rec = SQLSelectOne("SELECT * FROM blockly_code WHERE SYSTEM_NAME LIKE '" . DBSafe($this->system_name) . "'");
        if (isset($rec['CODE_TYPE'])) {
            $rec['CODE_TYPE'] = $this->normalizeCodeType($rec['CODE_TYPE']);
            $out['CODE_TYPE'] = $rec['CODE_TYPE'];
        }
        if (!isset($rec['ID']) && isset($this->owner->xml)) {
            $rec['XML'] = $this->owner->xml;
        }
        $out['CODE_EDITOR_MODE'] = $this->detectEditorMode(isset($rec['CODE']) ? $rec['CODE'] : '', $out['TYPE']);
        $out['CODE_EDITOR_VALIDATE'] = in_array($out['CODE_EDITOR_MODE'], array('php', 'python'), true) ? 1 : 0;
        if (isset($rec['XML']) && preg_match_all('/<block type="(.+?)\_turnoff"(.+?)>/uis', $rec['XML'], $m)) {
            $total = count($m[0]);
            for ($i = 0; $i < $total; $i++) {
                $new_line = $m[0][$i];
                $closed_block = 0;
                if (preg_match('/\/>/', $new_line)) {
                    $closed_block = 1;
                }
                $new_line = str_replace('_turnOff', '_switch', $new_line);
                $new_line .= "\n<field name=\"MODE\">OFF</field>";
                if ($closed_block) {
                    $new_line = str_replace('/>', '>', $new_line);
                    $new_line .= "\n</block>";
                }
                $rec['XML'] = str_replace($m[0][$i], $new_line, $rec['XML']);
            }
        }
        if (isset($rec['XML']) && preg_match_all('/<block type="(.+?)\_turnOn"(.+?)>/uis', $rec['XML'], $m)) {
            $total = count($m[0]);
            for ($i = 0; $i < $total; $i++) {
                $new_line = $m[0][$i];
                $closed_block = 0;
                if (preg_match('/\/>/', $new_line)) {
                    $closed_block = 1;
                }
                $new_line = str_replace('_turnOn', '_switch', $new_line);
                $new_line .= "\n<field name=\"MODE\">ON</field>";
                if ($closed_block) {
                    $new_line = str_replace('/>', '>', $new_line);
                    $new_line .= "\n</block>";
                }
                $rec['XML'] = str_replace($m[0][$i], $new_line, $rec['XML']);
            }
        }

        if (!isset($rec['ID']) && !$this->type) {
            $rec['CODE_TYPE'] = 0;
            $rec['CODE_TYPE_UNKNOWN'] = 1;
        }


        if ($_SERVER['REQUEST_METHOD'] == 'POST' && $out['TYPE'] == 'php') {
            $code_type = $this->normalizeCodeType(gr($this->code_field . "_code_type", 'int'));
            $rec = SQLSelectOne("SELECT * FROM blockly_code WHERE SYSTEM_NAME LIKE '" . DBSafe($this->system_name) . "'");
            $old_rec = $rec;
            $rec['XML'] = gr('xml');
            $rec['CODE'] = gr('code');
            $rec['UPDATED'] = date('Y-m-d H:i:s');
            $rec['SYSTEM_NAME'] = $this->system_name;
            $rec['CODE_TYPE'] = $code_type;
            $valid_code = 1;
            if ($rec['CODE'] != '') {
                $errorDetails = code_syntax_error_details($rec['CODE']);
                if ($errorDetails) {
                    $valid_code = 0;
                    $out['ERR_LINE'] = (int)$errorDetails['line'];
                    $out['ERR_CODE'] = 1;
                    $out['ERRORS'] = $errorDetails['message'];
                    $out['ERR_FULL'] = $errorDetails['full'];
                    $out['ERR_OLD_CODE'] = isset($old_rec['CODE']) ? $old_rec['CODE'] : '';
                }
            }
            if ($rec['CODE'] == '' && $rec['XML'] == '') {
                $valid_code = 0;
            }
            if ($valid_code) {
                if (isset($rec['ID'])) {
                    if ($old_rec['CODE'] != $rec['CODE'] || $old_rec['CODE_TYPE'] != $rec['CODE_TYPE'] || $old_rec['XML'] != $rec['XML']) {
                        $old_rec['ADDED'] = $rec['UPDATED'];
                        unset($old_rec['ID']);
                        unset($old_rec['UPDATED']);
                        SQLInsert('blockly_code_history', $old_rec);
                    }
                    SQLUpdate('blockly_code', $rec);
                } else {
                    $rec['ID'] = SQLInsert('blockly_code', $rec);
                }
            }
        }

        if (isset($rec['XML'])) {
            $rec['XML'] = preg_replace('/id="\?/', 'id="Q', $rec['XML']);
            $out['XML'] = $rec['XML'];
        }

        $out['CODE_TYPE'] = isset($rec['CODE_TYPE']) ? $this->normalizeCodeType($rec['CODE_TYPE']) : 0;



        if (isset($this->data_source) && !$_GET['data_source'] && !$_POST['data_source']) {
            $out['SET_DATASOURCE'] = 1;
        }
        if ($this->data_source == 'blockly_code' || $this->data_source == '') {
            if ($this->view_mode == '' || $this->view_mode == 'search_blockly_code') {
                $this->search_blockly_code($out);
            }
            if ($this->view_mode == 'edit_blockly_code') {
                $this->edit_blockly_code($out, $this->id);
            }
            if ($this->view_mode == 'delete_blockly_code') {
                $this->delete_blockly_code($this->id);
                $this->redirect("?");
            }
        }

        if ($this->system_name) {
            $code_history = SQLSelect("SELECT * FROM blockly_code_history WHERE SYSTEM_NAME LIKE '" . DBSafe($this->system_name) . "' ORDER BY ADDED DESC");
            if (isset($code_history[0])) {
                foreach ($code_history as $idx => $historyRec) {
                    $code_history[$idx]['CODE_B64'] = base64_encode((string)$historyRec['CODE']);
                }
                $out['HISTORY_AVAILABLE'] = 1;
                $out['CODE_HISTORY'] = $code_history;
            }
        }

    }

    /**
     * FrontEnd
     *
     * Module frontend
     *
     * @access public
     */
    function usual(&$out)
    {
        $this->admin($out);
    }

    /**
     * blockly_code search
     *
     * @access public
     */
    function search_blockly_code(&$out)
    {
        require(DIR_MODULES . $this->name . '/blockly_code_search.inc.php');
    }

    /**
     * blockly_code edit/add
     *
     * @access public
     */
    function edit_blockly_code(&$out, $id)
    {
        require(DIR_MODULES . $this->name . '/blockly_code_edit.inc.php');
    }

    /**
     * blockly_code delete record
     *
     * @access public
     */
    function delete_blockly_code($id)
    {
        $rec = SQLSelectOne("SELECT * FROM blockly_code WHERE ID='$id'");
        // some action for related tables
        SQLExec("DELETE FROM blockly_code WHERE ID='" . $rec['ID'] . "'");
    }

    /**
     * Install
     *
     * Module installation routine
     *
     * @access private
     */
    function install($data = '')
    {
        parent::install();
        SQLExec("UPDATE project_modules SET HIDDEN=1 WHERE NAME LIKE 'blockly_code'");
    }

    /**
     * Uninstall
     *
     * Module uninstall routine
     *
     * @access public
     */
    function uninstall()
    {
        SQLDropTable('blockly_code');
        parent::uninstall();
    }

    /**
     * dbInstall
     *
     * Database installation routine
     *
     * @access private
     */
    function dbInstall($data)
    {
        /*
        blockly_code - Code editor
        */
        $data = <<<EOD
 blockly_code: ID int(10) unsigned NOT NULL auto_increment
 blockly_code: SYSTEM_NAME varchar(255) NOT NULL DEFAULT ''
 blockly_code: CODE_TYPE int(3) NOT NULL DEFAULT '0'
 blockly_code: CODE text
 blockly_code: XML text
 blockly_code: UPDATED datetime
 
 blockly_code_history: ID int(10) unsigned NOT NULL auto_increment
 blockly_code_history: SYSTEM_NAME varchar(255) NOT NULL DEFAULT ''
 blockly_code_history: CODE_TYPE int(3) NOT NULL DEFAULT '0'
 blockly_code_history: CODE text
 blockly_code_history: XML text
 blockly_code_history: ADDED datetime 
EOD;
        parent::dbInstall($data);
    }
// --------------------------------------------------------------------
}

/*
*
* TW9kdWxlIGNyZWF0ZWQgU2VwIDAxLCAyMDE0IHVzaW5nIFNlcmdlIEouIHdpemFyZCAoQWN0aXZlVW5pdCBJbmMgd3d3LmFjdGl2ZXVuaXQuY29tKQ==
*
*/
?>
