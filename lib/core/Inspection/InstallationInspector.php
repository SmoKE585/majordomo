<?php

declare(strict_types=1);

namespace MajorDoMo\Core\Inspection;

use InvalidArgumentException;
use MajorDoMo\Core\Database;
use MajorDoMo\Core\PropertyAudit;
use RuntimeException;

/**
 * Только чтение установки для контекста ИИ: объекты, свойства, методы,
 * сценарии, ссылки и история. Никакого SQL, shell или записи от модели.
 *
 * Изменения:
 * 2026-10-23 — поиск кандидатов с опечатками, транслитерацией и бытовыми названиями.
 * 2026-10-23 — единый серверный слой для Tools помощника и контекста редактора.
 */
final class InstallationInspector
{
    private const MAX_PAGE = 25;

    public function __construct(private Database $db, private ?PropertyAudit $audit = null) {}

    /** @return array{items:list<array>,total:int,next_offset:?int} */
    public function searchObjects(string $query, int $offset = 0, int $limit = 10): array
    {
        $query = trim($query);
        if (mb_strlen($query) > 200 || !mb_check_encoding($query, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $query)) {
            throw new InvalidArgumentException('Поисковый запрос должен быть текстом UTF-8 до 200 символов.');
        }
        self::page($offset, $limit, 25);
        $where = '';
        if ($query !== '') {
            $like = '%' . str_replace(array('%', '_'), array('\\%', '\\_'), $this->db->escape($query)) . '%';
            $where = " WHERE (objects.TITLE LIKE '" . $like . "' OR objects.DESCRIPTION LIKE '" . $like
                . "' OR classes.TITLE LIKE '" . $like . "')";
        }
        $count = $this->db->one('SELECT COUNT(*) AS C FROM objects LEFT JOIN classes ON objects.CLASS_ID=classes.ID' . $where);
        $rows = $this->db->select('SELECT objects.ID,objects.TITLE,objects.DESCRIPTION,objects.CLASS_ID,classes.TITLE AS CLASS '
            . 'FROM objects LEFT JOIN classes ON objects.CLASS_ID=classes.ID' . $where
            . ' ORDER BY objects.TITLE,objects.ID LIMIT ' . $offset . ',' . $limit);
        $items = array();
        foreach ($rows as $row) {
            $items[] = $this->objectSummary($row, $query);
        }
        $total = (int)($count['C'] ?? 0);
        if ($total === 0 && $query !== '') return $this->fuzzyObjects($query, $offset, $limit);
        return array('items' => $items, 'total' => $total,
            'next_offset' => $offset + $limit < $total ? $offset + $limit : null);
    }

    /** Нечёткое совпадение — только кандидат; управлять им без проверки нельзя. */
    private function fuzzyObjects(string $query, int $offset, int $limit): array
    {
        $terms = self::searchWords($query);
        $candidates = $this->db->select('SELECT objects.ID,objects.TITLE,objects.DESCRIPTION,objects.CLASS_ID,classes.TITLE AS CLASS '
            . 'FROM objects LEFT JOIN classes ON objects.CLASS_ID=classes.ID ORDER BY objects.ID LIMIT 5001');
        $truncated = count($candidates) > 5000;
        $matches = array();
        foreach (array_slice($candidates, 0, 5000) as $row) {
            $words = self::searchWords($row['TITLE'] . ' ' . ($row['DESCRIPTION'] ?? '') . ' ' . ($row['CLASS'] ?? ''), true);
            $score = 0;
            foreach ($terms as $term) {
                $best = 0;
                foreach ($words as $word) {
                    if ($word === $term) $best = max($best, 10);
                    elseif (min(strlen($term), strlen($word)) >= 3 && (str_contains($word, $term) || str_contains($term, $word))) $best = max($best, 6);
                    elseif (strlen($term) >= 4 && abs(strlen($word) - strlen($term)) <= 2
                        && self::similarWord($term, $word)) $best = max($best, 3);
                }
                if ($best === 0) continue 2;
                $score += $best;
            }
            if ($score === 0) continue;
            $item = $this->objectSummary($row, $query);
            $item['match_score'] = $score;
            $matches[] = $item;
        }
        usort($matches, static fn(array $a, array $b): int => ($b['match_score'] <=> $a['match_score']) ?: ($a['id'] <=> $b['id']));
        $total = count($matches);
        return array('items' => array_slice($matches, $offset, $limit), 'total' => $total,
            'next_offset' => $offset + $limit < $total ? $offset + $limit : null,
            'search_mode' => 'fuzzy_candidates', 'truncated' => $truncated);
    }

