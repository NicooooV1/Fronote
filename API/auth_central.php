<?php
/**
 * Système d'authentification centralisé pour Pronote
 * Ce fichier fournit toutes les fonctions d'authentification pour les différents modules
 * IMPORTANT: Tous les modules doivent inclure ce fichier pour la gestion de l'authentification
 */

// Charger les dépendances nécessaires
require_once __DIR__ . '/bootstrap.php';

// Constantes de rôles utilisateurs
if (!defined('USER_TYPE_ADMIN')) define('USER_TYPE_ADMIN', 'administrateur');
if (!defined('USER_TYPE_TEACHER')) define('USER_TYPE_TEACHER', 'professeur');
if (!defined('USER_TYPE_STUDENT')) define('USER_TYPE_STUDENT', 'eleve');
if (!defined('USER_TYPE_PARENT')) define('USER_TYPE_PARENT', 'parent');
if (!defined('USER_TYPE_STAFF')) define('USER_TYPE_STAFF', 'vie_scolaire');

// Vérifier si les fonctions existent déjà pour éviter les redéclarations
if (!function_exists('isLoggedIn')) {
    /**
     * Vérifie si l'utilisateur est connecté
     * @return bool True si l'utilisateur est connecté
     */
    function isLoggedIn() {
        return isset($_SESSION['user']) && !empty($_SESSION['user']);
    }
}

if (!function_exists('isSessionExpired')) {
    /**
     * Vérifier si la session a expiré
     * @param int $timeout Timeout en secondes (par défaut 7200 = 2 heures)
     * @return bool True si la session a expiré
     */
    function isSessionExpired($timeout = SESSION_LIFETIME ?? 7200) {
        if (!isset($_SESSION['auth_time'])) {
            return true;
        }
        
        return (time() - $_SESSION['auth_time']) > $timeout;
    }
}

if (!function_exists('refreshAuthTime')) {
    /**
     * Rafraîchir le timestamp d'authentification
     */
    function refreshAuthTime() {
        $_SESSION['auth_time'] = time();
    }
}

if (!function_exists('getCurrentUser')) {
    /**
     * Récupère l'utilisateur connecté
     * @return array|null Données de l'utilisateur ou null
     */
    function getCurrentUser() {
        return $_SESSION['user'] ?? null;
    }
}

if (!function_exists('getUserRole')) {
    /**
     * Récupère le rôle de l'utilisateur connecté
     * @return string|null Rôle de l'utilisateur ou null
     */
    function getUserRole() {
        $user = getCurrentUser();
        return $user ? $user['profil'] : null;
    }
}

if (!function_exists('isAdmin')) {
    /**
     * Vérifie si l'utilisateur est administrateur
     * @return bool True si l'utilisateur est administrateur
     */
    function isAdmin() {
        return getUserRole() === USER_TYPE_ADMIN;
    }
}

if (!function_exists('isTeacher')) {
    /**
     * Vérifie si l'utilisateur est professeur
     * @return bool True si l'utilisateur est professeur
     */
    function isTeacher() {
        return getUserRole() === USER_TYPE_TEACHER;
    }
}

if (!function_exists('isStudent')) {
    /**
     * Vérifie si l'utilisateur est élève
     * @return bool True si l'utilisateur est élève
     */
    function isStudent() {
        return getUserRole() === USER_TYPE_STUDENT;
    }
}

if (!function_exists('isParent')) {
    /**
     * Vérifie si l'utilisateur est parent
     * @return bool True si l'utilisateur est parent
     */
    function isParent() {
        return getUserRole() === USER_TYPE_PARENT;
    }
}

if (!function_exists('isVieScolaire')) {
    /**
     * Vérifie si l'utilisateur est membre de la vie scolaire
     * @return bool True si l'utilisateur est membre de la vie scolaire
     */
    function isVieScolaire() {
        return getUserRole() === USER_TYPE_STAFF;
    }
}

if (!function_exists('getUserFullName')) {
    /**
     * Récupère le nom complet de l'utilisateur
     * @return string Nom complet de l'utilisateur ou chaîne vide
     */
    function getUserFullName() {
        $user = getCurrentUser();
        if ($user) {
            return $user['prenom'] . ' ' . $user['nom'];
        }
        return '';
    }
}

if (!function_exists('canManageNotes')) {
    /**
     * Vérifie si l'utilisateur peut gérer les notes
     * @return bool True si l'utilisateur peut gérer les notes
     */
    function canManageNotes() {
        $role = getUserRole();
        return in_array($role, [USER_TYPE_ADMIN, USER_TYPE_TEACHER, USER_TYPE_STAFF]);
    }
}

