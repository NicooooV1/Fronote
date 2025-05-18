<?php
/**
 * Classe de secours pour Session
 * Fournit les mêmes fonctionnalités que Session mais sous forme de classe statique
 */
class Session {
    /**
     * Initialise une session sécurisée
     * @param array $options Options de configuration de la session
     */
    public static function init($options = []) {
        if (session_status() === PHP_SESSION_NONE) {
            // Configuration par défaut
            $defaults = [
                'name' => 'PRONOTE_SESSION',
                'lifetime' => 7200, // 2 heures
                'path' => '/',
                'domain' => '',
                'secure' => !empty($_SERVER['HTTPS']),
                'httponly' => true,
                'use_strict_mode' => true,
                'use_cookies' => true,
                'use_only_cookies' => true,
                'sid_length' => 48,
                'sid_bits_per_character' => 6,
            ];
            
            // Fusionner avec les options passées
            $options = array_merge($defaults, $options);
            
            // Configurer la session
            session_name($options['name']);
            
            // Configurer les paramètres des cookies de session
            session_set_cookie_params([
                'lifetime' => $options['lifetime'],
                'path' => $options['path'],
                'domain' => $options['domain'],
                'secure' => $options['secure'],
                'httponly' => $options['httponly'],
            ]);
            
            // Options de session supplémentaires
            ini_set('session.use_strict_mode', $options['use_strict_mode']);
            ini_set('session.use_cookies', $options['use_cookies']);
            ini_set('session.use_only_cookies', $options['use_only_cookies']);
            ini_set('session.sid_length', $options['sid_length']);
            ini_set('session.sid_bits_per_character', $options['sid_bits_per_character']);
            
            // Démarrer la session
            session_start();
            
            // Régénérer l'ID de session pour prévenir la fixation de session
            if (!isset($_SESSION['_session_started'])) {
                session_regenerate_id(true);
                $_SESSION['_session_started'] = time();
                $_SESSION['_client_ip'] = $_SERVER['REMOTE_ADDR'];
                $_SESSION['_user_agent'] = $_SERVER['HTTP_USER_AGENT'];
            } else {
                // Vérification de sécurité supplémentaire
                self::validate_session_integrity();
            }
            
            // Mettre à jour le temps d'activité si une session utilisateur existe
            if (isset($_SESSION['user'])) {
                $_SESSION['auth_time'] = time();
            }
        }
    }

    /**
     * Vérifie l'intégrité de la session
     * @return bool True si la session est valide
     */
    public static function validate_session_integrity() {
        // Vérifier l'adresse IP et l'agent utilisateur pour détecter un vol potentiel de session
        if (isset($_SESSION['_client_ip']) && isset($_SESSION['_user_agent'])) {
            $ip_check = $_SESSION['_client_ip'] === $_SERVER['REMOTE_ADDR'];
            $user_agent_check = $_SESSION['_user_agent'] === $_SERVER['HTTP_USER_AGENT'];
            
            // Si la validation échoue, fermer la session
            if (!$ip_check || !$user_agent_check) {
                self::destroy();
                return false;
            }
        }
        
        // Vérifier si la session a expiré
        if (isset($_SESSION['_session_started'])) {
            $session_lifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 7200; // 2 heures par défaut
            $is_expired = (time() - $_SESSION['_session_started']) > $session_lifetime;
            
            if ($is_expired) {
                self::destroy();
                return false;
            }
        }
        
        return true;
    }

    /**
     * Détruit la session en cours
     */
    public static function destroy() {
        $_SESSION = [];
        
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

    /**
     * Régénère l'ID de session
     */
    public static function regenerate() {
        session_regenerate_id(true);
        $_SESSION['_session_started'] = time();
    }

    /**
     * Définit une variable de session
     * @param string $key Clé de la variable
     * @param mixed $value Valeur à stocker
     */
    public static function set($key, $value) {
        $_SESSION[$key] = $value;
    }

    /**
     * Récupère une variable de session
     * @param string $key Clé de la variable
     * @param mixed $default Valeur par défaut si la clé n'existe pas
     * @return mixed Valeur de la variable ou valeur par défaut
     */
    public static function get($key, $default = null) {
        return isset($_SESSION[$key]) ? $_SESSION[$key] : $default;
    }

    /**
     * Vérifie si une variable de session existe
     * @param string $key Clé de la variable
     * @return bool True si la variable existe
     */
    public static function has($key) {
        return isset($_SESSION[$key]);
    }

    /**
     * Supprime une variable de session
     * @param string $key Clé de la variable
     */
    public static function remove($key) {
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * Définit un message flash
     * @param string $type Type de message (success, error, info, warning)
     * @param string $message Contenu du message
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
     * Récupère et supprime les messages flash d'un type donné
     * @param string $type Type de message (success, error, info, warning)
     * @return array Messages flash
     */
    public static function getFlash($type = null) {
        if (!isset($_SESSION['flash'])) {
            return [];
        }
        
        if ($type === null) {
            $flash = $_SESSION['flash'];
            unset($_SESSION['flash']);
            return $flash;
        }
        
        if (!isset($_SESSION['flash'][$type])) {
            return [];
        }
        
        $flash = $_SESSION['flash'][$type];
        unset($_SESSION['flash'][$type]);
        
        return $flash;
    }

    /**
     * Vérifie si des messages flash existent
     * @param string $type Type de message (facultatif)
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
}

// Initialiser la session si la classe est chargée directement
if (session_status() === PHP_SESSION_NONE) {
    Session::init();
}
