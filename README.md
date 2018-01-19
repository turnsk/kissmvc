#  KISSMVC

Simple PHP MVC (Model-View-Controller) framework based on [KISSMVC](http://kissmvc.com/) framework, keeping [KISS design principle](https://en.wikipedia.org/wiki/KISS_principle). 


## Minimal setup
Copy `index.html`, `kissmvc.php` and `.htacceess` into your project. Open `index.html` and setup your `APP_PATH` and `WEB_FOLDER` paths. Also setup your `.htaccess` file.

## Mvp
Mvp class is the heart of request life. It defines request parsing, PDO connection, params preprocessing, error handling. The main function of the class is prepare all necessary for request processing and route request to the right controller. Then also display processing result or handle error.  
If you wish implement your own error handling or define your PDO connection, feel free to override this class.

Don't forget to call   `route` method of MVP class for routing request.
```
(new Mvp('defaultController', 'defaultAction'))->route();
```

Once when the MVP is instantiate and you need something from MVP class, call `Mvp::getInstance()` to obtain Mvp instance.

## Controller class

Controller class file is located in `APP_PATH/controllers/` folder.  
Controller file & class name start with Name of controller with `Controller` suffix. Both names must be equal.  
Controller action called by MVP default definition must be public and begin with `_` prefix. Function will return, let's say anything. If result is instance of `View` class, then returned View is dumped as result.

Very important is access to parameters of function: `$_REQUEST` fields. You have option to obtain param by calling `Controller->getParam('key')`. If you need for some reason another way to prepare your params passed into controller, override `Mvp->preprocessParams()` method.

```php
class TestController extends Controller {

	function _testFunction($action = '') {
		// this function is called from url http://domain.com/test/testFunction
	    return new JsonView();
	}
	
	// example of custom action processing
	public function process($action = '') {
        $this->authenticate($action);
        return parent::process($action);
    }

	private function authenticate($action = '') {
		if ($action !== 'testFunction') {
			throw new Exception('Not allowed method', 500);
		}
	}
}
```


## Model

Every Model class file is located in `APP_PATH/models/` folder.  Model object represent one row in database table.  PDO connection for models is defined in MVP class in `getPdo()` function.
```php
class TestModel extends Model {
 
    public function __construct($id = null) {
        parent::__construct('test_table', ['id', 'name', 'type', 'content', 'created', 'modified'], $id);
    }
```
## View
Very often you will return some View from as Controller action result. 

```php
return new View('json', ['data' => $jsonData]);
```

Example above return View, that load and dump `APP_PATH/views/json.php` file.

```php
<?php

header('Content-Type: application/json; charset=utf-8');
print($data ? json_encode($data, JSON_UNESCAPED_UNICODE) : "{}");
```