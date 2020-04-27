<?php
/****************************************************************
  Copyright (Turn s.r.o.) 2017
  Derived from and origins proudly attributed to http://kissmvc.com

  Permission is hereby granted, free of charge, to any person
  obtaining a copy of this software and associated documentation
  files (the "Software"), to deal in the Software without
  restriction, including without limitation the rights to use,
  copy, modify, merge, publish, distribute, sublicense, and/or sell
  copies of the Software, and to permit persons to whom the
  Software is furnished to do so, subject to the following
  conditions:

  The above copyright notice and this permission notice shall be
  included in all copies or substantial portions of the Software.

  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
  EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES
  OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
  NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
  HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
  WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
  FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
  OTHER DEALINGS IN THE SOFTWARE.
 *****************************************************************/


//===============================================================
// NotFoundException
// Exception throws when controller or function not found
//===============================================================
class NotFoundException extends Exception {
    public function __construct($message = "") {
        parent::__construct($message, 404);
    }
}

//===============================================================
// Mvc
// Parses the HTTP request and routes to the appropriate function
//===============================================================
class Mvc {
    private static $instance;
    static function getInstance() {
        return self::$instance;
    }

    private $controller;
    private $action;

    public function __construct($defaultController = '', $defaultAction = '') {
        if (self::$instance) {
            throw new Exception('Can\'t instantiate more than one Mvc class', 500);
        }
        self::$instance = $this;

        $this->controller = $defaultController;
        $this->action = $defaultAction;
        spl_autoload_register(function($classname) {
            $classname = str_replace('\\', '/', $classname);
            if (file_exists(APP_PATH . 'models/' . $classname . '.php')) {
                require_once(APP_PATH . 'models/' . $classname . '.php');
            } else if (file_exists(APP_PATH . 'helpers/' . $classname . '.php')) {
                require_once(APP_PATH . 'helpers/' . $classname . '.php');
            }
        });
    }

    /**
     * Override to return a PDO to your database. 
     * 
     * The parameter is the model class name in case you have two or more databases.
     */
    public function getPdo($modelClass = '') {
        return null;
    }

    /**
     * Used for the main request routing.
     */
    public function route() {
        try {
            $requri = $_SERVER['REQUEST_URI'];
            if (strpos($requri, WEB_FOLDER) === 0) {
                $requri = substr($requri, strlen(WEB_FOLDER));
            }
            // Parse the controller and action from the URI
            $uriParts = $requri ? explode('?', $requri) : array();
            $parts = $uriParts[0] ? explode('/', $uriParts[0]) : array();
            if (isset($parts[0]) && $parts[0]) {
                $this->controller = $parts[0];
            }
            if (isset($parts[1]) && $parts[1]) {
                $this->action = $parts[1];
            }
            $this->args = isset($parts[2]) ? array_slice($parts, 2) : [];
            // Find the controller and process the request
            $controllerClassName = ucfirst($this->controller).'Controller';
            include (APP_PATH . 'controllers/' . $controllerClassName . '.php');
            if (!class_exists($controllerClassName)) {
                throw new NotFoundException("Controller " . $this->controller . " not found.");
            }
            $controller = new $controllerClassName();
            $controller->preprocessParams();
            $controller->process($this->action, $this->args);
        } catch (Exception $e) {
            $this->onError($e);
        }
    }

    /**
     * Override if you need custom error handling.
     */
    protected function onError(Exception $e) {
        if ($e instanceof NotFoundException) {
            header($_SERVER['SERVER_PROTOCOL'].' 404 Not Found', true, 404);
        } else if ($e->getCode() >= 400 && $e->getCode() < 600) {
            header($_SERVER['SERVER_PROTOCOL'].' '.$e->getCode().' '.$e->getMessage(), true, $e->getCode());
        } else {
            header($_SERVER['SERVER_PROTOCOL'].' 500 Internal Server Error', true, 500);
        }
    }
}

