<?php
/**
 * Page de diagnostic pour Pronote - RÉSERVÉE AUX ADMINISTRATEURS
 * Cette page permet de diagnostiquer les problèmes de configuration, de permissions et de redirections
 */

// Charger le système d'autoloading
require_once __DIR__ . '/API/autoload.php';

// Initialiser l'application
bootstrap();

// Vérifier que l'utilisateur est administrateur
if (!\Pronote\Auth\isAdmin()) {
    // Rediriger vers la page de connexion ou afficher un message d'erreur
    if (!\Pronote\Auth\isLoggedIn()) {
        if (!defined('BASE_URL')) {
            define('BASE_URL', '/~u22405372/SAE/Pronote');
        }
        header('Location: ' . BASE_URL . '/login/public/index.php');
    } else {
        http_response_code(403); // Forbidden
        echo '<h1>Accès refusé</h1><p>Seuls les administrateurs peuvent accéder à cette page.</p>';
    }
    exit;
}

// Journaliser l'accès à la page de diagnostic
\Pronote\Logging\info('Accès à la page de diagnostic', 'security');

// Afficher les erreurs pour le diagnostic
ini_set('display_errors', 1);
error_reporting(E_ALL);

/**
 * Teste les permissions d'un répertoire
 * @param string $directory Chemin du répertoire
 * @return array Résultats des tests
 */
function testDirectoryPermissions($directory) {
    $results = [];
    
    try {
        // Vérifier si le répertoire existe
        if (is_dir($directory)) {
            $results['exists'] = true;
            
            // Vérifier les permissions
            $results['readable'] = is_readable($directory);
            $results['writable'] = is_writable($directory);
            
            // Essayer de créer un fichier temporaire
            $testFile = $directory . '/test_' . time() . '.txt';
            $canWrite = false;
            try {
                $canWrite = file_put_contents($testFile, 'Test') !== false;
            } catch (\Exception $e) {
                $canWrite = false;
            }
            $results['can_write_file'] = $canWrite;
            
            // Supprimer le fichier de test s'il a été créé
            if ($canWrite && file_exists($testFile)) {
                @unlink($testFile);
            }
        } else {
            $results['exists'] = false;
            $results['readable'] = false;
            $results['writable'] = false;
            $results['can_write_file'] = false;
            
            // Essayer de créer le répertoire
            $canCreate = false;
            try {
                $canCreate = @mkdir($directory, 0755, true);
                if ($canCreate) {
                    @rmdir($directory); // Supprimer le répertoire créé
                }
            } catch (\Exception $e) {
                $canCreate = false;
            }
            $results['can_create'] = $canCreate;
        }
    } catch (\Exception $e) {
        $results['error'] = $e->getMessage();
    }
    
    return $results;
}

/**
 * Teste l'accessibilité d'une page
 * @param string $url URL à tester
 * @return array Résultats du test
 */
function testPageAccess($url) {
    $result = [
        'url' => $url,
        'code' => 0,
        'accessible' => false,
        'error' => null
    ];
    
    try {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \Exception('Impossible d\'initialiser cURL');
        }
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10 secondes est long pour un diagnostic
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        
        if ($response === false) {
            $result['error'] = curl_error($ch);
        } else {
            $result['code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $result['accessible'] = ($result['code'] >= 200 && $result['code'] < 400);
        }
        
        curl_close($ch);
    } catch (\Exception $e) {
        $result['error'] = $e->getMessage();
    }
    
    return $result;
}

// Répertoires à tester
$directories = [
    'API' => __DIR__ . '/API',
    'API/logs' => __DIR__ . '/API/logs',
    'API/config' => __DIR__ . '/API/config',
    'uploads' => __DIR__ . '/uploads',
    'login/logs' => __DIR__ . '/login/logs'
];

// Pages à tester
$baseUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/~u22405372/SAE/Pronote';
$pages = [
    'Accueil' => $baseUrl . '/accueil/accueil.php',
    'Login' => $baseUrl . '/login/public/index.php',
    'Logout' => $baseUrl . '/login/public/logout.php',
    'Notes' => $baseUrl . '/notes/notes.php',
    'Absences' => $baseUrl . '/absences/absences.php',
    'Agenda' => $baseUrl . '/agenda/agenda.php',
    'Cahier de Textes' => $baseUrl . '/cahierdetextes/cahierdetextes.php'
];

