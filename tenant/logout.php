<?php
declare(strict_types=1);
/** Portail ÉTABLISSEMENT — déconnexion (ne touche que la session établissement). */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$slug = (string) ($_GET['e'] ?? ($_SESSION['tenant']['slug'] ?? ''));
unset($_SESSION['tenant']);
// Le shim de login avait aussi établi la session legacy : on la ferme également.
try { app('auth')->logout(); } catch (\Throwable $e) { error_log('[tenant logout] ' . $e->getMessage()); }
unset($_SESSION['user'], $_SESSION['user_id'], $_SESSION['user_type']);
$base = defined('BASE_URL') ? BASE_URL : '';
header("Location: {$base}/tenant/login.php?e=" . urlencode($slug));
exit;
