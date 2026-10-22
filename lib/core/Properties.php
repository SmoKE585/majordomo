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
        private CodeExecutor $code,
        private ?ErrorReporter $errors = null
    ) {}

    /**
     * Kept for compatibility: the implementation lives in PropertyBindings.
     * New code should call Core::bindings()->detach().
     */
    public function removeLinkedModule(string $object, string $property, string $module): bool
    {
        return Core::bindings()->removeLinkedModule($object, $property, $module);
    }

    /**
     * Keeps module owner rows and pvalue labels in sync after a property rename.
     *
     * LINKED_PROPERTY in mqtt/scripts/zigbee/events is the property title, not
     * its id. Without this update the subscription stays in LINKED_MODULES but
     * every propertySetHandle() searches for the old title and does nothing.
     */
    public function renameReferences(int $propertyId, string $oldTitle, string $newTitle): void
    {
        $oldTitle = trim($oldTitle);
        $newTitle = trim($newTitle);
        if ($propertyId < 1 || $oldTitle === '' || $newTitle === '' || $oldTitle === $newTitle) return;
        if (strlen($newTitle) > 255) throw new \InvalidArgumentException('Property title is too long');

        $rows = $this->db->select('SELECT pvalues.ID,pvalues.OBJECT_ID,objects.TITLE AS OBJECT_TITLE'
            . ' FROM pvalues JOIN objects ON objects.ID=pvalues.OBJECT_ID'
            . ' WHERE pvalues.PROPERTY_ID=' . $propertyId);
        if (!$rows) return;
        $targets = array();
        foreach ($rows as $row) {
            $title = (string)($row['OBJECT_TITLE'] ?? '');
            if ($title !== '') $targets[$title] = true;
        }
        $tables = $this->db->select("SELECT DISTINCT c.TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS c"
            . " JOIN INFORMATION_SCHEMA.COLUMNS o ON o.TABLE_SCHEMA=c.TABLE_SCHEMA"
            . " AND o.TABLE_NAME=c.TABLE_NAME AND o.COLUMN_NAME='LINKED_OBJECT'"
            . " WHERE c.COLUMN_NAME='LINKED_PROPERTY' AND c.TABLE_SCHEMA=DATABASE()");

        $this->db->requireExec('START TRANSACTION');
        try {
            foreach ($tables as $table) {
                $name = (string)($table['TABLE_NAME'] ?? '');
                if (!preg_match('/^[A-Za-z0-9_]+$/D', $name)) continue;
                foreach (array_keys($targets) as $objectTitle) {
                    $this->db->requireExec('UPDATE `' . $name . "` SET LINKED_PROPERTY='" . $this->db->escape($newTitle)
                        . "' WHERE LINKED_OBJECT='" . $this->db->escape($objectTitle)
                        . "' AND LINKED_PROPERTY='" . $this->db->escape($oldTitle) . "'");
                }
            }
            foreach ($rows as $row) {
                $propertyName = (string)($row['OBJECT_TITLE'] ?? '') . '.' . $newTitle;
                $this->db->requireExec('UPDATE pvalues SET PROPERTY_NAME='
                    . "'" . $this->db->escape($propertyName) . "' WHERE ID=" . (int)$row['ID']);
            }
            $this->db->requireExec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK', true);
            throw $e;
        }

        $this->cache->clear('mjd:');
        $this->metadata->invalidate();
        if (function_exists('DebMes')) {
            DebMes('Renamed property references: ' . $oldTitle . ' -> ' . $newTitle, 'property_bindings');
        }
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

        $id = $this->resolve($object, $property, $object->class_id, $object->id);
        startMeasure('getPropertyAll');
        startMeasure('$this->get(' . $property . ')');

        $value = null;
        if ($id) {
            // Read the same row the writer updates: an overridden inherited
            // property may leave an obsolete row with the same PROPERTY_NAME.
            $value = $this->db->one("SELECT * FROM pvalues WHERE PROPERTY_ID='" . (int)$id
                . "' AND OBJECT_ID='" . (int)$object->id . "'");
            if (isset($value['ID']) && empty($value['PROPERTY_NAME']) && isset($object->object_title)) {
                $this->db->update('pvalues', array('ID' => (int)$value['ID'],
                    'PROPERTY_NAME' => $object->object_title . '.' . $property));
                $value['PROPERTY_NAME'] = $object->object_title . '.' . $property;
            }
        }

        if (!isset($value['VALUE']) && $property == 'location_title') {
            $value = array('VALUE' => current($this->db->one("SELECT TITLE FROM locations WHERE ID=" . (int)$object->location_id)));
        }

        if (!isset($value['VALUE'])) {
            // Legacy rows without a resolvable property (renamed/deleted class)
            // remain readable, but they never win over an existing active row.
            $legacy = $this->db->one("SELECT VALUE FROM pvalues WHERE PROPERTY_NAME = '"
                . $this->db->escape($object->object_title . '.' . $property) . "'");
            if (isset($legacy['VALUE'])) {
                startMeasure('getPropertyCached2');
                endMeasure('getPropertyCached2', 1);
                $value = $legacy;
            }
        }

        endMeasure('$this->get(' . $property . ')', 1);
        endMeasure('getPropertyAll', 1);
        if (!isset($value['VALUE'])) {
            $value = array('VALUE' => false);
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

        $linked_operation_errors = array();
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

            $chain = PropertyChain::current();
            $chain_token = PropertyChain::token($object->object_title, $property);
            $chain_stopped = count($chain) >= PropertyChain::MAX_LENGTH || in_array($chain_token, $chain, true);
            $next_chain = PropertyChain::append($chain, $chain_token);
            if ($chain_stopped) {
                $this->reportChainStop($object->object_title, $property, $chain);
            }

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

            if (isset($v['LINKED_MODULES']) && $v['LINKED_MODULES']) {
                if (!is_array($no_linked) && $no_linked) {
                    return;
                } elseif (!is_array($no_linked)) {
                    $no_linked = array();
                }

                $tmp = explode(',', $v['LINKED_MODULES']);
                $total = count($tmp);
                $seen = array();

                startMeasure('linkedModulesProcessing');
                for ($i = 0; $i < $total; $i++) {
                    $linked_module = trim($tmp[$i]);
                    if ($linked_module === '' || isset($no_linked[$linked_module]) || isset($seen[$linked_module])) {
                        continue;
                    }
                    $seen[$linked_module] = true;
                    startMeasure('linkedModule' . $linked_module);
                    // The worker validates availability and removes confirmed stale subscribers.
                    $params = array();
                    $params['object'] = $object->object_title;
                    $params['property'] = $property;
                    $params['value'] = $value;
                    $url = '/api/modulePropertySet/' . urlencode($linked_module);
                    try {
                        if (!Core::api()->request($url, 'GET', $params)) throw new RuntimeException('Linked operation was not queued');
                    } catch (Throwable $e) {
                        // The value is already saved. A failed notification to
                        // one module must not suppress the property's ONCHANGE.
                        $linked_operation_errors[] = $e;
                    }
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

        if ($linked_operation_errors) {
            throw new RuntimeException(implode('; ', array_map(static fn($e) => $e->getMessage(), $linked_operation_errors)), 0, $linked_operation_errors[0]);
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
