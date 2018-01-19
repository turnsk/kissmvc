<?php

mb_internal_encoding('utf-8');
define('APP_PATH', 'app/'); //with trailing slash pls
define('WEB_FOLDER', '/'); //with trailing slash pl

require_once('kissmvc.php');

session_set_cookie_params(3600, WEB_FOLDER, null, true, true);
session_start();

// TODO extend your own MVP class and define your Mvp for controll your requests
(new Mvp('dummy', 'dummy'))->route();