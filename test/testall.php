<?php

require_once 'conftest.php';
require_once 'PHPUnit.php';

$suite  = new PHPUnit_TestSuite("ConfTest");
$result = PHPUnit::run($suite);

echo $result -> toString();
?>
