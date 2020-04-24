#  KISSMVC

Simple PHP MVC (Model-View-Controller) framework based on [KISSMVC](http://kissmvc.com/) framework, keeping [KISS design principle](https://en.wikipedia.org/wiki/KISS_principle). 


## Minimal setup
Copy `index.html`, `kissmvc.php` and `.htacceess` into your project. Open `index.html` and setup your `APP_PATH` and `WEB_FOLDER` paths. Also setup your `.htaccess` file.

## Mvc
Mvc class is the heart of request life. It allows you to define PDO connection or customise error handling. The main function of the class is to find the correct Controller and pass processing control to it. Most of the time you'll need to override this class to create PDO connection.

Don't forget to call `route` method of `Mvc` class as the last instruction of your index.php.
```
(new Mvc('defaultController', 'defaultAction'))->route();
```

The terms controller and action are defined in the URL and follow the base URL (see WEB_FOLDER constant). For example, if the application is deployed under `https://example.com/path-to-app/` and you open the URL `https://example.com/path-to-app/user/list/`, the controller in this case is `user` and action is `list`. You may as well open `https://example.com/path-to-app/user/` in which case the action will be the `defaultAction` set in the `Mvc` constructor. If you even ommit the controller in the URL, the `defaultController` will be used as controller.

Once the Mvc is instantiated and you need something from Mvc class, call `Mvc::getInstance()` to obtain Mvc instance.

## Controller class
Each controller must have its own class declared (name in PascalCase and suffixed with `Controller`) in its own file located under `APP_PATH/controllers/` folder.

Each action for a controller must be defined as a separate method. If you follow the REST principles, the method name should be `{lower-case-http-method}_{action}`, e.g. `function get_list()`. In case you want to handle all HTTP methods with one method, just omit the prefix and follow the `_{action}` naming, e.g. `function _list()`. Usually all action calls should end with outputting (dumping) some View or redirecting to another controller/action. For view dump, use the `Controller::dumpView()` or `Controller::dumpJson()` methods or instatiate own `View` class should you need more control.

To access parameters of the request in your controller, use `Controller::getParam($name)` method. This method reads HTTP GET or POST params by default, though in the case the request has `Content-Type: application/json` the controller tries to parse the request body as a JSON object and `Controller::getParam($name)` method returns the named value in that object.

```php
// File APP_PATH/controllers/UserController.php
class UserController extends Controller {
	function _list() {
		// This function is called from url https://example.com/path-to-app/user/list/
		$users = $this->loadUsers($this->getParam('query')); // Read a list of users here, see Model section below on how to do this with Model
		$this->dumpView('userList', ['users' => $users]);
	}
}
```

Controller class allows you to override the `preprocessParams` method to implement your own parameter reading and/or validation and `process($action, $args)` method to implement your own authorisation mechanism, etc.

## Model
To access data in a database, you'll be using the `Model` class framework. For every DB table create a corresponding model class under `APP_PATH/models/` folder (e.g. for table `user` create class `User` in `User.php` file). In your constructor call the parent constructor with the table name and a list of table column names.

```php
class User extends Model {
    public function __construct($id = null) {
        parent::__construct('user', ['id', 'name', 'email', 'password'], $id);
    }
```

### Create a DB entry
```php
$user = new User();
$user->name = 'John Doe';
$user->email = 'john@doe.com';
$user->password = sha1('unguessable password');
if ($user->create()) {
	print('Created a user with ID '.$user->id);
}
```

### Read and update a DB entry
```php
$user = new User(1); // Load a user with ID=1
// Alternative way to load an entry would be $user->retrieve(1)
$user->email = 'john.doe@email.com';
if ($user->update()) {
	print('User '.$user->name.' updated');
}
```

### Query entries
```php
$user = new User();
$users = $user->retrieveMany('name LIKE % OR email LIKE %', [$query.'%', $query.'%']);
print('Found '.count($users).' users:');
for ($users as $user) {
	print('- '.$user->name);
}
```

## View
Very often your Controller will dump a view. View is basically any output returned as a result from an action method. In the example above, we did the following:
```php
$this->dumpView('userList', ['users' => $users]);
```

For this view to work, we need to create file `APP_PATH/views/userList.php` with some content for the client, e.g.

```html
<h1>Users</h1>
<ul>
	<?php for ($users as $user) { ?>
	<li><?= $user->name ?> (<?= $user->email ?>)</li>
	<?php } ?>
</ul>
```

If you're making a JSON REST API, you can dump any object as a JSON directly from Controller:

```php
$this->dumpJson(['users' => $users]);
```
