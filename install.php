<?php
/**
 * Script d'installation de Pronote
 */

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
        if (!@mkdir($path, 0755, true)) {
            $permissionIssues[] = "Impossible de créer le dossier {$dir}";
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
        $dbHost = isset($_POST['db_host']) ? $_POST['db_host'] : 'localhost';
        $dbName = isset($_POST['db_name']) ? $_POST['db_name'] : '';
        $dbUser = isset($_POST['db_user']) ? $_POST['db_user'] : '';
        $dbPass = isset($_POST['db_pass']) ? $_POST['db_pass'] : '';
        $appEnv = isset($_POST['app_env']) ? $_POST['app_env'] : 'production';
        $baseUrlInput = isset($_POST['base_url']) ? $_POST['base_url'] : $baseUrl;
        
        if (empty($dbName) || empty($dbUser)) {
            throw new Exception("Le nom de la base de données et l'utilisateur sont obligatoires.");
        }
        
        // Tester la connexion
        $dsn = "mysql:host={$dbHost};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ];
        
        $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
        
        // Créer la base de données si elle n'existe pas
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}`");
        $pdo->exec("USE `{$dbName}`");
        
        // Créer le fichier de configuration
        $apiDir = $installDir . '/API';
        $configDir = $apiDir . '/config';
        
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }
        
        $configContent = <<<CONFIG
<?php
/**
 * Configuration d'environnement
 */

// Environnement (development, production, test)
define('APP_ENV', '{$appEnv}');

// Configuration de base
define('APP_NAME', 'Pronote');
define('APP_VERSION', '1.0.0');

// Configuration des URLs et chemins
define('BASE_URL', '{$baseUrlInput}');
define('APP_ROOT', realpath(__DIR__ . '/../../'));

// Configuration de la base de données
define('DB_HOST', '{$dbHost}');
define('DB_NAME', '{$dbName}');
define('DB_USER', '{$dbUser}');
define('DB_PASS', '{$dbPass}');
define('DB_CHARSET', 'utf8mb4');

// Configuration des sessions
define('SESSION_NAME', 'pronote_session');
define('SESSION_LIFETIME', 3600); // 1 heure
define('SESSION_PATH', '/');
define('SESSION_SECURE', false); // Mettre à true en production si HTTPS
define('SESSION_HTTPONLY', true);

// Configuration des logs
define('LOG_ENABLED', true);
define('LOG_LEVEL', '{$appEnv}' === 'development' ? 'debug' : 'error');
CONFIG;

        file_put_contents($apiDir . '/config/env.php', $configContent);
        
        // Indiquer que l'installation est réussie
        $installed = true;
        
    } catch (PDOException $e) {
        $dbError = $e->getMessage();
    } catch (Exception $e) {
        $dbError = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
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
            <p><a href="<?= $baseUrl ?>">Accéder à l'application</a></p>
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
