<?php
/** 2026-10-23 — переходы журнала к фактическому объекту, классу и сценарию без загрузки приложения. */
putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:'); putenv('USE_REDIS=false');
class module {}
require dirname(__DIR__) . '/modules/objects/objects.class.php';
$module = (new ReflectionClass(objects::class))->newInstanceWithoutConstructor();
$object = array('ID' => 1);
$checks = 0;
function check($condition): void { if (!$condition) throw new RuntimeException('Неверная ссылка журнала'); $GLOBALS['checks']++; }
$links = $module->buildTraceEventLinks(array('type' => 'property', 'property_id' => 12, 'object_id' => 2), $object, array());
check(str_contains($links[0]['url'], 'id=2&tab=properties#prop-12'));
$links = $module->buildTraceEventLinks(array('type' => 'property', 'property_id' => 12), $object, array());
check(str_contains($links[0]['url'], 'id=1&tab=properties#prop-12'));
$links = $module->buildTraceEventLinks(array('method_id' => 7, 'class_id' => 3, 'object_id' => 0), $object, array());
check(str_contains($links[0]['url'], 'action=classes') && str_contains($links[0]['url'], 'id=3&tab=methods'));
$links = $module->buildTraceEventLinks(array('method_id' => 8, 'object_id' => 2), $object, array());
check(str_contains($links[0]['url'], 'id=2&tab=methods'));
$links = $module->buildTraceEventLinks(array('script_id' => 9), $object, array());
check(str_contains($links[0]['url'], 'action=scripts') && str_contains($links[0]['url'], 'id=9'));
check($module->buildTraceEventLinks(array('module' => 'bad<script>'), $object, array()) === array());
echo 'Property trace links smoke: ' . $checks . " passed; no application boot or external connections.\n";
