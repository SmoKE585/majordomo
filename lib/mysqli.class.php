<?php

/**
 * MySQL database class
 *
 * Used by all modules to work with MySQL database
 *
 * @package framework
 * @author Serge Dzheigalo <info@au78.com>
 * @copyright http://www.au78.com/ (c) 2002-2008
 * @version 1.0b
 */

/**
 * MySQL database class
 * @category DataBase
 * @package Framework
 * @author Serge Dzheigalo <info@au78.com>
 * @copyright 2002-2008 Atmatic
 * @license http://opensource.org/licenses/mit-license.php MIT License
 * @link https://github.com/sergejey/majordomo/blob/master/lib/mysql.class.php
 */

if (!defined('MYSQL_BOTH')) {
    define('MYSQL_BOTH', MYSQLI_BOTH);
    define('MYSQL_NUM', MYSQLI_NUM);
    define('MYSQL_ASSOC', MYSQLI_ASSOC);
}


class mysql
{
    var $host;
    var $port;
    var $user;
    var $password;
    var $dbName; // database name
    var $dbh;
    var $latestTransaction;
    var $pingTimeout;
    public $connected;
    private $lastErrorNo = 0;
    private $lastErrorDetails = '';
    private $transactionActive = false;
    private bool $transactionAborted = false;
    private int $lastAffectedRows = 0;
    private int $lastInsertId = 0;
    public $queryCount = 0;
    public $querySeconds = 0.0;

    /**
     * MySQL constructor
     * used to create mysql database object and connect to database
     * @param string $host Mysql host
     * @param string $port Mysql port
     * @param string $user Mysql user
     * @param string $password Mysql password
     * @param string $database Mysql database name
     * @access public
     */
    public function __construct($host, $port, $user, $password, $database)
    {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->password = $password;
        $this->dbName = $database;
        $this->latestTransaction = time();
        $this->pingTimeout = 5 * 60;
        $this->Connect();
    }

    public function __destruct()
    {
        $this->Disconnect();
    }

    /**
     * Connects to database
     * @access private
     * @return int connection result (1 - success, 0 - failed)
     */
    public function Connect()
    {
        if ($this->dbh) return true;
        try {
            $connection = mysqli_init();
            $connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
            $connection->real_connect($this->host, $this->user, $this->password, $this->dbName, $this->port ?: ((int)ini_get('mysqli.default_port') ?: 3306));
            $connection->set_charset('utf8');
            $connection->query("SET collation_connection='utf8_unicode_ci'");
            if (defined('SETTINGS_SITE_TIMEZONE')) {
                $offset = (new DateTimeZone(SETTINGS_SITE_TIMEZONE))->getOffset(new DateTime());
                $zone = sprintf('%s%02d:%02d', $offset < 0 ? '-' : '+', intdiv(abs($offset), 3600), intdiv(abs($offset) % 3600, 60));
                $connection->query("SET time_zone='" . $zone . "'");
            }
            $this->dbh = $connection;
            $this->connected = true;
            $this->latestTransaction = time();
            return true;
        } catch (Throwable $e) {
            $this->dbh = null;
            $this->connected = false;
            $this->lastErrorNo = (int)$e->getCode();
            $this->lastErrorDetails = $e->getMessage();
            return false;
        }
    }

