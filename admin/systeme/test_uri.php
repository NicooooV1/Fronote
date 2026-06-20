<?php
// Write the REQUEST_URI to a log file to see what Apache sends
$log = '/tmp/request_uri_test.log';
file_put_contents($log, "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'NOT SET') . "\n", FILE_APPEND);
file_put_contents($log, "SCRIPT_FILENAME: " . ($_SERVER['SCRIPT_FILENAME'] ?? 'NOT SET') . "\n", FILE_APPEND);
echo "Logged to $log\n";
?>
