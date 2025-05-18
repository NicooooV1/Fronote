<?php
/**
 * Script d'installation de Pronote
 * Ce script s'auto-désactivera après une installation réussie
 */

// Vérifier si l'installation est déjà terminée
$installLockFile = __DIR__ . '/install.lock';
if (file_exists($installLockFile)) {
    die('L\'installation a déjà été effectuée. Pour réinstaller, supprimez le fichier install.lock du répertoire racine.');
}

// Journaliser l'accès au script d'installation
error_log('Accès au script d\'installation de Pronote: ' . date('Y-m-d H:i:s'));

// Vérifier la version de PHP
if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    die('Pronote nécessite PHP 7.4 ou supérieur. Version actuelle: ' . PHP_VERSION);
}

// Vérifier les extensions requises
$requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'session'];
$missingExtensions = [];

foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        $missingExtensions[] = $ext;
    }
}

if (!empty($missingExtensions)) {
    die('Extensions PHP requises manquantes : ' . implode(', ', $missingExtensions));
}

// Détecter le chemin absolu du répertoire d'installation
$installDir = __DIR__;
$baseUrl = isset($_SERVER['REQUEST_URI']) ? 
    dirname(str_replace('/install.php', '', $_SERVER['REQUEST_URI'])) : 
    '/pronote';

// Si le chemin est la racine, ajuster la valeur
if ($baseUrl === '/.') {
    $baseUrl = '/';
}

// Vérifier les permissions des dossiers
$directories = [
    'API/logs',
    'uploads',
    'temp'
];

$permissionIssues = [];
foreach ($directories as $dir) {
    $path = $installDir . '/' . $dir;
    
    // Créer le dossier s'il n'existe pas
    if (!is_dir($path)) {
        try {
            if (!mkdir($path, 0755, true)) {
                $permissionIssues[] = "Impossible de créer le dossier {$dir}";
            }
        } catch (Exception $e) {
            $permissionIssues[] = "Erreur lors de la création du dossier {$dir}: " . $e->getMessage();
        }
    } else if (!is_writable($path)) {
        $permissionIssues[] = "Le dossier {$dir} n'est pas accessible en écriture";
    }
}

if (!empty($permissionIssues)) {
    echo '<h1>Problèmes de permissions</h1>';
    echo '<ul>';
    foreach ($permissionIssues as $issue) {
        echo '<li>' . htmlspecialchars($issue) . '</li>';
    }
    echo '</ul>';
    echo '<p>Veuillez résoudre ces problèmes de permissions avant de continuer.</p>';
    die();
}

// Traitement du formulaire
$installed = false;
$dbError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Valider les entrées utilisateur
        $dbHost = filter_input(INPUT_POST, 'db_host', FILTER_SANITIZE_STRING) ?: 'localhost';
        $dbName = filter_input(INPUT_POST, 'db_name', FILTER_SANITIZE_STRING) ?: '';
        $dbUser = filter_input(INPUT_POST, 'db_user', FILTER_SANITIZE_STRING) ?: '';
        $dbPass = $_POST['db_pass'] ?? ''; // Ne pas filtrer le mot de passe pour permettre les caractères spéciaux
        $appEnv = filter_input(INPUT_POST, 'app_env', FILTER_SANITIZE_STRING) ?: 'production';
        $baseUrlInput = filter_input(INPUT_POST, 'base_url', FILTER_SANITIZE_STRING) ?: $baseUrl;
        
        // Validation supplémentaire
        if (empty($dbName) || empty($dbUser)) {
            throw new Exception("Le nom de la base de données et l'utilisateur sont obligatoires.");
        }
        
        // Tester la connexion à la base de données
        try {
            $dsn = "mysql:host={$dbHost};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ];
            
            $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
            
            // Créer la base de données si elle n'existe pas
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . $pdo->quote($dbName) . "`");
            $pdo->exec("USE `" . $pdo->quote($dbName) . "`");
            
            // Créer le fichier de configuration
            $apiDir = $installDir . '/API';
            $configDir = $apiDir . '/config';
            
            if (!is_dir($configDir)) {
                if (!mkdir($configDir, 0755, true)) {
                    throw new Exception("Impossible de créer le répertoire de configuration.");
                }
            }
            
            // Créer le contenu du fichier de configuration en évitant les injections
            $configContent = <<<CONFIG
<?php
/**
 * Configuration d'environnement
 * Généré automatiquement par le script d'installation
 */

// Environnement (development, production, test)
if (!defined('APP_ENV')) define('APP_ENV', '{$appEnv}');

// Configuration de base
if (!defined('APP_NAME')) define('APP_NAME', 'Pronote');
if (!defined('APP_VERSION')) define('APP_VERSION', '1.0.0');

// Configuration des URLs et chemins - CHEMIN COMPLET OBLIGATOIRE
if (!defined('BASE_URL')) define('BASE_URL', '{$baseUrlInput}');
if (!defined('APP_URL')) define('APP_URL', '{$baseUrlInput}'); // Même valeur que BASE_URL par défaut
if (!defined('APP_ROOT')) define('APP_ROOT', realpath(__DIR__ . '/../../'));

