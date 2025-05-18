<?php
/**
 * Gestionnaire de session sécurisée
 */
class Session
{
    /**
     * Initialise la session avec des paramètres sécurisés
     * 
     * @return bool True si la session a été correctement démarrée
     */
    public static function init()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // Configuration des paramètres de session
            session_name(SESSION_NAME);
            
            session_set_cookie_params([
                'lifetime' => SESSION_LIFETIME,
                'path' => SESSION_PATH,
                'secure' => SESSION_SECURE,
                'httponly' => SESSION_HTTPONLY,
                'samesite' => 'Lax' // Protection contre CSRF
            ]);
            
            // Démarrer la session
            if (!session_start()) {
                return false;
            }
            
            // Régénérer l'ID de session périodiquement pour éviter la fixation de session
            if (!isset($_SESSION['last_regeneration']) || 
                $_SESSION['last_regeneration'] < (time() - 1800)) {
                self::regenerate();
                $_SESSION['last_regeneration'] = time();
            }
        }
        
        return true;
    }
    
    /**
     * Régénère l'ID de session
     * 
     * @return bool Succès ou échec
     */
    public static function regenerate()
    {
        return session_regenerate_id(true);
    }
    
    /**
     * Défini une valeur dans la session
     * 
     * @param string $key   Clé
     * @param mixed  $value Valeur
     */
    public static function set($key, $value)
    {
        $_SESSION[$key] = $value;
    }
    
    /**
     * Récupère une valeur depuis la session
     * 
     * @param string $key     Clé
     * @param mixed  $default Valeur par défaut si la clé n'existe pas
     * @return mixed La valeur ou la valeur par défaut
     */
    public static function get($key, $default = null)
    {
        return isset($_SESSION[$key]) ? $_SESSION[$key] : $default;
    }
    
    /**
     * Vérifie si une clé existe dans la session
     * 
     * @param string $key Clé à vérifier
     * @return bool True si la clé existe
     */
    public static function has($key)
    {
        return isset($_SESSION[$key]);
    }
    
    /**
     * Supprime une valeur de la session
     * 
     * @param string $key Clé à supprimer
     */
    public static function remove($key)
    {
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }
    
    /**
     * Défini un message flash qui sera disponible uniquement pour la requête suivante
     * 
     * @param string $type    Type de message (success, error, warning, info)
     * @param string $message Contenu du message
     */
    public static function setFlash($type, $message)
    {
        $_SESSION['flash'][$type] = $message;
    }
    
    /**
     * Récupère les messages flash et les supprime
     * 
     * @return array Messages flash
     */
    public static function getFlash()
    {
        $flash = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $flash;
    }
    
    /**
     * Détruit la session
     */
    public static function destroy()
    {
        // Vider le tableau de session
        $_SESSION = [];
        
        // Détruire le cookie de session
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        // Détruire la session
        session_destroy();
    }
}
