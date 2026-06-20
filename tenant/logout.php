<?php
/** Portail ÉTABLISSEMENT — déconnexion (ne touche que la session établissement). */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$slug = (string) ($_GET['e'] ?? ($_SESSION['tenant']['slug'] ?? ''));
unset($_SESSION['tenant']);
$base = defined('BASE_URL') ? BASE_URL : '';
header("Location: {$base}/tenant/login.php?e=" . urlencode($slug));
exit;
