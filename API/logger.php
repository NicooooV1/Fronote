<?php
/**
 * Système de journalisation centralisé
 */

// Constantes pour les niveaux de log
if (!defined('LOG_LEVEL_DEBUG')) define('LOG_LEVEL_DEBUG', 100);
if (!defined('LOG_LEVEL_INFO')) define('LOG_LEVEL_INFO', 200);
if (!defined('LOG_LEVEL_WARNING')) define('LOG_LEVEL_WARNING', 300);
if (!defined('LOG_LEVEL_ERROR')) define('LOG_LEVEL_ERROR', 400);
if (!defined('LOG_LEVEL_CRITICAL')) define('LOG_LEVEL_CRITICAL', 500);

/**
 * Écrire un message dans le journal
 * @param string $message Message à journaliser
 * @param int $level Niveau de log (LOG_LEVEL_*)
 * @param string $category Catégorie du message
 * @return bool True si l'écriture a réussi
 */
function log_message($message, $level = LOG_LEVEL_INFO, $category = 'general') {
    // Vérifier si la journalisation est activée
    if (!defined('LOG_ENABLED') || !LOG_ENABLED) {
        return false;
    }
    
    // Déterminer le niveau minimal de log
    $minLevel = get_min_log_level();
    if ($level < $minLevel) {
        return false;
    }
    
    // Déterminer le chemin du fichier de log
    $logDir = defined('LOGS_PATH') ? LOGS_PATH : (__DIR__ . '/logs');
    
    // Créer le répertoire s'il n'existe pas
    if (!is_dir($logDir)) {
        if (!@mkdir($logDir, 0755, true)) {
            // Utiliser le répertoire temporaire si on ne peut pas créer le dossier
            $logDir = sys_get_temp_dir();
        }
    }
    
    // Vérifier si le répertoire est accessible en écriture
    if (!is_writable($logDir)) {
        // Utiliser le répertoire temporaire si le dossier n'est pas accessible en écriture
        $logDir = sys_get_temp_dir();
    }
    
    // Construire le nom du fichier de log
    $logFile = $logDir . '/pronote_' . date('Y-m-d') . '.log';
    
    // Obtenir le nom textuel du niveau de log
    $levelNames = [
        LOG_LEVEL_DEBUG => 'DEBUG',
        LOG_LEVEL_INFO => 'INFO',
        LOG_LEVEL_WARNING => 'WARNING',
        LOG_LEVEL_ERROR => 'ERROR',
        LOG_LEVEL_CRITICAL => 'CRITICAL',
    ];
    $levelName = $levelNames[$level] ?? 'UNKNOWN';
    
    // Formatage du message
    $timestamp = date('Y-m-d H:i:s');
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $userInfo = '';
    
    // Ajouter les informations de l'utilisateur si disponibles
    if (isset($_SESSION['user']) && !empty($_SESSION['user'])) {
        $user = $_SESSION['user'];
        $userInfo = " | User: {$user['prenom']} {$user['nom']} ({$user['profil']})";
    }
    
    // Préparer le message de log
    $formattedMessage = "[$timestamp] [$levelName] [$category] [$remoteAddr]$userInfo | $message\n";
    
    // Écrire dans le fichier de log
    return @file_put_contents($logFile, $formattedMessage, FILE_APPEND) !== false;
}

/**
 * Détermine le niveau minimum de journalisation
 * @return int Niveau minimum de log
 */
function get_min_log_level() {
    if (!defined('LOG_LEVEL')) {
        return LOG_LEVEL_INFO; // Par défaut
    }
    
    switch (strtolower(LOG_LEVEL)) {
        case 'debug': return LOG_LEVEL_DEBUG;
        case 'info': return LOG_LEVEL_INFO;
        case 'warning': return LOG_LEVEL_WARNING;
        case 'error': return LOG_LEVEL_ERROR;
        case 'critical': return LOG_LEVEL_CRITICAL;
        default: return LOG_LEVEL_INFO;
    }
}

/**
 * Journaliser un message de débogage
 * @param string $message Message à journaliser
 * @param string $category Catégorie du message
 */
function debug_log($message, $category = 'debug') {
    return log_message($message, LOG_LEVEL_DEBUG, $category);
}

/**
 * Journaliser un message d'information
 * @param string $message Message à journaliser
 * @param string $category Catégorie du message
 */
function info_log($message, $category = 'info') {
    return log_message($message, LOG_LEVEL_INFO, $category);
}

/**
 * Journaliser un avertissement
 * @param string $message Message à journaliser
 * @param string $category Catégorie du message
 */
function warning_log($message, $category = 'warning') {
    return log_message($message, LOG_LEVEL_WARNING, $category);
}

/**
 * Journaliser une erreur
 * @param string $message Message à journaliser
 * @param string $category Catégorie du message
 */
function error_log_custom($message, $category = 'error') {
    return log_message($message, LOG_LEVEL_ERROR, $category);
}

/**
 * Journaliser une erreur critique
 * @param string $message Message à journaliser
 * @param string $category Catégorie du message
 */
function critical_log($message, $category = 'critical') {
    return log_message($message, LOG_LEVEL_CRITICAL, $category);
}
