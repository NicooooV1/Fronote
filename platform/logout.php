<?php
/** Portail PLATEFORME — déconnexion (ne touche que la session plateforme). */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
unset($_SESSION['platform']);
$base = defined('BASE_URL') ? BASE_URL : '';
header("Location: {$base}/platform/login.php");
exit;