if (!function_exists('canManageAbsences')) {
    /**
     * Vérifie si l'utilisateur peut gérer les absences
     * @return bool True si l'utilisateur peut gérer les absences
     */
    function canManageAbsences() {
        $role = getUserRole();
        return in_array($role, [USER_TYPE_ADMIN, USER_TYPE_TEACHER, USER_TYPE_STAFF]);
    }
}

if (!function_exists('canManageDevoirs')) {
    /**
     * Vérifie si l'utilisateur peut gérer les devoirs/cahier de textes
     * @return bool True si l'utilisateur peut gérer les devoirs
     */
    function canManageDevoirs() {
        $role = getUserRole();
        return in_array($role, [USER_TYPE_ADMIN, USER_TYPE_TEACHER, USER_TYPE_STAFF]);
    }
}

if (!function_exists('canSendAnnouncement')) {
    /**
     * Vérifie si l'utilisateur peut envoyer des annonces
     * @return bool True si l'utilisateur peut envoyer des annonces
     */
    function canSendAnnouncement() {
        $role = getUserRole();
        return in_array($role, [USER_TYPE_ADMIN, USER_TYPE_STAFF]);
    }
}

if (!function_exists('canManageEvents')) {
    /**
     * Vérifie si l'utilisateur peut gérer les événements
     * @return bool True si l'utilisateur peut gérer les événements
     */
    function canManageEvents() {
        $role = getUserRole();
        return in_array($role, [USER_TYPE_ADMIN, USER_TYPE_TEACHER, USER_TYPE_STAFF]);
    }
}

if (!function_exists('requireLogin')) {
    /**
     * Force l'authentification de l'utilisateur
     * @param bool $redirect Rediriger vers la page de login si non connecté
     * @return array|false Données de l'utilisateur connecté ou false
     */
    function requireLogin($redirect = true) {
        if (!isLoggedIn()) {
            if ($redirect) {
                // Définir les URLs de redirection si nécessaire
                if (!defined('BASE_URL')) {
                    define('BASE_URL', '/~u22405372/SAE/Pronote');
                }
                
                if (!defined('LOGIN_URL')) {
                    define('LOGIN_URL', BASE_URL . '/login/public/index.php');
                }
                
                // Stocker l'URL actuelle pour y revenir après connexion
                $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
                
                header("Location: " . LOGIN_URL);
                exit;
            }
            return false;
        }
        
        // Vérifier si la session a expiré
        if (isSessionExpired()) {
            if ($redirect) {
                // Stocker l'URL actuelle pour y revenir après la reconnexion
                $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
                $_SESSION['flash_message'] = 'Votre session a expiré. Veuillez vous reconnecter.';
                
                if (!defined('LOGOUT_URL')) {
                    define('LOGOUT_URL', BASE_URL . '/login/public/logout.php');
                }
                
                header("Location: " . LOGOUT_URL);
                exit;
            }
            return false;
        }
        
        // Vérifications de sécurité supplémentaires
        if (isset($_SESSION['_client_ip']) && isset($_SESSION['_user_agent'])) {
            $ip_match = $_SESSION['_client_ip'] === $_SERVER['REMOTE_ADDR'];
            $ua_match = $_SESSION['_user_agent'] === $_SERVER['HTTP_USER_AGENT'];
            
            // Si les vérifications échouent, déconnexion par mesure de sécurité
            if (!$ip_match || !$ua_match) {
                $_SESSION['flash_message'] = 'Votre session a été invalidée pour des raisons de sécurité.';
                session_destroy();
                
                if ($redirect) {
                    if (!defined('LOGIN_URL')) {
                        define('LOGIN_URL', BASE_URL . '/login/public/index.php');
                    }
                    header("Location: " . LOGIN_URL);
                    exit;
                }
                return false;
            }
        }
        
        // Rafraîchir le timestamp d'authentification
        refreshAuthTime();
        
        return getCurrentUser();
    }
}

if (!function_exists('hasRole')) {
    /**
     * Vérifie si l'utilisateur a un rôle spécifique
     * @param string|array $roles Rôle(s) à vérifier
     * @return bool True si l'utilisateur a un des rôles spécifiés
     */
    function hasRole($roles) {
        $userRole = getUserRole();
        if (!$userRole) return false;
        
        if (is_array($roles)) {
            return in_array($userRole, $roles);
        } else {
            return $userRole === $roles;
        }
    }
}

if (!function_exists('hasPermission')) {
    /**
     * Vérifie si l'utilisateur a la permission pour une action spécifique
     * @param string $permission Nom de la permission
     * @return bool True si l'utilisateur a la permission
     */
    function hasPermission($permission) {
        switch ($permission) {
            case 'manage_notes':
                return canManageNotes();
            case 'manage_absences':
                return canManageAbsences();
            case 'manage_devoirs':
                return canManageDevoirs();
            case 'send_announcement':
                return canSendAnnouncement();
            case 'manage_events':
                return canManageEvents();
            case 'admin_access':
                return isAdmin();
            default:
                return false;
        }
    }
}

