<?php
/**
 * Legacy entry point for the template parser.
 *
 * The parser itself lives in MajorDoMo\Core\Template\Parser since the template
 * layer was moved into lib/core. This file only keeps the old global class name
 * alive: modules instantiate `new parser(...)` in dozens of places and must keep
 * working, including third-party ones.
 *
 * @package MajorDoMo
 * @see \MajorDoMo\Core\Template\Parser
 */

require_once __DIR__ . '/core/Template/Parser.php';

if (!class_exists('parser', false)) {
    class_alias(\MajorDoMo\Core\Template\Parser::class, 'parser');
}
