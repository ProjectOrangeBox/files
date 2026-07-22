<?php

// the project root path
define('__ROOT__', realpath(__DIR__ . '/../../../../'));
// the htdocs path
define('__WWW__', realpath(__DIR__ . '/../../../../htdocs'));

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
function logMsg() {}
function isLogEnabled()
{
    return false;
}


// include the composer autoloader
require __DIR__ . '/../../../autoload.php';