/**
 * Base controller, override this class and implement individual actions as methods called "_action".
 */
abstract class Controller {
    private $params;

    /**
     * Override for any parameter validation/preprocessing.
     */
    function preprocessParams() {
        if (strpos($_SERVER['HTTP_CONTENT_TYPE'], 'application/json') === 0) {
            $this->setParams(json_decode(file_get_contents('php://input'), true));
        } else {
            $this->setParams($_REQUEST);
        }
    }

    /**
     * Default request processing. You may override this method for custom handling.
     */
    function process($action, $args) {
        $method = strtolower($_SERVER['REQUEST_METHOD']).($action ? '_'.$action : '');
        if (!method_exists($this, $method)) {
            if (method_exists($this, '_'.$action)) {
                $method = '_'.$action;
            } else {
                throw new NotFoundException('Method \''.$method.'\' nor \'_'.$action.'\' not found in '.get_class($this));
            }
        }
        return call_user_func_array([$this, $method], $args);
    }

    protected function getParams() {
        return $this->params;
    }
    protected function getParam($key) {
        return $this->params[$key];
    }
    protected function setParams($params = []) {
        $this->params = $params;
    }

    /**
     * Dumps the view directly to output.
     */
    function dumpView($file, $vars = '') {
        $file = APP_PATH.'views/'.$file.'.php';
        if (!file_exists($file)) {
            throw new NotFoundException('View '.$file.' not found');
        }
        extract($vars);
        require($file);
    }

