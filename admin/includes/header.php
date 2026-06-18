<?php
/**
 * En-tête unifié pour toutes les pages admin.
 * Détecte automatiquement la profondeur (root ou sous-dossier) pour calculer $rootPrefix.
 * Remplace l'ancien header.php (root) et sub_header.php (sous-pages).
 */
require_once __DIR__ . '/../../API/core.php';
require_once __DIR__ . '/admin_functions.php';

requireAuth();
// Le super-admin (gestion multi-établissements) doit pouvoir atteindre les pages
// d'administration (notamment admin/etablissement/*) : sans lui, requireRole le
// renvoyait vers l'accueil avant même que la page ne s'affiche.
requireRole('administrateur', 'super_admin');

// Onboarding obligatoire : tant que l'établissement n'est pas configuré, on
// redirige vers le wizard depuis n'importe quelle page admin (sauf les pages de
// configuration de l'établissement, exemptées dans le gate pour éviter la boucle).
require_once __DIR__ . '/../../API/onboarding_gate.php';
enforceOnboardingGate();

if (!isset($user_initials)) {
    $user_initials = getUserInitials();
    $user_fullname = getUserFullName();
}

$pageTitle = $pageTitle ?? 'Administration';
$activePage = 'admin';
$isAdmin = true;
$user_fullname = $user_fullname ?? '';
$currentPage = $currentPage ?? '';
$extraCss = array_merge([], $extraCss ?? []);
$extraHeadHtml = ($extraHeadHtml ?? '') . '';
$sidebarExtraContent = '';

// Auto-détection de la profondeur pour $rootPrefix
if (!isset($rootPrefix)) {
    $callerFile = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'] ?? '';
    $adminDir = str_replace('\\', '/', dirname(__DIR__));
    $callerDir = str_replace('\\', '/', dirname($callerFile));
    $relative = substr($callerDir, strlen($adminDir));
    $depth = $relative ? substr_count(ltrim($relative, '/'), '/') + 1 : 0;
    $rootPrefix = str_repeat('../', $depth + 1);
}

// Bouton « Retour » par défaut sur toutes les pages admin : le tableau de bord
// renvoie à l'accueil, toute autre page admin renvoie au tableau de bord. Une page
// peut surcharger $pageBack AVANT d'inclure ce header pour cibler son parent réel
// (ex. admin/users/create.php -> 'admin/users/index.php').
if (!isset($pageBack)) {
    $pageBack = ($currentPage === 'dashboard') ? 'accueil/accueil.php' : 'admin/dashboard.php';
}

include __DIR__ . '/../../templates/shared_header.php';
include __DIR__ . '/../../templates/shared_topbar.php';
?>

<div class="content-container">
    <?= renderAdminBreadcrumb($currentPage, $pageTitle, $rootPrefix) ?>
