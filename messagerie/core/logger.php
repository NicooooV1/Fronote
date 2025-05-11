<?php
/**
 * Système de journalisation des erreurs
 */
require_once __DIR__ . '/../config/config.php';

// Définir le dossier des logs (en dehors du dossier web)
define('LOG_DIR', dirname(__DIR__, 2) . '/logs/');

// S'assurer que le dossier existe
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}

/**
 * Écrit un message dans le journal des erreurs
 * @param string $message Message à journaliser
 * @param string $level Niveau de log (ERROR, WARNING, INFO, DEBUG)
 * @param array $context Données contextuelles
 */
function logMessage($message, $level = 'ERROR', $context = []) {
    $date = date('Y-m-d H:i:s');
    $logFile = LOG_DIR . date('Y-m-d') . '.log';
    
    // Préparer le contenu du message
    $logData = [
        'timestamp' => $date,
        'level' => $level,
        'message' => $message,
        'user_id' => $_SESSION['user']['id'] ?? 'anonymous',
        'user_type' => $_SESSION['user']['type'] ?? 'anonymous',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
        'context' => $context
    ];
    
    // Formater le message
    $logEntry = json_encode($logData) . PHP_EOL;
    
    // Écrire dans le fichier
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

/**
 * Journalise une exception
 * @param Exception $exception Exception à journaliser
 * @param array $context Données contextuelles
 */
function logException($exception, $context = []) {
    $context['exception'] = [
        'class' => get_class($exception),
        'code' => $exception->getCode(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString()
    ];
    
    logMessage($exception->getMessage(), 'ERROR', $context);
}

/**
 * Gère une erreur de manière centralisée
 * @param Exception $e Exception à gérer
 * @param bool $isApi Si c'est une API qui renvoie du JSON
 * @param string $redirectUrl URL de redirection en cas d'erreur
 */
function handleError($e, $isApi = false, $redirectUrl = null) {
    // Journaliser l'erreur
    logException($e);
    
    // Déterminer le type d'erreur et le message à afficher
    $publicMessage = "Une erreur est survenue. Veuillez réessayer plus tard.";
    
    // En développement, afficher l'erreur réelle
    if (getenv('ENVIRONMENT') !== 'production') {
        $publicMessage = $e->getMessage();
    }
    
    // Gérer différemment selon le type de réponse
    if ($isApi) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $publicMessage
        ]);
        exit;
    } else {
        // Store error in session for display after redirect
        $_SESSION['error_message'] = $publicMessage;
        
        if ($redirectUrl) {
            redirect($redirectUrl);
        } else {
            // Fallback to display error directly
            include_once dirname(__DIR__) . '/templates/error.php';
            exit;
        }
    }
}