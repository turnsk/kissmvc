<?php

/* * ***************************************************************
 * 
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
 * *************************************************************** */


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
// Mvp
// Parses the HTTP request and routes to the appropriate function
//===============================================================


class Mvp {
    
    private static $instance;
    
    protected $params = array();
    
    private $args = array();
    private $controller;
    private $action;
    
    static function getInstance() {
        return self::$instance;
    }   

    public function __construct($defaultController = '', $defaultAction = '') {
        if (self::$instance) {
            throw new Exception('Can\'t instantiate more than one Mvp class', 500);
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
    
    public function getPdo($modelClass = '') {
        return null;
    }
    
    public function route() {
        try {
            $requri = $_SERVER['REQUEST_URI'];
            if (strpos($requri, WEB_FOLDER) === 0) {
                $requri = substr($requri, strlen(WEB_FOLDER));
            }
            $uriParts = $requri ? explode('?', $requri) : array();;
            $parts = $uriParts[0] ? explode('/', $uriParts[0]) : array();
            
            if (isset($parts[0]) &&  $parts[0]) {
                $this->controller = $parts[0];
            }
            if (isset($parts[1]) && $parts[1]) {
                $this->action = $parts[1];
            }
            if (isset($parts[2])) {
                $this->args = array_slice($parts, 2);
            }

            $this->preprocessParams();
            $this->route_request();
        } catch (Exception $e) {
            $this->onError($e);
        }
    }
    
    protected function onError(Exception $e) {
        if ($e instanceof NotFoundException) {
            header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found", true, 404);
        } else {
            header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
        }
    }
    
    private function route_request() {
        $controllerClassName = ucfirst($this->controller).'Controller';
        include (APP_PATH . 'controllers/' . $controllerClassName . '.php');
        if (class_exists($controllerClassName)) {
            $controller = new $controllerClassName();
        } else {
            throw new NotFoundException("Controller " . $this->controller . " not found.");
        }
        $controller->setParams($this->params);
        $controller->setArgs($this->args);
        $view = $controller->process($this->action);
        if ($view && $view instanceof View) {
            $view->dump();
        }
    }
    
    protected function preprocessParams() {
        $this->params = $_REQUEST;
    }
}

//===============================================================
// Controller
//===============================================================
abstract class Controller {

    private $params;
    private $args;

    /**
     * process is called from Mvp class.
     */
    function process($action = '') {
        $action = '_'.$action;
        if (!method_exists($this, $action)) {
            throw new NotFoundException("Function '".$action."' not found in ".get_class($this));
        }
        return call_user_func_array($this->$action, $this->args);
    }
    
    function setParams($params = []) {
        $this->params = $params;
    }
    
    function setArgs($args = []) {
        $this->args = $args;
    }
    
    protected function getParam($key) {
        return $this->params[$key];
    }
    
    protected function getParams() {
        return $this->params;
    }
    
}

//===============================================================
// View
// For plain .php templates
//===============================================================
class View {

    protected $file = '';
    public $vars = array();

    function __construct($file = '', $vars = '') {
        if ($file) {
            $this->file = APP_PATH . 'views/' . $file . '.php';
        }
        if (is_array($vars)) {
            $this->vars = $vars;
        }
        return $this;
    }

    function __set($key, $var) {
        return $this->set($key, $var);
    }

    function set($key, $var) {
        $this->vars[$key] = $var;
        return $this;
    }

    public function dump($vars = '') {
        if (is_array($vars)) {
            $this->vars = array_merge($this->vars, $vars);
        }
        extract($this->vars);
        require($this->file);
    }
}

//===============================================================
// Model
//===============================================================
abstract class Model {
    const EXPIRE_DEFAULT = 86400; // 1 day
    const VERSION_SUFFIX = '_v12'; // na testovanie to meň na nieco ine ako postupnost cisel, napr. "_vNahodnyString", inak sa stavaju zle veci...

    protected $pkname;
    protected $tablename;
    protected $QUOTE_STYLE = 'MYSQL'; // valid types are MYSQL,MSSQL,ANSI
    public $rs = []; // for holding all object property variables
    private $memcache;

    function __construct($tablename, $columns, $id, $memcache = null, $pkname = 'id', $quote_style = 'MYSQL') {
        $this->pkname = $pkname; //Name of auto-incremented Primary Key
        $this->tablename = $tablename; //Corresponding table in database
        $this->QUOTE_STYLE = $quote_style;
        
        foreach ($columns as $column) {
            $this->rs[$column] = null;
        }
        
        if (isset($id) && is_scalar($id)) {
            $this->retrieve($id);
        }
        
        if ($memcache && $memcache instanceof Memcache) {
            $this->memcache = $memcache;
        } else if ($memcache && is_string($memcache)) {
            $this->memcache = new Memcache;
            $this->memcache->connect($memcache);
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

    protected function getdbh() {
        return Mvp::getInstance()->getPdo(get_class($this));
    }

    protected function getmemcache() {
        return $this->memcache;
    }

    protected function enquote($name) {
        if ($this->QUOTE_STYLE == 'MYSQL') {
            return '`' . $name . '`';
        } else if ($this->QUOTE_STYLE == 'MSSQL') {
            return '[' . $name . ']';
        } else {
            return '"' . $name . '"';
        }
    }
    
    protected function onError($errorInfo) {
        throw new Exception('[DB] Error in  ' . static::class . ": {$errorInfo[0]} - {$errorInfo[2]} ({$errorInfo[1]})", 500);
    }
    
//===============================================================
// public functions 
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

    function delete() {
        return  $this->deleteMemcache() && $this->deleteSql();
    }

    function purgeFromMemCache() {
        return $this->deleteMemcache();
    }

    function retrieve($id) {
        $obj = $this->retrieveFromMem($id);
        if (!$obj) {
            $this->retrieveSql($id);
            $this->setMemcache();
        } else {
            // merge for new clumns in sql table
            $this->rs = array_merge($this->rs, $obj);
        }
        return $this;
    }

    function retrieveMany(array $ids, $assoc = false) {
        if (!count($ids)) {
            return [];
        }

        // get models from memcache
        $objs = $this->retrieveManyFromMem($ids);
        $models = array_map(function($obj) {
            $model = new static();
            // merge for new clumns in sql table
            $model->rs = array_merge($model->rs, $obj);
            return $model;
            // if $assoc is set, keep the array associative
        }, ($assoc ? $objs : array_values($objs)));

        if (count($models) != count($ids)) {
            // get missing models from db
            $missingIds = array_diff($ids, array_keys($objs));
            $missingModels = $this->retrieveManySql($missingIds, $assoc);

            // put missing models to memcache
            foreach ($missingModels as $model) {
                $model->setMemcache();
            }

            // both arrays are either associative or plain indexed arrays (but never mix of both)
            $models = array_merge($models, $missingModels);
        }
        
        return $models;
    }

//===============================================================
// sql implementation
//===============================================================
    private function createSql() {
        $pkname = $this->pkname;
        $s1 = $s2 = '';
        foreach ($this->rs as $k => $v) {
            if ($k != $pkname || $v) {
                $s1 .= ',' . $this->enquote($k);
                $s2 .= ',?';
            }
        }
        $sql = 'INSERT INTO ' . $this->enquote($this->tablename) . ' (' . substr($s1, 1) . ') VALUES (' . substr($s2, 1) . ')';
        $stmt = $this->prepareStatement($sql);
        $i = 0;
        foreach ($this->rs as $k => $v) {
            if ($k != $pkname || $v) {
                $stmt->bindValue( ++$i, $v, $v === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            }
        }
        if (!$this->executePDOStatement($stmt)) {
            return false;
        }
        if (!$stmt->rowCount()) {
            return false;
        }
        $this->set($pkname, $this->getdbh()->lastInsertId());
        return $this;
    }

    private function retrieveSql($pkvalue) {
        $sql = 'SELECT * FROM ' . $this->enquote($this->tablename) . ' WHERE ' . $this->enquote($this->pkname) . '=?';
        $stmt = $this->prepareStatement($sql);
        $stmt->bindValue(1, (int) $pkvalue);
        if (!$this->executePDOStatement($stmt)) {
            return false;
        }
        $rs = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->fill($rs);
        return $this;
    }

    private function updateSql() {
        $s = '';
        foreach ($this->rs as $k => $v) {
            $s .= ',' . $this->enquote($k) . '=?';
        }
        $s = substr($s, 1);
        $sql = 'UPDATE ' . $this->enquote($this->tablename) . ' SET ' . $s . ' WHERE ' . $this->enquote($this->pkname) . '=?';
        $stmt = $this->prepareStatement($sql);
        $i = 0;
        foreach ($this->rs as $k => $v) {
            $stmt->bindValue( ++$i, $v, $v === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        }
        $stmt->bindValue( ++$i, $this->rs[$this->pkname]);
        return $this->executePDOStatement($stmt);
    }

    private function deleteSql() {
        $sql = 'DELETE FROM ' . $this->enquote($this->tablename) . ' WHERE ' . $this->enquote($this->pkname) . '=?';
        $stmt = $this->prepareStatement($sql);
        $stmt->bindValue(1, $this->rs[$this->pkname]);
        return $this->executePDOStatement($stmt);
    }
    
    private function retrieveManySql(array $ids, $assoc = false) {
        if (!count($ids)) {
            return [];
        }

        $where = $this->pkname . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $bind = array_map('intval', $ids);
        $result = $this->retrieve_many($where, $bind);
        if (!$assoc) {
            return $result;
        }
        $keys = array_map(function($o) { return $o->id; }, $result);
        return array_combine($keys, $result);
    }
    
    // returns true if primary key is a positive integer
    // if checkdb is set to true, this function will return true if there exists such a record in the database
    function exists($checkdb = false) {
        if ((int) $this->rs[$this->pkname] < 1) {
            return false;
        }
        if (!$checkdb) {
            return true;
        }
        $dbh = $this->getdbh();
        $sql = 'SELECT 1 FROM ' . $this->enquote($this->tablename) . ' WHERE ' . $this->enquote($this->pkname) . "='" . $this->rs[$this->pkname] . "'";
        $result = $dbh->query('*', $sql)->fetchAll();
        return count($result);
    }

    function retrieve_one($wherewhat = '', $bindings = '') {
        $sql = ($wherewhat ? ' WHERE ' . $wherewhat : '') . ' LIMIT 1';
        $stmt = $this->executeStatement('*', $sql, $bindings, false);
        $this->rs[$this->pkname] = '';
        $rs = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->fill($rs);
        return $this;
    }

    function retrieve_many($wherewhat = '', $bindings = '', &$foundRows = null) {
        $countRows = func_num_args() >= 3;
        $stmt = $this->executeStatement('*', $wherewhat ? ' WHERE ' . $wherewhat : '', $bindings, $countRows);
        $arr = $this->fillObjectArray($stmt);
        if ($countRows) {
            $this->selectFoundRows($foundRows);
        }
        return $arr;
    }

    function select($selectwhat = '*', $wherewhat = '', $bindings = '', $pdo_fetch_mode = PDO::FETCH_ASSOC) {
        $stmt = $this->executeStatement($selectwhat, $wherewhat ? ' WHERE ' . $wherewhat : '', $bindings, false);
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
// memcache implementation
//===============================================================
    private function retrieveFromMem($id) {
        if (!$this->getmemcache()) {
            return null;
        }
        $key = $this->getKey($id);
        return $this->getmemcache()->get($key);
    }

    private function retrieveManyFromMem(array $ids) {
        if (!$this->getmemcache()) {
            return array();
        }
        $keys = array_map(function($id) { return $this->getKey($id); }, $ids);
        $objs = $this->getmemcache()->get($keys);

        // return array($id => $obj)
        $result = [];
        foreach ($objs as $obj) {
            $id = $obj[$this->pkname];
            $result[$id] = $obj;
        }
        return $result;
    }
    
    private function setMemcache() {
        if ($this->getmemcache()) {
            return $this->getmemcache()->set($this->getKey(), $this->rs, $this->getActualExpiresTime());
        }
        return false;
    }

    private function deleteMemcache() {
        if ($this->getmemcache()) {
            return $this->getmemcache()->delete($this->getKey());
        }
        return true;
    }


//===============================================================
// helper methods
//===============================================================
//
    // fetch PDO statement into Model array
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

    // put result set into Model object
    private function fill($rs) {
        if ($rs) {
            foreach ($rs as $key => $val) {
                if (array_key_exists($key, $this->rs)) {
                    $this->rs[$key] = $val;
                }
            }
        }
    }

    // select found rows previously requested by SQL_CALC_FOUND_ROWS
    private function selectFoundRows(&$foundRows) {
        $rowsStmt = $this->getdbh()->query('SELECT FOUND_ROWS()');
        $rowsResult = $rowsStmt->fetch(PDO::FETCH_NUM);
        if ($rowsResult) {
            $foundRows = $rowsResult[0];
        }
    }

    // prepare, bind and execute PDO statement
    private function executeStatement($selectwhat, $sql, $bindings, $countRows) {
        $finalSql = $this->createSqlSelectQuery($selectwhat, $sql, $countRows);
        $stmt = $this->prepareStatement($finalSql);
        $this->bindParams($stmt, $bindings);
        $this->executePDOStatement($stmt);
        return $stmt;
    }
    
    private function prepareStatement($sql) {
        $dbh = $this->getdbh();
        $stmt = $dbh->prepare($sql);
        if ($stmt === false) {
            $this->onError($dbh->errorInfo());
        }
        return $stmt;
    }

    // create select sql query string, based on input params
    private function createSqlSelectQuery($selectwhat, $sql, $countRows) {
        return 'SELECT ' . ($countRows ? 'SQL_CALC_FOUND_ROWS ' : '') . $selectwhat . ' FROM ' . $this->enquote($this->tablename) . ' ' . $sql;
    }

    // bind parameters into given PDO statement
    private function bindParams(PDOStatement $stmt, $bindings) {
        if (is_scalar($bindings)) {
            $bindings = trim($bindings) ? [$bindings] : [];
        }
        foreach (array_values($bindings) as $i => $v) {
            $stmt->bindValue($i + 1, $v);
        }
    }
    
    private function executePDOStatement(PDOStatement $stmt) {
        if (!$stmt->execute()) {
            $this->onError($stmt->errorInfo());
            return false;
        }
        return true;
    }

    
    protected function getMemcacheExpiration() {
        return self::EXPIRE_DEFAULT;
    }

    protected function getClass() {
        return static::class;
    }

    protected function getKey($id = null, $prefix = "model_") {
        return $prefix . $this->getClass() . "_" . (isset($id) ? $id : $this->id) . self::VERSION_SUFFIX;
    }

    private function getActualExpiresTime() {
        $exp = $this->getMemcacheExpiration();
        return $exp == 0 ? 0 : (time() + $exp);
    }

}
