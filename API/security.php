<?php
/**
 * Fonctions de sécurité pour l'application Pronote
 */

/**
 * Nettoie une chaîne contre les attaques XSS
 * @param string $str La chaîne à nettoyer
 * @return string La chaîne nettoyée
 */
function xss_clean($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * Exécute une requête préparée de manière sécurisée
 * @param object $pdo Instance PDO
 * @param string $sql Requête SQL avec placeholders
 * @param array $params Paramètres pour la requête
 * @param string $fetchMode Mode de récupération (fetch, fetchAll, rowCount, none)
 * @param int $pdoFetchMode Constante PDO pour le mode de récupération
 * @return mixed Résultat de la requête ou false en cas d'erreur
 */
function db_query($pdo, $sql, $params = [], $fetchMode = 'fetchAll', $pdoFetchMode = PDO::FETCH_ASSOC) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        switch ($fetchMode) {
            case 'fetch':
                return $stmt->fetch($pdoFetchMode);
            case 'fetchAll':
                return $stmt->fetchAll($pdoFetchMode);
            case 'rowCount':
                return $stmt->rowCount();
            case 'lastInsertId':
                return $pdo->lastInsertId();
            case 'none':
            default:
                return true;
        }
    } catch (PDOException $e) {
        error_log('Erreur SQL: ' . $e->getMessage() . ' - Requête: ' . $sql);
        return false;
    }
}

/**
 * Génère un jeton CSRF sécurisé
 * @return string Jeton CSRF
 */
function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Vérifie la validité d'un jeton CSRF
 * @param string $token Jeton à vérifier
 * @return bool True si le jeton est valide
 */
function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Filtre un tableau de données d'entrée
 * @param array $data Données à filtrer
 * @param bool $strip_tags Supprimer les balises HTML
 * @return array Données filtrées
 */
function filter_input_array($data, $strip_tags = true) {
    if (!is_array($data)) {
        return $strip_tags ? strip_tags($data) : $data;
    }
    
    $filtered = [];
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $filtered[$key] = filter_input_array($value, $strip_tags);
        } else {
            $filtered[$key] = $strip_tags ? strip_tags($value) : $value;
        }
    }
    
    return $filtered;
}

/**
 * Vérifie si une adresse IP est dans la liste des IPs autorisées
 * @param string $ip Adresse IP à vérifier
 * @param array $whitelist Liste d'adresses IP autorisées
 * @return bool True si l'IP est autorisée
 */
function is_ip_allowed($ip, $whitelist) {
    // Si la liste blanche est vide, autoriser toutes les IPs
    if (empty($whitelist)) {
        return true;
    }
    
    return in_array($ip, $whitelist);
}

/**
 * Génère une empreinte du navigateur pour vérification supplémentaire
 * @return string Empreinte unique du navigateur
 */
function browser_fingerprint() {
    $fingerprint = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $fingerprint .= $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    $fingerprint .= $_SERVER['REMOTE_ADDR'];
    
    return hash('sha256', $fingerprint);
}

/**
 * Vérifie si le fichier d'installation doit être protégé
 * @return bool True si le fichier d'installation est accessible et l'installation terminée
 */
function check_install_security() {
    $installFile = dirname(__DIR__) . '/install.php';
    $installLockFile = dirname(__DIR__) . '/install.lock';
    
    // Si le fichier d'installation existe et qu'un lock file existe aussi,
    // l'installation est terminée mais le fichier est toujours accessible
    return file_exists($installFile) && file_exists($installLockFile);
}

/**
 * Protège le fichier d'installation après une installation réussie
 * @return bool True si le fichier a été protégé avec succès
 */
function protect_install_file() {
    $installFile = dirname(__DIR__) . '/install.php';
    
    if (file_exists($installFile)) {
        try {
            // Option 1: Renommer le fichier
            rename($installFile, $installFile . '.disabled');
            return true;
        } catch (Exception $e) {
            try {
                // Option 2: Créer un fichier .htaccess pour bloquer l'accès
                $htaccess = dirname(__DIR__) . '/.htaccess';
                $content = "# Protection du fichier d'installation\n";
                $content .= "<Files \"install.php\">\n";
                $content .= "    Order allow,deny\n";
                $content .= "    Deny from all\n";
                $content .= "</Files>\n";
                
                file_put_contents($htaccess, $content, FILE_APPEND);
                return true;
            } catch (Exception $e2) {
                return false;
            }
        }
    }
    
    return false;
}
