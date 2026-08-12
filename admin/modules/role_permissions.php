<?php
declare(strict_types=1);
/**
 * (Retiré du panneau d'établissement) — La matrice « rôle → permissions » est
 * désormais gouvernée AU NIVEAU DE LA PLATEFORME (console centrale, table globale
 * rbac_grants sur le catalogue RoleCatalog). Les directions d'établissement ne
 * configurent plus ce qu'un rôle peut faire ; elles ATTRIBUENT les rôles aux
 * utilisateurs. Cette page redirige donc vers l'attribution des rôles.
 */
require_once __DIR__ . '/../../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$_SESSION['info_message'] = "Les permissions des rôles sont gérées au niveau de la plateforme. Ici, vous attribuez les rôles aux utilisateurs.";
$base = defined('BASE_URL') ? BASE_URL : '';
header('Location: ' . $base . '/admin/users/roles.php');
exit;
