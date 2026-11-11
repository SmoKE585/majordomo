<?php

declare(strict_types=1);

namespace MajorDoMo\Core;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Создание сущностей автоматизации без исполнения кода и неявных родителей.
 * Изменения:
 * 2026-11-11 — методы классов и объектов, правка с проверкой исходной версии.
 * 2026-10-23 — общий write-path для подтверждённых действий и административных клиентов.
 */
final class EntityCreator
{
    public function __construct(private Database $db, private Closure $invalidate, private bool $sqlite = false) {}

    public static function validate(array $input): array
    {
        if (array_diff(array_keys($input), array('kind', 'name', 'parent', 'description', 'code', 'operation', 'call_parent')) !== array()) {
            throw new InvalidArgumentException('Неизвестный параметр создания.');
        }
        $data = array();
        foreach (array('kind', 'name', 'parent', 'description', 'code', 'operation', 'call_parent') as $key) {
            $value = $input[$key] ?? '';
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
                throw new InvalidArgumentException('Параметры создания должны быть текстом UTF-8.');
            }
            $data[$key] = $key === 'code' ? $value : trim($value);
        }
        $data['operation'] = $data['operation'] ?: 'create';
        $method = in_array($data['kind'], array('class_method', 'object_method'), true);
        if (!in_array($data['operation'], array('create', 'update'), true) || ($data['operation'] === 'update' && !$method)) {
            throw new InvalidArgumentException('Изменять можно только метод класса или объекта.');
        }
        if (!in_array($data['call_parent'], array('', '0', '1', '2'), true) || (!$method && $data['call_parent'] !== '')) {
            throw new InvalidArgumentException('call_parent для метода: 0 — не вызывать, 1 — до, 2 — после; пусто — сохранить.');
        }
        if (!in_array($data['kind'], array('script', 'class', 'object', 'class_property', 'object_property', 'class_method', 'object_method'), true)) {
            throw new InvalidArgumentException('Неизвестный тип сущности.');
        }
        if (!preg_match('/^[\p{L}_][\p{L}\p{N}_-]{0,189}$/uD', $data['name'])) {
            throw new InvalidArgumentException('Имя: до 190 букв, цифр, дефисов и подчёркиваний, без пробелов и точки.');
        }
        if (mb_strlen($data['parent']) > 190 || mb_strlen($data['description']) > 4000 || strlen($data['code']) > 60000) {
            throw new InvalidArgumentException('Превышен размер имени, описания или кода.');
        }
        if (in_array($data['kind'], array('object', 'class_property', 'object_property', 'class_method', 'object_method'), true) && $data['parent'] === '') {
            throw new InvalidArgumentException('Укажите существующий класс или объект в parent.');
        }
        if ($data['kind'] === 'script' || $method) {
            if (($data['kind'] === 'script' && trim($data['code']) === '') || str_contains($data['code'], '<?') || str_contains($data['code'], '?>')) {
                throw new InvalidArgumentException('Код сценария или метода должен быть PHP без открывающего и закрывающего тегов.');
            }
            try { token_get_all("<?php\n" . $data['code'], TOKEN_PARSE); }
            catch (\ParseError $failure) { throw new InvalidArgumentException('Синтаксическая ошибка PHP: ' . $failure->getMessage()); }
            if (!$method && $data['parent'] !== '') throw new InvalidArgumentException('Сценарий не имеет родительского класса.');
        } elseif ($data['code'] !== '') {
            throw new InvalidArgumentException('Код допустим только для сценария или метода.');
        }
        return $data;
    }

    public function create(array $input): array
    {
        $data = self::validate($input);
        if ($data['operation'] !== 'create') throw new InvalidArgumentException('Правка требует исходной версии метода.');
        $lock = 'mjd:create:' . sha1($data['kind'] . '|' . $data['parent'] . '|' . $data['name']);
        if (!$this->sqlite && (int)($this->db->one("SELECT GET_LOCK('" . $lock . "',5) AS L")['L'] ?? 0) !== 1) {
            throw new RuntimeException('Сущность сейчас создаётся другим запросом. Повторите позже.');
        }
        try {
            $parent = 0;
            if ($data['parent'] !== '') {
                $parentTable = in_array($data['kind'], array('object_property', 'object_method'), true) ? 'objects' : 'classes';
                $record = $this->db->one('SELECT ID FROM ' . $parentTable . " WHERE TITLE='" . $this->db->escape($data['parent']) . "'");
                if ($record === null) throw new InvalidArgumentException('Родитель не найден. Сначала создайте и подтвердите его отдельно.');
                $parent = (int)$record['ID'];
            }
            $fields = array('TITLE' => $data['name'], 'DESCRIPTION' => $data['description']);
            $table = match ($data['kind']) {
                'script' => 'scripts', 'class' => 'classes', 'object' => 'objects',
                'class_method', 'object_method' => 'methods', default => 'properties',
            };
            $where = "TITLE='" . $this->db->escape($data['name']) . "'";
            if ($table === 'methods') {
                $fields['OBJECT_ID'] = $data['kind'] === 'object_method' ? $parent : 0;
                $fields['CLASS_ID'] = $data['kind'] === 'class_method' ? $parent : 0;
                $fields['CODE'] = $data['code'];
                $fields['CALL_PARENT'] = (int)$data['call_parent'];
                $fields['SCRIPT_ID'] = 0;
                $where .= ' AND OBJECT_ID=' . $fields['OBJECT_ID'] . ($fields['OBJECT_ID'] > 0 ? '' : ' AND CLASS_ID=' . $fields['CLASS_ID']);
            } elseif ($table === 'properties') {
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

    /** Снимок только собственного метода: правка объекта не меняет его класс. */
    public static function methodSnapshot(Database $db, array $input): array
    {
        $data = self::validate($input);
        if (!in_array($data['kind'], array('class_method', 'object_method'), true)) throw new InvalidArgumentException('Ожидался метод.');
        $object = $data['kind'] === 'object_method';
        $owner = $db->one('SELECT ID FROM ' . ($object ? 'objects' : 'classes') . " WHERE TITLE='" . $db->escape($data['parent']) . "'");
        if ($owner === null) throw new InvalidArgumentException('Владелец метода не найден.');
        $row = $db->one("SELECT ID,TITLE,CLASS_ID,OBJECT_ID,CODE,DESCRIPTION,CALL_PARENT,SCRIPT_ID FROM methods WHERE TITLE='"
            . $db->escape($data['name']) . "' AND " . ($object ? 'OBJECT_ID=' . (int)$owner['ID'] : 'OBJECT_ID=0 AND CLASS_ID=' . (int)$owner['ID']));
        if ($row === null) throw new InvalidArgumentException('Собственный метод не найден. Для унаследованного метода создайте явное переопределение у выбранного владельца.');
        $snapshot = array_map(static fn($value): string => (string)$value, $row);
        if ((int)$row['SCRIPT_ID'] > 0) {
            $script = $db->one('SELECT TITLE,CODE FROM scripts WHERE ID=' . (int)$row['SCRIPT_ID']);
            if ($script === null) throw new InvalidArgumentException('Связанный сценарий метода не найден. Проверьте привязку.');
            $snapshot['_SCRIPT_TITLE'] = (string)$script['TITLE'];
            $snapshot['_SCRIPT_CODE'] = (string)$script['CODE'];
        }
        return $snapshot;
    }

    public function updateMethod(array $input, array $expected): array
    {
        $keepDescription = !array_key_exists('description', $input);
        $data = self::validate($input);
        if ($data['operation'] !== 'update') throw new InvalidArgumentException('Ожидалась правка метода.');
        $current = self::methodSnapshot($this->db, $data);
        if ($current !== $expected) throw new RuntimeException('Метод изменился после подготовки предложения. Запросите новую правку.');
        $fields = array('CODE' => $data['code'], 'DESCRIPTION' => $keepDescription ? $current['DESCRIPTION'] : $data['description'],
            'CALL_PARENT' => $data['call_parent'] === '' ? $current['CALL_PARENT'] : $data['call_parent'], 'SCRIPT_ID' => '0');
        $changed = false;
        foreach ($fields as $key => $value) if ($current[$key] !== $value) $changed = true;
        if ($changed) {
            $assign = array(); $conditions = array();
            foreach ($fields as $key => $value) $assign[] = $key . "='" . $this->db->escape($value) . "'";
            // Условная запись защищает и от обычного редактора, не берущего GET_LOCK.
            foreach ($expected as $key => $value) {
                if (str_starts_with($key, '_SCRIPT_')) continue;
                $column = 'COALESCE(' . $key . ",'')";
                $conditions[] = ($this->sqlite ? 'CAST(' . $column . ' AS TEXT)' : 'BINARY ' . $column) . "='" . $this->db->escape($value) . "'";
            }
            if ((int)$current['SCRIPT_ID'] > 0) {
                $scriptConditions = array();
                foreach (array('TITLE', 'CODE') as $key) {
                    $column = 'COALESCE(source_script.' . $key . ",'')";
                    $scriptConditions[] = ($this->sqlite ? 'CAST(' . $column . ' AS TEXT)' : 'BINARY ' . $column)
                        . "='" . $this->db->escape($expected['_SCRIPT_' . $key]) . "'";
                }
                $conditions[] = 'EXISTS(SELECT 1 FROM scripts source_script WHERE source_script.ID=methods.SCRIPT_ID AND ' . implode(' AND ', $scriptConditions) . ')';
            }
            $this->db->requireExec('UPDATE methods SET ' . implode(',', $assign) . ' WHERE ' . implode(' AND ', $conditions));
            if ($this->db->affectedRows() !== 1) throw new RuntimeException('Метод изменился во время подтверждения. Запросите новую правку.');
        }
        $warning = '';
        try { ($this->invalidate)(); }
        catch (\Throwable $failure) {
            error_log('MajorDoMo method metadata invalidation: ' . get_class($failure));
            $warning = 'Метод сохранён, но не удалось обновить кэш метаданных. Проверьте службу кэша.';
        }
        return array('id' => (int)$current['ID'], 'executed' => false, 'warning' => $warning);
    }
}
