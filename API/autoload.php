<?php
/**
 * Chargeur automatique pour Pronote
 * Ce fichier s'occupe de charger les dépendances de base nécessaires au bon fonctionnement de l'application
 */

// Démarrer la session si elle n'est pas déjà démarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Marquer ce fichier comme inclus pour éviter les redéclarations de fonctions
if (!defined('PRONOTE_AUTOLOAD_INCLUDED')) {
    define('PRONOTE_AUTOLOAD_INCLUDED', true);
}

// Tableau des fichiers à charger dans l'ordre
$coreFiles = [
    __DIR__ . '/config/env.php',      // Configuration de l'environnement
    __DIR__ . '/config/config.php',   // Configuration générale
    __DIR__ . '/errors.php',          // Gestionnaire d'erreurs
    __DIR__ . '/auth_central.php',    // Système d'authentification central
    __DIR__ . '/database.php',        // Connexion à la base de données
    __DIR__ . '/validator.php',       // Validateur de formulaires
    __DIR__ . '/cache.php'            // Système de cache
];

// Garder une trace des fichiers chargés pour éviter les inclusions multiples
$GLOBALS['PRONOTE_LOADED_FILES'] = $GLOBALS['PRONOTE_LOADED_FILES'] ?? [];

// Charger chaque fichier s'il existe
foreach ($coreFiles as $file) {
    if (file_exists($file) && !in_array($file, $GLOBALS['PRONOTE_LOADED_FILES'])) {
        require_once $file;
        $GLOBALS['PRONOTE_LOADED_FILES'][] = $file;
    }
}

/**
 * Fonction pour initialiser l'application
 * @param array $options Options d'initialisation
 * @return bool Succès de l'initialisation
 */
function bootstrap($options = []) {
    // Fusionner avec les options par défaut
    $defaultOptions = [
        'requireLogin' => false,      // Exiger une authentification?
        'requireAdmin' => false,      // Exiger un rôle administrateur?
        'errorHandling' => true,      // Activer le gestionnaire d'erreurs personnalisé?
    ];
    
    $options = array_merge($defaultOptions, $options);
    
    // Activer la gestion d'erreurs personnalisée si demandé
    if ($options['errorHandling'] && function_exists('registerErrorHandler')) {
        registerErrorHandler();
    }
    
    try {
        // Initialiser la base de données si la fonction existe
        if (function_exists('initDatabase')) {
            initDatabase();
        }
        
        // Vérifier l'authentification si nécessaire
        if ($options['requireLogin']) {
            if (function_exists('requireLogin')) {
                $user = requireLogin(true); // true = rediriger si non connecté
                
                if ($options['requireAdmin'] && (!function_exists('isAdmin') || !isAdmin())) {
                    // L'utilisateur n'est pas administrateur
                    header('Location: ' . (defined('HOME_URL') ? HOME_URL : '/'));
                    exit('Accès refusé: privilèges administrateur requis.');
                }
            } else {
                // La fonction requireLogin n'existe pas
                if (defined('LOGIN_URL')) {
                    header('Location: ' . LOGIN_URL);
                    exit;
                }
            }
        }
        
        return true;
    } catch (Exception $e) {
        // Journaliser l'erreur
        error_log('Erreur bootstrap: ' . $e->getMessage());
        
        // Afficher une erreur en mode développement
        if (defined('APP_ENV') && APP_ENV === 'development') {
            echo '<div style="color:red">Erreur bootstrap: ' . $e->getMessage() . '</div>';
        }
        
        return false;
    }
}
