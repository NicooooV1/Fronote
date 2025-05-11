<?php
/**
 * Gestionnaire d'erreurs centralisé
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/logger.php';

/**
 * Gère les erreurs API et renvoie une réponse JSON
 * @param Exception $e L'exception
 * @param array $context Contexte supplémentaire
 * @return void
 */
function handleApiError($e, $context = []) {
    // Journaliser l'erreur
    logException($e, $context);
    
    // Préparer la réponse
    $isProduction = (getenv('ENVIRONMENT') === 'production');
    $message = $isProduction ? 'Une erreur est survenue' : $e->getMessage();
    
    // Définir le code HTTP approprié
    $httpCode = 500;
    if ($e instanceof PDOException) {
        $httpCode = 500; // Erreur de base de données
    } elseif ($e->getCode() >= 400 && $e->getCode() <= 599) {
        $httpCode = $e->getCode(); // Utiliser le code d'erreur HTTP si disponible
    }
    
    http_response_code($httpCode);
    
    // Envoyer la réponse JSON
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $message,
        'error_code' => $httpCode
    ]);
    exit;
}

/**
 * Gère les erreurs d'authentification
 * @return void
 */
function handleAuthError() {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Non authentifié',
        'error_code' => 401
    ]);
    exit;
}

/**
 * Gère les erreurs d'autorisation
 * @param string $message Message d'erreur optionnel
 * @return void
 */
function handleForbiddenError($message = 'Accès non autorisé') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $message,
        'error_code' => 403
    ]);
    exit;
}

/**
 * Gère les erreurs de validation
 * @param string $message Message d'erreur
 * @param array $validationErrors Erreurs de validation détaillées
 * @return void
 */
function handleValidationError($message = 'Données invalides', $validationErrors = []) {
    http_response_code(422);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $message,
        'error_code' => 422,
        'validation_errors' => $validationErrors
    ]);
    exit;
}

/**
 * Gère les erreurs de ressource non trouvée
 * @param string $message Message d'erreur
 * @return void
 */
function handleNotFoundError($message = 'Ressource non trouvée') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $message,
        'error_code' => 404
    ]);
    exit;
}