<?php
/**
 * Bootstrap pour l'application Pronote
 */

// Charger les fichiers de configuration
if (file_exists(__DIR__ . '/config/config.php')) {
    require_once __DIR__ . '/config/config.php';
} else {
    // Définir des valeurs par défaut si la config n'est pas disponible
    if (!defined('BASE_URL')) define('BASE_URL', '/~u22405372/SAE/Pronote');
    if (!defined('APP_ENV')) define('APP_ENV', 'development');
}

// Gestion des erreurs selon l'environnement
if (defined('APP_ENV') && APP_ENV === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}

// Initialiser la connexion à la base de données
if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')) {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ];
        
        $GLOBALS['pdo'] = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        error_log("Erreur de connexion à la base de données: " . $e->getMessage());
    }
}

// Définir la classe Session pour gérer les sessions de manière sécurisée
class Session {
    /**
     * Initialise une session sécurisée
     */
    public static function start() {
        if (session_status() === PHP_SESSION_NONE) {
            // Configuration sécurisée des cookies de session
            $lifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 3600;
            $path = defined('SESSION_PATH') ? SESSION_PATH : '/';
            $domain = '';
            $secure = defined('SESSION_SECURE') ? SESSION_SECURE : false;
            $httponly = defined('SESSION_HTTPONLY') ? SESSION_HTTPONLY : true;
            
            session_set_cookie_params([
                'lifetime' => $lifetime,
                'path' => $path,
                'domain' => $domain,
                'secure' => $secure,
                'httponly' => $httponly,
                'samesite' => 'Lax'
            ]);
            
            session_start();
            
            // Régénérer l'ID de session pour empêcher la fixation de session
            if (!isset($_SESSION['_session_started'])) {
                session_regenerate_id(true);
                $_SESSION['_session_started'] = time();
                $_SESSION['_client_ip'] = $_SERVER['REMOTE_ADDR'];
                $_SESSION['_user_agent'] = $_SERVER['HTTP_USER_AGENT'];
            }
        }
    }
    
    /**
     * Vérifie si une variable existe dans la session
     * @param string $key La clé à vérifier
     * @return bool True si la clé existe
     */
    public static function has($key) {
        return isset($_SESSION[$key]);
    }
    
    /**
     * Récupère une valeur de la session
     * @param string $key La clé à récupérer
     * @param mixed $default Valeur par défaut si la clé n'existe pas
     * @return mixed La valeur ou la valeur par défaut
     */
    public static function get($key, $default = null) {
        return isset($_SESSION[$key]) ? $_SESSION[$key] : $default;
    }
    
    /**
     * Définit une valeur dans la session
     * @param string $key La clé à définir
     * @param mixed $value La valeur à stocker
     */
    public static function set($key, $value) {
        $_SESSION[$key] = $value;
    }
    
    /**
     * Supprime une valeur de la session
     * @param string $key La clé à supprimer
     */
    public static function remove($key) {
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }
    
    /**
     * Définit un message flash
     * @param string $type Type de message (success, error, warning, info)
     * @param string $message Le contenu du message
     */
    public static function setFlash($type, $message) {
        if (!isset($_SESSION['flash'])) {
            $_SESSION['flash'] = [];
        }
        if (!isset($_SESSION['flash'][$type])) {
            $_SESSION['flash'][$type] = [];
        }
        $_SESSION['flash'][$type][] = $message;
    }
    
    /**
     * Récupère les messages flash
     * @param string|null $type Type de message à récupérer (null pour tous)
     * @return array Messages flash
     */
    public static function getFlash($type = null) {
        if (!isset($_SESSION['flash'])) {
            return [];
        }
        
        if ($type === null) {
            $messages = $_SESSION['flash'];
            unset($_SESSION['flash']);
            return $messages;
        }
        
        if (!isset($_SESSION['flash'][$type])) {
            return [];
        }
        
        $messages = $_SESSION['flash'][$type];
        unset($_SESSION['flash'][$type]);
        return $messages;
    }
    
    /**
     * Vérifie si des messages flash existent
     * @param string|null $type Type de message à vérifier (null pour tous)
     * @return bool True si des messages existent
     */
    public static function hasFlash($type = null) {
        if (!isset($_SESSION['flash'])) {
            return false;
        }
        
        if ($type === null) {
            return !empty($_SESSION['flash']);
        }
        
        return isset($_SESSION['flash'][$type]) && !empty($_SESSION['flash'][$type]);
    }
    
    /**
     * Détruit la session
     */
    public static function destroy() {
        $_SESSION = [];
        
        // Détruire le cookie de session
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        
        session_destroy();
    }
}

// Démarrer la session
Session::start();
