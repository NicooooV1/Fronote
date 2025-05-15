<?php
// Use the centralized API for database connection
require_once __DIR__ . '/../../../API/core.php';

// The $pdo variable is now available from the API/core.php file

// Start session if necessary
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>