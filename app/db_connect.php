<?php

/**
 * PDO и конфиг подключения к БД
 */

require_once __DIR__ . "/.conf.php";

$dsn = 'mysql:host=' . $configDb['host'] . ';dbname=' . $configDb['name'];

$mysqlConnect = new PDO($dsn, $configDb['user'], $configDb['pass']);