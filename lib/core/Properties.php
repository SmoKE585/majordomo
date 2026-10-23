<?php

namespace MajorDoMo\Core;

use \Throwable;
use \RuntimeException;

/**
 * Запись и чтение свойств объектов.
 *
 * Сохраняет значение, обновляет кеш и историю, ведёт журнал изменений,
 * вызывает ONCHANGE и ставит уведомления привязанным модулям. Цепочка
 * изменений не даёт автоматизации зациклиться.
 *
 * Изменения:
 * 2026-10-23 — одинаковые сохранённые значения не создают повторов в WebSocket
 *              из-за различия типов строки и числа.
 * 2026-10-22 — boolean-значение сохраняется как '0'/'1', а не как пустая строка:
 *              false больше не выглядит в админке как незаполненное свойство.
 * 2026-10-22 — исключение модуля-источника действует только на то свойство,
 *              о котором его уведомили; вложенные привязки больше не теряют
 *              подписчика.
 * 2026-10-22 — уведомления модулей ставятся напрямую в очередь ядра.
 * 2026-10-22 — сообщение об остановке цепочки объясняет причину и что делать.
 */
final class Properties
{
    public function __construct(
        private Database $db,
        private Cache $cache,
        private MetadataCache $metadata,
        private PropertyLock $locks,
        private WebSocketQueue $websockets,
        private CodeExecutor $code,
        private ?ErrorReporter $errors = null,
        private ?PropertyAudit $audit = null
    ) {}

    /**
     * Kept for compatibility: the implementation lives in PropertyBindings.
     * New code should call Core::bindings()->detach().
     */
    public function removeLinkedModule(string $object, string $property, string $module): bool
    {
        return Core::bindings()->removeLinkedModule($object, $property, $module);
    }

    private function reportChainStop(string $object, string $property, array $chain): void
    {
        $full = $object . '.' . $property;
        $token = PropertyChain::token($object, $property);
        if (in_array($token, $chain, true)) {
            $reason = 'свойство повторно изменилось во время обработки собственного изменения';
            $action = 'найдите в стеке обработчик (модуль, метод или сценарий), который записывает это же свойство в ответ на его изменение, и уберите обратную запись; если она нужна другим подписчикам, вынесите её в отдельное свойство или источник';
        } else {
            $reason = 'цепочка изменений превысила ' . PropertyChain::MAX_LENGTH . ' шагов';
            $action = 'проверьте цепочку: автоматизация ходит по кругу через разные свойства';
        }
        $tail = array_slice($chain, -5);
        if (($tail[count($tail) - 1] ?? null) !== $token) {
            $tail[] = $token;
        }
        $message = 'Цикл привязок остановлен для ' . $full . ': ' . $reason . '.'
            . "\nЗначение сохранено; уведомления модулям и ONCHANGE для этого шага не отправлены."
            . "\nЦепочка (последние шаги): " . implode(' -> ', $tail)
            . "\nЧто делать: " . $action . '.';
        if ($this->errors) {
            $this->errors->report('property_chain', $message, $full, 60);
        } elseif (function_exists('DebMes')) {
            DebMes($message, 'property_chain', 'warning');
        }
    }

