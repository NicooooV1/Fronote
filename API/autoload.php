<?php
/**
 * Système d'autoload pour Pronote
 * Ce fichier permet de charger automatiquement les classes et fonctions nécessaires
 */

// Démarrer automatiquement la session si nécessaire
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Définir une constante pour éviter des inclusions multiples
if (!defined('PRONOTE_AUTOLOAD_LOADED')) {
    define('PRONOTE_AUTOLOAD_LOADED', true);
    
    // Chemins des fichiers importants à charger
    $coreFiles = [
        __DIR__ . '/config/config.php',      // Configuration système
        __DIR__ . '/core/errors.php',        // Gestionnaire d'erreurs
        __DIR__ . '/core/logging.php',       // Système de journalisation
        __DIR__ . '/core/Security.php',      // Fonctions de sécurité
        __DIR__ . '/auth_central.php',       // Système d'authentification central
        __DIR__ . '/database.php',           // Connexion à la base de données
    ];
    
    // Charger les fichiers de base
    foreach ($coreFiles as $file) {
        if (file_exists($file)) {
            require_once $file;
        }
    }
    
    /**
     * Fonction de bootstrap pour initialiser l'application
     * @return void
     */
    function bootstrap() {
        // Activer le gestionnaire d'erreurs si disponible
        if (function_exists('\Pronote\Errors\registerErrorHandlers')) {
            \Pronote\Errors\registerErrorHandlers();
        }
        
        // Journaliser l'accès à la page si la fonction est disponible
        if (function_exists('\Pronote\Logging\pageAccess')) {
            \Pronote\Logging\pageAccess();
        }
        
        // Vérifier si le fichier d'installation existe et devrait être protégé
        $installGuardFile = dirname(__DIR__) . '/install_guard.php';
        if (file_exists($installGuardFile)) {
            require_once $installGuardFile;
        }
    }
    
    // Exécuter le bootstrap automatiquement sauf si désactivé
    if (!defined('DISABLE_AUTO_BOOTSTRAP')) {
        bootstrap();
    }
}
