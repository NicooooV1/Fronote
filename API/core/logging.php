<?php
/**
 * Système de journalisation pour Pronote
 * Ce fichier fournit des fonctions de journalisation centralisées
 */
namespace Pronote\Logging;

// Constantes pour les niveaux de journalisation
const LEVEL_DEBUG = 'debug';
const LEVEL_INFO = 'info';
const LEVEL_WARNING = 'warning';
const LEVEL_ERROR = 'error';
const LEVEL_CRITICAL = 'critical';

/**
 * Journalise un message
 * @param string $message Message à journaliser
 * @param string $level Niveau de journalisation
 * @param array $context Contexte additionnel
 * @return bool True si le message a été journalisé
 */
function log($message, $level = LEVEL_INFO, $context = []) {
    // Vérifier si la journalisation est activée
    if (!defined('LOG_ENABLED') || !LOG_ENABLED) {
        return false;
    }
    
    // Vérifier le niveau de journalisation
    $logLevel = defined('LOG_LEVEL') ? LOG_LEVEL : LEVEL_INFO;
    $levels = [LEVEL_DEBUG, LEVEL_INFO, LEVEL_WARNING, LEVEL_ERROR, LEVEL_CRITICAL];
    
    if (array_search($level, $levels) < array_search($logLevel, $levels)) {
        return false;
    }
    
    // Préparer le message de log
    $timestamp = date('Y-m-d H:i:s');
    $user = isset($_SESSION['user']) ? $_SESSION['user']['prenom'] . ' ' . $_SESSION['user']['nom'] : 'Système';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    
    $logMessage = "[$timestamp] [$level] [$ip] [$user] $message";
    
    // Ajouter le contexte si présent
    if (!empty($context)) {
        $logMessage .= PHP_EOL . json_encode($context, JSON_PRETTY_PRINT);
    }
    
    // Déterminer le fichier de log
    $logDir = defined('LOGS_PATH') ? LOGS_PATH : __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/pronote-' . date('Y-m-d') . '.log';
    
    // Écrire dans le fichier de log
    return error_log($logMessage . PHP_EOL, 3, $logFile);
}

/**
 * Journalise un message de débogage
 * @param string $message Message à journaliser
 * @param array $context Contexte additionnel
 * @return bool True si le message a été journalisé
 */
function debug($message, $context = []) {
    return log($message, LEVEL_DEBUG, $context);
}

/**
 * Journalise un message d'information
 * @param string $message Message à journaliser
 * @param array $context Contexte additionnel
 * @return bool True si le message a été journalisé
 */
function info($message, $context = []) {
    return log($message, LEVEL_INFO, $context);
}

/**
 * Journalise un avertissement
 * @param string $message Message à journaliser
 * @param array $context Contexte additionnel
 * @return bool True si le message a été journalisé
 */
function warning($message, $context = []) {
    return log($message, LEVEL_WARNING, $context);
}

/**
 * Journalise une erreur
 * @param string $message Message à journaliser
 * @param array $context Contexte additionnel
 * @return bool True si le message a été journalisé
 */
function error($message, $context = []) {
    return log($message, LEVEL_ERROR, $context);
}

/**
 * Journalise une erreur critique
 * @param string $message Message à journaliser
 * @param array $context Contexte additionnel
 * @return bool True si le message a été journalisé
 */
function critical($message, $context = []) {
    return log($message, LEVEL_CRITICAL, $context);
}

/**
 * Journalise un accès à une page
 * @return bool True si le message a été journalisé
 */
function pageAccess() {
    $page = $_SERVER['REQUEST_URI'] ?? 'Unknown';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $referer = $_SERVER['HTTP_REFERER'] ?? 'Direct';
    
    return info("Accès à la page $page [$method] depuis $referer");
}

/**
 * Journalise une action d'authentification
 * @param string $action Type d'action (login, logout, session_expired, etc.)
 * @param string $username Nom d'utilisateur concerné
 * @param bool $success Succès ou échec de l'action
 * @param array $context Contexte additionnel
 * @return bool True si le message a été journalisé
 */
function authAction($action, $username, $success = true, $context = []) {
    $status = $success ? 'Succès' : 'Échec';
    $message = "Authentication $action: $username - $status";
    $level = $success ? LEVEL_INFO : LEVEL_WARNING;
    
    return log($message, $level, $context);
}
