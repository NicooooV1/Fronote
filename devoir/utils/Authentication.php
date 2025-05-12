<?php
/**
 * Classe pour la gestion de l'authentification des utilisateurs
 */
class Authentication {
    private $db;
    
    /**
     * Constructeur
     */
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Authentifie un utilisateur
     * @param string $username Nom d'utilisateur
     * @param string $password Mot de passe
     * @param bool $remember Se souvenir de l'utilisateur
     * @return array|false Données de l'utilisateur ou false en cas d'échec
     */
    public function login($username, $password, $remember = false) {
        // Récupérer l'utilisateur
        $sql = "SELECT id, username, password, email, first_name, last_name, user_type 
                FROM users 
                WHERE username = :username";
        
        $user = $this->db->fetch($sql, [':username' => $username]);
        
        // Vérifier si l'utilisateur existe
        if (!$user) {
            return false;
        }
        
        // Vérifier le mot de passe
        if (!password_verify($password, $user['password'])) {
            // Enregistrer la tentative échouée
            $this->logLoginAttempt($user['id'], false);
            return false;
        }
        
        // Mettre à jour la date de dernière connexion
        $this->updateLastLogin($user['id']);
        
        // Enregistrer la tentative réussie
        $this->logLoginAttempt($user['id'], true);
        
        // Gérer "Se souvenir de moi"
        if ($remember) {
            $this->createRememberToken($user['id']);
        }
        
        return $user;
    }
    
    /**
     * Vérifie si un utilisateur est connecté via le cookie "Se souvenir de moi"
     * @return array|false Données de l'utilisateur ou false en cas d'échec
     */
    public function checkRememberToken() {
        if (!isset($_COOKIE['remember_token'])) {
            return false;
        }
        
        list($userId, $token) = explode(':', $_COOKIE['remember_token']);
        
        $sql = "SELECT token, expiry 
                FROM remember_tokens 
                WHERE user_id = :user_id";
        
        $tokenData = $this->db->fetch($sql, [':user_id' => $userId]);
        
        if (!$tokenData) {
            $this->clearRememberToken();
            return false;
        }
        
        // Vérifier si le token est expiré
        if (strtotime($tokenData['expiry']) < time()) {
            $this->deleteRememberToken($userId);
            $this->clearRememberToken();
            return false;
        }
        
        // Vérifier si le token correspond
        if (!hash_equals($tokenData['token'], $token)) {
            $this->clearRememberToken();
            return false;
        }
        
        // Récupérer les informations de l'utilisateur
        $sql = "SELECT id, username, email, first_name, last_name, user_type 
                FROM users 
                WHERE id = :id";
        
        $user = $this->db->fetch($sql, [':id' => $userId]);
        
        if (!$user) {
            $this->clearRememberToken();
            return false;
        }
        
        // Mettre à jour la date de dernière connexion
        $this->updateLastLogin($user['id']);
        
        return $user;
    }
    
    /**
     * Créer un token "Se souvenir de moi" pour un utilisateur
     * @param int $userId ID de l'utilisateur
     * @return bool Succès ou échec
     */
    public function createRememberToken($userId) {
        // Générer un token sécurisé
        $token = bin2hex(random_bytes(32));
        
        // Définir la date d'expiration (30 jours)
        $expiry = date('Y-m-d H:i:s', time() + 30 * 24 * 60 * 60);
        
        // Supprimer les tokens existants pour cet utilisateur
        $this->deleteRememberToken($userId);
        
        // Insérer le nouveau token
        $this->db->insert('remember_tokens', [
            'user_id' => $userId,
            'token' => $token,
            'expiry' => $expiry
        ]);
        
        // Créer le cookie
        $cookie = $userId . ':' . $token;
        setcookie('remember_token', $cookie, time() + 30 * 24 * 60 * 60, '/', '', false, true);
        
        return true;
    }
    
    /**
     * Supprimer les tokens "Se souvenir de moi" d'un utilisateur
     * @param int $userId ID de l'utilisateur
     * @return bool Succès ou échec
     */
    public function deleteRememberToken($userId) {
        return $this->db->delete('remember_tokens', 'user_id = :user_id', [':user_id' => $userId]);
    }
    
    /**
     * Supprimer le cookie "Se souvenir de moi"
     * @return void
     */
    public function clearRememberToken() {
        setcookie('remember_token', '', time() - 3600, '/', '', false, true);
    }
    
    /**
     * Déconnecte l'utilisateur actuel
     * @return void
     */
    public function logout() {
        // Supprimer le token "Se souvenir de moi"
        if (isset($_SESSION['user_id'])) {
            $this->deleteRememberToken($_SESSION['user_id']);
        }
        
        $this->clearRememberToken();
        
        // Supprimer les variables de session
        session_unset();
        
        // Détruire la session
        session_destroy();
    }
    
    /**
     * Mettre à jour la date de dernière connexion d'un utilisateur
     * @param int $userId ID de l'utilisateur
     * @return bool Succès ou échec
     */
    private function updateLastLogin($userId) {
        return $this->db->update('users', 
                                ['last_login' => date('Y-m-d H:i:s')], 
                                'id = :id', 
                                [':id' => $userId]);
    }
    