// Détection du chemin absolu de l'application
$scriptPath = $_SERVER['SCRIPT_NAME'];
$applicationPath = dirname($scriptPath);

// Détection des configurations importantes
$configFiles = [
    'API/config/env.php' => __DIR__ . '/API/config/env.php',
    'API/config/config.php' => __DIR__ . '/API/config/config.php'
];

// Lire le contenu des fichiers de configuration de manière sécurisée
$configContents = [];
foreach ($configFiles as $name => $path) {
    if (file_exists($path)) {
        // Charger le contenu mais masquer les informations sensibles
        $content = file_get_contents($path);
        // Masquer les mots de passe et informations sensibles
        $content = preg_replace('/define\s*\(\s*([\'"])DB_PASS\1\s*,\s*([\'"])(.*?)\2\s*\)/', 'define(\'DB_PASS\', \'********\')', $content);
        $content = preg_replace('/\$db_pass\s*=\s*([\'"])(.*?)\1/', '$db_pass = \'********\'', $content);
        $configContents[$name] = $content;
    } else {
        $configContents[$name] = 'Fichier non trouvé';
    }
}

// Informations de session sécurisées
$sessionInfo = [
    'session_id' => '********' . substr(session_id(), -4),
    'session_status' => session_status(),
    'session_name' => session_name(),
    'session_cookie_params' => session_get_cookie_params(),
    'user_logged_in' => isset($_SESSION['user']),
    'user_role' => $_SESSION['user']['profil'] ?? 'Non connecté'
];

// Vérifier les fichiers d'authentification des modules
$authFiles = [
    'API/autoload.php' => __DIR__ . '/API/autoload.php',
    'API/core/auth.php' => __DIR__ . '/API/core/auth.php',
    'Notes/includes/auth.php' => __DIR__ . '/notes/includes/auth.php',
    'Absences/includes/auth.php' => __DIR__ . '/absences/includes/auth.php',
    'Agenda/includes/auth.php' => __DIR__ . '/agenda/includes/auth.php',
    'CahierDeTextes/includes/auth.php' => __DIR__ . '/cahierdetextes/includes/auth.php',
];

$authFilesStatus = [];
foreach ($authFiles as $name => $path) {
    $authFilesStatus[$name] = [
        'exists' => file_exists($path),
        'readable' => is_readable($path),
        'modified' => file_exists($path) ? date('Y-m-d H:i:s', filemtime($path)) : 'N/A'
    ];
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnostic Pronote - Administration</title>
    <style>
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            line-height: 1.6;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
            color: #333;
        }
        h1, h2, h3 {
            color: #2c3e50;
        }
        .section {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
        }
        tr:hover {
            background-color: #f9f9f9;
        }
        .success {
            color: #2ecc71;
        }
        .warning {
            color: #e67e22;
        }
        .error {
            color: #e74c3c;
        }
        pre {
            background: #f8f8f8;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            overflow: auto;
            max-height: 400px;
        }
        .actions {
            margin-top: 20px;
        }
        .button {
            display: inline-block;
            padding: 10px 15px;
            background: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin-right: 10px;
        }
        .button:hover {
            background: #2980b9;
        }
    </style>