    /**
     * Execute SQL query
     * @param string $query SQL query
     * @param boolean $ignore_errors Ignore SQL errors
     * @return mixed execution result (0 - failed)
     * @access public
     */
    public function Exec($query, $ignore_errors = false)
    {
        if ($this->transactionAborted) {
            if (preg_match('/^\s*(?:ROLLBACK(?:\s+WORK)?\s*;?\s*$|BEGIN\b|START\s+TRANSACTION\b)/i', $query)) $this->transactionAborted = false;
            else {
                $this->lastErrorNo = 2013;
                $this->lastErrorDetails = 'Transaction connection was lost; explicit rollback or restart is required';
                if (!$ignore_errors) $this->Error($query, 0);
                return false;
            }
        }
        if (!$this->dbh && !$this->Connect()) return false;
        $this->latestTransaction = time();
        $result = false;
        $this->lastAffectedRows = 0;
        $this->lastInsertId = 0;
        $query_started = microtime(true);
        $this->queryCount++;
        $this->lastErrorNo = 0;
        $this->lastErrorDetails = '';
        try {
            $result = mysqli_query($this->dbh, $query);
        } catch (Throwable $e) {
            $this->lastErrorNo = (int)$e->getCode();
            $this->lastErrorDetails = $e->getMessage();
            $result = false;
        }

        if (!$result) {
            $errorNo = $this->lastErrorNo ? $this->lastErrorNo : (int)@mysqli_errno($this->dbh);
            $errorDetails = $this->lastErrorDetails ? $this->lastErrorDetails : (string)@mysqli_error($this->dbh);
            if ($this->isConnectionLostError($errorNo, $errorDetails)) {
                $in_transaction = $this->transactionActive;
                $this->Disconnect();
                $this->transactionAborted = $in_transaction;
                $read_only = preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $query)
                    && !preg_match('/FOR\s+UPDATE|LOCK\s+IN\s+SHARE|\bINTO\b|GET_LOCK|RELEASE_LOCK|LAST_INSERT_ID|NEXTVAL|@/i', $query);
                if ($this->Connect() && !$in_transaction && $read_only) {
                    try {
                        $this->queryCount++;
                        $result = mysqli_query($this->dbh, $query);
                    } catch (Throwable $e) {
                        $this->lastErrorNo = (int)$e->getCode();
                        $this->lastErrorDetails = $e->getMessage();
                    }
                }
                if (!$result) {
                    $this->lastErrorNo = $errorNo;
                    $this->lastErrorDetails = $in_transaction ? 'Connection lost during transaction; transaction was not replayed' : $errorDetails;
                }
            }
        }
        $this->querySeconds += microtime(true) - $query_started;
        if ($result) {
            if (preg_match('/^\s*(BEGIN|START\s+TRANSACTION)\b/i', $query)) $this->transactionActive = true;
            if (preg_match('/^\s*(COMMIT|ROLLBACK)\b/i', $query) && !preg_match('/^\s*ROLLBACK\s+TO\b/i', $query)) $this->transactionActive = (bool)preg_match('/\bAND\s+CHAIN\b/i', $query);
            $affected = mysqli_affected_rows($this->dbh);
            $insertId = mysqli_insert_id($this->dbh);
            try {
                if (class_exists('MajorDoMo\\Core\\Core')) \MajorDoMo\Core\Core::metadata()->changedBySql($query);
            } finally {
                $this->lastAffectedRows = $affected;
                $this->lastInsertId = $insertId;
            }
        }

        if (!$result && !$ignore_errors) {
            $this->Error($query, 0);
            return 0;
        }

