<?php

namespace MajorDoMo\Core;

use \Throwable;
use \RuntimeException;

final class Properties
{
    public function __construct(
        private Database $db,
        private Cache $cache,
        private MetadataCache $metadata,
        private PropertyLock $locks,
        private WebSocketQueue $websockets,
        private CodeExecutor $code
    ) {}

    /** Remove one subscriber without creating values or overwriting concurrent edits. */
    public function removeLinkedModule(string $object, string $property, string $module): bool
    {
        if ($object === '' || $property === '' || $module === '') {
            throw new \InvalidArgumentException('Object, property and linked module are required');
        }
        $object_row = $this->db->one("SELECT ID,CLASS_ID FROM objects WHERE TITLE='" . $this->db->escape($object) . "' LIMIT 1");
        if (!$object_row) return false;
        $property_id = $this->resolve((object)$object_row, $property, (int)$object_row['CLASS_ID'], (int)$object_row['ID']);
        if (!$property_id) return false;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $row = $this->db->one('SELECT ID,LINKED_MODULES FROM pvalues WHERE OBJECT_ID=' . (int)$object_row['ID']
                . ' AND PROPERTY_ID=' . (int)$property_id . ' LIMIT 1');
            if (!$row) return false;
            $modules = explode(',', (string)$row['LINKED_MODULES']);
            $remaining = array_filter($modules, static fn($linked) => trim($linked) !== $module);
            if (count($remaining) === count($modules)) return false;

            // Compare the exact previous list: another editor may have added a subscriber.
            $this->db->requireExec("UPDATE pvalues SET LINKED_MODULES='" . $this->db->escape(implode(',', $remaining))
                . "' WHERE ID=" . (int)$row['ID'] . " AND BINARY LINKED_MODULES=BINARY '" . $this->db->escape($row['LINKED_MODULES']) . "'", true);
            if ($this->db->affectedRows() > 0) return true;
        }
        throw new RuntimeException('Property module bindings changed concurrently; retry later');
    }

    public function get($object, $property, $cache_checked = false)
    {
        if (!$object->object_title) return false;

        $property = trim($property);
        $cached_name = 'MJD:' . $object->object_title . '.' . $property;

        if ($property == 'object_title') {
            return $object->object_title;
        } elseif ($property == 'object_description') {
            return $object->description;
        } elseif ($property == 'object_id') {
            return returnTypedValue($object->id);
        } elseif ($property == 'class_title') {
            return $object->class_title;
        }

        if (!$cache_checked) {
            $cached_value = $this->cache->get($cached_name);
            if ($cached_value !== false) {
                return returnTypedValue($cached_value);
            }
        }

        $value = $this->db->one("SELECT VALUE FROM pvalues WHERE PROPERTY_NAME = '" . $this->db->escapeLike($object->object_title . '.' . $property) . "'");
        if (isset($value['VALUE'])) {
            startMeasure('getPropertyCached2');
            endMeasure('getPropertyCached2', 1);
            endMeasure('$this->get(' . $property . ')', 1);
            endMeasure('getProperty', 1);
            $this->cache->set($cached_name, $value['VALUE'], true);
            return $value['VALUE'];
        }

        if ($property == 'location_title') {
            $value = current($this->db->one("SELECT TITLE FROM locations WHERE ID=" . (int)$object->location_id));
            $this->cache->set($cached_name, $value, true);
            return $value;
        }

        $id = $this->resolve($object, $property, $object->class_id, $object->id);
        startMeasure('getPropertyAll');
        startMeasure('$this->get(' . $property . ')');

        if ($id) {
            $value = $this->db->one("SELECT * FROM pvalues WHERE PROPERTY_ID='" . (int)$id . "' AND OBJECT_ID='" . (int)$object->id . "'");
            if (isset($value['ID']) && !$value['PROPERTY_NAME'] && isset($object->object_title)) {
                $value['PROPERTY_NAME'] = $object->object_title . '.' . $property;
                $this->db->update('pvalues', $value);
            }
        } else {
            $value['VALUE'] = false;
        }
        endMeasure('$this->get(' . $property . ')', 1);
        endMeasure('getPropertyAll', 1);
        if (!isset($value['VALUE'])) {
            $value['VALUE'] = false;
        }
        $this->cache->set($cached_name, $value['VALUE'], true);
        return returnTypedValue($value['VALUE']);

    }

    public function set($object, $property, $value, $no_linked = 0, $source = '')
    {
        if (!preg_match('/cycle/is', $property) && function_exists('verbose_log')) {
            verbose_log('Property [' . $object->object_title . '.' . $property . '] set to \'' . $value . '\'');
        }
        startMeasure('setProperty');
        startMeasure('setProperty (' . $property . ')');

        $property = trim($property);

        if (is_array($value)) $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (is_null($value)) {
            $value = '';
        }

        if (!$source && is_array($no_linked)) {
            $source = implode(',', array_keys($no_linked));
        }

        if (!$source && is_string($no_linked)) {
            $source = $no_linked;
            $no_linked = 0;
        }
        if (defined('CALL_SOURCE')) {
            $source .= ' ' . CALL_SOURCE;
        }
        if (isset($_SERVER['REQUEST_URI'])) {
            $source .= ' ' . urldecode($_SERVER['REQUEST_URI']);
        }
        $source = str_replace("\n", '', $source);
        if (mb_strlen($source) > 250) {
            $source = mb_substr($source, 0, 250) . '...';
        }

        if (Config::bool('TRACK_DATA_CHANGES')) {
            $save = 1;

            if (!is_numeric(trim($value))) {
                $save = 0;
            }

            $trackIgnore = Config::string('TRACK_DATA_CHANGES_IGNORE');
            if ($trackIgnore !== '' && $save) {
                $tmp = explode(',', $trackIgnore);
                $total = count($tmp);
                for ($i = 0; $i < $total; $i++) {
                    $regex = trim($tmp[$i]);
                    if (preg_match('/' . $regex . '/is', $object->object_title . '.' . $property)) {
                        $save = 0;
                        break;
                    }
                }
            }
            if ($save) {
                if ($object->location_id) {
                    $location = current($this->db->one("SELECT TITLE FROM locations WHERE ID=" . (int)$object->location_id));
                } else {
                    $location = '';
                }

                if (defined('SETTINGS_SYSTEM_DEBMES_PATH') && SETTINGS_SYSTEM_DEBMES_PATH != '') {
                    $path = SETTINGS_SYSTEM_DEBMES_PATH;
                } elseif (($logDirectory = Config::string('LOG_DIRECTORY')) !== '') {
                    $path = $logDirectory;
                } else {
                    $path = ROOT . 'cms/debmes';
                }

                $today_file = $path . '/' . date('Y-m-d') . '.data';
                $f = fopen($today_file, "a+");
                if ($f) {
                    fputs($f, date("Y-m-d H:i:s"));
                    fputs($f, "\t" . $object->object_title . '.' . $property . "\t" . trim($value) . "\t" . trim($source) . "\t" . trim($location) . "\n");
                    fclose($f);
                    @chmod($today_file, 0666);
                }
            }
        }

        $property_lock = $this->locks->acquire($object->id, $property);
        try {
            startMeasure('getPropertyByName');
            $id = $this->resolve($object, $property, $object->class_id, $object->id);
            endMeasure('getPropertyByName');
            $old_value = '';
            $v = array();

            $cached_name = 'MJD:' . $object->object_title . '.' . $property;

            startMeasure('setproperty_update');
            if ($id) {
                $prop = $this->metadata->get('property:' . (int)$id, function () use ($id) { return $this->db->one("SELECT * FROM properties WHERE ID=" . (int)$id); });

                if ($prop['VALIDATION_TYPE'] == 1) {
                    if (!is_numeric($value)) return false;
                    if ($prop['VALIDATION_NUM_MIN'] != '' && (float)$value < (float)$prop['VALIDATION_NUM_MIN']) {
                        return false;
                    }
                    if ($prop['VALIDATION_NUM_MAX'] != '' && (float)$value > (float)$prop['VALIDATION_NUM_MAX']) {
                        return false;
                    }
                }
                if ($prop['VALIDATION_TYPE'] == 2) {
                    if ($value != '1' && $value != '0') {
                        return false;
                    }
                }
                if ($prop['VALIDATION_TYPE'] == 3) {
                    $items = explode(',', $prop['VALIDATION_LIST']);
                    if (!in_array(mb_strtolower($value, 'UTF-8'), $items)) return false;
                }
                if ($prop['VALIDATION_TYPE'] == 100) {
                    setEvalCode($prop['VALIDATION_CODE']);
                    $variables = array('value' => &$value, 'property' => &$property, 'no_linked' => &$no_linked, 'source' => &$source);
                    $this->code->evaluate($object, $prop['VALIDATION_CODE'], $variables);
                    setEvalCode();
                    if (is_null($value)) return false;
                }

                $property = $prop['TITLE'];
                startMeasure('setproperty_update_getvalue');
                $v = $this->db->one("SELECT * FROM pvalues WHERE PROPERTY_ID=" . (int)$id . " AND OBJECT_ID=" . (int)$object->id);
                endMeasure('setproperty_update_getvalue');
                $old_value = isset($v['VALUE']) ? $v['VALUE'] : false;

                if ($prop['DATA_TYPE'] == 5 && $value != $old_value) { // image
                    $path_parts = pathinfo($value);
                    $extension = strtolower($path_parts['extension']);
                    if ($extension != 'jpg' && $extension != 'jpeg' && $extension != 'png' && $extension != 'gif') {
                        $extension = 'jpg';
                    }
                    $image_file_name = date('Ymd_His') . '.' . $extension;
                    if (preg_match('/^http.+/', $value)) {
                        $image_data = getURL($value);
                        @mkdir(ROOT . 'cms/images/' . $prop['ID'], 0777);
                        SaveFile(ROOT . 'cms/images/' . $prop['ID'] . '/' . $image_file_name, $image_data);
                        $value = $prop['ID'] . '/' . $image_file_name;
                    } elseif (file_exists($value)) {
                        @mkdir(ROOT . 'cms/images/' . $prop['ID'], 0777);
                        copyFile($value, ROOT . 'cms/images/' . $prop['ID'] . '/' . $image_file_name);
                        $value = $prop['ID'] . '/' . $image_file_name;
                    } else {
                        $value = '';
                    }
                    if ($value != '' && file_exists(ROOT . 'cms/images/' . $value)) {
                        $lst = GetImageSize(ROOT . 'cms/images/' . $value);
                        //$image_width=$lst[0];
                        //$image_height=$lst[1];
                        $image_format = $lst[2];
                        if (!$image_format) {
                            @unlink(ROOT . 'cms/images/' . $value);
                            $value = '';
                        }
                    } else {
                        $value = '';
                    }
                    if ($value != '' && $old_value != '' && !$prop['KEEP_HISTORY'] && file_exists(ROOT . 'cms/images/' . $old_value)) {
                        @unlink(ROOT . 'cms/images/' . $old_value);
                    }
                    if ($value == '') $value = $old_value;
                }

                $this->locks->check($property_lock);
                $v['VALUE'] = $value . '';
                $v['SOURCE'] = $source . '';
                if (!isset($v['PROPERTY_NAME'])) {
                    $v['PROPERTY_NAME'] = $object->object_title . '.' . $property;
                }
                if (isset($v['ID'])) {
                    $v['UPDATED'] = date('Y-m-d H:i:s');
                    $update = array('ID' => $v['ID'], 'VALUE' => $v['VALUE'], 'SOURCE' => $v['SOURCE'], 'UPDATED' => $v['UPDATED'], 'PROPERTY_NAME' => $v['PROPERTY_NAME']);
                    if (!$this->db->update('pvalues', $update)) throw new RuntimeException('Property value was not saved');
                } else {
                    $v['PROPERTY_ID'] = $id;
                    $v['OBJECT_ID'] = $object->id;
                    $v['VALUE'] = $value . '';
                    $v['SOURCE'] = $source . '';
                    $v['UPDATED'] = date('Y-m-d H:i:s');
                    $v['ID'] = $this->db->insert('pvalues', $v);
                    if (!$v['ID']) throw new RuntimeException('Property value was not created');
                }
            } else {
                $prop = array();
                $prop['OBJECT_ID'] = $object->id;
                $prop['TITLE'] = $property;
                $prop['ID'] = $this->db->insert('properties', $prop);

                $v['PROPERTY_NAME'] = $object->object_title . '.' . $property;
                $v['PROPERTY_ID'] = $prop['ID'];
                $v['OBJECT_ID'] = $object->id;
                $v['VALUE'] = $value . '';
                $v['SOURCE'] = $source . '';
                $v['UPDATED'] = date('Y-m-d H:i:s');
                $v['ID'] = $this->db->insert('pvalues', $v);
                if (!$v['ID']) throw new RuntimeException('Property value was not created');
            }
            endMeasure('setproperty_update');

            $this->cache->set($cached_name, $value);

            $skip_notifications = !empty($v['LINKED_MODULES']) && !is_array($no_linked) && $no_linked;
            if (!$skip_notifications && function_exists('postToWebSocketQueue')) {
                startMeasure('setproperty_postwebsocketqueue');
                if ($old_value !== $value) {
                    $this->websockets->post($object->object_title . '.' . $property, $value);
                }
                endMeasure('setproperty_postwebsocketqueue');
            }

            if (!$skip_notifications && isset($prop['KEEP_HISTORY']) && ($prop['KEEP_HISTORY'] > 0)) {
                $q_rec = array();
                $q_rec['VALUE_ID'] = $v['ID'];
                $q_rec['ADDED'] = date('Y-m-d H:i:s');
                $q_rec['VALUE'] = $value . '';
                $q_rec['SOURCE'] = $source . '';
                $q_rec['OLD_VALUE'] = $old_value;
                $q_rec['KEEP_HISTORY'] = $prop['KEEP_HISTORY'];
                if (!$this->db->insert('phistory_queue', $q_rec)) throw new RuntimeException('Property history was not queued');
            }

            if (isset($v['LINKED_MODULES']) && $v['LINKED_MODULES']) { // TO-DO !
                if (!is_array($no_linked) && $no_linked) {
                    return;
                } elseif (!is_array($no_linked)) {
                    $no_linked = array();
                }

                $tmp = explode(',', $v['LINKED_MODULES']);
                $total = count($tmp);

                startMeasure('linkedModulesProcessing');
                for ($i = 0; $i < $total; $i++) {
                    $linked_module = trim($tmp[$i]);
                    if ($linked_module === '' || isset($no_linked[$linked_module])) {
                        continue;
                    }
                    startMeasure('linkedModule' . $linked_module);
                    // The worker validates availability and removes confirmed stale subscribers.
                    $params = array();
                    $params['object'] = $object->object_title;
                    $params['property'] = $property;
                    $params['value'] = $value;
                    $url = '/api/modulePropertySet/' . urlencode($linked_module);
                    if (!Core::api()->request($url, 'GET', $params)) throw new RuntimeException('Linked operation was not queued');
                    endMeasure('linkedModule' . $linked_module);
                }
                endMeasure('linkedModulesProcessing');
            }

        } catch (Throwable $e) {
            $this->cache->delete($cached_name ?? ('MJD:' . $object->object_title . '.' . $property));
            throw $e;
        } finally {
            $this->locks->release($property_lock);
        }

        if (isset($prop['ONCHANGE']) && $prop['ONCHANGE']) {
            global $property_linked_history;
            if (!isset($property_linked_history[$object->object_title . '.' . $property][$prop['ONCHANGE']])) {
                $property_linked_history[$object->object_title . '.' . $property][$prop['ONCHANGE']] = 1;
                $params = array();
                $params['PROPERTY'] = $property;
                $params['NEW_VALUE'] = (string)$value;
                $params['OLD_VALUE'] = (string)$old_value;
                $params['SOURCE'] = (string)$source;
                $params['NO_LINKED'] = $no_linked;
                //$object->callMethod($prop['ONCHANGE'], $params);
                //$object->callMethodSafe($prop['ONCHANGE'], $params);
                try {
                    if (isset($_SERVER['REQUEST_URI']) && ($_SERVER['REQUEST_URI'] != '')) {
                        $object->callMethod($prop['ONCHANGE'], $params);
                    } else {
                        $object->raiseEvent($prop['ONCHANGE'], $params);
                    }
                } finally {
                    unset($property_linked_history[$object->object_title . '.' . $property][$prop['ONCHANGE']]);
                }
            }
        }

        endMeasure('setProperty (' . $property . ')', 1);
        endMeasure('setProperty', 1);

    }

    public function resolve($object, $name, $class_id, $object_id)
    {
        $record = $this->metadata->get('properties-resolution:' . (int)$class_id . ':' . (int)$object_id . ':' . strtolower($name), function () use ($name, $class_id, $object_id) {
                if ($object_id) {
                    $row = $this->db->one("SELECT ID FROM properties WHERE OBJECT_ID=" . (int)$object_id . " AND TITLE='" . $this->db->escape($name) . "' LIMIT 1");
                    if (isset($row['ID'])) return $row;
                }
                $visited = array();
                while ($class_id && !isset($visited[$class_id])) {
                    $visited[$class_id] = true;
                    $row = $this->db->one("SELECT ID FROM properties WHERE CLASS_ID=" . (int)$class_id . " AND OBJECT_ID=0 AND TITLE='" . $this->db->escape($name) . "' LIMIT 1");
                    if (isset($row['ID'])) return $row;
                    $class = $this->db->one("SELECT PARENT_ID FROM classes WHERE ID=" . (int)$class_id);
                    $class_id = (int)($class['PARENT_ID'] ?? 0);
                }
                return array();
            });
        return $record['ID'] ?? false;

    }
}
