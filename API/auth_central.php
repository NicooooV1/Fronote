<?php
/**
 * Système d'authentification centralisé pour Pronote
 * Ce fichier gère l'authentification pour tous les modules de l'application
 */

// Démarrer ou reprendre la session de manière sécurisée
if (session_status() === PHP_SESSION_NONE) {
    // Configuration sécurisée des cookies de session
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    
    // Charger les constantes si elles existent
    if (file_exists(__DIR__ . '/config/env.php')) {
        require_once __DIR__ . '/config/env.php';
    }
    
    // Configurer la session avec des paramètres sécurisés
    $sessionParams = [
        'cookie_httponly' => true,   // Empêche l'accès au cookie via JavaScript
        'cookie_secure' => $secure,  // Cookie envoyé seulement en HTTPS si disponible
        'use_strict_mode' => true,   // Empêche les fixations de session
        'cookie_samesite' => 'Lax'   // Protège contre certaines attaques CSRF
    ];
    
    // Utiliser les paramètres définis dans la configuration si disponibles
    if (defined('SESSION_NAME')) {
        session_name(SESSION_NAME);
    }
    
    if (defined('SESSION_LIFETIME')) {
        $sessionParams['gc_maxlifetime'] = SESSION_LIFETIME;
        $sessionParams['cookie_lifetime'] = SESSION_LIFETIME;
    }
    
    if (defined('SESSION_PATH')) {
        $sessionParams['cookie_path'] = SESSION_PATH;
    }
    
    if (defined('SESSION_SECURE')) {
        $sessionParams['cookie_secure'] = SESSION_SECURE;
    }
    
    if (defined('SESSION_HTTPONLY')) {
        $sessionParams['cookie_httponly'] = SESSION_HTTPONLY;
    }
    
    if (defined('SESSION_SAMESITE')) {
        $sessionParams['cookie_samesite'] = SESSION_SAMESITE;
    }
    
    session_start($sessionParams);
    
    // Régénérer périodiquement l'ID de session pour prévenir la fixation
    if (!isset($_SESSION['last_regeneration']) || time() - $_SESSION['last_regeneration'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}

// Charger les constantes de configuration si elles ne sont pas définies
if (!defined('LOGIN_URL') && file_exists(__DIR__ . '/config/env.php')) {
    require_once __DIR__ . '/config/env.php';
}

/**
 * Vérifie si l'utilisateur est connecté
 * @return bool True si l'utilisateur est connecté, sinon False
 */
function isLoggedIn() {
    return isset($_SESSION['user']) && isset($_SESSION['user']['id']) && !empty($_SESSION['user']['id']);
}

/**
 * Récupère les données de l'utilisateur connecté
 * @return array|null Les données de l'utilisateur ou null si non connecté
 */
function getCurrentUser() {
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

/**
 * Récupère le nom complet de l'utilisateur connecté
 * @return string Le nom et prénom de l'utilisateur ou une chaîne vide
 */
function getUserFullName() {
    if (isset($_SESSION['user'])) {
        $user = $_SESSION['user'];
        return isset($user['prenom'], $user['nom']) ? "{$user['prenom']} {$user['nom']}" : '';
    }
    return '';
}

/**
 * Récupère le rôle de l'utilisateur connecté
 * @return string Le rôle de l'utilisateur ou 'visiteur' par défaut
 */
function getUserRole() {
    return isset($_SESSION['user']['profil']) ? $_SESSION['user']['profil'] : 'visiteur';
}

/**
 * Récupère les initiales de l'utilisateur connecté
 * @return string Les initiales du prénom et nom de l'utilisateur
 */
function getUserInitials() {
    if (isset($_SESSION['user'])) {
        $user = $_SESSION['user'];
        if (isset($user['prenom'], $user['nom']) && !empty($user['prenom']) && !empty($user['nom'])) {
            // Utiliser mb_substr pour gérer correctement les caractères UTF-8
            return mb_strtoupper(mb_substr($user['prenom'], 0, 1, 'UTF-8') . mb_substr($user['nom'], 0, 1, 'UTF-8'), 'UTF-8');
        }
    }
    return '?';
}

/**
 * Vérifie si l'utilisateur connecté est un administrateur
 * @return bool True si l'utilisateur est un administrateur, sinon False
 */
function isAdmin() {
    return isset($_SESSION['user']['profil']) && $_SESSION['user']['profil'] === 'administrateur';
}

/**
 * Vérifie si l'utilisateur connecté est un enseignant
 * @return bool True si l'utilisateur est un enseignant, sinon False
 */
function isTeacher() {
    return isset($_SESSION['user']['profil']) && $_SESSION['user']['profil'] === 'professeur';
}

/**
 * Vérifie si l'utilisateur connecté est un élève
 * @return bool True si l'utilisateur est un élève, sinon False
 */
function isStudent() {
    return isset($_SESSION['user']['profil']) && $_SESSION['user']['profil'] === 'eleve';
}

/**
 * Vérifie si l'utilisateur connecté est un parent
 * @return bool True si l'utilisateur est un parent, sinon False
 */
function isParent() {
    return isset($_SESSION['user']['profil']) && $_SESSION['user']['profil'] === 'parent';
}

/**
 * Vérifie si l'utilisateur connecté fait partie de la vie scolaire
 * @return bool True si l'utilisateur fait partie de la vie scolaire, sinon False
 */
function isVieScolaire() {
    return isset($_SESSION['user']['profil']) && $_SESSION['user']['profil'] === 'vie_scolaire';
}

/**
 * Valide et nettoie les entrées utilisateur
 * @param string $value La valeur à nettoyer
 * @param bool $allowHtml Indique si le HTML doit être conservé
 * @return string La valeur nettoyée
 */
function sanitizeInput($value, $allowHtml = false) {
    if (!is_string($value)) {
        return '';
    }
    
    $value = trim($value);
    
    if ($allowHtml) {
        // Si HTMLPurifier est disponible, l'utiliser pour nettoyer le HTML
        if (class_exists('HTMLPurifier')) {
            $config = HTMLPurifier_Config::createDefault();
            $purifier = new HTMLPurifier($config);
            return $purifier->purify($value);
        } else {
            // Sinon, utiliser une méthode plus basique mais moins sûre
            return strip_tags($value, '<p><br><strong><em><ul><ol><li><a><h1><h2><h3><h4><h5><h6><blockquote><pre><code><table><tr><th><td>');
        }
    }
    
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Génère un token CSRF sécurisé
 * @param string $name Le nom du token à stocker en session
 * @param int $expiration Durée de validité du token en secondes (défaut: 1h)
 * @return string Le token généré
 */
function generateCSRFToken($name = 'csrf_token', $expiration = 3600) {
    try {
        $token = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        // Fallback plus sécurisé si random_bytes n'est pas disponible
        $token = hash('sha256', uniqid(mt_rand(), true));
    }
    
    $_SESSION[$name] = $token;
    $_SESSION["{$name}_time"] = time(); // Pour l'expiration
    $_SESSION["{$name}_expiration"] = $expiration;
    
    return $token;
}

/**
 * Vérifie un token CSRF
 * @param string $token Le token à vérifier
 * @param string $name Le nom du token stocké en session
 * @return bool True si le token est valide, sinon False
 */
function validateCSRFToken($token, $name = 'csrf_token') {
    if (!isset($_SESSION[$name]) || !isset($_SESSION["{$name}_time"])) {
        return false;
    }
    
    // Vérifier l'expiration du token
    $expiration = $_SESSION["{$name}_expiration"] ?? 3600; // 1 heure par défaut
    
    $valid = hash_equals($_SESSION[$name], $token);
    $notExpired = (time() - $_SESSION["{$name}_time"]) < $expiration;
    
    // Si le token a été vérifié avec succès, le régénérer pour empêcher les attaques par réutilisation
    if ($valid && $notExpired) {
        try {
            $_SESSION[$name] = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $_SESSION[$name] = hash('sha256', uniqid(mt_rand(), true));
        }
        $_SESSION["{$name}_time"] = time();
    }
    
    return $valid && $notExpired;
}

/**
 * Journalise les actions des utilisateurs de façon sécurisée
 * @param string $action Description de l'action
 * @param string $module Module concerné
 * @param int $severity Niveau de gravité (1: info, 2: avertissement, 3: erreur)
 */
function logUserAction($action, $module = null, $severity = 1) {
    // Ne rien faire si la journalisation est désactivée
    if (defined('LOG_ENABLED') && !LOG_ENABLED) {
        return;
    }
    
    // Vérifier le niveau de journalisation
    if (defined('LOG_LEVEL')) {
        // Ne pas journaliser les messages de débogage en production
        if ($severity === 1 && LOG_LEVEL === 'error') {
            return;
        }
    }
    
    // Obtenir les informations de l'utilisateur
    $user = getCurrentUser();
    $userId = $user ? ($user['id'] ?? 'unknown') : 'guest';
    $userProfile = $user ? ($user['profil'] ?? 'unknown') : 'guest';
    
    // Masquer les informations sensibles dans les messages de log
    $action = preg_replace('/(password|mot_de_passe|secret|token)=\S+/i', '$1=[REDACTED]', $action);
    
    // Formater le message de journal
    $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? 'unknown', FILTER_VALIDATE_IP) ?: 'unknown';
    $datetime = date('Y-m-d H:i:s');
    $module = $module ?? basename(dirname($_SERVER['SCRIPT_FILENAME'] ?? ''));
    
    // Déterminer le niveau de gravité
    $severityLabel = ['INFO', 'WARNING', 'ERROR'][$severity - 1] ?? 'INFO';
    
    // Construire le message de journal
    $logMessage = sprintf(
        "[%s] [%s] [User ID: %s, Profile: %s, IP: %s] %s",
        $datetime,
        $severityLabel,
        $userId,
        $userProfile,
        $ip,
        $action
    );
    
    // Créer le répertoire de logs s'il n'existe pas
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        if (!is_dir(dirname($logDir))) {
            return; // Si le répertoire parent n'existe pas, abandonner silencieusement
        }
        
        // Essayer de créer le répertoire
        if (!@mkdir($logDir, 0755, true)) {
            // En cas d'échec, essayer d'utiliser le répertoire système temporaire
            $logDir = sys_get_temp_dir();
        }
    }
    
    // Journal spécifique au module avec rotation mensuelle
    $logFile = $logDir . '/' . preg_replace('/[^a-z0-9_-]/i', '', $module) . '_' . date('Y-m') . '.log';
    
    // Écrire dans le journal avec verrouillage exclusif
    @error_log($logMessage . PHP_EOL, 3, $logFile);
    
    // En cas d'erreur grave, envoyer également au journal système
    if ($severity === 3) {
        error_log("PRONOTE ERROR: " . $logMessage);
    }
}

/**
 * Redirige l'utilisateur après un délai
 * @param string $url URL de redirection
 * @param int $delay Délai en secondes avant redirection
 * @param string $message Message à afficher avant la redirection
 */
function redirectWithDelay($url, $delay = 2, $message = 'Redirection...') {
    // Valider l'URL pour éviter les redirections vers des sites externes
    if (!isValidRedirectUrl($url)) {
        $url = defined('HOME_URL') ? HOME_URL : '/';
        logUserAction("Tentative de redirection vers une URL non autorisée", "auth", 2);
    }
    
    $url = filter_var($url, FILTER_SANITIZE_URL);
    echo '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta http-equiv="refresh" content="' . intval($delay) . ';url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">
        <title>Redirection</title>
        <style>
            body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; text-align: center; }
            .message { background-color: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
            .countdown { font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="message">
            <p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>
            <p>Vous allez être redirigé dans <span id="countdown" class="countdown">' . intval($delay) . '</span> seconde(s).</p>
            <p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Cliquez ici si vous n\'êtes pas redirigé automatiquement</a>.</p>
        </div>
        <script>
            var seconds = ' . intval($delay) . ';
            var countdown = document.getElementById("countdown");
            var timer = setInterval(function() {
                seconds--;
                countdown.textContent = seconds;
                if (seconds <= 0) clearInterval(timer);
            }, 1000);
        </script>
    </body>
    </html>';
    exit;
}

/**
 * Vérifie si l'URL de redirection est valide (empêche les redirections vers des sites externes)
 * @param string $url L'URL à vérifier
 * @return bool True si l'URL est valide pour la redirection
 */
function isValidRedirectUrl($url) {
    // Accepter les chemins relatifs
    if (strpos($url, '/') === 0) {
        return true;
    }
    
    // Accepter les URLs locales absolues
    if (defined('APP_URL')) {
        $appUrl = rtrim(APP_URL, '/');
        if (strpos($url, $appUrl) === 0) {
            return true;
        }
    }
    
    $currentHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    if (!empty($currentHost) && strpos($url, 'http') === 0) {
        $urlParts = parse_url($url);
        if (isset($urlParts['host']) && $urlParts['host'] === $currentHost) {
            return true;
        }
    }
    
    return false;
}

/**
 * Vérifie si une requête est faite via AJAX
 * @return bool True si la requête est une requête AJAX
 */
function isAjaxRequest() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Génère une réponse JSON pour les requêtes AJAX
 * @param array $data Données à renvoyer
 * @param int $statusCode Code de statut HTTP
 */
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Force l'utilisation de HTTPS
 * @return bool True si la redirection a été effectuée
 */
function forceHTTPS() {
    if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
        if (!headers_sent()) {
            $redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            header('Location: ' . $redirect);
            exit;
            return true;
        }
    }
    return false;
}

// Si l'application est en production, forcer HTTPS
if (defined('APP_ENV') && APP_ENV === 'production') {
    if (defined('FORCE_HTTPS') && FORCE_HTTPS) {
        forceHTTPS();
    }
}

/**
 * Fonction de compatibilité pour la connexion à la base de données
 * @deprecated Utiliser le pattern singleton dans db_connection.php à la place
 */
function getDBConnection() {
    // Inclure le fichier de connexion à la base de données s'il existe
    $dbConnectionFile = __DIR__ . '/db_connection.php';
    if (file_exists($dbConnectionFile)) {
        require_once $dbConnectionFile;
        
        // Vérifier si la nouvelle fonction db() existe
        if (function_exists('db')) {
            return db()->getPDO();
        }
    }
    
    // Fallback: créer une nouvelle connexion PDO
    try {
        if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
            throw new Exception("Configuration de base de données manquante");
        }
        
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ];
        
        return new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (Exception $e) {
        error_log("Erreur de connexion à la base de données: " . $e->getMessage());
        throw new PDOException("Impossible de se connecter à la base de données.");
    }
}
