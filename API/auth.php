<?php
/**
 * Système d'authentification centralisé pour Pronote
 * Ce fichier fournit toutes les fonctions d'authentification et de gestion des sessions
 */

// Vérifier si la session est démarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Charger les dépendances nécessaires si elles existent
$bootstrapPath = __DIR__ . '/bootstrap.php';
if (file_exists($bootstrapPath)) {
    require_once $bootstrapPath;
}

// Définir les constantes de rôles si nécessaire
if (!defined('USER_TYPE_ADMIN')) define('USER_TYPE_ADMIN', 'administrateur');
if (!defined('USER_TYPE_TEACHER')) define('USER_TYPE_TEACHER', 'professeur');
if (!defined('USER_TYPE_STUDENT')) define('USER_TYPE_STUDENT', 'eleve');
if (!defined('USER_TYPE_PARENT')) define('USER_TYPE_PARENT', 'parent');
if (!defined('USER_TYPE_STAFF')) define('USER_TYPE_STAFF', 'vie_scolaire');

// Vérifier si auth_central.php est déjà chargé avant de définir les fonctions d'authentification
$authCentralPath = __DIR__ . '/auth_central.php';
$loadFunctions = true;

if (file_exists($authCentralPath)) {
    // Charger auth_central.php qui contient déjà les fonctions d'authentification
    require_once $authCentralPath;
    $loadFunctions = false;
}

