<?php

// the project root path
define('__ROOT__', realpath(__DIR__ . '/../'));
// the htdocs path
define('__WWW__', realpath(__DIR__ . '/../htdocs'));

// normally defined by Application::preContainer() at runtime
if (!defined('UNDEFINED')) {
    define('UNDEFINED', chr(0));
}

// the environment variables seeded with the phpunit environment
$_ENV = array_replace_recursive($_ENV, ['ENVIRONMENT' => 'phpunit']);

// define the orange exception and error handlers to avoid errors when running tests
if (!function_exists('orangeExceptionHandler')) {
    function orangeExceptionHandler()
    {
    }
}

if (!function_exists('orangeErrorHandler')) {
    function orangeErrorHandler()
    {
    }
}

// define the orange log function to avoid errors when running tests
function logMsg()
{
}
function isLogEnabled()
{
    return false;
}

// two layouts: a standalone clone carries its own vendor/ directory; a clone
// developed in place inside an application's vendor tree finds the autoloader
// three directories up and the framework two up
$standalone = is_dir(__DIR__ . '/../vendor');

require $standalone
    ? __DIR__ . '/../vendor/autoload.php'
    : __DIR__ . '/../../../autoload.php';

// wrappers supplies container() used by the framework Security service
require $standalone
    ? __DIR__ . '/../vendor/orange/framework/src/helpers/wrappers.php'
    : __DIR__ . '/../../framework/src/helpers/wrappers.php';
