<?php

namespace MajorDoMo\Core;

use \RuntimeException;

final class Objects
{
    public function __construct(
        private Database $db,
        private MetadataCache $metadata,
        private Cache $cache,
        private Properties $properties,
        private PropertyLock $locks
    ) {}

    public function value($varname)
    {
        $varname = trim((string)$varname);
        $tmp = explode('.', $varname);

        $class_name = '';
        if (isset($tmp[2])) {
            $class_name = $tmp[0];
            $object_name = $tmp[0] . '.' . $tmp[1];
            $varname = $tmp[2];
        } elseif (isset($tmp[1])) {
            $object_name = $tmp[0];
            $varname = $tmp[1];
        } else {
            $object_name = 'ThisComputer';
        }
        $cached_name = 'MJD:' . $object_name . '.' . $varname;

        if (strpos($varname, 'cycle_') === 0) {
            $cached_value = $this->cache->cycle($varname);
        } else {
            $cached_value = $this->cache->get($cached_name);
        }
        if ($cached_value !== false) {
            return returnTypedValue($cached_value);
        }

        if ($class_name != '' && isModuleInstalled($class_name)) {
            include_once(DIR_MODULES . $class_name . '/' . $class_name . '.class.php');
            $module = new $class_name();
            if (method_exists($module, 'getModuleProperty')) {
                $data = $module->getModuleProperty($tmp[1] . '.' . $tmp[2]);
                return returnTypedValue($data);
            }
        } else {
            $obj = $this->get($object_name);
            if ($obj) {
                $value = $this->properties->get($obj, $varname, true);
                return returnTypedValue($value);
            }
        }
        return false;

    }

    public function setValue($varname, $value, $no_linked = 0, $source = '')
    {
        $varname = trim((string)$varname);
        if (strpos($varname, 'cycle_') === 0) {
            $this->cache->saveCycle($varname, $value);
            return;
        }

        $tmp = explode('.', $varname);

        if (isset($tmp[2])) {
            $object_name = $tmp[0] . '.' . $tmp[1];
            $varname = $tmp[2];
        } elseif (isset($tmp[1])) {
            $object_name = $tmp[0];
            $varname = $tmp[1];
        } else {
            $object_name = 'ThisComputer';
        }

        $obj = $this->get($object_name);

        if ($obj) {
            return $this->properties->set($obj, $varname, $value, $no_linked, $source);
        } else {
            return 0;
        }
    }

    public function get($name)
    {
        if (trim((string)$name) == '') return 0;

        if (preg_match('/^(.+?)\.(.+?)$/', $name, $m)) {
            $class_name = $m[1];
            $object_name = $m[2];

            $sqlQuery = "SELECT objects.*
                     FROM objects
                     LEFT JOIN classes ON objects.CLASS_ID = classes.ID
                    WHERE objects.TITLE = '" . $this->db->escapeLike($object_name) . "'
                      AND classes.TITLE = '" . $this->db->escapeLike($class_name) . "'";
            $rec = $this->metadata->get('object-query:' . hash('sha256', $sqlQuery), function () use ($sqlQuery) { return $this->db->one($sqlQuery); });
        } else {
            $sqlQuery = "SELECT objects.*
                     FROM objects
                    WHERE TITLE = '" . $this->db->escapeLike($name) . "'";
            $rec = $this->metadata->get('object-query:' . hash('sha256', $sqlQuery), function () use ($sqlQuery) { return $this->db->one($sqlQuery); });
            //$rec = $this->db->one("SELECT objects.* FROM objects WHERE TITLE = '".$this->db->escapeLike($name)."'");
        }

        if (!isset($rec['ID'])) {
            $sqlQuery = "SELECT objects.*
                     FROM objects
                    WHERE TITLE = '" . $this->db->escapeLike($name) . "'";
            $rec = $this->metadata->get('object-query:' . hash('sha256', $sqlQuery), function () use ($sqlQuery) { return $this->db->one($sqlQuery); });
        }

        if (isset($rec['ID'])) {
            include_once(DIR_MODULES . 'objects/objects.class.php');
            $obj = new \objects();
            $obj->id = $rec['ID'];
            $obj->loadObjectRecord($rec);
            return $obj;
        }

        return 0;
    }
    public function call($methodName, $params = 0, bool $safe = false)
    {
        $parts = explode('.', trim($methodName));
        if (count($parts) >= 3) {
            $objectName = $parts[0] . '.' . $parts[1];
            $methodName = $parts[2];
        } elseif (count($parts) === 2) {
            [$objectName, $methodName] = $parts;
        } else $objectName = 'ThisComputer';
        if ($objectName === 'AllScripts') return $safe ? runScriptSafe($methodName, $params) : runScript($methodName, $params);
        $object = $this->get($objectName);
        if (!$object) return 0;
        return $safe ? Core::methods()->callSafe($object, $methodName, $params) : Core::methods()->call($object, $methodName, $params);
    }

    /**
     * Resolves the value row for the *currently active* property.
     *
     * PROPERTY_NAME is only a denormalized label: an old row of an overridden
     * inherited property can keep the same name while the active property has
     * another PROPERTY_ID. Binding a module to that obsolete row makes the
     * subscription silently stop working, so the active property is resolved
     * first and the row is looked up by (PROPERTY_ID, OBJECT_ID).
     */
    public function valueId($objectName, $property, bool $create = true): int
    {
        $object = $this->get($objectName);
        if (!$object || !$object->object_title) return 0;
        $property = trim((string)$property);
        if ($property === '') return 0;
        $propertyId = $this->properties->resolve($object, $property, $object->class_id, $object->id);
        if (!$propertyId) return 0;
        $propertyName = $object->object_title . '.' . $property;
        $lock = $create ? $this->locks->acquire($object->id, $property) : null;
        try {
            $row = $this->db->one('SELECT ID,PROPERTY_NAME FROM pvalues WHERE PROPERTY_ID=' . (int)$propertyId
                . ' AND OBJECT_ID=' . (int)$object->id . ' LIMIT 1');
            if ($row) {
                if ($create && (string)($row['PROPERTY_NAME'] ?? '') === '') {
                    $this->db->update('pvalues', array('ID' => (int)$row['ID'], 'PROPERTY_NAME' => $propertyName));
                }
                return (int)$row['ID'];
            }
            if (!$create) return 0;
            $record = array('PROPERTY_ID' => $propertyId, 'OBJECT_ID' => $object->id,
                'PROPERTY_NAME' => $propertyName, 'VALUE' => '', 'SOURCE' => '');
            $id = (int)$this->db->insert('pvalues', $record);
            if (!$id) {
                // Another writer created the row while this one was waiting for the lock.
                $row = $this->db->one('SELECT ID FROM pvalues WHERE PROPERTY_ID=' . (int)$propertyId
                    . ' AND OBJECT_ID=' . (int)$object->id . ' LIMIT 1');
                if ($row) return (int)$row['ID'];
                throw new RuntimeException('Cannot create property value');
            }
            return $id;
        } finally {
            if ($lock !== null) $this->locks->release($lock);
        }
    }

}
