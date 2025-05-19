<?php
/**
 * Système de journalisation centralisé
 * Fournit une compatibilité avec l'ancien système et le nouveau
 */

// Inclure le système de journalisation de base s'il existe
$loggingPath = __DIR__ . '/core/logging.php';
if (file_exists($loggingPath)) {
    require_once $loggingPath;
}

// Constantes pour les niveaux de log (pour compatibilité)
if (!defined('LOG_LEVEL_DEBUG')) define('LOG_LEVEL_DEBUG', 100);
if (!defined('LOG_LEVEL_INFO')) define('LOG_LEVEL_INFO', 200);
if (!defined('LOG_LEVEL_WARNING')) define('LOG_LEVEL_WARNING', 300);
if (!defined('LOG_LEVEL_ERROR')) define('LOG_LEVEL_ERROR', 400);
if (!defined('LOG_LEVEL_CRITICAL')) define('LOG_LEVEL_CRITICAL', 500);

/**
 * Convertit un niveau numérique en chaîne
 * @param int $level Niveau numérique
 * @return string Niveau en chaîne
 */
function getLogLevelString($level) {
    switch ($level) {
        case LOG_LEVEL_DEBUG:
            return \Pronote\Logging\LEVEL_DEBUG;
        case LOG_LEVEL_INFO:
            return \Pronote\Logging\LEVEL_INFO;
        case LOG_LEVEL_WARNING:
            return \Pronote\Logging\LEVEL_WARNING;
        case LOG_LEVEL_ERROR:
            return \Pronote\Logging\LEVEL_ERROR;
        case LOG_LEVEL_CRITICAL:
            return \Pronote\Logging\LEVEL_CRITICAL;
        default:
            return \Pronote\Logging\LEVEL_INFO;
    }
}

/**
 * Écrire un message dans le journal (compatible avec l'ancien système)
 * @param string $message Message à journaliser
 * @param int $level Niveau de log (LOG_LEVEL_*)
 * @param string $category Catégorie du message
 * @return bool True si l'écriture a réussi
 */
function log_message($message, $level = LOG_LEVEL_INFO, $category = 'general') {
    if (function_exists('\\Pronote\\Logging\\log')) {
        $levelString = getLogLevelString($level);
        return \Pronote\Logging\log($message, $levelString, ['category' => $category]);
    }
    
    // Implémentation de secours si le nouveau système n'est pas disponible
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

// Fonctions d'alias pour compatibilité
if (!function_exists('debug_log')) {
    function debug_log($message, $category = 'debug') {
        if (function_exists('\\Pronote\\Logging\\debug')) {
            return \Pronote\Logging\debug($message, ['category' => $category]);
        }
        return log_message($message, LOG_LEVEL_DEBUG, $category);
    }
}

if (!function_exists('info_log')) {
    function info_log($message, $category = 'info') {
        return log_message($message, LOG_LEVEL_INFO, $category);
    }
}

if (!function_exists('warning_log')) {
    function warning_log($message, $category = 'warning') {
        return log_message($message, LOG_LEVEL_WARNING, $category);
    }
}

if (!function_exists('error_log_custom')) {
    function error_log_custom($message, $category = 'error') {
        if (function_exists('\\Pronote\\Logging\\error')) {
            return \Pronote\Logging\error($message, ['category' => $category]);
        }
        return log_message($message, LOG_LEVEL_ERROR, $category);
    }
}

if (!function_exists('critical_log')) {
    function critical_log($message, $category = 'critical') {
        return log_message($message, LOG_LEVEL_CRITICAL, $category);
    }
}
