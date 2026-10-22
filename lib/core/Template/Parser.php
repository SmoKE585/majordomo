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

        // Only one engine is supported: jTemplates. .tpl (Smarty) and .xslt
        // (Sablotron) were removed from the project — the single template that
        // used Smarty (the classes tree) is now a PHP partial.
        if (str_ends_with($template, '.xslt')) {
            $this->result = '<!-- XSLT templates are not supported: ' . htmlspecialchars($template, ENT_QUOTES) . ' -->';
        } elseif (str_ends_with($template, '.tpl')) {
            $this->result = '<!-- Smarty templates are not supported: ' . htmlspecialchars($template, ENT_QUOTES) . ' -->';
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
}