<?php
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

// Onboarding gate — au premier login admin, tant que l'établissement n'est pas
// configuré (code 'default'), rediriger vers l'assistant de mise en route.
// Le wizard lui-même définit FRONOTE_ONBOARDING pour éviter la boucle.
if (!defined('FRONOTE_ONBOARDING') && $isAdmin) {
    if (!isset($_SESSION['onboarding_done'])) {
        try {
            $etab = app('etablissement')->getCurrent();
            $_SESSION['onboarding_done'] = $etab && (($etab['code'] ?? '') !== 'default');
        } catch (\Throwable $e) {
            $_SESSION['onboarding_done'] = true; // ne pas bloquer si la résolution échoue
        }
    }
    if (!$_SESSION['onboarding_done']) {
        header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/modules/onboarding/index.php');
        exit;
    }
}

// Gate « année scolaire terminée » — si l'établissement courant possède des périodes
// mais qu'aucune ne couvre aujourd'hui (on est sorti de l'année définie), on force
// l'admin à redéfinir les plages de trimestres/semestres avant d'utiliser l'app.
// Chaque établissement a ses propres périodes (et son propre type), donc le contrôle
// est scopé à l'établissement actif.
if (!defined('FRONOTE_ONBOARDING') && $isAdmin && !empty($_SESSION['onboarding_done'])) {
    try {
        $eid = \API\Core\EstablishmentContext::id();
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM periodes WHERE etablissement_id = ?");
        $cntStmt->execute([$eid]);
        $nbPeriodes = (int) $cntStmt->fetchColumn();

        if ($nbPeriodes > 0) {
            $curStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM periodes WHERE etablissement_id = ? AND CURDATE() BETWEEN date_debut AND date_fin"
            );
            $curStmt->execute([$eid]);
            if ((int) $curStmt->fetchColumn() === 0) {
                if (function_exists('setFlashMessage')) {
                    setFlashMessage('warning', "L'année scolaire définie est terminée. Veuillez redéfinir les plages de trimestres/semestres pour continuer.");
                }
                header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/admin/etablissement/periodes.php?reconfigure=1');
                exit;
            }
        }
    } catch (\Throwable $e) {
        // ne pas bloquer l'accès si la résolution échoue
    }
}

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