// URLs communes construites avec BASE_URL
if (!defined('LOGIN_URL')) define('LOGIN_URL', BASE_URL . '/login/public/index.php');
if (!defined('LOGOUT_URL')) define('LOGOUT_URL', BASE_URL . '/login/public/logout.php');
if (!defined('HOME_URL')) define('HOME_URL', BASE_URL . '/accueil/accueil.php');

// Configuration de la base de données
if (!defined('DB_HOST')) define('DB_HOST', '{$dbHost}');
if (!defined('DB_NAME')) define('DB_NAME', '{$dbName}');
if (!defined('DB_USER')) define('DB_USER', '{$dbUser}');
if (!defined('DB_PASS')) define('DB_PASS', '{$dbPass}');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// Configuration des sessions
if (!defined('SESSION_NAME')) define('SESSION_NAME', 'pronote_session');
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 3600); // 1 heure
if (!defined('SESSION_PATH')) define('SESSION_PATH', '/');
if (!defined('SESSION_SECURE')) define('SESSION_SECURE', false); // Mettre à true en production si HTTPS
if (!defined('SESSION_HTTPONLY')) define('SESSION_HTTPONLY', true);

// Configuration des logs
if (!defined('LOG_ENABLED')) define('LOG_ENABLED', true);
if (!defined('LOG_LEVEL')) define('LOG_LEVEL', '{$appEnv}' === 'development' ? 'debug' : 'error');
CONFIG;

            if (file_put_contents($apiDir . '/config/env.php', $configContent) === false) {
                throw new Exception("Impossible d'écrire le fichier de configuration.");
            }
            
            // Créer un fichier .htaccess pour protéger les fichiers de config
            $htaccessContent = <<<HTACCESS
# Protéger les fichiers de configuration
<Files ~ "\.php$">
    Order allow,deny
    Deny from all
</Files>
HTACCESS;

            file_put_contents($configDir . '/.htaccess', $htaccessContent);
            
            // Créer un fichier de verrou pour empêcher l'exécution future de l'installation
            file_put_contents($installLockFile, date('Y-m-d H:i:s'));
            
            // Indiquer que l'installation est réussie
            $installed = true;
            
        } catch (PDOException $e) {
            throw new Exception("Erreur de connexion à la base de données: " . $e->getMessage());
        }
    } catch (Exception $e) {
        $dbError = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation de Pronote</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; }
        input[type="text"], input[type="password"] { width: 100%; padding: 8px; }
        .error { color: red; padding: 10px; background: #ffeeee; border-radius: 5px; margin-bottom: 20px; }
        .success { color: green; padding: 10px; background: #eeffee; border-radius: 5px; margin-bottom: 20px; }
        button { padding: 10px 15px; background-color: #4CAF50; color: white; border: none; cursor: pointer; }
    </style>
</head>
<body>
    <h1>Installation de Pronote</h1>
    
    <?php if ($installed): ?>
        <div class="success">
            <h2>Installation réussie!</h2>
            <p>Pronote a été correctement configuré.</p>
            <p><strong>Important:</strong> Par mesure de sécurité, le script d'installation a été désactivé. Pour réinstaller, supprimez le fichier <code>install.lock</code> du répertoire racine.</p>
            <p><a href="<?= htmlspecialchars($baseUrl) ?>/login/public/index.php">Accéder à l'application</a></p>
        </div>
    <?php else: ?>
        <?php if (!empty($dbError)): ?>
            <div class="error">
                <p>Erreur: <?= htmlspecialchars($dbError) ?></p>
            </div>
        <?php endif; ?>
        
        <form method="post" action="">
            <div class="form-group">
                <label for="base_url">URL de base de l'application</label>
                <input type="text" id="base_url" name="base_url" value="<?= htmlspecialchars($baseUrl) ?>" required>
                <small>Par exemple: /pronote ou laisser vide si installé à la racine</small>
            </div>
            
            <div class="form-group">
                <label for="app_env">Environnement</label>
                <select id="app_env" name="app_env">
                    <option value="development">Développement</option>
                    <option value="production" selected>Production</option>
                    <option value="test">Test</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="db_host">Hôte de la base de données</label>
                <input type="text" id="db_host" name="db_host" value="localhost" required>
            </div>
            
            <div class="form-group">
                <label for="db_name">Nom de la base de données</label>
                <input type="text" id="db_name" name="db_name" required>
            </div>
            
            <div class="form-group">
                <label for="db_user">Utilisateur de la base de données</label>
                <input type="text" id="db_user" name="db_user" required>
            </div>
            
            <div class="form-group">
                <label for="db_pass">Mot de passe de la base de données</label>
                <input type="password" id="db_pass" name="db_pass">
            </div>
            
            <button type="submit">Installer</button>
        </form>
    <?php endif; ?>
</body>
</html>