if (!function_exists('login')) {
    /**
     * Connecte un utilisateur
     * @param string $profil Type de profil (eleve, parent, professeur, etc.)
     * @param string $identifiant Identifiant de l'utilisateur
     * @param string $password Mot de passe en clair
     * @return bool True si l'authentification réussit
     */
    function login($profil, $identifiant, $password) {
        global $pdo;
        
        // Nettoyer les entrées
        $profil = filter_var($profil, FILTER_SANITIZE_STRING);
        $identifiant = filter_var($identifiant, FILTER_SANITIZE_STRING);
        
        // Définir la table en fonction du profil
        $tableMap = [
            'eleve'        => 'eleves',
            'parent'       => 'parents',
            'professeur'   => 'professeurs',
            'vie_scolaire' => 'vie_scolaire',
            'administrateur' => 'administrateurs',
        ];
        
        if (!isset($tableMap[$profil])) {
            logAuthFailure($identifiant, $profil, 'Profil invalide');
            return false;
        }
        
        $table = $tableMap[$profil];
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE identifiant = ? LIMIT 1");
            $stmt->execute([$identifiant]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['mot_de_passe'])) {
                // Stocker les informations utilisateur en session
                $_SESSION['user'] = [
                    'id'      => $user['id'],
                    'profil'  => $profil,
                    'nom'     => $user['nom'],
                    'prenom'  => $user['prenom'],
                    'identifiant' => $user['identifiant'],
                    'table'   => $table,
                    'classe'  => $user['classe'] ?? '',
                ];
                
                // Stocker le moment de l'authentification
                $_SESSION['auth_time'] = time();
                
                // Régénérer l'ID de session pour éviter la fixation de session
                session_regenerate_id(true);
                $_SESSION['last_regenerate'] = time();
                
                // Stocker l'IP et l'agent utilisateur pour la vérification
                $_SESSION['_client_ip'] = $_SERVER['REMOTE_ADDR'];
                $_SESSION['_user_agent'] = $_SERVER['HTTP_USER_AGENT'];
                
                // Journaliser la connexion réussie
                logAuthSuccess($user['identifiant'], $profil);
                
                return true;
            } else {
                // Journaliser l'échec de connexion
                logAuthFailure($identifiant, $profil, $user ? 'Mot de passe incorrect' : 'Utilisateur non trouvé');
            }
        } catch (PDOException $e) {
            // Journaliser l'erreur
            error_log("Erreur d'authentification: " . $e->getMessage());
            logAuthFailure($identifiant, $profil, 'Erreur de base de données');
        }
        
        return false;
    }
}

if (!function_exists('logAuthSuccess')) {
    /**
     * Journalise une connexion réussie
     * @param string $username Nom d'utilisateur
     * @param string $role Rôle de l'utilisateur
     */
    function logAuthSuccess($username, $role) {
        $logFile = __DIR__ . '/logs/auth_success.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'];
        $ua = $_SERVER['HTTP_USER_AGENT'];
        
        $logEntry = "[$timestamp] SUCCESS: $username ($role) from IP: $ip User-Agent: $ua" . PHP_EOL;
        
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}

if (!function_exists('logAuthFailure')) {
    /**
     * Journalise un échec de connexion
     * @param string $username Nom d'utilisateur
     * @param string $role Rôle de l'utilisateur
     * @param string $reason Raison de l'échec
     */
    function logAuthFailure($username, $role, $reason) {
        $logFile = __DIR__ . '/logs/auth_failure.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'];
        $ua = $_SERVER['HTTP_USER_AGENT'];
        
        $logEntry = "[$timestamp] FAILURE: $username ($role) from IP: $ip Reason: $reason User-Agent: $ua" . PHP_EOL;
        
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}

if (!function_exists('logout')) {
    /**
     * Déconnecte l'utilisateur
     */
    function logout() {
        if (isset($_SESSION['user'])) {
            // Journaliser la déconnexion
            $user = $_SESSION['user'];
            $logFile = __DIR__ . '/logs/auth_logout.log';
            $timestamp = date('Y-m-d H:i:s');
            $ip = $_SERVER['REMOTE_ADDR'];
            
            $logEntry = "[$timestamp] LOGOUT: {$user['identifiant']} ({$user['profil']}) from IP: $ip" . PHP_EOL;
            file_put_contents($logFile, $logEntry, FILE_APPEND);
        }
        
        // Détruire la session
        $_SESSION = array();
        
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

// Créer un bridge pour assurer la compatibilité avec le code existant
require_once __DIR__ . '/auth_bridge.php';
