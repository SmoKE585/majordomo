<?php

namespace MajorDoMo\Core\Settings;

use MajorDoMo\Core\Install\InstallSchema;

/**
 * Converts schema fields into the row shape used by the settings template.
 * Keeping this separate from persistence makes the manager easy to test.
 */
final class SettingsPresenter
{
    public function __construct(private SettingsManager $manager)
    {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function rows(string $section): array
    {
        $rows = array();
        $values = $this->manager->values();
        $secrets = $this->manager->existingSecrets();
        foreach (SettingsSchema::fieldsForSection($section) as $id => $field) {
            $value = (string)($values[$id] ?? '');
            $default = isset($field['default']) ? (string)$field['default'] : '';
            $type = (string)($field['type'] ?? 'text');
            $isSecret = !empty($field['secret']);
            $hasSecret = $isSecret && !empty($secrets[$id]);
            $displayType = $this->displayType($field);
            $options = $this->options($field, $value);
            $title = (string)($field['label'] ?? $id);
            $notes = trim((string)($field['description'] ?? ''));
            if ($isSecret) {
                $notes = trim($notes . ' Сохранённое значение не показывается: оставьте поле пустым, чтобы не менять его.');
            }
            $search = $title . ' ' . $notes . ' ' . $id;
            if (function_exists('mb_strtolower')) {
                $search = mb_strtolower($search, 'UTF-8');
            } else {
                $search = strtolower($search);
            }

            $row = array(
                'ID' => $id,
                'NAME' => $id,
                'TITLE' => $title,
                'NOTES' => $notes,
                'TYPE' => $displayType,
                'VALUE' => $isSecret ? '' : htmlspecialchars($value, ENT_QUOTES),
                'VALUE_RAW' => $value,
                'DEFAULTVALUE' => $default,
                'DEFAULT_DISPLAY' => $this->defaultDisplay($field, $default),
                'ISDEFAULT' => $value === $default ? 1 : 0,
                'IS_WIDE' => in_array($type, array('textarea', 'json'), true) || ($field['width'] ?? 'half') === 'full' ? 1 : 0,
                'IS_SECRET' => $isSecret ? 1 : 0,
                'HAS_SECRET' => $hasSecret ? 1 : 0,
                'SEARCH_TEXT' => htmlspecialchars($search, ENT_QUOTES),
                'STORAGE' => $this->storageLabel($field),
                'STORAGE_CLASS' => $this->storageClass($field),
                'ROWS' => (int)($field['rows'] ?? 4),
                'IS_SEARCHABLE' => $this->isSearchable($field) ? 1 : 0,
                'MIN' => isset($field['min']) ? (string)$field['min'] : '',
                'MAX' => isset($field['max']) ? (string)$field['max'] : '',
                'STEP' => isset($field['step_attr']) ? (string)$field['step_attr'] : '',
                'OPTIONS' => $options,
                'DEPENDS' => $this->depends($field),
                'IS_SELECT_DROPDOWN' => 1,
                'IS_SELECT_CHOICES' => 0,
            );
            if ($displayType === 'select') {
                $row['IS_SELECT_DROPDOWN'] = count($options) > 6 || $row['IS_SEARCHABLE'] ? 1 : 0;
                $row['IS_SELECT_CHOICES'] = $row['IS_SELECT_DROPDOWN'] ? 0 : 1;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param array<string,mixed> $field
     */
    private function displayType(array $field): string
    {
        $type = (string)($field['type'] ?? 'text');
        if ($type === 'switch') {
            return 'onoff';
        }
        if ($type === 'choice') {
            return 'select';
        }
        if ($type === 'list') {
            return 'text';
        }
        if ($type === 'timezone') {
            return 'select';
        }
        if (in_array($type, array('text', 'textarea', 'password', 'path', 'select', 'json', 'number', 'url'), true)) {
            return $type;
        }
        return 'text';
    }

    /**
     * @param array<string,mixed> $field
     * @return array<int,array{OPTION_VALUE:string,OPTION_TITLE:string,SELECTED:int}>
     */
    private function options(array $field, string $value): array
    {
        $type = (string)($field['type'] ?? 'text');
        if ($type === 'timezone') {
            $result = array();
            foreach (InstallSchema::timezoneOptions() as $zone => $label) {
                $result[] = array(
                    'OPTION_VALUE' => (string)$zone,
                    'OPTION_TITLE' => (string)$label,
                    'SELECTED' => (string)$zone === $value ? 1 : 0,
                );
            }
            return $result;
        }
        if (!in_array($type, array('select', 'choice'), true)) {
            return array();
        }
        $result = array();
        foreach ((array)($field['options'] ?? array()) as $key => $option) {
            if (is_array($option)) {
                $optionValue = (string)($option['value'] ?? $key);
                $optionTitle = (string)($option['label'] ?? $optionValue);
            } else {
                $optionValue = (string)$key;
                $optionTitle = (string)$option;
            }
            $result[] = array(
                'OPTION_VALUE' => $optionValue,
                'OPTION_TITLE' => $optionTitle,
                'SELECTED' => $optionValue === $value ? 1 : 0,
            );
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $field
     */
    private function depends(array $field): string
    {
        $depends = $field['depends'] ?? null;
        if (!is_array($depends) || !isset($depends['field'], $depends['value'])) {
            return '';
        }
        $json = json_encode(
            array('field' => (string)$depends['field'], 'value' => (string)$depends['value']),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        return is_string($json) ? htmlspecialchars($json, ENT_QUOTES) : '';
    }

    /**
     * @param array<string,mixed> $field
     */
    private function storageLabel(array $field): string
    {
        if (($field['target'] ?? '') === 'env') {
            return '.env';
        }
        if (($field['target'] ?? '') === 'meta') {
            return 'Переключатель';
        }
        return 'База данных';
    }

    /**
     * @param array<string,mixed> $field
     */
    private function storageClass(array $field): string
    {
        if (($field['target'] ?? '') === 'env') {
            return 'env';
        }
        if (($field['target'] ?? '') === 'meta') {
            return 'meta';
        }
        return 'db';
    }

    /**
     * @param array<string,mixed> $field
     */
    private function isSearchable(array $field): bool
    {
        $type = (string)($field['type'] ?? 'text');
        if ($type === 'timezone') {
            return true;
        }
        if ($type === 'select') {
            return count((array)($field['options'] ?? array())) > 8;
        }
        return false;
    }

    /**
     * @param array<string,mixed> $field
     */
    private function defaultDisplay(array $field, string $default): string
    {
        if (!empty($field['secret'])) {
            return 'не задано';
        }
        if (($field['type'] ?? '') === 'switch') {
            return $default === '1' ? 'включено' : 'выключено';
        }
        if (($field['type'] ?? '') === 'select' || ($field['type'] ?? '') === 'choice') {
            foreach ((array)($field['options'] ?? array()) as $key => $option) {
                if (is_array($option) && (string)($option['value'] ?? $key) === $default) {
                    return (string)($option['label'] ?? $default);
                }
                if (!is_array($option) && (string)$key === $default) {
                    return (string)$option;
                }
            }
        }
        return $default;
    }
}
