<?php
/**
 * Configuration bootstrap.
 *
 * Settings live in `.env` (documented in `.env.example`). This file remains a
 * stable include target for legacy entry points and third-party modules; do not
 * add settings here.
 *
 * @package MajorDoMo
 */

require_once __DIR__ . '/lib/core.class.php';

\MajorDoMo\Core\Config::boot(__DIR__);

// Legacy globals expected by old modules. Values are declared in .env.
$restart_threads = \MajorDoMo\Core\Config::arrayValue('RESTART_THREADS');
$aditional_git_urls = \MajorDoMo\Core\Config::arrayValue('ADITIONAL_GIT_URLS');
