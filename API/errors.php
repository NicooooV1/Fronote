<?php
/**
 * Gestionnaire d'erreurs centralisé pour Pronote
 * Fournit des fonctions pour la journalisation et l'affichage des erreurs
 */

// Configuration initiale pour la gestion d'erreurs
if (!defined('APP_ENV')) define('APP_ENV', 'development');
ini_set('display_errors', APP_ENV === 'development');
error_reporting(E_ALL);

/**
 * Enregistre le gestionnaire d'erreurs personnalisé
 */
function registerErrorHandler() {
    set_error_handler('customErrorHandler');
    set_exception_handler('customExceptionHandler');
    register_shutdown_function('fatalErrorHandler');
}

/**
 * Gestionnaire d'erreurs personnalisé
 * @param int $errno Niveau d'erreur
 * @param string $errstr Message d'erreur
 * @param string $errfile Fichier où l'erreur s'est produite
 * @param int $errline Ligne où l'erreur s'est produite
 * @return bool True pour empêcher le gestionnaire d'erreur standard d'être appelé
 */
function customErrorHandler($errno, $errstr, $errfile, $errline) {
    // Ignorer les erreurs qui sont désactivées par la configuration de PHP
    if (!(error_reporting() & $errno)) {
        return false;
    }
    
    $error_type = getErrorTypeName($errno);
    
    // Journaliser l'erreur en utilisant la fonction de journalisation sécurisée
    safeLogError($error_type, $errstr, $errfile, $errline);
    
    // En mode développement, afficher les erreurs
    if (APP_ENV === 'development' && error_reporting() & $errno) {
        echo "<div style='background-color: #FFDDDD; color: #CC0000; padding: 10px; margin: 10px 0; border: 1px solid #CC0000;'>";
        echo "<h3>Une erreur est survenue</h3>";
        echo "<p><strong>Type:</strong> $error_type</p>";
        echo "<p><strong>Message:</strong> $errstr</p>";
        echo "<p><strong>Fichier:</strong> $errfile</p>";
        echo "<p><strong>Ligne:</strong> $errline</p>";
        echo "</div>";
    }
    
    // Si c'est une erreur fatale, on arrête l'exécution
    if ($errno == E_USER_ERROR || $errno == E_ERROR) {
        exit(1);
    }
    
    return true;
}

/**
 * Gestionnaire d'exceptions personnalisé
 * @param Throwable $exception L'exception lancée
 */
function customExceptionHandler($exception) {
    // Journaliser l'exception en utilisant la fonction de journalisation sécurisée
    safeLogException($exception);
    
    // En mode développement, afficher l'exception
    if (APP_ENV === 'development') {
        echo "<div style='background-color: #FFDDDD; color: #CC0000; padding: 10px; margin: 10px 0; border: 1px solid #CC0000;'>";
        echo "<h3>Une exception est survenue</h3>";
        echo "<p><strong>Type:</strong> " . get_class($exception) . "</p>";
        echo "<p><strong>Message:</strong> " . $exception->getMessage() . "</p>";
        echo "<p><strong>Fichier:</strong> " . $exception->getFile() . "</p>";
        echo "<p><strong>Ligne:</strong> " . $exception->getLine() . "</p>";
        
        // En mode dev, afficher la trace
        echo "<h4>Trace:</h4>";
        echo "<pre>" . $exception->getTraceAsString() . "</pre>";
        echo "</div>";
    } else {
        // En production, afficher un message d'erreur générique
        displayUserFriendlyError();
    }
}

/**
 * Gestionnaire d'erreurs fatales
 */
function fatalErrorHandler() {
    $error = error_get_last();
    
    if ($error !== null && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
        // Nettoyer la sortie précédente
        ob_clean();
        
        // Journaliser l'erreur fatale en utilisant la fonction sécurisée
        safeLogError('FATAL_ERROR', $error['message'], $error['file'], $error['line']);
        
        // Afficher un message d'erreur approprié
        if (APP_ENV === 'development') {
            echo "<div style='background-color: #FFDDDD; color: #CC0000; padding: 10px; margin: 10px 0; border: 1px solid #CC0000;'>";
            echo "<h3>Une erreur fatale est survenue</h3>";
            echo "<p><strong>Message:</strong> " . $error['message'] . "</p>";
            echo "<p><strong>Fichier:</strong> " . $error['file'] . "</p>";
            echo "<p><strong>Ligne:</strong> " . $error['line'] . "</p>";
            echo "</div>";
        } else {
            // En production, afficher un message d'erreur générique
            displayUserFriendlyError();
        }
    }
}