    private static function searchWords(string $text, bool $candidate = false): array
    {
        $text = preg_replace('/([a-zа-я])([A-ZА-Я])/u', '$1 $2', $text) ?? $text;
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: array();
        $groups = array('light' => array('свет', 'ламп', 'освещ', 'light', 'lamp', 'svet'),
            'toilet' => array('туалет', 'сануз', 'toilet', 'tualet', 'wc'),
            'bathroom' => array('ванн', 'bath', 'vann'), 'kitchen' => array('кухн', 'kitchen', 'kuhn', 'kukhn'),
            'motion' => array('движ', 'motion', 'pir'), 'temperature' => array('температ', 'temperat') );
        $letters = array_combine(preg_split('//u', 'абвгдеёжзийклмнопрстуфхцчшщъыьэюя', -1, PREG_SPLIT_NO_EMPTY),
            array('a','b','v','g','d','e','e','zh','z','i','y','k','l','m','n','o','p','r','s','t','u','f','kh','ts','ch','sh','sch','','y','','e','yu','ya'));
        $result = array();
        foreach ($words as $word) {
            if (in_array($word, array('в', 'во', 'на', 'из', 'и', 'для', 'the', 'in', 'of'), true)) continue;
            if ($candidate) {
                $result[] = strtr($word, $letters);
                foreach ($groups as $canonical => $prefixes) {
                    foreach ($prefixes as $prefix) {
                        if ((strlen($prefix) > 2 && str_contains($word, $prefix)) || $word === $prefix) {
                            $result[] = $canonical;
                            break;
                        }
                    }
                }
                continue;
            }
            foreach ($groups as $canonical => $prefixes) {
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($word, $prefix)) { $word = $canonical; break 2; }
                }
            }
            $result[] = strtr($word, $letters);
        }
        return array_values(array_unique($result));
    }

    private static function similarWord(string $term, string $word): bool
    {
        if (levenshtein($term, $word) <= (strlen($term) >= 7 ? 2 : 1)) return true;
        if (strlen($term) !== strlen($word)) return false;
        for ($i = 0; $i < strlen($term) - 1; $i++) {
            $swapped = $term;
            $swapped[$i] = $term[$i + 1];
            $swapped[$i + 1] = $term[$i];
            if ($swapped === $word) return true;
        }
        return false;
    }

    public function objectDetails(string $name): array
    {
        $object = $this->object($name);
        $class = $this->classRecord((int)$object['CLASS_ID']);
        $classes = $this->classChain((int)$object['CLASS_ID']);
        $classIds = array_column($classes, 'ID');
        $properties = $this->dedupe($this->propertiesFor((int)$object['ID'], $classIds));
        $methods = $this->dedupe($this->methodsFor((int)$object['ID'], $classIds));
        if ((int)($object['KEEP_HISTORY'] ?? 0) === 1) {
            foreach ($properties as &$property) {
                if (($property['origin'] ?? '') === 'class') $property['history'] = true;
            }
            unset($property);
        }
        foreach ($properties as &$property) {
            if (mb_strlen((string)$property['value']) > 2000) {
                $property['value'] = mb_substr((string)$property['value'], 0, 2000) . '…';
                $property['value_truncated'] = true;
            }
        }
        unset($property);
        return array(
            'kind' => 'reference_data',
            'id' => (int)$object['ID'],
            'title' => SensitiveText::clean((string)$object['TITLE']),
            'class' => SensitiveText::clean((string)($class['TITLE'] ?? '')),
            'class_path' => array_map(static fn(array $item): string => (string)$item['TITLE'], $classes),
            'description' => SensitiveText::clean((string)($object['DESCRIPTION'] ?? '')),
            'location_id' => (int)($object['LOCATION_ID'] ?? 0),
            'nearby' => $this->nearbyObjects($object),
            'url' => self::url('/panel/class/' . (int)$object['CLASS_ID'] . '/object/' . (int)$object['ID'] . '.html'),
            'properties' => $properties,
            'methods' => $methods,
            'totals' => array('properties' => count($properties), 'methods' => count($methods),
                'properties_truncated' => count($properties) >= 200, 'methods_truncated' => count($methods) >= 200),
        );
    }

    public function propertyDetails(string $objectName, string $propertyName): array
    {
        $object = $this->object($objectName);
        $property = $this->property($object, $propertyName);
        $class = $this->classRecord((int)$object['CLASS_ID']);
        $value = $this->value((string)$object['TITLE'], (string)$property['TITLE']);
        $valueLength = mb_strlen($value);
        $linked = array();
        foreach (explode(',', (string)($property['LINKED_MODULES'] ?? '')) as $module) {
            $module = trim($module);
            if ($module !== '') $linked[] = SensitiveText::clean($module);
        }
        $keepHistory = (int)($property['KEEP_HISTORY'] ?? 0) === 1
            || ((int)($property['OBJECT_ID'] ?? 0) === 0 && (int)($object['KEEP_HISTORY'] ?? 0) === 1);
        return array(
            'kind' => 'reference_data',
            'object' => SensitiveText::clean((string)$object['TITLE']),
            'property' => SensitiveText::clean((string)$property['TITLE']),
            'description' => SensitiveText::clean((string)($property['DESCRIPTION'] ?? '')),
            'value' => SensitiveText::clean(mb_substr($value, 0, 4000)),
            'value_truncated' => $valueLength > 4000,
            'updated' => (string)($property['UPDATED'] ?? ''),
            'source' => SensitiveText::clean((string)($property['SOURCE'] ?? '')),
            'owner' => !empty($property['OBJECT_ID']) ? 'object' : 'class',
            'class' => SensitiveText::clean((string)($class['TITLE'] ?? '')),
            'keep_history' => $keepHistory,
            'handler' => SensitiveText::clean((string)($property['ONCHANGE'] ?? '')),
            'linked_modules' => $linked,
            'data_type' => (int)($property['DATA_TYPE'] ?? 0),
            'validation' => array('type' => (int)($property['VALIDATION_TYPE'] ?? 0),
                'min' => (string)($property['VALIDATION_NUM_MIN'] ?? ''),
                'max' => (string)($property['VALIDATION_NUM_MAX'] ?? ''),
                'list' => (string)($property['VALIDATION_LIST'] ?? '')),
            'system' => (string)($property['SYSTEM'] ?? ''),
            'url' => self::url('/panel/class/' . (int)$object['CLASS_ID'] . '/object/' . (int)$object['ID'] . '/properties.html'),
        );
    }

    /** Точные структурные связи отделены от текстовых совпадений в коде. */
    public function references(string $objectName, string $propertyName = '', int $limit = 20): array
    {
        $object = $this->object($objectName);
        $objectTitle = (string)$object['TITLE'];
        $propertyName = trim($propertyName);
        if ($propertyName !== '' && mb_strlen($propertyName) > 190) throw new InvalidArgumentException('Слишком длинное имя свойства.');
        self::page(0, $limit, 30);
        $full = $propertyName === '' ? $objectTitle : $objectTitle . '.' . $propertyName;
        $result = array('exact' => array(), 'text' => array());
        $seen = array();

        $events = $this->db->select("SELECT e.ID,e.EVENT_NAME,e.DESCRIPTION,p.LINKED_OBJECT,p.LINKED_PROPERTY "
            . "FROM events_params p JOIN events e ON e.ID=p.EVENT_ID WHERE p.LINKED_OBJECT='"
            . $this->db->escape($objectTitle) . "'"
            . ($propertyName !== '' ? " AND p.LINKED_PROPERTY='" . $this->db->escape($propertyName) . "'" : '')
            . ' ORDER BY e.ID DESC LIMIT ' . $limit);
        foreach ($events as $event) {
            $seen['event:' . (int)$event['ID']] = true;
            $result['exact'][] = array('type' => 'event', 'title' => SensitiveText::clean((string)$event['EVENT_NAME']),
                'description' => SensitiveText::clean((string)($event['DESCRIPTION'] ?? '')),
                'url' => self::url('/panel/event/' . (int)$event['ID'] . '.html'));
        }

        $bindingQuery = "SELECT ID,PROPERTY_NAME,LINKED_MODULES FROM pvalues WHERE LINKED_MODULES<>'' AND PROPERTY_NAME"
            . ($propertyName !== '' ? "='" . $this->db->escape($full) . "'" : " LIKE '" . $this->db->escape($objectTitle . '.%') . "'")
            . ' LIMIT ' . $limit;
        foreach ($this->db->select($bindingQuery) as $binding) {
            $result['exact'][] = array('type' => 'module', 'title' => SensitiveText::clean((string)$binding['PROPERTY_NAME']),
                'description' => SensitiveText::clean((string)$binding['LINKED_MODULES']), 'url' => '');
        }

        foreach ($this->bindingTables() as $table) {
            if (in_array($table, array('events_params', 'pvalues', 'objects', 'properties', 'methods'), true)) continue;
            try {
                $rows = $this->db->select('SELECT * FROM ' . $table . " WHERE LINKED_OBJECT='" . $this->db->escape($objectTitle) . "'"
                    . ($propertyName !== '' ? " AND LINKED_PROPERTY='" . $this->db->escape($propertyName) . "'" : '')
                    . ' LIMIT ' . $limit);
            } catch (\Throwable) {
                continue;
            }
            foreach ($rows as $row) {
                $id = (int)($row['ID'] ?? 0);
                $key = $table . ':' . $id;
                if ($id > 0 && isset($seen[$key])) continue;
                $seen[$key] = true;
                $title = (string)($row['TITLE'] ?? $row['NAME'] ?? $row['EVENT_NAME'] ?? $id);
                $result['exact'][] = array('type' => 'module', 'table' => $table,
                    'title' => SensitiveText::clean($title),
                    'description' => SensitiveText::clean((string)($row['LINKED_PROPERTY'] ?? $row['LINKED_METHOD'] ?? '')),
                    'url' => $table === 'scripts' && $id > 0 ? self::url('/panel/script/' . $id . '.html') : '');
            }
        }

        $like = "'%" . str_replace(array('%', '_'), array('\\%', '\\_'), $this->db->escape($full)) . "%'";
        $methods = $this->db->select('SELECT methods.ID,methods.TITLE,methods.OBJECT_ID,methods.CLASS_ID,methods.CODE,'
            . 'objects.CLASS_ID AS OBJECT_CLASS_ID,objects.TITLE AS OBJECT,classes.TITLE AS CLASS '
            . 'FROM methods LEFT JOIN objects ON methods.OBJECT_ID=objects.ID LEFT JOIN classes ON methods.CLASS_ID=classes.ID '
            . 'WHERE methods.CODE LIKE ' . $like . ' ORDER BY methods.TITLE LIMIT ' . $limit);
        foreach ($methods as $method) {
            $result['text'][] = array('type' => 'method',
                'title' => SensitiveText::clean(($method['OBJECT'] ?: $method['CLASS']) . '.' . $method['TITLE']),
                'description' => self::excerpt((string)$method['CODE'], $full),
                'url' => !empty($method['OBJECT_ID'])
                    ? self::url('/panel/class/' . (int)$method['OBJECT_CLASS_ID'] . '/object/' . (int)$method['OBJECT_ID'] . '/methods/' . (int)$method['ID'] . '.html')
                    : self::url('/panel/class/' . (int)$method['CLASS_ID'] . '/methods/' . (int)$method['ID'] . '.html'));
        }
        $scripts = $this->db->select('SELECT ID,TITLE,CODE FROM scripts WHERE CODE LIKE ' . $like . ' ORDER BY TITLE LIMIT ' . $limit);
        foreach ($scripts as $script) {
            $result['text'][] = array('type' => 'script', 'title' => SensitiveText::clean((string)$script['TITLE']),
                'description' => self::excerpt((string)$script['CODE'], $full), 'url' => self::url('/panel/script/' . (int)$script['ID'] . '.html'));
        }

        return array('kind' => 'reference_data', 'query' => SensitiveText::clean($full),
            'links' => array_slice($result['exact'], 0, $limit), 'text_matches' => array_slice($result['text'], 0, $limit),
            'limitation' => 'Текстовые совпадения не доказывают связь: имена могут вычисляться динамически.',
            'url' => self::url('/panel/class/' . (int)$object['CLASS_ID'] . '/object/' . (int)$object['ID'] . '.html'));
    }

    public function methodCode(string $objectName, string $methodName, int $offset = 0, int $limit = 4000, string $className = ''): array
    {
        self::page($offset, $limit, 12000);
        if ($className !== '') {
            $class = $this->db->one("SELECT ID,TITLE FROM classes WHERE TITLE='" . $this->db->escape(self::query($className, 190)) . "' LIMIT 1");
            if ($class === null) throw new InvalidArgumentException('Класс не найден.');
            $object = array('ID' => 0, 'CLASS_ID' => (int)$class['ID'], 'TITLE' => (string)$class['TITLE'], 'KEEP_HISTORY' => 0);
        } else {
            $object = $this->object($objectName);
        }
        $methodName = self::query($methodName, 190);
        $method = null;
        foreach ($this->methodCandidates($object, $methodName) as $candidate) {
            $method = $candidate;
            break;
        }
        if ($method === null) throw new InvalidArgumentException('Метод не найден у объекта или его классов.');
        $script = null;
        if ((int)($method['SCRIPT_ID'] ?? 0) > 0) {
            $script = $this->db->one('SELECT ID,TITLE,CODE FROM scripts WHERE ID=' . (int)$method['SCRIPT_ID']);
        }
        $code = SensitiveText::clean((string)($method['CODE'] ?? ''));
        if ($code === '' && $script !== null) $code = SensitiveText::clean((string)($script['CODE'] ?? ''));
        $total = mb_strlen($code);
        $parent = null;
        if ((int)($method['CALL_PARENT'] ?? 0) > 0) {
            foreach ($this->methodCandidates($object, $methodName) as $candidate) {
                if ((int)$candidate['OBJECT_ID'] === 0 && (int)$candidate['CLASS_ID'] !== (int)$method['CLASS_ID']) {
                    $parent = $candidate;
                    break;
                }
            }
        }
        return array(
            'kind' => 'reference_data',
            'object' => SensitiveText::clean((string)($method['OBJECT'] ?: $method['CLASS'])),
            'method' => SensitiveText::clean((string)$method['TITLE']),
            'description' => SensitiveText::clean((string)($method['DESCRIPTION'] ?? '')),
            'origin' => (int)$method['OBJECT_ID'] > 0 ? 'object' : 'class',
            'code' => mb_substr($code, $offset, $limit),
            'parent_code' => $parent !== null ? SensitiveText::clean((string)$parent['CODE']) : '',
            'call_parent' => (int)($method['CALL_PARENT'] ?? 0),
            'script_id' => (int)($method['SCRIPT_ID'] ?? 0),
            'script_title' => $script !== null ? SensitiveText::clean((string)$script['TITLE']) : '',
            'offset' => $offset,
            'total_characters' => $total,
            'next_offset' => $offset + $limit < $total ? $offset + $limit : null,
            'url' => (int)$method['OBJECT_ID'] > 0
                ? self::url('/panel/class/' . (int)$object['CLASS_ID'] . '/object/' . (int)$object['ID'] . '/methods/' . (int)$method['ID'] . '.html')
                : self::url('/panel/class/' . (int)$method['CLASS_ID'] . '/methods/' . (int)$method['ID'] . '.html'),
        );
    }

    /** Поиск сценариев по названию, описанию и коду; ID нужен для read_code. */
    public function searchScripts(string $query, int $offset = 0, int $limit = 10): array
    {
        $query = trim($query);
        if (mb_strlen($query) > 200 || !mb_check_encoding($query, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $query)) {
            throw new InvalidArgumentException('Поисковый запрос должен быть текстом UTF-8 до 200 символов.');
        }
        self::page($offset, $limit, 25);
        $where = '';
        if ($query !== '') {
            $like = '%' . str_replace(array('%', '_'), array('\\%', '\\_'), $this->db->escape($query)) . '%';
            $where = " WHERE (TITLE LIKE '" . $like . "' OR DESCRIPTION LIKE '" . $like . "' OR CODE LIKE '" . $like . "')";
        }
        $count = $this->db->one('SELECT COUNT(*) AS C FROM scripts' . $where);
        $rows = $this->db->select('SELECT ID,TITLE,DESCRIPTION FROM scripts' . $where
            . ' ORDER BY TITLE,ID LIMIT ' . $offset . ',' . $limit);
        $items = array();
        foreach ($rows as $row) {
            $items[] = array('id' => (int)$row['ID'], 'title' => SensitiveText::clean((string)$row['TITLE']),
                'description' => SensitiveText::clean(mb_substr((string)($row['DESCRIPTION'] ?? ''), 0, 300)),
                'url' => self::url('/panel/script/' . (int)$row['ID'] . '.html'));
        }
        $total = (int)($count['C'] ?? 0);
        return array('kind' => 'reference_data', 'items' => $items, 'total' => $total,
            'next_offset' => $offset + $limit < $total ? $offset + $limit : null);
    }

    public function scriptCode(string $script, int $offset = 0, int $limit = 4000): array    {
        self::page($offset, $limit, 12000);
        $record = null;
        if (preg_match('/^\d{1,10}$/', $script)) {
            $record = $this->db->one('SELECT ID,TITLE,DESCRIPTION,CODE FROM scripts WHERE ID=' . (int)$script);
        } else {
            $record = $this->db->one("SELECT ID,TITLE,DESCRIPTION,CODE FROM scripts WHERE TITLE='"
                . $this->db->escape(self::query($script, 190)) . "' LIMIT 1");
        }
        if ($record === null) throw new InvalidArgumentException('Сценарий не найден.');
        $code = SensitiveText::clean((string)($record['CODE'] ?? ''));
        $total = mb_strlen($code);
        return array('kind' => 'reference_data', 'id' => (int)$record['ID'],
            'title' => SensitiveText::clean((string)$record['TITLE']),
            'description' => SensitiveText::clean((string)($record['DESCRIPTION'] ?? '')),
            'code' => mb_substr($code, $offset, $limit), 'offset' => $offset, 'total_characters' => $total,
            'next_offset' => $offset + $limit < $total ? $offset + $limit : null,
            'url' => self::url('/panel/script/' . (int)$record['ID'] . '.html'));
    }

    public function history(string $objectName, string $propertyName, int $from, int $to, int $page = 1, int $perPage = 10): array
    {
        $object = $this->object($objectName);
        $property = $this->property($object, $propertyName);
        if ($to < $from) throw new InvalidArgumentException('Конец периода раньше начала.');
        $perPage = max(1, min($perPage, 20));
        try {
            $audit = ($this->audit ?? new PropertyAudit($this->db))->tracesForProperty(
                (int)$object['ID'], (int)$property['ID'], $from, $to, max(1, $page), $perPage);
        } catch (\Throwable) {
            return array('kind' => 'reference_data', 'changes' => array(), 'total' => 0,
                'note' => 'Журнал изменений пока недоступен для этой установки.', 'url' => '');
        }
        $changes = array();
        foreach ($audit['runs'] as $run) {
            $target = null;
            foreach (($run['events'] ?? array()) as $event) {
                if (($event['is_target'] ?? false) === true) { $target = $event; break; }
            }
            $changes[] = array(
                'time' => (string)($run['started_at'] ?? ''),
                'actor' => SensitiveText::clean((string)($run['actor'] ?? '')),
                'source' => SensitiveText::clean((string)($run['trigger_label'] ?? $run['source'] ?? '')),
                'title' => SensitiveText::clean((string)($run['trigger_title'] ?? '')),
                'old' => SensitiveText::clean((string)($target['old_value'] ?? '')),
                'new' => SensitiveText::clean((string)($target['new_value'] ?? '')),
            );
        }
        return array('kind' => 'reference_data',
            'object' => SensitiveText::clean((string)$object['TITLE']),
            'property' => SensitiveText::clean((string)$property['TITLE']),
            'value' => SensitiveText::clean($this->value((string)$object['TITLE'], (string)$property['TITLE'])),
            'changes' => $changes, 'total' => (int)($audit['pagination']['total'] ?? 0),
            'page' => (int)($audit['pagination']['page'] ?? 1),
            'url' => self::url('/panel/class/' . (int)$object['CLASS_ID'] . '/object/' . (int)$object['ID'] . '/properties.html'));
    }

    private function objectSummary(array $row, string $query): array
    {
        return array('id' => (int)$row['ID'], 'title' => SensitiveText::clean((string)$row['TITLE']),
            'class' => SensitiveText::clean((string)($row['CLASS'] ?? '')),
            'description' => SensitiveText::clean(mb_substr((string)($row['DESCRIPTION'] ?? ''), 0, 300)),
            'match' => $query !== '' && mb_stripos((string)$row['TITLE'], $query) !== false ? 'title' : 'related',
            'url' => self::url('/panel/class/' . (int)$row['CLASS_ID'] . '/object/' . (int)$row['ID'] . '.html'));
    }

    private function object(string $name): array
    {
        $name = self::query($name, 190);
        $row = $this->db->one("SELECT * FROM objects WHERE TITLE='" . $this->db->escape($name) . "' LIMIT 1");
        if ($row === null) throw new InvalidArgumentException('Объект не найден. Проверьте точное имя через find_objects.');
        return $row;
    }

    private function nearbyObjects(array $object): array
    {
        $location = (int)($object['LOCATION_ID'] ?? 0);
        if ($location < 1) return array();
        $rows = $this->db->select('SELECT objects.ID,objects.TITLE,objects.DESCRIPTION,objects.CLASS_ID,classes.TITLE AS CLASS '
            . 'FROM objects LEFT JOIN classes ON objects.CLASS_ID=classes.ID WHERE objects.LOCATION_ID=' . $location
            . ' AND objects.ID<>' . (int)$object['ID'] . ' ORDER BY objects.TITLE LIMIT 12');
        return array_map(fn(array $row): array => $this->objectSummary($row, ''), $rows);
    }

    private function classRecord(int $id): array
    {
        return $this->db->one('SELECT ID,TITLE,PARENT_ID,DESCRIPTION FROM classes WHERE ID=' . $id) ?: array();
    }

    private function classChain(int $classId): array
    {
        $classes = array();
        $seen = array();
        for ($i = 0; $classId > 0 && $i < 20 && !isset($seen[$classId]); $i++) {
            $class = $this->classRecord($classId);
            if ($class === array()) break;
            $seen[$classId] = true;
            $classes[] = $class;
            $classId = (int)$class['PARENT_ID'];
        }
        return $classes;
    }

    private function property(array $object, string $name): array
    {
        $name = self::query($name, 190);
        foreach ($this->propertyCandidates($object, $name) as $candidate) {
            return $candidate;
        }
        throw new InvalidArgumentException('Свойство не найдено у объекта или его классов.');
    }

    /** Ближайшее свойство: сначала объекта, затем класса, затем родителей. */
    private function propertyCandidates(array $object, string $name): array
    {
        $classIds = array_map('intval', array_column($this->classChain((int)$object['CLASS_ID']), 'ID'));
        if ($classIds === array()) $classIds = array((int)$object['CLASS_ID']);
        $rows = $this->db->select('SELECT properties.*,pvalues.VALUE,pvalues.UPDATED,pvalues.LINKED_MODULES,pvalues.SOURCE '
            . 'FROM properties LEFT JOIN pvalues ON pvalues.PROPERTY_ID=properties.ID AND pvalues.OBJECT_ID='
            . (int)$object['ID'] . " WHERE properties.TITLE='" . $this->db->escape($name) . "' AND (properties.OBJECT_ID="
            . (int)$object['ID'] . ' OR properties.CLASS_ID IN (' . implode(',', $classIds) . '))');
        usort($rows, static function (array $left, array $right) use ($classIds, $object): int {
            $rank = static function (array $row) use ($classIds, $object): int {
                if ((int)$row['OBJECT_ID'] === (int)$object['ID']) return -1;
                $index = array_search((int)$row['CLASS_ID'], $classIds, true);
                return $index === false ? 1000 : (int)$index;
            };
            return $rank($left) <=> $rank($right);
        });
        return $rows;
    }

    /** Ближайший метод: сначала объекта, затем класса, затем родителей. */
    private function methodCandidates(array $object, string $methodName): array
    {
        $classIds = array_map('intval', array_column($this->classChain((int)$object['CLASS_ID']), 'ID'));
        if ($classIds === array()) $classIds = array((int)$object['CLASS_ID']);
        $rows = $this->db->select('SELECT methods.*,objects.TITLE AS OBJECT,classes.TITLE AS CLASS '
            . 'FROM methods LEFT JOIN objects ON methods.OBJECT_ID=objects.ID LEFT JOIN classes ON methods.CLASS_ID=classes.ID '
            . "WHERE methods.TITLE='" . $this->db->escape($methodName) . "' AND (methods.OBJECT_ID=" . (int)$object['ID']
            . ' OR methods.CLASS_ID IN (' . implode(',', $classIds) . ')) ORDER BY methods.ID');
        usort($rows, static function (array $left, array $right) use ($classIds, $object): int {
            $rank = static function (array $row) use ($classIds, $object): int {
                if ((int)$row['OBJECT_ID'] === (int)$object['ID']) return -1;
                $index = array_search((int)$row['CLASS_ID'], $classIds, true);
                return $index === false ? 1000 : (int)$index;
            };
            return $rank($left) <=> $rank($right);
        });
        return $rows;
    }

    private function propertiesFor(int $objectId, array $classIds): array
    {
        $condition = 'properties.OBJECT_ID=' . $objectId;
        if ($classIds !== array()) $condition .= ' OR properties.CLASS_ID IN (' . implode(',', array_map('intval', $classIds)) . ')';
        $rows = $this->db->select('SELECT properties.ID,properties.TITLE,properties.DESCRIPTION,properties.OBJECT_ID,'
            . 'properties.CLASS_ID,properties.ONCHANGE,properties.KEEP_HISTORY,pvalues.VALUE,pvalues.UPDATED,pvalues.LINKED_MODULES '
            . 'FROM properties LEFT JOIN pvalues ON pvalues.PROPERTY_ID=properties.ID AND pvalues.OBJECT_ID=' . $objectId
            . ' WHERE ' . $condition . ' ORDER BY properties.OBJECT_ID DESC,properties.TITLE LIMIT 200');
        $result = array();
        foreach ($rows as $row) {
            $result[] = array('title' => SensitiveText::clean((string)$row['TITLE']),
                'description' => SensitiveText::clean(mb_substr((string)($row['DESCRIPTION'] ?? ''), 0, 300)),
                'value' => SensitiveText::clean((string)($row['VALUE'] ?? '')),
                'origin' => (int)$row['OBJECT_ID'] > 0 ? 'object' : 'class',
                'handler' => SensitiveText::clean((string)($row['ONCHANGE'] ?? '')),
                'history' => (int)$row['KEEP_HISTORY'] === 1,
                'modules' => SensitiveText::clean((string)($row['LINKED_MODULES'] ?? '')));
        }
        return $result;
    }

    private function methodsFor(int $objectId, array $classIds): array
    {
        $condition = 'methods.OBJECT_ID=' . $objectId;
        if ($classIds !== array()) $condition .= ' OR methods.CLASS_ID IN (' . implode(',', array_map('intval', $classIds)) . ')';
        $rows = $this->db->select('SELECT ID,TITLE,DESCRIPTION,OBJECT_ID,CLASS_ID FROM methods WHERE ' . $condition
            . ' ORDER BY methods.OBJECT_ID DESC,methods.TITLE LIMIT 200');
        $result = array();
        foreach ($rows as $row) {
            $result[] = array('title' => SensitiveText::clean((string)$row['TITLE']),
                'description' => SensitiveText::clean(mb_substr((string)($row['DESCRIPTION'] ?? ''), 0, 300)),
                'origin' => (int)$row['OBJECT_ID'] > 0 ? 'object' : 'class');
        }
        return $result;
    }

    /** Переопределение объекта/ближайшего класса скрывает унаследованный дубль. */
    private static function dedupe(array $rows): array
    {
        $result = array();
        $seen = array();
        foreach ($rows as $row) {
            $key = mb_strtolower((string)$row['title']);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $result[] = $row;
        }
        return $result;
    }

    private function value(string $object, string $property): string
    {
        try {
            return (string)\MajorDoMo\Core\Core::objects()->value($object . '.' . $property);
        } catch (\Throwable) {
            $row = $this->db->one("SELECT pvalues.VALUE FROM pvalues JOIN properties ON properties.ID=pvalues.PROPERTY_ID "
                . "JOIN objects ON objects.ID=pvalues.OBJECT_ID WHERE objects.TITLE='" . $this->db->escape($object)
                . "' AND properties.TITLE='" . $this->db->escape($property) . "' LIMIT 1");
            return (string)($row['VALUE'] ?? '');
        }
    }

    /** Таблицы установленных модулей со структурной привязкой к объекту. */
    private function bindingTables(): array
    {
        $tables = array();
        try {
            $rows = $this->db->select("SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS "
                . "WHERE COLUMN_NAME='LINKED_OBJECT' AND TABLE_SCHEMA=DATABASE()");
            foreach ($rows as $row) $tables[] = (string)($row['TABLE_NAME'] ?? '');
        } catch (\Throwable) {
            try {
                foreach ($this->db->select("SELECT name,sql FROM sqlite_master WHERE type='table'") as $row) {
                    if (stripos((string)($row['sql'] ?? ''), 'LINKED_OBJECT') !== false) $tables[] = (string)$row['name'];
                }
            } catch (\Throwable) {
                return array();
            }
        }
        return array_values(array_filter(array_unique($tables), static fn(string $table): bool =>
            preg_match('/^[A-Za-z0-9_]+$/', $table) === 1));
    }

    private static function url(string $path): string
    {
        $root = defined('ROOTHTML') ? (string)ROOTHTML : '/';
        return rtrim($root, '/') . '/' . ltrim($path, '/');
    }

    private static function query(string $value, int $maximum): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maximum || !mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Ожидается корректное имя или запрос до ' . $maximum . ' символов.');
        }
        return $value;
    }

    private static function page(int $offset, int $limit, int $maximum): void
    {
        if ($offset < 0 || $offset > 1000000 || $limit < 1 || $limit > $maximum) {
            throw new InvalidArgumentException('Параметры страницы выходят за допустимые пределы.');
        }
    }

    private static function excerpt(string $code, string $needle): string
    {
        $position = mb_stripos($code, $needle);
        $start = $position === false ? 0 : max(0, $position - 80);
        return SensitiveText::clean(mb_substr($code, $start, 300));
    }
}
