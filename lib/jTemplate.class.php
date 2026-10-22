<?php
/**
 * Legacy entry point for the jTemplate engine.
 *
 * The engine itself lives in MajorDoMo\Core\Template\JTemplate since the template
 * layer was moved into lib/core. This file only keeps the old global class name
 * alive: third-party modules (and old code around the project) instantiate
 * `new jTemplate(...)` directly and must keep working.
 *
 * @package MajorDoMo
 * @see \MajorDoMo\Core\Template\JTemplate
 */

require_once __DIR__ . '/core/Template/JTemplate.php';

if (!class_exists('jTemplate', false)) {
    class_alias(\MajorDoMo\Core\Template\JTemplate::class, 'jTemplate');
}
