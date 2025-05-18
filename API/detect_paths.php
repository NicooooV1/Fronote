<?php
/**
 * Script pour auto-détecter et mettre à jour les chemins d'accès
 * Exécuter ce script une fois pour corriger automatiquement les problèmes de chemins
 */

// Afficher les erreurs
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Fonction pour détecter le chemin de base
function detectBasePath() {
    if (isset($_SERVER['SCRIPT_NAME'])) {
        $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
        
        // Remonter jusqu'à la racine de l'application (API est dans le dossier racine)
        $rootPath = dirname($scriptPath);
        
        return $rootPath;
    } elseif (isset($_SERVER['REQUEST_URI'])) {
        $uri = $_SERVER['REQUEST_URI'];
        $parts = explode('/API/detect_paths.php', $uri);
        return $parts[0];
    } else {
        // Fallback
        return '/~u22405372/SAE/Pronote';
    }
}

// Détecter le chemin de base
$basePath = detectBasePath();

// Déterminer l'URL complète
$appUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'r207.borelly.net') . $basePath;

// Créer le contenu du fichier de configuration
$configContent = <<<CONFIG
<?php
/**
 * Configuration d'environnement auto-détectée
 * Généré automatiquement par detect_paths.php
 */

// Base URL de l'application (chemin complet)
if (!defined('BASE_URL')) define('BASE_URL', '$basePath');

// URL absolue (pour les redirections externes)
if (!defined('APP_URL')) define('APP_URL', '$appUrl');

// Environnement (development, production, test)
if (!defined('APP_ENV')) define('APP_ENV', 'development');

// Configuration de la base de données
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'db_MASSE');
if (!defined('DB_USER')) define('DB_USER', '22405372');
if (!defined('DB_PASS')) define('DB_PASS', '807014');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// Chemin racine de l'application
if (!defined('APP_ROOT')) define('APP_ROOT', realpath(dirname(__FILE__) . '/../'));

// URLs communes construites avec BASE_URL pour garantir le bon chemin
if (!defined('LOGIN_URL')) define('LOGIN_URL', BASE_URL . '/login/public/index.php');
if (!defined('LOGOUT_URL')) define('LOGOUT_URL', BASE_URL . '/login/public/logout.php');
if (!defined('HOME_URL')) define('HOME_URL', BASE_URL . '/accueil/accueil.php');

// Configuration des sessions
if (!defined('SESSION_NAME')) define('SESSION_NAME', 'pronote_session');
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 3600); // 1 heure
if (!defined('SESSION_PATH')) define('SESSION_PATH', '/');
if (!defined('SESSION_SECURE')) define('SESSION_SECURE', false);
if (!defined('SESSION_HTTPONLY')) define('SESSION_HTTPONLY', true);

// Configuration des logs
if (!defined('LOG_ENABLED')) define('LOG_ENABLED', true);
if (!defined('LOG_LEVEL')) define('LOG_LEVEL', APP_ENV === 'development' ? 'debug' : 'error');
CONFIG;

// Chemin du fichier de configuration
$configFile = __DIR__ . '/config/env.php';

// Créer le répertoire config s'il n'existe pas
if (!is_dir(dirname($configFile))) {
    mkdir(dirname($configFile), 0755, true);
}

// Sauvegarder le fichier de configuration existant s'il existe
if (file_exists($configFile)) {
    copy($configFile, $configFile . '.bak.' . date('Y-m-d-H-i-s'));
}

// Écrire le nouveau fichier de configuration
if (file_put_contents($configFile, $configContent) !== false) {
    echo "<h1>Configuration mise à jour avec succès</h1>";
    echo "<p>Le fichier de configuration a été créé/mis à jour avec les paramètres suivants:</p>";
    echo "<ul>";
    echo "<li><strong>BASE_URL:</strong> $basePath</li>";
    echo "<li><strong>APP_URL:</strong> $appUrl</li>";
    echo "<li><strong>LOGIN_URL:</strong> " . $basePath . "/login/public/index.php</li>";
    echo "<li><strong>LOGOUT_URL:</strong> " . $basePath . "/login/public/logout.php</li>";
    echo "<li><strong>HOME_URL:</strong> " . $basePath . "/accueil/accueil.php</li>";
    echo "</ul>";
    
    echo "<p>Vous pouvez maintenant:</p>";
    echo "<ul>";
    echo "<li><a href='$basePath/accueil/accueil.php'>Aller à la page d'accueil</a></li>";
    echo "<li><a href='$basePath/login/public/index.php'>Aller à la page de connexion</a></li>";
    echo "</ul>";
} else {
    echo "<h1>Erreur</h1>";
    echo "<p>Impossible d'écrire dans le fichier de configuration. Vérifiez les permissions.</p>";
}