        return $result;
    }

    /**
     * Execute SQL SELECT query and return all records
     *
     * This method returns records as array of assosiated arrays (by field names)
     *
     * @param string $query SQL SELECT query
     * @return array execution result
     * @access public
     */
    public function Select($query)
    {
        $res = array();
        if ($result = $this->Exec($query)) {
            while ($rec = mysqli_fetch_array($result, MYSQL_ASSOC)) {
                $res[] = $rec;
            }
        }

        return $res;
    }

    /**
     * Execute SQL SELECT query and return first record
     *
     * This method returns record assosiated array (by field names)
     *
     * @param string $query SQL SELECT query
     * @return array|void execution result
     * @access public
     */
    public function SelectOne($query)
    {
        if ($result = $this->Exec($query)) {
            $rec = mysqli_fetch_array($result, MYSQL_ASSOC);

            return $rec;
        }
    }

    public function getLastError(): array
    {
        return array('code' => $this->lastErrorNo, 'message' => $this->lastErrorDetails);
    }

    public function AffectedRows()
    {
        if (!$this->dbh) return 0;
        return $this->lastAffectedRows;
    }


    public function Ping()
    {
        if (!$this->dbh && !$this->Connect()) return false;
        return (bool)$this->Exec('SELECT 1', true);
    }

    private function assertIdentifier($identifier)
    {
        if (!is_string($identifier) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $identifier)) {
            throw new InvalidArgumentException('Invalid database identifier');
        }
    }

    private function isConnectionLostError($errorNo, $errorDetails)
    {
        if ((int)$errorNo === 2006 || (int)$errorNo === 2013) {
            return true;
        }
        if ($errorDetails && preg_match('/MySQL server has gone away|Lost connection to MySQL server/is', $errorDetails)) {
            return true;
        }
        return false;
    }


    /**
     * Execute SQL UPDATE query for one record
     *
     * Record is defined by assosiated array
     *
     * @param string $table Table to update
     * @param array $data Record to update
     * @param string $ndx Index field (used in WHERE part of SQL request)
     * @return int
     * @access public
     */
    public function Update($table, $data, $ndx = "ID")
    {
        if (is_array($ndx)) $ndx = 'ID';
        $this->assertIdentifier($table);
        $this->assertIdentifier($ndx);
        $qry = "UPDATE `$table` SET ";
        $qry_fields = array();
        foreach ($data as $field => $value) {
            if (is_Numeric($field) || $field == $ndx) continue;
            $this->assertIdentifier($field);
            if (!is_null($value)) {
                $qry_fields[] = "`$field`='" . $this->DBSafe1($value) . "'";
            } else {
                $qry_fields[] = "`$field`=NULL";
            }
        }
        $qry .= implode(', ', $qry_fields);
        if (!isset($data[$ndx])) {
            $data[$ndx] = '';
        }
        if (!$qry_fields) return 1;
        $qry .= " WHERE `$ndx` = '" . $this->DBSafe1($data[$ndx]) . "'";
        if (gr('debug_qry')) {
            dprint($qry, false);
        }
        if (!$this->Exec($qry)) {
            $this->Error($qry);
            return 0;
        }
        return 1;
    }

    /**
     * Execute SQL INSERT query for one record
     *
     * Record is defined by assosiated array
     *
     * @param string $table Table for new record
     * @param array $data Record to insert
     * @return int Execution result (0 - if failed, INSERT ID - if succeed)
     * @access public
     */
    public function Insert($table, &$data)
    {
        $this->assertIdentifier($table);
        $fields = "";
        $values = "";
        foreach ($data as $field => $value) {
            if (is_Numeric($field)) continue;
            $this->assertIdentifier($field);
            $fields .= "`$field`, ";
            $values .= "'" . $this->DBSafe1($value) . "', ";
        }

        $fields = substr($fields, 0, strlen($fields) - 2);
        $values = substr($values, 0, strlen($values) - 2);

        $qry = "INSERT INTO `$table`($fields) VALUES($values)";

        if (!$this->Exec($qry)) {
            $this->error($qry);
            return 0;
        }

        return $this->lastInsertId;
    }

    /**
     * Disconnect from database
     *
     * @access public
     * @return void
     */
    public function Disconnect()
    {
        if ($this->dbh) mysqli_close($this->dbh);
        $this->dbh = null;
        $this->connected = false;
        $this->transactionActive = false;
    }

    /**
     * Used to strip "bad" symbols from sql query results
     *
     * @param string $str string to make "safe"
     * @return string correct string
     * @access public
     */
    public function DbSafe($str)
    {
        if (!$this->connected) {
            echo "Database is not connected!";
            exit;
        }
        if ($str != '') {
            $str = mysqli_real_escape_string($this->dbh, $str);
            $str = str_replace("%", "\%", $str);
        }
        return $str;
    }

    /**
     * Used to strip "bad" symbols from sql query results
     * @param mixed $str string to make "safe"
     * @access public
     * @return string
     */
    public function DbSafe1($str)
    {
        if (!$this->connected) {
            echo "Database is not connected!";
            exit;
        }
        if (is_array($str)) {
            $str = json_encode($str);
        }
        $str = mysqli_real_escape_string($this->dbh, (string)$str);
        return $str;
    }

    /**
     * MySQL database error handler
     *
     * @param string $query used query string
     * @access private
     * @return int
     */
    public function Error($query = "", $stop = 0)
    {
        if (!$this->dbh) return false;
        $err_no = $this->lastErrorNo ? $this->lastErrorNo : mysqli_errno($this->dbh);
        $err_details = $this->lastErrorDetails ? $this->lastErrorDetails : mysqli_error($this->dbh);
        $stop = 1;
        if (preg_match('/You have an error in your SQL syntax/is', $err_details)) {
            $stop = 0;
        } elseif (preg_match('/unknown column/is', $err_details)) {
            unlink(ROOT . 'cms/modules_installed/control_modules.installed');
            $stop = 0;
        }
        registerError('sql', "Query: " . $query . "\n" . $err_no . ": " . $err_details . "\n$query");
        new custom_error($err_no . ": " . $err_details . "<br>$query", $stop);
        return 1;
    }

    /**
     * Returns mysql table definition string (CREATE TABLE ... ;)
     *
     * @param string $table table to get structure
     * @return string table definition
     * @access public
     */
    public function get_mysql_def($table)
    {
        $result = $this->Exec('SHOW CREATE TABLE ' . $table);

        if ($result) {
            $row = mysqli_fetch_row($result);

            if (!$row)
                return '';

            $row[1] = preg_replace('/DEFAULT CHARSET=(\w+)/', '', $row[1]);
            $row[1] = str_replace(' default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP', '', $row[1]);
            $row[1] = str_replace("\n", ' ', $row[1]);

            $strs = trim($row[1]);

            return (empty($strs)) ? '' : 'DROP TABLE IF EXISTS ' . $table . ';' . "\n" . $row[1] . ';' . "\n";
        }

        return '';
    }

    /**
     * Returns mysql table content string (INSERT INTO ... ;)
     *
     * @param string $table table to get content
     * @return string table content
     * @access public
     */
    public function get_mysql_content($table)
    {
        $content = "";
        $result = $this->Exec("SELECT * FROM $table");

        while ($row = mysqli_fetch_row($result)) {
            $insert = "INSERT INTO $table VALUES (";

            $filedsNum = mysqli_num_fields($result);

            for ($j = 0; $j < $filedsNum; $j++) {
                if (!isset($row[$j])) $insert .= "NULL,";
                else if ($row[$j] != "") $insert .= "'" . $this->DbSafe($row[$j]) . "',";
                else                     $insert .= "'',";
            }

            $insert = preg_replace("/,$/", "", $insert);
            $insert .= ");\n";
            $content .= $insert;
        }

        return $content;
    }
}
