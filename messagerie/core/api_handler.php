<?php
/**
 * Gestionnaire centralisé pour les requêtes API
 */
class ApiHandler {
    private $pdo;
    private $user;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->user = checkAuth();
        
        // Vérification d'authentification
        if (!$this->user) {
            $this->sendResponse(false, 'Non authentifié', 401);
        }
        
        // Vérification CSRF pour les requêtes POST
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCsrf();
        }
        
        // Limiter le taux de requêtes
        $this->enforceRateLimit();
    }
    
    /**
     * Valide le jeton CSRF pour les requêtes POST
     */
    private function validateCsrf() {
        $requestData = file_get_contents('php://input');
        $data = json_decode($requestData, true) ?: [];
        
        // Vérifier le jeton CSRF soit dans les données JSON, soit dans l'en-tête
        $csrfToken = $data['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        
        if (!validateCSRFToken($csrfToken)) {
            $this->sendResponse(false, 'Jeton CSRF invalide', 403);
        }
    }
    
    /**
     * Applique une limite de taux pour l'API
     */
    private function enforceRateLimit($key = 'api_default', $maxAttempts = 60, $timeFrame = 60) {
        enforceRateLimit($key, $maxAttempts, $timeFrame, true);
    }
    
    /**
     * Envoie une réponse JSON avec le code HTTP approprié
     */
    public function sendResponse($success, $data = null, $code = 200) {
        http_response_code($code);
        header('Content-Type: application/json');
        
        $response = ['success' => $success];
        if ($success && $data) {
            $response = array_merge($response, $data);
        } elseif (!$success) {
            $response['error'] = $data;
        }
        
        echo json_encode($response);
        exit;
    }
    
    /**
     * Vérifie si l'utilisateur a une permission particulière
     */
    public function checkPermission($permission, $context = []) {
        return hasPermission($this->user, $permission, $context);
    }
    
    /**
     * Exige une permission particulière ou envoie une erreur
     */
    public function requirePermission($permission, $context = []) {
        if (!$this->checkPermission($permission, $context)) {
            $this->sendResponse(false, "Vous n'avez pas les droits nécessaires pour effectuer cette action", 403);
        }
    }
    
    /**
     * Récupère l'utilisateur authentifié
     */
    public function getUser() {
        return $this->user;
    }
    
    /**
     * Récupère l'instance PDO
     */
    public function getPdo() {
        return $this->pdo;
    }
    
    /**
     * Gère les erreurs d'API
     */
    public function handleError($e, $context = []) {
        logException($e, $context);
        
        $isProduction = (getenv('ENVIRONMENT') === 'production');
        $message = $isProduction ? 'Une erreur est survenue' : $e->getMessage();
        
        $httpCode = 500;
        if ($e instanceof PDOException) {
            $httpCode = 500;
        } elseif ($e->getCode() >= 400 && $e->getCode() <= 599) {
            $httpCode = $e->getCode();
        }
        
        $this->sendResponse(false, $message, $httpCode);
    }
}