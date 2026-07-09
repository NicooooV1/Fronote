<?php
declare(strict_types=1);
/**
 * Module Boot — Point d'entrée standardisé pour tous les modules Fronote
 *
 * Ce fichier centralise le boot commun à tous les modules :
 * 1. Output buffering
 * 2. Chargement de l'API (core.php → bootstrap → Bridge)
 * 3. Authentification obligatoire
 * 4. Récupération des variables utilisateur
 * 5. Connexion PDO
 * 6. Calcul du rootPrefix (chemin relatif vers la racine)
 *
 * Variables mises à disposition après inclusion :
 *   $user          — array  : données utilisateur complètes
 *   $user_role     — string : rôle (administrateur, professeur, vie_scolaire, eleve, parent)
 *   $user_fullname — string : nom complet
 *   $user_initials — string : initiales (2 lettres)
 *   $pdo           — PDO    : connexion base de données
 *   $rootPrefix    — string : chemin relatif vers la racine (ex: '../')
 *   $isAdmin       — bool   : true si administrateur
 *
 * Variables à définir AVANT l'inclusion :
 *   $pageTitle     — string : titre de la page (optionnel, défaut 'FRONOTE')
 *   $activePage    — string : clé du module pour la sidebar (optionnel)
 *
 * Usage :
 *   $pageTitle = 'Notes';
 *   $activePage = 'notes';
 *   require_once __DIR__ . '/../API/module_boot.php';
 */

// Output buffering pour permettre les redirections après output
if (!ob_get_level()) {
    ob_start();
}

// Charger le core API (bootstrap + Bridge + helpers)
require_once __DIR__ . '/core.php';

// Authentification obligatoire — redirige vers le login si non connecté
requireAuth();

// Récupérer les données utilisateur
$user          = getCurrentUser();
$user_role     = getUserRole();
$user_fullname = getUserFullName();
$user_initials = getUserInitials();
$isAdmin       = ($user_role === 'administrateur');

// Connexion base de données
$pdo = getPDO();

// Garde « changement de mot de passe imposé » — incontournable quel que soit le
// module : tant que $_SESSION['force_password_change'] est armé (mot de passe jamais
// changé, cf. login/index.php & login/verify_2fa.php), on force la redirection vers
// la page de changement. On exclut la page de changement elle-même pour éviter la
// boucle. Le drapeau est retiré après un changePassword réussi (login/change_password.php).
if (!empty($_SESSION['force_password_change'])) {
    $__currentScript = basename(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
    if ($__currentScript !== 'change_password.php') {
        redirect('login/change_password.php');
    }
}

// Gate d'autorisation par module (défense en profondeur — cohérent avec la nav).
$__moduleKey = $activePage ?? '';
if ($__moduleKey === '') {
    $__caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'] ?? '';
    if (preg_match('#/modules/([^/]+)/#', str_replace('\\', '/', $__caller), $__mm)) {
        $__moduleKey = $__mm[1];
    }
}
if ($__moduleKey !== '' && function_exists('enforceModuleAccess')) {
    enforceModuleAccess($__moduleKey);
}

// Onboarding gate — au premier login admin, tant que l'établissement n'est pas
// configuré (code 'default'), rediriger vers l'assistant de mise en route ; puis,
// une fois configuré, forcer la redéfinition des périodes si l'année est terminée.
// Logique partagée avec admin/includes/header.php pour que le wizard soit
// incontournable quel que soit le point d'entrée (cf. API/onboarding_gate.php).
require_once __DIR__ . '/onboarding_gate.php';
enforceOnboardingGate();

// Calcul du rootPrefix (chemin relatif vers la racine du projet)
// Utilise le nombre de niveaux de profondeur du fichier appelant
if (!isset($rootPrefix)) {
    $callerFile = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'] ?? '';
    $projectRoot = dirname(__DIR__);
    if ($callerFile && str_starts_with(str_replace('\\', '/', $callerFile), str_replace('\\', '/', $projectRoot))) {
        $relativePath = substr(str_replace('\\', '/', $callerFile), strlen(str_replace('\\', '/', $projectRoot)) + 1);
        $depth = substr_count($relativePath, '/');
        $rootPrefix = str_repeat('../', $depth);
    } else {
        $rootPrefix = '../';
    }
}