    /**
     * Dumps the object as a JSON directly to output.
     */
    function dumpJson($obj) {
        header('Content-Type: application/json; charset=utf-8');
        print(json_encode($obj, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Renders the view and returns it as a string.
     */
    function renderView($file, $vars = '') {
        ob_start();
        $this->dumpView($file, $vars);
        return ob_get_flush();
    }
}

//===============================================================
// Model
//===============================================================
abstract class Model {
    const QUOTE_MYSQL = 'MYSQL';
    const QUOTE_MSSQL = 'MSSQL';
    const QUOTE_ANSI = 'ANSI';
    const MEMCACHE_EXPIRE_DEFAULT = 86400; // 1 day
    const MEMCACHE_VERSION_SUFFIX = '_v1'; // During testing change this to something other than a number sequence, e.g. '_vRandomString', otherwise bad things tend to happen...

    protected $tableName;
    protected $pkName;
    protected $quoteStyle;
    /** Holds all object property variables */
    public $rs = [];
    private $memcache;

    function __construct($tableName, $columns, $id = null, $memcache = null, $pkName = 'id', $quoteStyle = QUOTE_MYSQL) {
        $this->tableName = $tableName;
        $this->pkName = $pkName; //Name of auto-incremented Primary Key
        $this->quoteStyle = $quoteStyle;
        // Initialize all columns to null
        foreach ($columns as $column) {
            $this->rs[$column] = null;
        }
        // Initialize memcache if supplied
        if ($memcache && $memcache instanceof Memcache) {
            $this->memcache = $memcache;
        } else if ($memcache && is_string($memcache)) {
            $this->memcache = new Memcache;
            $this->memcache->connect($memcache);
        }
        // If we immediately need to retrieve a specific record from the DB, do it
        if (isset($id) && is_scalar($id)) {
            $this->retrieve($id);
        }
    }

    function get($key) {
        return $this->rs[$key];
    }

    function set($key, $val) {
        if (array_key_exists($key, $this->rs)) {
            $this->rs[$key] = $val;
        }
        return $this;
    }

    public function __get($key) {
        return $this->get($key);
    }

    public function __set($key, $val) {
        return $this->set($key, $val);
    }

    public function __isset($key) {
        return isset($this->rs[$key]);
    }

    protected function getPdo() {
        return Mvc::getInstance()->getPdo(get_class($this));
    }

    protected function getMemcache() {
        return $this->memcache;
    }

    protected function getClass() {
        return static::class;
    }

    protected function enquote($name) {
        if ($this->quoteStyle == self::QUOTE_MYSQL) {
            return '`'.$name.'`';
        } else if ($this->quoteStyle == self::QUOTE_MSSQL) {
            return '['.$name.']';
        } else {
            return '"'.$name.'"';
        }
    }

    protected function onError($errorInfo) {
        throw new Exception('[DB] Error in '.static::class.': '.$errorInfo[0].' - '.$errorInfo[2].' ('.$errorInfo[1].')', 500);
    }

//===============================================================
// Public functions 
//===============================================================
    function create() {
        $result = $this->createSql();
        if ($result) {
            $this->setMemcache();
        }
        return $result;
    }

    function update() {
        $result = $this->deleteMemcache() && $this->updateSql();
        if ($result) {
            $this->setMemcache();
        }
        return $result;
    }

    function createOrUpdate() {
        if ($this->exists()) {
            return $this->update();
        } else {
            return $this->create();
        }
    }

    function delete() {
        return $this->deleteMemcache() && $this->deleteSql();
    }

    function purgeFromMemcache() {
        return $this->deleteMemcache();
    }

    function retrieve($id) {
        $obj = $this->retrieveFromMemcache($id);
        if (!$obj) {
            $this->retrieveSql($id);
            $this->setMemcache();
        } else {
            $this->rs = array_merge($this->rs, $obj);
        }
        return $this;
    }

    function retrieveIds(array $ids, $assoc = false) {
        if (!count($ids)) {
            return [];
        }
        // Get models from memcache
        $objs = $this->retrieveManyFromMemcache($ids);
        $models = array_map(function($obj) {
            $model = new static();
            // Merge for new clumns in sql table
            $model->rs = array_merge($model->rs, $obj);
            return $model;
            // if $assoc is set, keep the array associative
        }, ($assoc ? $objs : array_values($objs)));
        // Get missing models from db
        if (count($models) != count($ids)) {
            $missingIds = array_diff($ids, array_keys($objs));
            $missingModels = $this->retrieveManySql($missingIds, $assoc);
            // Put missing models to memcache
            foreach ($missingModels as $model) {
                $model->setMemcache();
            }
            // Both arrays are either associative or plain indexed arrays (but never mix of both)
            $models = array_merge($models, $missingModels);
        }
        return $models;
    }

    /**
     * Returns true if primary key is a positive integer. If checkdb is set to true, this function 
     * will return true if there exists such a record in the database.
     */
    function exists($checkdb = false) {
        if ((int) $this->rs[$this->pkName] < 1) {
            return false;
        }
        if (!$checkdb) {
            return true;
        }
        $sql = 'SELECT 1 FROM '.$this->enquote($this->tableName).' WHERE '.$this->enquote($this->pkName).'=\''.$this->rs[$this->pkName].'\'';
        $result = $this->getPdo()->query('*', $sql)->fetchAll();
        return count($result);
    }

    function retrieveOne($wherewhat = '', $bindings = '') {
        $sql = ($wherewhat ? ' WHERE '.$wherewhat : '').' LIMIT 1';
        $stmt = $this->executeStatement('*', $sql, $bindings, false);
        $this->rs[$this->pkName] = '';
        $rs = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->fill($rs);
        return $this;
    }

    function retrieveMany($wherewhat = '', $bindings = '', &$foundRows = null) {
        $countRows = func_num_args() >= 3;
        $stmt = $this->executeStatement('*', $wherewhat ? ' WHERE '.$wherewhat : '', $bindings, $countRows);
        $arr = $this->fillObjectArray($stmt);
        if ($countRows) {
            $this->selectFoundRows($foundRows);
        }
        return $arr;
    }

    function select($selectwhat = '*', $wherewhat = '', $bindings = '', $pdo_fetch_mode = PDO::FETCH_ASSOC) {
        $stmt = $this->executeStatement($selectwhat, $wherewhat ? ' WHERE '.$wherewhat : '', $bindings, false);
        return $stmt->fetchAll($pdo_fetch_mode);
    }

    function query($selectwhat = '*', $sql = '', $bindings = '', $pdo_fetch_mode = PDO::FETCH_ASSOC) {
        $stmt = $this->executeStatement($selectwhat, $sql, $bindings, false);
        return $stmt->fetchAll($pdo_fetch_mode);
    }

    function queryModel($selectwhat = '*', $sql = '', $bindings = '', &$foundRows = null) {
        $countRows = func_num_args() >= 4;
        $stmt = $this->executeStatement($selectwhat, $sql, $bindings, $countRows);
        $arr = $this->fillObjectArray($stmt);
        if ($countRows) {
            $this->selectFoundRows($foundRows);
        }
        return $arr;
    }

//===============================================================
// SQL implementation
//===============================================================
    private function createSql() {
        $s1 = $s2 = '';
        foreach ($this->rs as $k => $v) {
            if ($k != $this->pkName || $v) {
                $s1 .= ','.$this->enquote($k);
                $s2 .= ',?';
            }
        }
        $sql = 'INSERT INTO '.$this->enquote($this->tableName).' ('.substr($s1, 1).') VALUES ('.substr($s2, 1).')';
        $stmt = $this->prepareStatement($sql);
        $i = 0;
        foreach ($this->rs as $k => $v) {
            if ($k != $this->pkName || $v) {
                $stmt->bindValue( ++$i, $v, $v === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            }
        }
        if (!$this->executePdoStatement($stmt)) {
            return false;
        }
        if (!$stmt->rowCount()) {
            return false;
        }
        $this->set($this->pkName, $this->getPdo()->lastInsertId());
        return $this;
    }

    private function retrieveSql($pkvalue) {
        $sql = 'SELECT * FROM '.$this->enquote($this->tableName).' WHERE '.$this->enquote($this->pkName).'=?';
        $stmt = $this->prepareStatement($sql);
        $stmt->bindValue(1, (int) $pkvalue);
        if (!$this->executePdoStatement($stmt)) {
            return false;
        }
        $rs = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->fill($rs);
        return $this;
    }

    private function updateSql() {
        $s = '';
        foreach ($this->rs as $k => $v) {
            $s .= ','.$this->enquote($k).'=?';
        }
        $s = substr($s, 1);
        $sql = 'UPDATE '.$this->enquote($this->tableName).' SET '.$s.' WHERE '.$this->enquote($this->pkName).'=?';
        $stmt = $this->prepareStatement($sql);
        $i = 0;
        foreach ($this->rs as $k => $v) {
            $stmt->bindValue( ++$i, $v, $v === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        }
        $stmt->bindValue( ++$i, $this->rs[$this->pkName]);
        return $this->executePdoStatement($stmt);
    }

    private function deleteSql() {
        $sql = 'DELETE FROM '.$this->enquote($this->tableName).' WHERE '.$this->enquote($this->pkName).'=?';
        $stmt = $this->prepareStatement($sql);
        $stmt->bindValue(1, $this->rs[$this->pkName]);
        return $this->executePdoStatement($stmt);
    }

    private function retrieveManySql(array $ids, $assoc = false) {
        if (!count($ids)) {
            return [];
        }
        $where = $this->pkName.' IN ('.implode(',', array_fill(0, count($ids), '?')).')';
        $bind = array_map('intval', $ids);
        $result = $this->retrieveMany($where, $bind);
        if (!$assoc) {
            return $result;
        }
        $keys = array_map(function($o) { return $o->id; }, $result);
        return array_combine($keys, $result);
    }

//===============================================================
// Memcache implementation
//===============================================================
    private function retrieveFromMemcache($id) {
        if (!$this->getMemcache()) {
            return null;
        }
        $key = $this->getMemcacheKey($id);
        return $this->getMemcache()->get($key);
    }

    private function retrieveManyFromMemcache(array $ids) {
        if (!$this->getMemcache()) {
            return array();
        }
        $keys = array_map(function($id) { return $this->getMemcacheKey($id); }, $ids);
        $objs = $this->getMemcache()->get($keys);
        // return array($id => $obj)
        $result = [];
        foreach ($objs as $obj) {
            $id = $obj[$this->pkName];
            $result[$id] = $obj;
        }
        return $result;
    }

    private function setMemcache() {
        if ($this->getMemcache()) {
            return $this->getMemcache()->set($this->getMemcacheKey(), $this->rs, 0, $this->getMemcacheExpiresTime());
        }
        return false;
    }

    private function deleteMemcache() {
        if ($this->getMemcache()) {
            return $this->getMemcache()->delete($this->getMemcacheKey());
        }
        return true;
    }

    protected function getMemcacheExpiration() {
        return self::MEMCACHE_EXPIRE_DEFAULT;
    }

    protected function getMemcacheKey($id = null, $prefix = 'model_') {
        return $prefix.$this->getClass().'_'.(isset($id) ? $id : $this->id).self::MEMCACHE_VERSION_SUFFIX;
    }

    private function getMemcacheExpiresTime() {
        $exp = $this->getMemcacheExpiration();
        return $exp == 0 ? 0 : (time() + $exp);
    }

//===============================================================
// Helper methods
//===============================================================
    // Fetch PDO statement into Model array
    private function fillObjectArray(PDOStatement $stmt) {
        $arr = [];
        $class = get_class($this);
        while ($rs = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $myclass = new $class();
            $myclass->fill($rs);
            $arr[] = $myclass;
        }
        return $arr;
    }

    // Put result set into Model object
    private function fill($rs) {
        if ($rs) {
            foreach ($rs as $key => $val) {
                if (array_key_exists($key, $this->rs)) {
                    $this->rs[$key] = $val;
                }
            }
        }
    }

    // Select found rows previously requested by SQL_CALC_FOUND_ROWS
    private function selectFoundRows(&$foundRows) {
        $rowsStmt = $this->getPdo()->query('SELECT FOUND_ROWS()');
        $rowsResult = $rowsStmt->fetch(PDO::FETCH_NUM);
        if ($rowsResult) {
            $foundRows = $rowsResult[0];
        }
    }

    // Prepare, bind and execute PDO statement
    private function executeStatement($selectwhat, $sql, $bindings, $countRows) {
        $finalSql = $this->createSqlSelectQuery($selectwhat, $sql, $countRows);
        $stmt = $this->prepareStatement($finalSql);
        $this->bindParams($stmt, $bindings);
        $this->executePdoStatement($stmt);
        return $stmt;
    }

    private function prepareStatement($sql) {
        $dbh = $this->getPdo();
        $stmt = $dbh->prepare($sql);
        if ($stmt === false) {
            $this->onError($dbh->errorInfo());
        }
        return $stmt;
    }

    // Create select sql query string, based on input params
    private function createSqlSelectQuery($selectwhat, $sql, $countRows) {
        return 'SELECT '.($countRows ? 'SQL_CALC_FOUND_ROWS ' : '').$selectwhat.' FROM '.$this->enquote($this->tableName).' '.$sql;
    }

    // Bind parameters into given PDO statement
    private function bindParams(PDOStatement $stmt, $bindings) {
        if (is_scalar($bindings)) {
            $bindings = trim($bindings) ? [$bindings] : [];
        }
        foreach (array_values($bindings) as $i => $v) {
            $stmt->bindValue($i + 1, $v);
        }
    }

    private function executePdoStatement(PDOStatement $stmt) {
        if (!$stmt->execute()) {
            $this->onError($stmt->errorInfo());
            return false;
        }
        return true;
    }
}
