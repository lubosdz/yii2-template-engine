<?php
/**
* PHPUnit bootstrap file
*/

// ensure we get report on all possible php errors
//error_reporting(-1);

//define('YII_ENABLE_ERROR_HANDLER', false);
//define('YII_DEBUG', true);
//$_SERVER['SCRIPT_NAME'] = '/' . __FILE__;
//$_SERVER['SCRIPT_FILENAME'] = __FILE__;

if(!is_file(__DIR__ . '/../vendor/autoload.php')){
	exit('Please install "vendor" directory - run "composer install" in the root directory.');
}

require_once(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/../vendor/yiisoft/yii2/Yii.php');