    /**
     * Гасит эхо модуля только на том свойстве, о котором его уведомили.
     *
     * Обработчик модуля (и любой следующий метод цепочки) часто передаёт
     * NO_LINKED дальше. Раньше из-за этого модуль-источник навсегда выпадал из
     * уведомлений для всех следующих свойств: например, zigbee записывал кнопку,
     * её метод — свойство устройства, и устройство уже не получало команду,
     * хотя визуально значение менялось. Теперь исключение источника действует
     * ровно на одно свойство, а на остальных он остаётся обычным подписчиком.
     *
     * @param mixed $no_linked
     * @return mixed
     */
    private function scopeDeliveryExclusions($no_linked, string $target_token)
    {
        if (!is_array($no_linked)) return $no_linked;
        $module = PropertyChain::source();
        if ($module === '') return $no_linked;
        $point = PropertyChain::point();
        // Пустая точка — старый вызов без сведений о доставке: поведение
        // прежнее, исключаем источник для всех записей.
        if ($point === '' || $point === $target_token) {
            $no_linked[$module] = 0;
        } else {
            unset($no_linked[$module]);
        }
        return $no_linked;
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
        // bool — это состояние 0/1, а не «пусто»: иначе false сохранялся как
        // '', карточка свойства показывала «Значение не задано», а журнал и
        // модули видели 0. Пустое значение задаётся '' или null.
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
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

            // Останавливаем цепочку, если это свойство уже встречалось или шагов
            // стало слишком много: так автоматизация A → модуль → A не зациклится.
            $chain = PropertyChain::current();
            $chain_token = PropertyChain::token($object->object_title, $property);
            $chain_stopped = count($chain) >= PropertyChain::MAX_LENGTH || in_array($chain_token, $chain, true);
            $next_chain = PropertyChain::append($chain, $chain_token);
            // Эхо гасим только для того свойства, о котором уведомили модуль.
            // Всё, что записано глубже по цепочке, должно доходить до него как
            // до обычного подписчика — иначе привязка «пропадает» на втором
            // уровне вложенности (модуль записал кнопку, её метод — устройство,
            // а устройство команду уже не получает).
            $no_linked = $this->scopeDeliveryExclusions($no_linked, $chain_token);
            if ($chain_stopped) {
                $this->reportChainStop($object->object_title, $property, $chain);
                $this->audit?->note('chain', 'Цикл привязок остановлен', array(
                    'object_id' => (int)$object->id,
                    'property_id' => (int)$id,
                    'property_name' => $object->object_title . '.' . $property,
                    'source' => $source,
                    'chain' => $chain,
                ));
            }

            // The journal stores actual value changes only. A repeated value is
            // still delivered to ONCHANGE/subscribers, but does not create a
            // second "changed" row for every sensor poll.
            $audit_owned = false;
            if ($this->audit && (string)$old_value !== (string)$value) {
                $full_name = $object->object_title . '.' . $property;
                $audit_owned = $this->audit->beginIfMissing('property', $full_name, array(), array('source' => $source));
                $this->audit->propertyChanged(array(
                    'title' => $full_name,
                    'object_id' => (int)$object->id,
                    'property_id' => (int)$id,
                    'property_name' => $full_name,
                    'old_value' => $old_value === false ? '' : $old_value,
                    'new_value' => $value,
                    'source' => $source,
                    'chain' => $next_chain,
                ));
            }

            $skip_notifications = !empty($v['LINKED_MODULES']) && !is_array($no_linked) && $no_linked;
            if (!$skip_notifications && function_exists('postToWebSocketQueue')) {
                startMeasure('setproperty_postwebsocketqueue');
                // В БД значения хранятся строками: число 1 и строка '1'
                // обозначают одно состояние и не требуют повторной отправки.
                if ($old_value === false || (string)$old_value !== $v['VALUE']) {
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

            $abort_after_save = false;
            if (!$chain_stopped && isset($v['LINKED_MODULES']) && $v['LINKED_MODULES']) {
                if (!is_array($no_linked) && $no_linked) {
                    // Legacy callers use a truthy $no_linked as "value only":
                    // no module deliveries and no ONCHANGE after this point.
                    $abort_after_save = true;
                } else {
                    if (!is_array($no_linked)) {
                        $no_linked = array();
                    }
                    // Для записи без явного NO_LINKED исключение источника
                    // добавляется здесь; для переданного массива оно уже
                    // ограничено своим свойством выше.
                    $no_linked = $this->scopeDeliveryExclusions($no_linked, $chain_token);

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
                        $params['_chain'] = $next_chain;
                        PropertyAudit::attach($params);
                        try {
                            if (!Core::queue()->enqueueRuntime('modulepropertyset', $params, (string)$linked_module)) throw new RuntimeException('Linked operation was not queued');
                        } catch (Throwable $e) {
                            // The value is already saved. A failed notification is
                            // reported once per module/window, not per value.
                            $linked_operation_errors[] = $e;
                            $this->errors?->report('property_delivery',
                                'Не удалось поставить в очередь уведомление модулю ' . $linked_module
                                . ' для ' . $object->object_title . '.' . $property . ': ' . $e->getMessage(),
                                $linked_module);
                        }
                        endMeasure('linkedModule' . $linked_module);
                    }
                    endMeasure('linkedModulesProcessing');
                }
            }

            if ($abort_after_save) {
                if ($audit_owned) {
                    $this->audit?->finishRun('done');
                    $this->audit?->leave();
                }
                return;
            }

        } catch (Throwable $e) {
            $this->cache->delete($cached_name ?? ('MJD:' . $object->object_title . '.' . $property));
            if (!empty($audit_owned)) {
                $this->audit?->finishRun('error', null, $e);
                $this->audit?->leave();
            }
            throw $e;
        } finally {
            $this->locks->release($property_lock);
        }

        try {
            if (!$chain_stopped && isset($prop['ONCHANGE']) && $prop['ONCHANGE']) {
                global $property_linked_history;
                if (!isset($property_linked_history[$object->object_title . '.' . $property][$prop['ONCHANGE']])) {
                    $property_linked_history[$object->object_title . '.' . $property][$prop['ONCHANGE']] = 1;
                    $params = array();
                    $params['PROPERTY'] = $property;
                    $params['NEW_VALUE'] = (string)$value;
                    $params['OLD_VALUE'] = (string)$old_value;
                    $params['SOURCE'] = (string)$source;
                    $params['NO_LINKED'] = $no_linked;
                    $params['_chain'] = $next_chain;
                    // Обработчик выполнится, возможно, в другом процессе:
                    // передаём ему точку доставки, чтобы и там исключение
                    // модуля-источника действовало только на одно свойство.
                    $params['_delivery'] = PropertyChain::delivery();
                    PropertyAudit::attach($params);
                    try {
                        // Always enqueue: a web request must not wait for user code,
                        // and web/CLI must behave identically. The worker executes
                        // the method and carries the chain into nested writes.
                        $object->raiseEvent($prop['ONCHANGE'], $params);
                    } finally {
                        unset($property_linked_history[$object->object_title . '.' . $property][$prop['ONCHANGE']]);
                    }
                }
            }
        } catch (Throwable $e) {
            if ($audit_owned) {
                $this->audit?->finishRun('error', null, $e);
                $this->audit?->leave();
            }
            throw $e;
        } finally {
            if ($audit_owned) {
                $this->audit?->finishRun('done');
                $this->audit?->leave();
            }
        }

        if ($linked_operation_errors && Config::bool('PROPERTY_DELIVERY_STRICT')) {
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
