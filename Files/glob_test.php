<?php
echo "CWD: " . getcwd() . PHP_EOL;
var_dump(glob('core/resources/views/templates/*'));
var_dump(glob('/var/www/html/core/resources/views/templates/*'));
