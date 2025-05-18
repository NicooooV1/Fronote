<?php
/**
 * Système de journalisation centralisé
 */
namespace Pronote\Logging;

// Niveaux de journalisation
define('LOG_LEVEL_DEBUG', 100);
define('LOG_LEVEL_INFO', 200);
define('LOG_LEVEL_WARNING', 300);
define('LOG_LEVEL_ERROR', 400);
define('LOG_LEVEL_CRITICAL', 500);

// Tableau associant les niveaux de log à leur nom
$GLOBALS['log_levels'] = [
    LOG_LEVEL_DEBUG => 'DEBUG',
    LOG_LEVEL_INFO => 'INFO',
    LOG_LEVEL_WARNING => 'WARNING',
    LOG_LEVEL_ERROR => 'ERROR',
    LOG_LEVEL_CRITICAL => 'CRITICAL',
];

/**
 * Écrit un message dans le fichier de log
 * @param string $message Le message à journaliser
 * @param int $level Le niveau de journalisation
 * @param string $category La catégorie du message
 * @return bool True si l'écriture a réussi
 */
function log_message($message, $level = LOG_LEVEL_INFO, $category = 'general') {
    // Vérifier si la journalisation est activée
    if (!defined('LOG_ENABLED') || !LOG_ENABLED) {
        return false;
    }
    
    // Vérifier le niveau minimum de journalisation
    $min_level = get_min_log_level();
    if ($level < $min_level) {
        return false;
    }
    
    // Déterminer le chemin du fichier de log
    $log_dir = defined('LOGS_PATH') ? LOGS_PATH : (__DIR__ . '/../../logs');
    
    // Créer le répertoire s'il n'existe pas
    if (!is_dir($log_dir)) {
        if (!@mkdir($log_dir, 0755, true)) {
            // Utiliser le répertoire temporaire si on ne peut pas créer le dossier
            $log_dir = sys_get_temp_dir();
        }
    }
    
    // Vérifier si le répertoire est accessible en écriture
    if (!is_writable($log_dir)) {
        // Utiliser le répertoire temporaire si le dossier n'est pas accessible en écriture
        $log_dir = sys_get_temp_dir();
    }
    
    // Construire le nom du fichier de log
    $log_file = $log_dir . '/pronote_' . date('Y-m-d') . '.log';
    
    // Formater le message de log
    $level_name = $GLOBALS['log_levels'][$level] ?? 'UNKNOWN';
    $timestamp = date('Y-m-d H:i:s');
    $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $user_info = '';
    
    // Ajouter les informations de l'utilisateur si disponibles
    if (isset($_SESSION['user']) && !empty($_SESSION['user'])) {
        $user_info = "| User: {$_SESSION['user']['prenom']} {$_SESSION['user']['nom']} ({$_SESSION['user']['profil']})";
    }
    
    // Préparer le message de log
    $formatted_message = "[$timestamp] [$level_name] [$category] [$remote_addr] $user_info | $message\n";
    
    // Écrire dans le fichier de log
    return @file_put_contents($log_file, $formatted_message, FILE_APPEND) !== false;
}

/**
 * Détermine le niveau minimum de journalisation
 * @return int Le niveau minimum
 */
function get_min_log_level() {
    if (!defined('LOG_LEVEL')) {
        return LOG_LEVEL_INFO;
    }
    
    switch (strtolower(LOG_LEVEL)) {
        case 'debug':
            return LOG_LEVEL_DEBUG;
        case 'info':
            return LOG_LEVEL_INFO;
        case 'warning':
            return LOG_LEVEL_WARNING;
        case 'error':
            return LOG_LEVEL_ERROR;
        case 'critical':
            return LOG_LEVEL_CRITICAL;
        default:
            return LOG_LEVEL_INFO;
    }
}

/**
 * Journalise un message de débogage
 * @param string $message Le message
 * @param string $category La catégorie
 * @return bool Résultat de l'opération
 */
function debug($message, $category = 'debug') {
    return log_message($message, LOG_LEVEL_DEBUG, $category);
}

/**
 * Journalise un message d'information
 * @param string $message Le message
 * @param string $category La catégorie
 * @return bool Résultat de l'opération
 */
function info($message, $category = 'info') {
    return log_message($message, LOG_LEVEL_INFO, $category);
}

/**
 * Journalise un avertissement
 * @param string $message Le message
 * @param string $category La catégorie
 * @return bool Résultat de l'opération
 */
function warning($message, $category = 'warning') {
    return log_message($message, LOG_LEVEL_WARNING, $category);
}

/**
 * Journalise une erreur
 * @param string $message Le message
 * @param string $category La catégorie
 * @return bool Résultat de l'opération
 */
function error($message, $category = 'error') {
    return log_message($message, LOG_LEVEL_ERROR, $category);
}

/**
 * Journalise une erreur critique
 * @param string $message Le message
 * @param string $category La catégorie
 * @return bool Résultat de l'opération
 */
function critical($message, $category = 'critical') {
    return log_message($message, LOG_LEVEL_CRITICAL, $category);
}

// Alias de fonctions sans namespace pour la compatibilité
function_alias('Pronote\Logging\debug', 'debug_log');
function_alias('Pronote\Logging\info', 'info_log');
function_alias('Pronote\Logging\warning', 'warning_log');
function_alias('Pronote\Logging\error', 'error_log_custom');
function_alias('Pronote\Logging\critical', 'critical_log');

/**
 * Crée un alias de fonction s'il n'existe pas déjà
 * @param string $original Nom de la fonction originale
 * @param string $alias Nom de l'alias à créer
 */
function function_alias($original, $alias) {
    if (!function_exists($alias) && function_exists($original)) {
        eval("function $alias() { return call_user_func_array('$original', func_get_args()); }");
    }
}
