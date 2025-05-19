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
if (!function_exists('\\Pronote\\Logging\\error')) {
    $loggingPath = __DIR__ . '/logging.php';
    if (file_exists($loggingPath)) {
        require_once $loggingPath;
    } else {
        // Définir des fonctions de secours en namespace global
        // car on ne peut pas définir de fonctions dans un namespace différent
        // au milieu d'un fichier
        
        // On les stocke donc en namespace global et on les utilisera par leur nom complet
        namespace {
            if (!function_exists('Pronote\\Logging\\error')) {
                function pronote_logging_error($message, $context = []) {
                    error_log($message);
                    return true;
                }
            }
            
            if (!function_exists('Pronote\\Logging\\critical')) {
                function pronote_logging_critical($message, $context = []) {
                    error_log('CRITICAL: ' . $message);
                    return true;
                }
            }
        }
    }
}

// Fonctions pour la gestion d'erreurs
namespace Pronote\Errors {
    /**
     * Enregistre les gestionnaires d'erreurs personnalisés
     */
    function registerErrorHandlers() {
        // Gestionnaire d'exceptions personnalisé
        set_exception_handler('\\Pronote\\Errors\\handleException');
        
        // Gestionnaire d'erreurs personnalisé
        set_error_handler('\\Pronote\\Errors\\handleError');
        
        // Gestionnaire de shutdown
        register_shutdown_function('\\Pronote\\Errors\\handleShutdown');
    }
    
    /**
     * Gestionnaire d'exceptions personnalisé
     * @param \Throwable $e Exception capturée
     */
    function handleException(\Throwable $e) {
        $severity = "ERROR";
        $message = $e->getMessage();
        $file = $e->getFile();
        $line = $e->getLine();
        $trace = $e->getTraceAsString();
        
        // Journaliser l'exception avec le système de journalisation
        if (function_exists('\\Pronote\\Logging\\error')) {
            \Pronote\Logging\error("{$severity}: {$message}", [
                'file' => $file,
                'line' => $line,
                'trace' => $trace
            ]);
        } else {
            // Fallback si le système de journalisation n'est pas disponible
            // On utilise la fonction définie dans le namespace global
            \pronote_logging_error("{$severity}: {$message} in {$file} on line {$line}\n{$trace}");
        }
    }
    
    /**
     * Gestionnaire d'erreurs personnalisé
     * @param int $errno Niveau d'erreur
     * @param string $errstr Message d'erreur
     * @param string $errfile Fichier où l'erreur s'est produite
     * @param int $errline Ligne où l'erreur s'est produite
     * @return bool
     */
    function handleError($errno, $errstr, $errfile, $errline) {
        // Ne pas gérer les erreurs qui sont désactivées dans la configuration PHP
        if (!(error_reporting() & $errno)) {
            return false;
        }
        
        // Déterminer la gravité de l'erreur
        $severity = "UNKNOWN";
        switch ($errno) {
            case E_ERROR:
            case E_USER_ERROR:
                $severity = "ERROR";
                break;
            case E_WARNING:
            case E_USER_WARNING:
                $severity = "WARNING";
                break;
            case E_NOTICE:
            case E_USER_NOTICE:
                $severity = "NOTICE";
                break;
        }
        
        // Journaliser l'erreur
        if (function_exists('\\Pronote\\Logging\\error')) {
            \Pronote\Logging\error("{$severity}: {$errstr}", [
                'file' => $errfile,
                'line' => $errline
            ]);
        } else {
            // Fallback
            \pronote_logging_error("{$severity}: {$errstr} in {$errfile} on line {$errline}");
        }
        
        // Retourner true pour empêcher l'exécution du gestionnaire d'erreurs standard de PHP
        return true;
    }
    
    /**
     * Gestionnaire de shutdown pour capturer les erreurs fatales
     */
    function handleShutdown() {
        $error = error_get_last();
        
        if ($error !== null && ($error['type'] === E_ERROR || $error['type'] === E_PARSE)) {
            $severity = "FATAL ERROR";
            $message = $error['message'];
            $file = $error['file'];
            $line = $error['line'];
            
            // Journaliser l'erreur fatale
            if (function_exists('\\Pronote\\Logging\\critical')) {
                \Pronote\Logging\critical("{$severity}: {$message}", [
                    'file' => $file,
                    'line' => $line
                ]);
            } else {
                // Fallback
                \pronote_logging_critical("{$severity}: {$message} in {$file} on line {$line}");
            }
        }
    }
}