// Ne charger les fonctions que si auth_central.php n'a pas été chargé
if ($loadFunctions) {
    // Vérifier si la classe Session existe, sinon utiliser les fonctions directement
    if (!class_exists('Session')) {
        /**
         * Vérifie si l'utilisateur est connecté
         * @return bool True si l'utilisateur est connecté
         */
        if (!function_exists('isLoggedIn')) {
            function isLoggedIn() {
                return isset($_SESSION['user']) && !empty($_SESSION['user']);
            }
        }
        
        /**
         * Vérifier si la session a expiré
         * @param int $timeout Timeout en secondes (par défaut 7200 = 2 heures)
         * @return bool True si la session a expiré
         */
        if (!function_exists('isSessionExpired')) {
            function isSessionExpired($timeout = 7200) {
                if (!isset($_SESSION['auth_time'])) {
                    return true;
                }
                
                return time() - $_SESSION['auth_time'] > $timeout;
            }
        }
        
        /**
         * Rafraîchir le timestamp d'authentification
         */
        if (!function_exists('refreshAuthTime')) {
            function refreshAuthTime() {
                $_SESSION['auth_time'] = time();
            }
        }
        
        /**
         * Récupère l'utilisateur connecté
         * @return array|null Données de l'utilisateur ou null
         */
        if (!function_exists('getCurrentUser')) {
            function getCurrentUser() {
                return $_SESSION['user'] ?? null;
            }
        }
        
        /**
         * Récupère le rôle de l'utilisateur connecté
         * @return string|null Rôle de l'utilisateur ou null
         */
        if (!function_exists('getUserRole')) {
            function getUserRole() {
                $user = getCurrentUser();
                return $user ? $user['profil'] : null;
            }
        }
        
        /**
         * Vérifie si l'utilisateur est administrateur
         * @return bool True si l'utilisateur est administrateur
         */
        if (!function_exists('isAdmin')) {
            function isAdmin() {
                return getUserRole() === USER_TYPE_ADMIN;
            }
        }
        
        /**
         * Vérifie si l'utilisateur est professeur
         * @return bool True si l'utilisateur est professeur
         */
        if (!function_exists('isTeacher')) {
            function isTeacher() {
                return getUserRole() === USER_TYPE_TEACHER;
            }
        }
        
        /**
         * Vérifie si l'utilisateur est élève
         * @return bool True si l'utilisateur est élève
         */
        if (!function_exists('isStudent')) {
            function isStudent() {
                return getUserRole() === USER_TYPE_STUDENT;
            }
        }
        
        /**
         * Vérifie si l'utilisateur est parent
         * @return bool True si l'utilisateur est parent
         */
        if (!function_exists('isParent')) {
            function isParent() {
                return getUserRole() === USER_TYPE_PARENT;
            }
        }
        
        /**
         * Vérifie si l'utilisateur est membre de la vie scolaire
         * @return bool True si l'utilisateur est membre de la vie scolaire
         */
        if (!function_exists('isVieScolaire')) {
            function isVieScolaire() {
                return getUserRole() === USER_TYPE_STAFF;
            }
        }
        
        /**
         * Récupère le nom complet de l'utilisateur
         * @return string Nom complet de l'utilisateur ou chaîne vide
         */
        if (!function_exists('getUserFullName')) {
            function getUserFullName() {
                $user = getCurrentUser();
                if ($user) {
                    return $user['prenom'] . ' ' . $user['nom'];
                }
                return '';
            }
        }

        /**
         * Vérifie si l'utilisateur peut gérer les notes
         * @return bool True si l'utilisateur peut gérer les notes
         */
        if (!function_exists('canManageNotes')) {
            function canManageNotes() {
                $role = getUserRole();
                return in_array($role, [USER_TYPE_ADMIN, USER_TYPE_TEACHER, USER_TYPE_STAFF]);
            }
        }
        
        /**
         * Vérifie si l'utilisateur peut gérer les absences
         * @return bool True si l'utilisateur peut gérer les absences
         */
        if (!function_exists('canManageAbsences')) {
            function canManageAbsences() {
                $role = getUserRole();
                return in_array($role, [USER_TYPE_ADMIN, USER_TYPE_TEACHER, USER_TYPE_STAFF]);
            }
        }
        
        /**
         * Vérifie si l'utilisateur peut gérer les devoirs/cahier de textes
         * @return bool True si l'utilisateur peut gérer les devoirs
         */
        if (!function_exists('canManageDevoirs')) {
            function canManageDevoirs() {
                $role = getUserRole();
                return in_array($role, [USER_TYPE_ADMIN, USER_TYPE_TEACHER, USER_TYPE_STAFF]);
            }
        }
        
        /**
         * Force l'authentification de l'utilisateur
         * @return array Données de l'utilisateur connecté
         */
        if (!function_exists('requireLogin')) {
            function requireLogin() {
                if (!isLoggedIn()) {
                    // Définir les URLs de redirection si nécessaire
                    if (!defined('BASE_URL')) {
                        define('BASE_URL', '/~u22405372/SAE/Pronote');
                    }
                    
                    if (!defined('LOGIN_URL')) {
                        define('LOGIN_URL', BASE_URL . '/login/public/index.php');
                    }
                    
                    // Stocker l'URL actuelle pour y revenir après connexion
                    Session::set('redirect_after_login', $_SERVER['REQUEST_URI']);
                    
                    header("Location: " . LOGIN_URL);
                    exit;
                }
                
                // Vérifier si la session a expiré
                if (isSessionExpired()) {
                    // Stocker l'URL actuelle pour y revenir après la reconnexion
                    Session::set('redirect_after_login', $_SERVER['REQUEST_URI']);
                    Session::setFlash('warning', 'Votre session a expiré. Veuillez vous reconnecter.');
                    
                    if (!defined('LOGOUT_URL')) {
                        define('LOGOUT_URL', BASE_URL . '/login/public/logout.php');
                    }
                    
                    header("Location: " . LOGOUT_URL);
                    exit;
                }
                
                // Régénérer l'ID de session périodiquement pour éviter les attaques de fixation
                if (!Session::has('last_regenerate') || (time() - Session::get('last_regenerate')) > 1800) { // 30 minutes
                    session_regenerate_id(true);
                    Session::set('last_regenerate', time());
                }
                
                // Vérifications de sécurité supplémentaires
                if (Session::has('_client_ip') && Session::has('_user_agent')) {
                    $ip_match = Session::get('_client_ip') === $_SERVER['REMOTE_ADDR'];
                    $ua_match = Session::get('_user_agent') === $_SERVER['HTTP_USER_AGENT'];
                    
                    // Si les vérifications échouent, déconnexion par mesure de sécurité
                    if (!$ip_match || !$ua_match) {
                        Session::setFlash('error', 'Votre session a été invalidée pour des raisons de sécurité.');
                        Session::destroy();
                        header("Location: " . LOGIN_URL);
                        exit;
                    }
                }
                
                // Rafraîchir le timestamp d'authentification
                refreshAuthTime();
                
                return getCurrentUser();
            }
        }

        /**
         * Connecte un utilisateur
         * @param string $profil Type de profil (eleve, parent, professeur, etc.)
         * @param string $identifiant Identifiant de l'utilisateur
         * @param string $password Mot de passe en clair
         * @return bool True si l'authentification réussit
         */
        if (!function_exists('login')) {
            function login($profil, $identifiant, $password) {
                global $pdo;
                
                // Définir la table en fonction du profil
                $tableMap = [
                    'eleve'        => 'eleves',
                    'parent'       => 'parents',
                    'professeur'   => 'professeurs',
                    'vie_scolaire' => 'vie_scolaire',
                    'administrateur' => 'administrateurs',
                ];
                
                if (!isset($tableMap[$profil])) {
                    return false;
                }
                
                $table = $tableMap[$profil];
                
                try {
                    $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE identifiant = ? LIMIT 1");
                    $stmt->execute([$identifiant]);
                    $user = $stmt->fetch();
                    
                    if ($user && password_verify($password, $user['mot_de_passe'])) {
                        // Stocker les informations utilisateur en session
                        Session::set('user', [
                            'id'      => $user['id'],
                            'profil'  => $profil,
                            'nom'     => $user['nom'],
                            'prenom'  => $user['prenom'],
                            'identifiant' => $user['identifiant'],
                            'table'   => $table,
                            'classe'  => $user['classe'] ?? '',
                        ]);
                        
                        // Stocker le moment de l'authentification
                        Session::set('auth_time', time());
                        
                        // Régénérer l'ID de session pour éviter la fixation de session
                        session_regenerate_id(true);
                        Session::set('last_regenerate', time());
                        
                        // Stocker l'IP et l'agent utilisateur pour la vérification
                        Session::set('_client_ip', $_SERVER['REMOTE_ADDR']);
                        Session::set('_user_agent', $_SERVER['HTTP_USER_AGENT']);
                        
                        return true;
                    }
                } catch (PDOException $e) {
                    error_log("Erreur d'authentification: " . $e->getMessage());
                }
                
                return false;
            }
        }
    }
}

// Fonctions communes qui ne dépendent pas de Session
if (!function_exists('logout')) {
    /**
     * Déconnecte l'utilisateur
     */
    function logout() {
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

// Inclure le pont d'authentification pour assurer la compatibilité
$authBridgePath = __DIR__ . '/auth_bridge.php';
if (file_exists($authBridgePath)) {
    include_once $authBridgePath;
}
?>
