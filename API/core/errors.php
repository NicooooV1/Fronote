<?php
/**
 * Gestionnaire d'erreurs pour l'application Pronote
 * Ce fichier centralise la gestion des erreurs et exceptions
 */
namespace Pronote\Errors;

// Prévenir l'inclusion récursive
if (defined('PRONOTE_ERRORS_LOADED')) {
    return;
}
define('PRONOTE_ERRORS_LOADED', true);

// Chargement du système de journalisation
if (!function_exists('\Pronote\Logging\error')) {
    $loggingPath = __DIR__ . '/logging.php';
    if (file_exists($loggingPath)) {
        require_once $loggingPath;
    } else {
        /**
         * Fonction de secours si le système de journalisation n'est pas disponible
         */
        namespace Pronote\Logging;
        if (!function_exists('error')) {
            function error($message, $context = []) {
                error_log($message);
                return true;
            }
        }
        if (!function_exists('critical')) {
            function critical($message, $context = []) {
                error_log('CRITICAL: ' . $message);
                return true;
            }
        }
    }
}

/**
 * Gestionnaire d'erreurs personnalisé
 * @param int $errno Numéro de l'erreur
 * @param string $errstr Message d'erreur
 * @param string $errfile Fichier où s'est produite l'erreur
 * @param int $errline Ligne où s'est produite l'erreur
 * @return bool True pour empêcher l'exécution du gestionnaire d'erreurs interne de PHP
 */
function errorHandler($errno, $errstr, $errfile, $errline) {
    // Les erreurs que nous voulons gérer
    if (!(error_reporting() & $errno)) {
        // Cette erreur a été supprimée par @ - donc nous l'ignorons
        return false;
    }
    
    $errorTypes = [
        E_ERROR             => 'Error',
        E_WARNING           => 'Warning',
        E_PARSE             => 'Parsing Error',
        E_NOTICE            => 'Notice',
        E_CORE_ERROR        => 'Core Error',
        E_CORE_WARNING      => 'Core Warning',
        E_COMPILE_ERROR     => 'Compile Error',
        E_COMPILE_WARNING   => 'Compile Warning',
        E_USER_ERROR        => 'User Error',
        E_USER_WARNING      => 'User Warning',
        E_USER_NOTICE       => 'User Notice',
        E_STRICT            => 'Runtime Notice',
        E_RECOVERABLE_ERROR => 'Catchable Fatal Error',
        E_DEPRECATED        => 'Deprecated',
        E_USER_DEPRECATED   => 'User Deprecated'
    ];
    
    $errorType = $errorTypes[$errno] ?? 'Unknown Error';
    $message = "$errorType: $errstr in $errfile on line $errline";
    
    // Journaliser l'erreur
    \Pronote\Logging\error($message);
    
    // En production, certaines erreurs peuvent être masquées
    if (defined('APP_ENV') && APP_ENV === 'production') {
        if (in_array($errno, [E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED])) {
            return true;
        }
    }
    
    // Si c'est une erreur fatale, on affiche une page d'erreur générique
    if (in_array($errno, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR])) {
        if (defined('APP_ENV') && APP_ENV === 'production') {
            displayErrorPage(500, "Une erreur interne est survenue.");
        } else {
            displayErrorPage(500, $message);
        }
    }
    
    return true;
}

/**
 * Gestionnaire d'exceptions personnalisé
 * @param \Throwable $exception Exception à traiter
 * @return void
 */
function exceptionHandler($exception) {
    $message = "Exception: " . $exception->getMessage() . 
               " in " . $exception->getFile() . 
               " on line " . $exception->getLine() . 
               "\nTrace: " . $exception->getTraceAsString();
    
    // Journaliser l'exception
    \Pronote\Logging\error($message);
    
    // En production, afficher un message générique
    if (defined('APP_ENV') && APP_ENV === 'production') {
        displayErrorPage(500, "Une erreur interne est survenue.");
    } else {
        displayErrorPage(500, $exception->getMessage(), $exception->getTraceAsString());
    }
}

/**
 * Callback appelé à la fin du script pour capturer les erreurs fatales
 * @return void
 */
function shutdownHandler() {
    $error = error_get_last();
    
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR])) {
        $message = "Fatal Error: " . $error['message'] . 
                   " in " . $error['file'] . 
                   " on line " . $error['line'];
        
        // Journaliser l'erreur fatale
        \Pronote\Logging\critical($message);
        
        // En production, afficher un message générique
        if (defined('APP_ENV') && APP_ENV === 'production') {
            displayErrorPage(500, "Une erreur interne fatale est survenue.");
        } else {
            displayErrorPage(500, $message);
        }
    }
}

/**
 * Affiche une page d'erreur
 * @param int $code Code HTTP de l'erreur
 * @param string $message Message d'erreur
 * @param string $detail Détails de l'erreur (optionnel)
 * @return void
 */
function displayErrorPage($code, $message, $detail = '') {
    // Envoyer le code HTTP approprié
    http_response_code($code);
    
    // Si la sortie a déjà commencé, l'effacer si possible
    if (ob_get_length()) {
        ob_clean();
    }
    
    // Titre selon le code HTTP
    $titles = [
        400 => 'Requête incorrecte',
        401 => 'Non autorisé',
        403 => 'Accès interdit',
        404 => 'Page non trouvée',
        500 => 'Erreur interne du serveur',
        503 => 'Service indisponible'
    ];
    
    $title = $titles[$code] ?? 'Erreur';
    
    // Afficher la page d'erreur
    echo '<!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>' . $title . ' - Pronote</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                line-height: 1.6;
                max-width: 800px;
                margin: 0 auto;
                padding: 20px;
                color: #333;
            }
            h1 {
                color: #e74c3c;
                margin-bottom: 20px;
            }
            .error-code {
                font-size: 72px;
                margin-bottom: 20px;
                color: #e74c3c;
            }
            .error-message {
                font-size: 18px;
                margin-bottom: 30px;
            }
            .error-details {
                background: #f8f8f8;
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 15px;
                font-family: monospace;
                white-space: pre-wrap;
                margin-top: 20px;
                max-height: 300px;
                overflow: auto;
            }
            .back-link {
                display: inline-block;
                margin-top: 20px;
                color: #3498db;
                text-decoration: none;
            }
            .back-link:hover {
                text-decoration: underline;
            }
        </style>
    </head>
    <body>
        <div class="error-code">' . $code . '</div>
        <h1>' . $title . '</h1>
        <div class="error-message">' . htmlspecialchars($message) . '</div>';
    
    // Afficher les détails en mode développement
    if (!empty($detail) && defined('APP_ENV') && APP_ENV !== 'production') {
        echo '<div class="error-details">' . htmlspecialchars($detail) . '</div>';
    }
    
    echo '<a href="' . (defined('HOME_URL') ? HOME_URL : '/') . '" class="back-link">Retour à l\'accueil</a>
    </body>
    </html>';
    
    // Terminer l'exécution du script
    exit;
}

/**
 * Enregistre les gestionnaires d'erreurs personnalisés
 * @return void
 */
function registerErrorHandlers() {
    set_error_handler('\Pronote\Errors\errorHandler');
    set_exception_handler('\Pronote\Errors\exceptionHandler');
    register_shutdown_function('\Pronote\Errors\shutdownHandler');
    
    // Activer la mise en mémoire tampon de sortie pour pouvoir effacer les erreurs
    if (!ob_get_level()) {
        ob_start();
    }
}
