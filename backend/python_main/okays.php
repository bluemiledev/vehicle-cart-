<?php
// test_php_to_python.php


error_reporting(E_ALL);
ini_set('display_errors', 1);

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Path to your Python executable
$python = "/usr/bin/python3";

// Path to the Python script
$script = __DIR__ . "/kapil_json_response.py";

$command = "$python $script 2>&1";
$output = shell_exec($command);
echo "<pre>$output</pre>";
?>
