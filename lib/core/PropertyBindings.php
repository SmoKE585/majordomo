<?php

namespace MajorDoMo\Core;

use \InvalidArgumentException;
use \RuntimeException;

/**
 * Module subscriptions stored in `pvalues.LINKED_MODULES`.
 *
 * The column is kept as the single source of truth so existing modules,
 * backups and the `objects` editor continue to work. All mutations are
 * compare-and-swap updates of that column only: a binding operation must never
 * rewrite VALUE/UPDATED/SOURCE or erase a subscriber added concurrently, which
 * is what made the old `addLinkedProperty()` fragile.
 */
final class PropertyBindings
{
    /** `pvalues.LINKED_MODULES` is varchar(255); fail before MySQL truncates it. */
    private const MAX_LENGTH = 255;
    private const MAX_ATTEMPTS = 5;
    private const MODULE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/D';
    /** Only identifier names are interpolated into the "still used" query. */
    private const TABLE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/D';

    public function __construct(private Database $db, private Properties $properties, private PropertyLock $locks) {}

    /**
     * Adds one module to a property subscription.
     *
     * @return int value row id, or 0 when the object/property does not exist
     */
    public function attach($object, $property, $module): int
    {
        $module = $this->module($module);
        $id = $this->valueId($object, $property, false);
        if (!$id) $id = $this->valueId($object, $property, true);
        if (!$id) return 0;

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $row = $this->row($id);
            if (!$row) return 0;
            $current = (string)($row['LINKED_MODULES'] ?? '');
            $modules = self::normalize($current);
            $canonical = implode(',', $modules);
            if (in_array($module, $modules, true)) {
                // Repair duplicates/whitespace only when the list is actually different.
                if ($canonical === $current) return $id;
            } else {
                $modules[] = $module;
                $canonical = implode(',', $modules);
            }
            if (strlen($canonical) > self::MAX_LENGTH) {
                throw new RuntimeException('Too many linked modules for ' . $object . '.' . $property
                    . '; shorten the subscriber list before adding ' . $module);
            }
            if ($this->compareAndSet($id, $row['LINKED_MODULES'], $canonical)) return $id;
        }
        throw new RuntimeException('Property module bindings changed concurrently; retry later');
    }

    /**
     * Removes one module token. Other modules, duplicate tokens and every other
     * column of the value row stay untouched.
     */
    public function detach($object, $property, $module, bool $onlyIfUnused = false, ?string $ownerTable = null): bool
    {
        $module = $this->module($module);
        $id = $this->valueId($object, $property, false);
        if (!$id) return false;
        if ($onlyIfUnused) {
            if ($ownerTable === null) throw new InvalidArgumentException('Owner table is required for a conditional detach');
            $this->assertTable($ownerTable);
            $used = $this->db->one('SELECT ID FROM `' . $ownerTable . '`'
                . " WHERE LINKED_OBJECT='" . $this->db->escape($object) . "'"
                . " AND LINKED_PROPERTY='" . $this->db->escape($property) . "' LIMIT 1");
            if ($used) return false;
        }

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $row = $this->row($id);
            if (!$row) return false;
            $current = (string)($row['LINKED_MODULES'] ?? '');
            $modules = self::normalize($current);
            $remaining = array_values(array_filter($modules, static fn($linked) => $linked !== $module));
            if (count($remaining) === count($modules)) return false;
            if (!$this->compareAndSet($id, $row['LINKED_MODULES'], implode(',', $remaining))) continue;
            return true;
        }
        throw new RuntimeException('Property module bindings changed concurrently; retry later');
    }

    /** Removes a module only when the caller's table no longer references the pair. */
    public function detachIfUnused($table, $object, $property, $module): bool
    {
        return $this->detach($object, $property, $module, true, (string)$table);
    }

    /** @return string[] normalized subscribers of one property */
    public function modules($object, $property): array
    {
        $id = $this->valueId($object, $property, false);
        if (!$id) return array();
        $row = $this->row($id);
        return $row ? self::normalize((string)($row['LINKED_MODULES'] ?? '')) : array();
    }

    /** Kept as a facade for callers that used Properties::removeLinkedModule(). */
    public function removeLinkedModule(string $object, string $property, string $module): bool
    {
        return $this->detach($object, $property, $module);
    }

    /**
     * Finds the value row of the active property. A property resolved through
     * object/class inheritance may have an obsolete same-name sibling row;
     * only (PROPERTY_ID, OBJECT_ID) identifies the row the writer actually uses.
     */
    private function valueId($object, $property, bool $create): int
    {
        $object = trim((string)$object);
        $property = trim((string)$property);
        if ($object === '' || $property === '') return 0;
        $objectRow = $this->db->one("SELECT ID,CLASS_ID,TITLE FROM objects WHERE TITLE='"
            . $this->db->escape($object) . "' LIMIT 1");
        if (!$objectRow) return 0;
        $propertyId = $this->properties->resolve((object)$objectRow, $property,
            (int)$objectRow['CLASS_ID'], (int)$objectRow['ID']);
        if (!$propertyId) return 0;
        $propertyName = $objectRow['TITLE'] . '.' . $property;
        $lock = $create ? $this->locks->acquire((int)$objectRow['ID'], $property) : null;
        try {
            $row = $this->db->one('SELECT ID,PROPERTY_NAME FROM pvalues WHERE PROPERTY_ID=' . (int)$propertyId
                . ' AND OBJECT_ID=' . (int)$objectRow['ID'] . ' LIMIT 1');
            if ($row) {
                if ($create && (string)($row['PROPERTY_NAME'] ?? '') === '') {
                    $this->db->update('pvalues', array('ID' => (int)$row['ID'], 'PROPERTY_NAME' => $propertyName));
                }
                return (int)$row['ID'];
            }
            if (!$create) return 0;
            $record = array('PROPERTY_ID' => $propertyId, 'OBJECT_ID' => (int)$objectRow['ID'],
                'PROPERTY_NAME' => $propertyName, 'VALUE' => '', 'SOURCE' => '');
            $id = (int)$this->db->insert('pvalues', $record);
            if ($id) return $id;
            // Another writer created the row while this one was waiting for the lock.
            $row = $this->db->one('SELECT ID FROM pvalues WHERE PROPERTY_ID=' . (int)$propertyId
                . ' AND OBJECT_ID=' . (int)$objectRow['ID'] . ' LIMIT 1');
            if ($row) return (int)$row['ID'];
            throw new RuntimeException('Cannot create property value');
        } finally {
            if ($lock !== null) $this->locks->release($lock);
        }
    }

    private function row(int $id): ?array
    {
        return $this->db->one('SELECT ID,LINKED_MODULES FROM pvalues WHERE ID=' . $id . ' LIMIT 1');
    }

    /**
     * Updates only LINKED_MODULES when it still has the value that was read.
     * NULL is handled separately because the legacy schema allowed it.
     */
    private function compareAndSet(int $id, ?string $expected, string $next): bool
    {
        $where = $expected === null
            ? ' AND LINKED_MODULES IS NULL'
            : " AND BINARY LINKED_MODULES=BINARY '" . $this->db->escape($expected) . "'";
        $this->db->requireExec("UPDATE pvalues SET LINKED_MODULES='" . $this->db->escape($next) . "' WHERE ID=" . $id . $where);
        return $this->db->affectedRows() > 0;
    }

    private function module($module): string
    {
        $module = trim((string)$module);
        if ($module === '' || !preg_match(self::MODULE_PATTERN, $module)) {
            throw new InvalidArgumentException('Invalid linked module name: ' . $module);
        }
        return $module;
    }

    private function assertTable(string $table): void
    {
        if (!preg_match(self::TABLE_PATTERN, $table)) {
            throw new InvalidArgumentException('Invalid owner table name: ' . $table);
        }
    }

    /** @return string[] */
    private static function normalize(string $links): array
    {
        $result = array();
        foreach (explode(',', $links) as $module) {
            $module = trim($module);
            if ($module === '' || in_array($module, $result, true)) continue;
            $result[] = $module;
        }
        return $result;
    }
}
