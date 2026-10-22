<?php

/** System helpers. Public names and argument defaults are part of the module API. */

/**
 * Summary of addLinkedProperty
 * @param mixed $object Object
 * @param mixed $property Property
 * @param mixed $module Module
 * @return int
 */
function addLinkedProperty($object, $property, $module)
{
    // Atomic subscription; no longer rewrites the whole pvalues row.
    return \MajorDoMo\Core\Core::bindings()->attach((string)$object, (string)$property, (string)$module);
}

function removeLinkedProperty($object, $property, $module)
{
    return \MajorDoMo\Core\Core::bindings()->detach((string)$object, (string)$property, (string)$module);
}

function removeLinkedPropertyIfNotUsed($table_name, $object, $property, $module)
{
    // The table is whitelisted by PropertyBindings; it must not be user input.
    return \MajorDoMo\Core\Core::bindings()->detachIfUnused((string)$table_name, (string)$object, (string)$property, (string)$module);
}
