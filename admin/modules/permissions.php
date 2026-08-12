<?php
declare(strict_types=1);
/**
 * (Retiré du panneau d'établissement) — La configuration des permissions (module ×
 * rôle) est désormais gouvernée AU NIVEAU DE LA PLATEFORME. Les directions
 * d'établissement n'éditent plus les permissions ; elles gèrent l'activation des
 * modules (index.php) et l'attribution des rôles (users/roles.php). Redirection.
 */
require_once __DIR__ . '/../../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$_SESSION['info_message'] = "Les permissions sont gérées au niveau de la plateforme. Gérez ici l'activation des modules ; attribuez les rôles depuis Utilisateurs.";
$base = defined('BASE_URL') ? BASE_URL : '';
header('Location: ' . $base . '/admin/modules/index.php');
exit;
