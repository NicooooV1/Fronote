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

/**
 * Vérifie si l'utilisateur est authentifié
 * @return array|false Données utilisateur ou false si non connecté
 */
function checkAuth() {
    // Vérifier si l'utilisateur est authentifié dans la session
    if (isset($_SESSION['user'])) {
        $user = $_SESSION['user'];
        
        // Journaliser des informations de diagnostic en mode développement
        if (defined('APP_ENV') && APP_ENV === 'development') {
            error_log('Utilisateur de session trouvé : ' . json_encode($user));
        }
        
        // S'assurer que les données utilisateur ont les champs essentiels
        if (!isset($user['id'])) {
            error_log('ID utilisateur manquant dans les données de session');
            return false;
        }
        
        // Gérer la compatibilité type/profil
        if (!isset($user['type']) && isset($user['profil'])) {
            $user['type'] = $user['profil'];
        }
        
        return $user;
    }
    
    // Essayer de charger à partir de l'auth central si disponible
    if (function_exists('getCurrentUser')) {
        $user = getCurrentUser();
        if ($user) {
            // Mettre en cache dans la session
            $_SESSION['user'] = $user;
            return $user;
        }
    }
    
    return false;
}

/**
 * Vérifie l'authentification et redirige si nécessaire
 * @param string $redirect URL de redirection
 * @return array Données utilisateur
 */
function requireAuth($redirect = 'login.php') {
    $user = checkAuth();
    
    if (!$user) {
        // Déterminer l'URL de connexion absolue en fonction de la configuration
        $loginUrl = defined('LOGIN_URL') ? LOGIN_URL : $redirect;
        
        // Ajouter un paramètre d'URL de retour
        $returnUrl = urlencode($_SERVER['REQUEST_URI']);
        $delimiter = (strpos($loginUrl, '?') === false) ? '?' : '&';
        $redirectUrl = $loginUrl . $delimiter . 'return=' . $returnUrl;
        
        // Rediriger
        header('Location: ' . $redirectUrl);
        exit;
    }
    
    return $user;
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