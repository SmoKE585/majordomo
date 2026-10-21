<?php
require dirname(__DIR__) . '/bootstrap.php';

/*
 * @version 0.1 (auto-set)
 */

/**
 * 404-error handler
 *
 * @package MajorDoMo
 * @author Serge Dzheigalo <sergejey@gmail.com> https://majordomohome.com/
 */
list($usec, $sec) = explode(" ",microtime());
$script_started_time = ((float)$usec + (float)$sec);


$requestUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH);
if (!is_string($requestPath) || $requestPath === '') {
    $requestPath = '/';
}

$file = '';
if (!preg_match('/\/$/', $requestPath)) {
    $file = basename($requestPath);
}

$ext = strtolower(substr($file, -3));

if ($ext == 'jpg' || $ext == 'gif' || $ext == 'css')
{
   header("HTTP/1.0 404 Page not found\n");
   exit;
}

if (preg_match('/\/ajax\//', $requestUri)) {
    Define('ALLOW_RUNNING_WITH_ERRORS', 1);
}

if (preg_match("/\?(.*?)$/", $requestUri, $matches))
   $redir_qry = $matches[1];

$file = preg_replace("/\.htm.*$/", '', $file);

if ($file != '')
   $fake_doc = $file;

include_once("./config.php");
include_once("./lib/admin_router.php");

// URL conversion rules live in lib/admin_router.php and are shared with the
// outbound link prettifier, so inbound and outbound routing can never drift.
$rootHTML = ROOTHTML;
$requests = mdj_admin_route_table($rootHTML);

// Admin root: /panel/ and /panel (no .html suffix).
$requestPath = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
$panelRoot = rtrim(ROOTHTML, '/') . '/panel/';
if ($requestPath === $panelRoot || $requestPath === rtrim($panelRoot, '/')) {
    include_once(__DIR__ . "/admin.php");
    exit;
}

$found=0;
foreach($requests as $key => $value)
{
   if (!$found && preg_match($key, $_SERVER["REQUEST_URI"], $matches))
   {
      $link = $value;

      $matchesCount = count($matches);
      
      for ($i = 1; $i < $matchesCount; $i++)
      {
         $link = str_replace("\\$i", $matches[$i], $link);
      }
  
      $link  = preg_replace('/\\\\(\d+?)/is', '', $link);
      $found = 1;
   }
}

if (isset($link) && preg_match('/^moved:(.+)/is', $link, $matches))
{
   Header("HTTP/1.1 301 Moved Permanently");
   header("Location:" . $matches[1]);
   exit;
}

if (!isset($link))
{
   include_once("./lib/error_page.php");
   mdj_render_404(array(
      'root' => ROOTHTML,
      'area' => 'site',
      'path' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
   ));
}

include_once("./lib/perfmonitor.class.php");

startMeasure('TOTAL');
include_once("./config.php");
startMeasure('loader');
include_once("./lib/loader.php");
endMeasure('loader');

$requestQuery = mdj_admin_target_to_query($link);
// Route targets only provide the default state of a pretty URL. Explicit
// request params must win: module AJAX endpoints are called relative to the
// current pretty page (for example /panel/script/10.html?view_mode=run_script_ajax)
// and the route's view_mode must not overwrite them.
$explicitParams = array_merge($_GET, $_POST);
foreach ($requestQuery as $key => $value) {
   if (array_key_exists($key, $explicitParams)) {
      $_REQUEST[$key] = $explicitParams[$key];
      ${$key} = $explicitParams[$key];
      continue;
   }
   $_REQUEST[$key] = $value;
   ${$key} = $value;
}
if (!isset($_REQUEST['pd'])) {
   $_REQUEST['pd'] = '';
}

if (preg_match('/^' . preg_quote($rootHTML, '/') . 'panel\//is', $_SERVER['REQUEST_URI']))
   include_once(__DIR__ . "/admin.php");
else
   include_once(__DIR__ . "/index.php");