    /**
     * Enregistrer une tentative de connexion
     * @param int $userId ID de l'utilisateur
     * @param bool $success Succès ou échec
     * @return bool Succès ou échec
     */
    private function logLoginAttempt($userId, $success) {
        return $this->db->insert('login_attempts', [
            'user_id' => $userId,
            'success' => $success ? 1 : 0,
            'ip_address' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Vérifie si un compte est verrouillé en raison de trop nombreuses tentatives échouées
     * @param string $username Nom d'utilisateur
     * @return bool Vrai si le compte est verrouillé
     */
    public function isAccountLocked($username) {
        // Récupérer l'ID de l'utilisateur
        $sql = "SELECT id FROM users WHERE username = :username";
        $user = $this->db->fetch($sql, [':username' => $username]);
        
        if (!$user) {
            return false;
        }
        
        // Vérifier les tentatives échouées dans les dernières 15 minutes
        $sql = "SELECT COUNT(*) AS attempts 
                FROM login_attempts 
                WHERE user_id = :user_id 
                AND success = 0 
                AND timestamp > DATE_SUB(NOW(), INTERVAL 15 MINUTE)";
        
        $result = $this->db->fetch($sql, [':user_id' => $user['id']]);
        
        // Verrouiller après 5 tentatives échouées
        return $result['attempts'] >= 5;
    }
    
    /**
     * Crypte un mot de passe
     * @param string $password Mot de passe en clair
     * @return string Mot de passe crypté
     */
    public function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    
    /**
     * Vérifie si un mot de passe correspond au hash
     * @param string $password Mot de passe en clair
     * @param string $hash Hash du mot de passe
     * @return bool Vrai si le mot de passe correspond au hash
     */
    public function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    /**
     * Vérifie si un mot de passe doit être rehashé (algorithme obsolète)
     * @param string $hash Hash du mot de passe
     * @return bool Vrai si le hash doit être mis à jour
     */
    public function needsRehash($hash) {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }
    
    /**
     * Vérifie si un nom d'utilisateur existe déjà
     * @param string $username Nom d'utilisateur
     * @return bool Vrai si le nom d'utilisateur existe
     */
    public function usernameExists($username) {
        $sql = "SELECT id FROM users WHERE username = :username";
        $result = $this->db->fetch($sql, [':username' => $username]);
        return $result !== false;
    }
    
    /**
     * Vérifie si un email existe déjà
     * @param string $email Email
     * @return bool Vrai si l'email existe
     */
    public function emailExists($email) {
        $sql = "SELECT id FROM users WHERE email = :email";
        $result = $this->db->fetch($sql, [':email' => $email]);
        return $result !== false;
    }
    
    /**
     * Génère et enregistre un token de réinitialisation de mot de passe
     * @param string $email Email de l'utilisateur
     * @return string|false Token généré ou false en cas d'échec
     */
    public function createPasswordResetToken($email) {
        // Vérifier si l'email existe
        $sql = "SELECT id FROM users WHERE email = :email";
        $user = $this->db->fetch($sql, [':email' => $email]);
        
        if (!$user) {
            return false;
        }
        
        // Générer un token sécurisé
        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', time() + 60 * 60); // 1 heure
        
        // Supprimer les tokens existants pour cet utilisateur
        $this->db->delete('password_reset_tokens', 'user_id = :user_id', [':user_id' => $user['id']]);
        
        // Insérer le nouveau token
        $success = $this->db->insert('password_reset_tokens', [
            'user_id' => $user['id'],
            'token' => $token,
            'expiry' => $expiry
        ]);
        
        return $success ? $token : false;
    }
    
    /**
     * Vérifie un token de réinitialisation de mot de passe
     * @param string $token Token à vérifier
     * @return int|false ID de l'utilisateur ou false en cas d'échec
     */
    public function verifyPasswordResetToken($token) {
        $sql = "SELECT user_id, expiry FROM password_reset_tokens WHERE token = :token";
        $tokenData = $this->db->fetch($sql, [':token' => $token]);
        
        if (!$tokenData) {
            return false;
        }
        
        // Vérifier si le token est expiré
        if (strtotime($tokenData['expiry']) < time()) {
            // Supprimer le token expiré
            $this->db->delete('password_reset_tokens', 'token = :token', [':token' => $token]);
            return false;
        }
        
        return $tokenData['user_id'];
    }
    
    /**
     * Réinitialise le mot de passe d'un utilisateur
     * @param int $userId ID de l'utilisateur
     * @param string $newPassword Nouveau mot de passe
     * @return bool Succès ou échec
     */
    public function resetPassword($userId, $newPassword) {
        // Hasher le nouveau mot de passe
        $hashedPassword = $this->hashPassword($newPassword);
        
        // Mettre à jour le mot de passe
        $result = $this->db->update('users', 
                                   ['password' => $hashedPassword], 
                                   'id = :id', 
                                   [':id' => $userId]);
        
        if ($result) {
            // Supprimer le token de réinitialisation
            $this->db->delete('password_reset_tokens', 'user_id = :user_id', [':user_id' => $userId]);
            return true;
        }
        
        return false;
    }
}