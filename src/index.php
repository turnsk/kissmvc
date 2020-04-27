<?php
// The relative path to where the controller, models, views and helpers folders are
define('APP_PATH', 'app/'); // With trailing slash pls
// The web path to this file, e.g. for https://example.com/my-app/index.html the below would be 'my-app/'
define('WEB_FOLDER', '/'); // With trailing slash pls
// Include the kissmvc implementation
require_once('kissmvc.php');
// Route the request with the default config
// If you need DB or custom routing rules, override Mvp class and use your class
(new Mvc())->route();