/**
 * Affiche un message d'erreur convivial pour l'utilisateur
 */
function displayUserFriendlyError() {
    http_response_code(500);
    echo "<div style='text-align: center; padding: 40px;'>";
    echo "<h2>Oups! Une erreur est survenue.</h2>";
    echo "<p>Nous sommes désolés pour ce désagrément. Notre équipe technique a été informée.</p>";
    echo "<p>Veuillez réessayer ultérieurement ou <a href='". (defined('HOME_URL') ? HOME_URL : '/') ."'>retourner à l'accueil</a>.</p>";
    echo "</div>";
}

/**
 * Version sécurisée de la journalisation d'erreurs qui gère les problèmes de permission
 * @param string $error_type Type d'erreur
 * @param string $message Message d'erreur
 * @param string $file Fichier où l'erreur s'est produite
 * @param int $line Ligne où l'erreur s'est produite
 */
function safeLogError($error_type, $message, $file, $line) {
    try {
        $log_file = sys_get_temp_dir() . '/pronote_errors.log';
        $timestamp = date('Y-m-d H:i:s');
        $user_info = 'Non connecté';
        
        if (function_exists('getCurrentUser')) {
            $user = getCurrentUser();
            if ($user && isset($user['identifiant'])) { // Vérification de l'existence de l'index 'identifiant'
                $user_info = $user['identifiant'] . ' (' . $user['profil'] . ')';
            } elseif ($user) {
                // Si l'utilisateur est défini mais pas son identifiant
                $nom = isset($user['nom']) ? $user['nom'] : 'Inconnu';
                $prenom = isset($user['prenom']) ? $user['prenom'] : '';
                $profil = isset($user['profil']) ? $user['profil'] : 'Inconnu';
                $user_info = "$prenom $nom ($profil)";
            }
        }
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $url = $_SERVER['REQUEST_URI'] ?? 'Unknown';
        
        $log_entry = "[$timestamp] [$error_type] [$ip] [$user_info] [$url] $message in $file on line $line" . PHP_EOL;
        
        file_put_contents($log_file, $log_entry, FILE_APPEND);
        
        // Essayer d'écrire dans le répertoire de logs de l'application si possible
        $app_log_dir = __DIR__ . '/logs';
        if (is_dir($app_log_dir) && is_writable($app_log_dir)) {
            $app_log_file = $app_log_dir . '/errors.log';
            file_put_contents($app_log_file, $log_entry, FILE_APPEND);
        }
    } catch (Exception $e) {
        // En cas d'erreur dans la journalisation, simplement continuer
        // Ne pas échouer si la journalisation est impossible
    }
}

/**
 * Version sécurisée de la journalisation d'exceptions qui gère les problèmes de permission
 * @param Throwable $exception L'exception à journaliser
 */
