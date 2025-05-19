<?php
/**
 * Module d'authentification pour la messagerie
 */

// Utiliser les mêmes paramètres de session que dans config.php
if (session_status() === PHP_SESSION_NONE) {
    // Utiliser un nom de session cohérent
    session_name('pronote_session');
    
    // Appliquer des paramètres de session sécurisés
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    session_start();
}

// Essayer d'inclure le fichier d'authentification central
$authCentralPath = __DIR__ . '/../../API/auth_central.php';
if (file_exists($authCentralPath)) {
    require_once $authCentralPath;
} else {
    // Inclure le fichier de résolution d'authentification s'il existe
    $authResolvePath = __DIR__ . '/../../API/auth_resolve.php';
    if (file_exists($authResolvePath)) {
        require_once $authResolvePath;
    }
}

// Vérifier si les fonctions d'authentification nécessaires existent, sinon les définir localement
if (!function_exists('checkAuth')) {
    /**
     * Vérifie si l'utilisateur est authentifié
     * @return array|false Données utilisateur ou false si non connecté
     */
    function checkAuth() {
        return $_SESSION['user'] ?? false;
    }
}

if (!function_exists('requireAuth')) {
    /**
     * Force l'authentification
     * @return array Données utilisateur
     */
    function requireAuth() {
        if (!isset($_SESSION['user'])) {
            // Utiliser BASE_URL si défini, sinon un chemin relatif
            $baseUrl = defined('BASE_URL') ? BASE_URL : '/~u22405372/SAE/Pronote';
            $loginUrl = $baseUrl . '/login/public/index.php';
            
            // Stocker l'URL actuelle pour redirection après connexion
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
            
            header("Location: $loginUrl");
            exit;
        }
        return $_SESSION['user'];
    }
}

// S'assurer que la fonction countUnreadNotifications est disponible
if (!function_exists('countUnreadNotifications')) {
    /**
     * Compte les notifications non lues
     * @param int $userId ID de l'utilisateur
     * @param string $userType Type d'utilisateur
     * @return int Nombre de notifications non lues
     */
    function countUnreadNotifications($userId, $userType) {
        global $pdo;
        if (!isset($pdo)) {
            return 0; // Si pas de connexion à la BDD, retourner 0
        }
        
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE user_id = ? AND user_type = ? AND is_read = 0
            ");
            $stmt->execute([$userId, $userType]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0; // En cas d'erreur, retourner 0
        }
    }
}
?>