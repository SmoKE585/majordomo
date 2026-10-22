<?php

namespace MajorDoMo\Core;


final class WebSocketQueue
{
    public function __construct(private Database $db, private RedisConnection $redis, private OperationsQueue $queue) {}

    public function claim($limit)
    {
        if (Config::get('USE_REDIS')) {
            $result = array();
            foreach ($this->queue->claim('websocket', $limit, 60) as $item) {
                $data = json_decode($item['DATAVALUE'], true, 512, JSON_THROW_ON_ERROR);
                $result[] = array_merge($item, $data);
            }
            return $result;
        }
        return $this->db->select('SELECT * FROM cached_ws ORDER BY ADDED LIMIT ' . max(1, (int)$limit));
    }

    public function acknowledge($row)
    {
        if (isset($row['QUEUE_ID'])) return $this->queue->acknowledge($row);
        return $this->db->exec("DELETE FROM cached_ws WHERE PROPERTY='" . $this->db->escape($row['PROPERTY']) . "' AND REVISION='" . $this->db->escape($row['REVISION']) . "'");
    }

    public function release($row)
    {
        if (isset($row['QUEUE_ID'])) return $this->queue->release($row, 1);
    }

    public function post($property, $value, $post_action = 'PostProperty')
    {
        if (Config::get('USE_REDIS')) {
            return $this->queue->enqueue('websocket', $post_action . ':' . $property,
                json_encode(array('PROPERTY' => $property, 'DATAVALUE' => is_array($value) ? $value : (string)$value, 'POST_ACTION' => $post_action), JSON_THROW_ON_ERROR), true, 3600);
        }
        //$this->db->exec("DELETE FROM cached_ws WHERE PROPERTY='" . $this->db->escapeLike($property) . "'");
        $rec = array();
        $rec['PROPERTY'] = $property;
        $rec['DATAVALUE'] = $value;
        $rec['POST_ACTION'] = $post_action;
        $rec['ADDED'] = date('Y-m-d H:i:s');
        $rec['REVISION'] = bin2hex(random_bytes(16));

        $fields = "";
        $values = "";
        $table = 'cached_ws';

        foreach ($rec as $field => $value) {
            if (is_Numeric($field)) continue;
            $fields .= "`$field`, ";
            $values .= "'" . $this->db->escape($value) . "', ";
        }

        $fields = substr($fields, 0, strlen($fields) - 2);
        $values = substr($values, 0, strlen($values) - 2);
        $query = "REPLACE INTO `$table`($fields) VALUES($values)";

        $res = $this->db->exec($query, true);
        return $res;
        //$this->db->insert('cached_ws', $rec);
    }
}
