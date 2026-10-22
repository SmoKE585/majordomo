<?php

namespace MajorDoMo\Core\Template;

/**************************************************************
 * Parser
 *
 * Templates parser
 *
 * @package framework
 * @author Serge Dzheigalo <jey@activeunit.com>
 * @copyright ActiveUnit, Inc
 * @version 1.0
 */

/**
 * Parser
 * @category Templates_Parser
 * @package Framework
 * @author Serge Dzheigalo <jey@unit.local>
 * @copyright 2001-2004 Activeunit Inc
 * @license http://opensource.org/licenses/mit-license.php MIT License
 * @link https://github.com/sergejey/majordomo/blob/master/lib/core/Template/Parser.php
 *
 * Moved here from lib/parser.class.php. The legacy global class name is kept
 * alive by a class_alias in that file so legacy modules keep working.
 */
class Parser
{
    public $data;     // data
    public $template; // template-file
    public $result;   // result
    public $owner;    // parser's owner object

    /**
     * Used to parse templates with data provided
     * Based on template file extension:
     * .tpl - Smarty parser
     * .xslt - reported as unsupported (Sablotron was removed from PHP in 5.3)
     * all other extensions - jTemplates parser
     * @param mixed $template Template filename
     * @param mixed $data Data
     * @param mixed $owner Parser's owner object
     */
    public function __construct($template, &$data, $owner = "")
    {
        // set current directory for template includes
        $this->data = &$data;
        $this->template = $template;

        if (is_object($owner)) {
            $this->owner = &$owner;
        }

        if (str_ends_with($template, '.xslt')) {
            // Sablotron XSLT was removed from PHP in 5.3 and no template in the
            // project or in the module catalogue uses this format: show a marker
            // instead of calling a function that does not exist anymore.
            $this->result = '<!-- XSLT templates are not supported: ' . htmlspecialchars($template, ENT_QUOTES) . ' -->';
        } elseif (str_ends_with($template, '.tpl')) {
            $this->result = $this->smarty_parse($template, $this->data);
        } else {
            // j-Templates
            $this->result = $this->jtemplate_parse($template, $this->data);
        }

        if (is_object($this->owner)) {
            // links parsing for all results (framework support)
            $this->result = $this->owner->parseLinks($this->result);
        }
    }

    /**
     * jTemplates-parse
     * Used to parse jTemplates
     * @access private
     * @param mixed $template jTemplate filename
     * @param mixed $data Data
     * @return string
     */
    public function jtemplate_parse($template, &$data)
    {
        startMeasure('Parse template ' . $template);

        $jTempl = new JTemplate($template, $data, $this->owner);
        $result = $jTempl->result;

        endMeasure('Parse template ' . $template);

        return $result;
    }

    /**
     * SMARTY-parser
     * Used to parse SMARTY templates
     * @access private
     * @param mixed $template_file Template filename
     * @param mixed $data Data
     * @return mixed
     */
    public function smarty_parse($template_file, &$data)
    {
        if (!defined('SMARTY_DIR')) {
            define('SMARTY_DIR', ROOT . '3rdparty/smarty3/');
        }

        require_once(SMARTY_DIR . 'Smarty.class.php');
        $smarty = new \Smarty;
        $smarty->compile_dir = ROOT . 'cms/cached/templates_c/';

        if (isset($this->owner)) {
            $smarty->template_dir = DIR_TEMPLATES . $this->owner->name . "/";
        }
        $data["ROOTHTML"] = ROOTHTML;
        foreach ($data as $k => $v) {
            $smarty->assign($k, $data[$k]);
        }
        if (is_object($this->owner)) {
            $smarty->owner = $this->owner;
        }

        $result = $smarty->fetch($template_file);

        return $result;
    }
}