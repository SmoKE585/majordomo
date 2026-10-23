<?php

declare(strict_types=1);

namespace MajorDoMo\Core;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Создание сущностей автоматизации без исполнения кода и неявных родителей.
 * Изменения:
 * 2026-10-23 — общий write-path для подтверждённых действий и административных клиентов.
 */
final class EntityCreator
{
    public function __construct(private Database $db, private Closure $invalidate, private bool $sqlite = false) {}

    public static function validate(array $input): array
    {
        if (array_diff(array_keys($input), array('kind', 'name', 'parent', 'description', 'code')) !== array()) {
            throw new InvalidArgumentException('Неизвестный параметр создания.');
        }
        $data = array();
        foreach (array('kind', 'name', 'parent', 'description', 'code') as $key) {
            $value = $input[$key] ?? '';
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
                throw new InvalidArgumentException('Параметры создания должны быть текстом UTF-8.');
            }
            $data[$key] = $key === 'code' ? $value : trim($value);
        }
        if (!in_array($data['kind'], array('script', 'class', 'object', 'class_property', 'object_property'), true)) {
            throw new InvalidArgumentException('Неизвестный тип сущности.');
        }
        if (!preg_match('/^[\p{L}_][\p{L}\p{N}_-]{0,189}$/uD', $data['name'])) {
            throw new InvalidArgumentException('Имя: до 190 букв, цифр, дефисов и подчёркиваний, без пробелов и точки.');
        }
        if (mb_strlen($data['parent']) > 190 || mb_strlen($data['description']) > 4000 || strlen($data['code']) > 60000) {
            throw new InvalidArgumentException('Превышен размер имени, описания или кода.');
        }
        if (in_array($data['kind'], array('object', 'class_property', 'object_property'), true) && $data['parent'] === '') {
            throw new InvalidArgumentException('Укажите существующий класс или объект в parent.');
        }
        if ($data['kind'] === 'script') {
            if (trim($data['code']) === '' || str_contains($data['code'], '<?') || str_contains($data['code'], '?>')) {
                throw new InvalidArgumentException('Сценарий должен содержать PHP-код без открывающего и закрывающего тегов.');
            }
            try { token_get_all("<?php\n" . $data['code'], TOKEN_PARSE); }
            catch (\ParseError $failure) { throw new InvalidArgumentException('Синтаксическая ошибка PHP: ' . $failure->getMessage()); }
            if ($data['parent'] !== '') throw new InvalidArgumentException('Сценарий не имеет родительского класса.');
        } elseif ($data['code'] !== '') {
            throw new InvalidArgumentException('Код допустим только при создании сценария.');
        }
        return $data;
    }

    public function create(array $input): array
    {
        $data = self::validate($input);
        $lock = 'mjd:create:' . sha1($data['kind'] . '|' . $data['parent'] . '|' . $data['name']);
        if (!$this->sqlite && (int)($this->db->one("SELECT GET_LOCK('" . $lock . "',5) AS L")['L'] ?? 0) !== 1) {
            throw new RuntimeException('Сущность сейчас создаётся другим запросом. Повторите позже.');
        }
        try {
            $parent = 0;
            if ($data['parent'] !== '') {
                $parentTable = $data['kind'] === 'object_property' ? 'objects' : 'classes';
                $record = $this->db->one('SELECT ID FROM ' . $parentTable . " WHERE TITLE='" . $this->db->escape($data['parent']) . "'");
                if ($record === null) throw new InvalidArgumentException('Родитель не найден. Сначала создайте и подтвердите его отдельно.');
                $parent = (int)$record['ID'];
            }
            $fields = array('TITLE' => $data['name'], 'DESCRIPTION' => $data['description']);
            $table = match ($data['kind']) {
                'script' => 'scripts', 'class' => 'classes', 'object' => 'objects', default => 'properties',
            };
            $where = "TITLE='" . $this->db->escape($data['name']) . "'";
            if ($table === 'properties') {
                $fields['OBJECT_ID'] = $data['kind'] === 'object_property' ? $parent : 0;
                $fields['CLASS_ID'] = $data['kind'] === 'class_property' ? $parent : 0;
                $classId = $fields['CLASS_ID'] ?: (int)($this->db->one('SELECT CLASS_ID FROM objects WHERE ID=' . $parent)['CLASS_ID'] ?? 0);
                $classes = array();
                while ($classId > 0) {
                    if (isset($classes[$classId]) || count($classes) >= 64) throw new RuntimeException('Некорректная цепочка наследования класса.');
                    $classes[$classId] = $classId;
                    $classId = (int)($this->db->one('SELECT PARENT_ID FROM classes WHERE ID=' . $classId)['PARENT_ID'] ?? 0);
                }
                $owners = array();
                if ($fields['OBJECT_ID'] > 0) $owners[] = 'OBJECT_ID=' . $fields['OBJECT_ID'];
                if ($classes !== array()) $owners[] = '(OBJECT_ID=0 AND CLASS_ID IN (' . implode(',', $classes) . '))';
                $where .= ' AND (' . implode(' OR ', $owners) . ')';
            } elseif ($table === 'objects') $fields['CLASS_ID'] = $parent;
            elseif ($table === 'classes') $fields['PARENT_ID'] = $parent;
            else $fields['CODE'] = $data['code'];
            if ($this->db->one('SELECT ID FROM ' . $table . ' WHERE ' . $where) !== null) {
                throw new InvalidArgumentException('Сущность с таким именем уже существует. Существующие данные не изменены.');
            }
            $id = $this->db->insert($table, $fields);
            if ($id < 1) throw new RuntimeException('Не удалось создать сущность.');
            $warning = '';
            try { ($this->invalidate)(); }
            catch (\Throwable $failure) {
                error_log('MajorDoMo entity metadata invalidation: ' . get_class($failure));
                $warning = 'Не удалось обновить кэш метаданных. Сущность уже создана; проверьте состояние службы кэша.';
            }
            return array('id' => $id, 'kind' => $data['kind'], 'name' => $data['name'], 'executed' => false, 'warning' => $warning);
        } finally {
            if (!$this->sqlite) $this->db->requireExec("SELECT RELEASE_LOCK('" . $lock . "')");
        }
    }
}
