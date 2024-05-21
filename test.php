<?php

use FpDbTest\Database;
use FpDbTest\DatabaseTest;

spl_autoload_register(function ($class) {
    $a = array_slice(explode('\\', $class), 1);
    if (!$a) {
        throw new Exception();
    }
    $filename = implode('/', [__DIR__, ...$a]) . '.php';
    require_once $filename;
});

$mysqli = @new mysqli('127.0.0.1', 'root', 'root', 'exampledb', 3306);
if ($mysqli->connect_errno) {
    throw new Exception($mysqli->connect_error);
}

$db = new Database($mysqli);
$test = new DatabaseTest($db);

// ahtung! db with table users and user_id needs to be created for `SELECT name FROM users WHERE ..`
// $test->testBuildQuery($validateMysql = true);

$test->testBuildQuery();

exit('OK');