</head>
<body>
    <div class="section">
        <h1>Diagnostic Pronote - Administration</h1>
        <p>Cette page est réservée aux administrateurs pour diagnostiquer les problèmes de configuration, de permissions et de redirections dans l'application Pronote.</p>
    </div>
    
    <div class="section">
        <h2>Informations de base</h2>
        <ul>
            <li><strong>Chemin d'application détecté:</strong> <?= htmlspecialchars($applicationPath) ?></li>
            <li><strong>Répertoire courant:</strong> <?= htmlspecialchars(__DIR__) ?></li>
            <li><strong>URL de base:</strong> <?= htmlspecialchars($baseUrl) ?></li>
        </ul>
    </div>
    
    <div class="section">
        <h2>Permissions des répertoires</h2>
        <table>
            <tr>
                <th>Répertoire</th>
                <th>Existe</th>
                <th>Lecture</th>
                <th>Écriture</th>
                <th>Peut créer un fichier</th>
                <th>Peut être créé</th>
            </tr>
            <?php foreach ($directories as $name => $path): ?>
                <?php $permissions = testDirectoryPermissions($path); ?>
                <tr>
                    <td><?= htmlspecialchars($name) ?></td>
                    <td class="<?= $permissions['exists'] ? 'success' : 'error' ?>"><?= $permissions['exists'] ? 'Oui' : 'Non' ?></td>
                    <td class="<?= $permissions['readable'] ? 'success' : 'error' ?>"><?= $permissions['readable'] ? 'Oui' : 'Non' ?></td>
                    <td class="<?= $permissions['writable'] ? 'success' : 'error' ?>"><?= $permissions['writable'] ? 'Oui' : 'Non' ?></td>
                    <td class="<?= $permissions['can_write_file'] ? 'success' : 'error' ?>"><?= $permissions['can_write_file'] ? 'Oui' : 'Non' ?></td>
                    <td class="<?= $permissions['exists'] ? 'N/A' : ($permissions['can_create'] ? 'success' : 'error') ?>"><?= $permissions['exists'] ? 'N/A' : ($permissions['can_create'] ? 'Oui' : 'Non') ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    
    <div class="section">
        <h2>Accessibilité des pages</h2>
        <table>
            <tr>
                <th>Page</th>
                <th>URL</th>
                <th>Code HTTP</th>
                <th>Accessible</th>
            </tr>
            <?php foreach ($pages as $name => $url): ?>
                <?php $access = testPageAccess($url); ?>
                <tr>
                    <td><?= htmlspecialchars($name) ?></td>
                    <td><?= htmlspecialchars($url) ?></td>
                    <td><?= $access['code'] ?></td>
                    <td class="<?= $access['accessible'] ? 'success' : 'error' ?>"><?= $access['accessible'] ? 'Oui' : 'Non' ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    
    <div class="section">
        <h2>Fichiers d'authentification</h2>
        <table>
            <tr>
                <th>Fichier</th>
                <th>Existe</th>
                <th>Lisible</th>
                <th>Dernière modification</th>
            </tr>
            <?php foreach ($authFilesStatus as $name => $status): ?>
                <tr>
                    <td><?= htmlspecialchars($name) ?></td>
                    <td class="<?= $status['exists'] ? 'success' : 'error' ?>"><?= $status['exists'] ? 'Oui' : 'Non' ?></td>
                    <td class="<?= $status['readable'] ? 'success' : 'error' ?>"><?= $status['readable'] ? 'Oui' : 'Non' ?></td>
                    <td><?= htmlspecialchars($status['modified']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    
    <div class="section">
        <h2>Information de session</h2>
        <pre><?= htmlspecialchars(print_r($sessionInfo, true)) ?></pre>
    </div>
    
    <div class="section">
        <h2>Variables du serveur</h2>
        <pre><?= htmlspecialchars(print_r([
            'SERVER_NAME' => $_SERVER['SERVER_NAME'] ?? 'Non défini',
            'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? 'Non défini',
            'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? 'Non défini',
            'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? 'Non défini',
            'PHP_SELF' => $_SERVER['PHP_SELF'] ?? 'Non défini',
            'DOCUMENT_ROOT' => $_SERVER['DOCUMENT_ROOT'] ?? 'Non défini',
            'PHP_VERSION' => PHP_VERSION,
        ], true)) ?></pre>
    </div>
    
    <div class="section">
        <h2>Fichiers de configuration</h2>
        <?php foreach ($configContents as $name => $content): ?>
            <h3><?= htmlspecialchars($name) ?></h3>
            <pre><?= htmlspecialchars($content) ?></pre>
        <?php endforeach; ?>
    </div>
    
    <div class="section actions">
        <h2>Actions</h2>
        <p>
            <a href="<?= $baseUrl ?>/login/public/index.php" class="button">Page de connexion</a>
            <a href="<?= $baseUrl ?>/accueil/accueil.php" class="button">Page d'accueil</a>
            <a href="<?= $baseUrl ?>/login/public/logout.php" class="button">Déconnexion</a>
        </p>
    </div>
</body>
</html>
