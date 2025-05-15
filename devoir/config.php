<?php
// config.php - General application configuration
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define absolute paths
define('ROOT_PATH', dirname(__FILE__));
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('DATA_PATH', ROOT_PATH . '/login/data');

// Use the centralized API
require_once __DIR__ . '/../../API/core.php';
require_once __DIR__ . '/../../API/auth.php';
require_once __DIR__ . '/../../API/data.php';

// All functions from API/data.php are now available
// No need to redefine functions here as they come from the API
?>