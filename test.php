<?php

include_once 'JSBeautify.php';

$obj = new JSBeautify('function(){alert("foo");}');
echo $obj->getResult();