function safeLogException($exception) {
    try {
        $log_file = sys_get_temp_dir() . '/pronote_exceptions.log';
        $timestamp = date('Y-m-d H:i:s');
        $user_info = 'Non connecté';
        
        if (function_exists('getCurrentUser')) {
            $user = getCurrentUser();
            if ($user && isset($user['identifiant'])) { // Vérification de l'existence de l'index 'identifiant'
                $user_info = $user['identifiant'] . ' (' . $user['profil'] . ')';
            } elseif ($user) {
                // Si l'utilisateur est défini mais pas son identifiant
                $nom = isset($user['nom']) ? $user['nom'] : 'Inconnu';
                $prenom = isset($user['prenom']) ? $user['prenom'] : '';
                $profil = isset($user['profil']) ? $user['profil'] : 'Inconnu';
                $user_info = "$prenom $nom ($profil)";
            }
        }
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $url = $_SERVER['REQUEST_URI'] ?? 'Unknown';
        
        $log_entry = "[$timestamp] [EXCEPTION] [$ip] [$user_info] [$url] " . PHP_EOL;
        $log_entry .= "Type: " . get_class($exception) . PHP_EOL;
        $log_entry .= "Message: " . $exception->getMessage() . PHP_EOL;
        $log_entry .= "File: " . $exception->getFile() . " on line " . $exception->getLine() . PHP_EOL;
        $log_entry .= "Trace: " . PHP_EOL . $exception->getTraceAsString() . PHP_EOL . PHP_EOL;
        
        file_put_contents($log_file, $log_entry, FILE_APPEND);
        
        // Essayer d'écrire dans le répertoire de logs de l'application si possible
        $app_log_dir = __DIR__ . '/logs';
        if (is_dir($app_log_dir) && is_writable($app_log_dir)) {
            $app_log_file = $app_log_dir . '/exceptions.log';
            file_put_contents($app_log_file, $log_entry, FILE_APPEND);
        }
    } catch (Exception $e) {
        // En cas d'erreur dans la journalisation, simplement continuer
    }
}

/**
 * Retourne le nom explicite d'un type d'erreur
 * @param int $type Code d'erreur
 * @return string Nom du type d'erreur
 */
function getErrorTypeName($type) {
    switch($type) {
        case E_ERROR:             return 'E_ERROR';
        case E_WARNING:           return 'E_WARNING';
        case E_PARSE:             return 'E_PARSE';
        case E_NOTICE:            return 'E_NOTICE';
        case E_CORE_ERROR:        return 'E_CORE_ERROR';
        case E_CORE_WARNING:      return 'E_CORE_WARNING';
        case E_COMPILE_ERROR:     return 'E_COMPILE_ERROR';
        case E_COMPILE_WARNING:   return 'E_COMPILE_WARNING';
        case E_USER_ERROR:        return 'E_USER_ERROR';
        case E_USER_WARNING:      return 'E_USER_WARNING';
        case E_USER_NOTICE:       return 'E_USER_NOTICE';
        case E_STRICT:            return 'E_STRICT';
        case E_RECOVERABLE_ERROR: return 'E_RECOVERABLE_ERROR';
        case E_DEPRECATED:        return 'E_DEPRECATED';
        case E_USER_DEPRECATED:   return 'E_USER_DEPRECATED';
        default:                  return 'UNKNOWN';
    }
}

/**
 * Arrête l'exécution avec un message d'erreur convivial et journalise l'erreur
 * @param string $message Message d'erreur pour le log
 * @param string $user_message Message à afficher à l'utilisateur
 * @param int $status_code Code HTTP à retourner
 */
function stopWithError($message, $user_message = null, $status_code = 500) {
    // Journaliser l'erreur
    safeLogError('MANUAL_STOP', $message, debug_backtrace()[0]['file'] ?? __FILE__, debug_backtrace()[0]['line'] ?? __LINE__);
    
    // Définir le code de statut HTTP
    http_response_code($status_code);
    
    // Afficher un message d'erreur approprié
    if (APP_ENV === 'development') {
        echo "<div style='background-color: #FFDDDD; color: #CC0000; padding: 10px; margin: 10px 0; border: 1px solid #CC0000;'>";
        echo "<h3>Erreur</h3>";
        echo "<p>" . htmlspecialchars($message) . "</p>";
        echo "</div>";
    } else {
        // En production, utiliser le message utilisateur ou le message générique
        echo "<div style='text-align: center; padding: 40px;'>";
        echo "<h2>Erreur</h2>";
        echo "<p>" . ($user_message ? htmlspecialchars($user_message) : "Une erreur est survenue. Veuillez réessayer ultérieurement.") . "</p>";
        echo "<p><a href='". (defined('HOME_URL') ? HOME_URL : '/') ."'>Retourner à l'accueil</a></p>";
        echo "</div>";
    }
    
    exit;
}

// Enregistrer les gestionnaires d'erreur
registerErrorHandler();
