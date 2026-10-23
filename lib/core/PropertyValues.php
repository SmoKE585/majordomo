<?php

namespace MajorDoMo\Core;

/**
 * Пакетное чтение свойств с тем же порядком наследования, что и в Properties.
 * 2026-10-23 — источник и время обновления передаются из БД вместе со значением,
 * чтобы интерфейс не подменял их временем получения пакета WebSocket.
 */
final class PropertyValues
{
    public function __construct(private Database $db) {}

    public function pages(int $size = 100): \Generator
    {
        $after = 0;
        do {
            $objects = $this->db->select('SELECT ID,TITLE,CLASS_ID FROM objects WHERE ID>' . $after . ' ORDER BY ID LIMIT ' . max(1, min(500, $size)));
            if (!$objects) return;
            yield $this->forObjects($objects);
            $after = (int)$objects[count($objects) - 1]['ID'];
        } while (true);
    }

    public function named(array $names): array
    {
        $titles = array();
        $wanted = array();
        foreach ($names as $name) {
            $name = (string)$name;
            $titles[explode('.', $name, 2)[0]] = true;
            $wanted[mb_strtolower($name, 'UTF-8')] = true;
        }
        if (!$titles) return array();
        $quoted = array_map(fn($title) => "'" . $this->db->escape($title) . "'", array_keys($titles));
        $objects = $this->db->select('SELECT ID,TITLE,CLASS_ID FROM objects WHERE TITLE IN (' . implode(',', $quoted) . ')');
        return array_values(array_filter($this->forObjects($objects), static fn($row) => isset($wanted[mb_strtolower($row['PROPERTY'], 'UTF-8')])
            || isset($wanted[mb_strtolower(explode('.', $row['PROPERTY'], 2)[0], 'UTF-8')])));
    }

    private function forObjects(array $objects): array
    {
        if (!$objects) return array();
        $ids = implode(',', array_map(static fn($object) => (int)$object['ID'], $objects));
        $parents = array();
        foreach ($this->db->select('SELECT ID,PARENT_ID FROM classes') as $class) $parents[(int)$class['ID']] = (int)$class['PARENT_ID'];
        $classIds = array();
        foreach ($objects as $object) {
            $id = (int)$object['CLASS_ID'];
            $seen = array();
            while ($id && !isset($seen[$id])) {
                $seen[$id] = $classIds[$id] = true;
                $id = $parents[$id] ?? 0;
            }
        }
        $definitions = $this->db->select('SELECT ID,TITLE,CLASS_ID,OBJECT_ID FROM properties WHERE OBJECT_ID IN (' . $ids . ')'
            . ' OR (OBJECT_ID=0 AND CLASS_ID IN (' . (implode(',', array_keys($classIds)) ?: '0') . ')) ORDER BY ID');
        $byObject = $byClass = array();
        foreach ($definitions as $property) {
            $key = mb_strtolower($property['TITLE'], 'UTF-8');
            if ((int)$property['OBJECT_ID']) $byObject[(int)$property['OBJECT_ID']][$key] ??= $property;
            else $byClass[(int)$property['CLASS_ID']][$key] ??= $property;
        }
        $values = $legacy = array();
        foreach ($this->db->select('SELECT ID,OBJECT_ID,PROPERTY_ID,PROPERTY_NAME,VALUE,SOURCE,UPDATED FROM pvalues WHERE OBJECT_ID IN (' . $ids . ') ORDER BY ID') as $value) {
            $values[(int)$value['OBJECT_ID']][(int)$value['PROPERTY_ID']] ??= $value;
            $legacy[mb_strtolower((string)$value['PROPERTY_NAME'], 'UTF-8')] ??= $value;
        }
        $result = array();
        foreach ($objects as $object) {
            $properties = $byObject[(int)$object['ID']] ?? array();
            $id = (int)$object['CLASS_ID'];
            $seen = array();
            while ($id && !isset($seen[$id])) {
                $seen[$id] = true;
                $properties += $byClass[$id] ?? array();
                $id = $parents[$id] ?? 0;
            }
            foreach ($properties as $property) {
                $name = $object['TITLE'] . '.' . $property['TITLE'];
                $value = $values[(int)$object['ID']][(int)$property['ID']] ?? $legacy[mb_strtolower($name, 'UTF-8')] ?? null;
                $result[] = array(
                    'PROPERTY' => $name,
                    'VALUE' => $value['VALUE'] ?? null,
                    'OBJECT_ID' => (int)$object['ID'],
                    'SOURCE' => (string)($value['SOURCE'] ?? ''),
                    'UPDATED' => $value['UPDATED'] ?? null,
                );
            }
        }
        return $result;
    }
}
