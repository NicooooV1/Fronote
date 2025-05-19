<?php
/**
 * Page de diagnostic pour Pronote - RÉSERVÉE AUX ADMINISTRATEURS
 * Cette page permet de diagnostiquer les problèmes de configuration, de permissions et de redirections
 */

// Démarrer la session pour la vérification d'authentification
session_start();

// Vérifier si l'utilisateur est administrateur avant même de charger d'autres fichiers
if (!isset($_SESSION['user']) || !isset($_SESSION['user']['profil']) || $_SESSION['user']['profil'] !== 'administrateur') {
    // Rediriger vers la page de connexion ou afficher un message d'erreur
    http_response_code(403); // Forbidden
    echo '<!DOCTYPE html><html><head><title>Accès refusé</title><meta charset="UTF-8"></head>';
    echo '<body><h1>Accès refusé</h1><p>Seuls les administrateurs peuvent accéder à cette page.</p>';
    echo '<p><a href="login/public/index.php">Connexion</a></p></body></html>';
    exit;
}

// Fonction pour masquer les informations sensibles
function sanitizeSensitiveData($content) {
    // Masquer les mots de passe
    $content = preg_replace('/([\'"]DB_PASS[\'"]\s*,\s*[\'"]).*?([\'"])/', '$1********$2', $content);
    $content = preg_replace('/(\$db_pass\s*=\s*[\'"]).*?([\'"])/', '$1********$2', $content);
    
    // Masquer les jetons de sécurité
    $content = preg_replace('/(csrf_token|token|secret|key)\s*=\s*[\'"].*?[\'"]/', '$1="********"', $content);
    
    // Masquer les identifiants de session
    $content = preg_replace('/PHPSESSID=([a-zA-Z0-9]{3}).*?;/', 'PHPSESSID=$1***;', $content);
    
    return $content;
}

// Fonction pour masquer les chemins absolus dans les messages d'erreur
function sanitizePath($path) {
    if (defined('APP_ROOT')) {
        // Remplacer le chemin absolu par un chemin relatif
        return str_replace(APP_ROOT, '[APP_ROOT]', $path);
    } else {
        // Masquer au moins le début du chemin absolu
        $parts = explode(DIRECTORY_SEPARATOR, $path);
        
        // Ne garder que les 2 dernières parties du chemin pour anonymiser
        if (count($parts) > 2) {
            return '...' . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, array_slice($parts, -2));
        }
    }
    
    return $path;
}

// Fonction pour générer un token anti-CSRF
function generateDiagToken() {
    if (!isset($_SESSION['diag_token'])) {
        $_SESSION['diag_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['diag_token'];
}

// Fonction pour tester la sécurité de configuration PHP
function testPhpSecurity() {
    $results = [];
    
    // Vérifier les directives de configuration importantes
    $results['display_errors'] = [
        'name' => 'display_errors',
        'value' => ini_get('display_errors'),
        'recommendation' => 'Off en production',
        'status' => ini_get('display_errors') ? 'warning' : 'success'
    ];
    
    $results['session.use_strict_mode'] = [
        'name' => 'session.use_strict_mode',
        'value' => ini_get('session.use_strict_mode'),
        'recommendation' => '1',
        'status' => ini_get('session.use_strict_mode') ? 'success' : 'error'
    ];
    
    $results['session.cookie_secure'] = [
        'name' => 'session.cookie_secure',
        'value' => ini_get('session.cookie_secure'),
        'recommendation' => '1 si HTTPS',
        'status' => ini_get('session.cookie_secure') ? 'success' : 'warning'
    ];
    
    $results['session.cookie_httponly'] = [
        'name' => 'session.cookie_httponly',
        'value' => ini_get('session.cookie_httponly'),
        'recommendation' => '1',
        'status' => ini_get('session.cookie_httponly') ? 'success' : 'error'
    ];
    
    return $results;
}

// Chargement du système d'authentification centralisé si possible
require_once __DIR__ . '/API/autoload.php';

// Génération d'un token pour la protection du formulaire de diagnostic
$diagToken = generateDiagToken();

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
        $content = sanitizeSensitiveData($content);
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

// Tests de sécurité PHP
$phpSecurity = testPhpSecurity();

// Vérifier la structure des tables essentielles
try {
    $dbStatus = ['connected' => false, 'tables' => []];
    
    if (function_exists('getDBConnection')) {
        $pdo = getDBConnection();
        $dbStatus['connected'] = true;
        
        // Vérifier les tables critiques
        $criticalTables = [
            'administrateurs', 'eleves', 'professeurs', 'vie_scolaire',
            'notes', 'absences', 'evenements', 'messages'
        ];
        
        foreach ($criticalTables as $table) {
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
                $exists = $stmt && $stmt->rowCount() > 0;
                
                if ($exists) {
                    $countStmt = $pdo->query("SELECT COUNT(*) FROM `$table`");
                    $count = $countStmt ? $countStmt->fetchColumn() : '?';
                } else {
                    $count = 'N/A';
                }
                
                $dbStatus['tables'][$table] = [
                    'exists' => $exists,
                    'count' => $count
                ];
            } catch (PDOException $e) {
                $dbStatus['tables'][$table] = [
                    'exists' => false,
                    'count' => 'N/A',
                    'error' => $e->getMessage()
                ];
            }
        }
    }
} catch (Exception $e) {
    $dbStatus = [
        'connected' => false,
        'error' => $e->getMessage()
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
        .security-good {
            color: #2ecc71;
        }
        .security-warning {
            color: #f39c12;
        }
        .security-bad {
            color: #e74c3c;
        }
        .copy-button {
            padding: 3px 8px;
            background: #7f8c8d;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
            margin-left: 10px;
        }
        .copy-button:hover {
            background: #95a5a6;
        }
    </style>
</head>
<body>
    <div class="section">
        <h1>Diagnostic Pronote - Administration</h1>
        <p>Cette page est réservée aux administrateurs pour diagnostiquer les problèmes de configuration, de permissions et de redirections dans l'application Pronote.</p>
    </div>
    
    <div class="section">
        <h2>Sécurité PHP</h2>
        <table>
            <tr>
                <th>Directive</th>
                <th>Valeur actuelle</th>
                <th>Recommandation</th>
                <th>Statut</th>
            </tr>
            <?php foreach ($phpSecurity as $setting): ?>
                <tr>
                    <td><?= htmlspecialchars($setting['name']) ?></td>
                    <td><?= htmlspecialchars($setting['value']) ?></td>
                    <td><?= htmlspecialchars($setting['recommendation']) ?></td>
                    <td class="security-<?= $setting['status'] ?>"><?= ucfirst($setting['status']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    
    <div class="section">
        <h2>État de la Base de Données</h2>
        <?php if ($dbStatus['connected']): ?>
            <p class="success">✓ Connexion à la base de données réussie</p>
            <h3>Tables critiques</h3>
            <table>
                <tr>
                    <th>Table</th>
                    <th>Existe</th>
                    <th>Nombre d'entrées</th>
                </tr>
                <?php foreach ($dbStatus['tables'] as $tableName => $tableInfo): ?>
                    <tr>
                        <td><?= htmlspecialchars($tableName) ?></td>
                        <td class="<?= $tableInfo['exists'] ? 'success' : 'error' ?>"><?= $tableInfo['exists'] ? 'Oui' : 'Non' ?></td>
                        <td><?= htmlspecialchars($tableInfo['count']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="error">✗ Impossible de se connecter à la base de données</p>
            <?php if (isset($dbStatus['error'])): ?>
                <p>Erreur: <?= htmlspecialchars($dbStatus['error']) ?></p>
            <?php endif; ?>
        <?php endif; ?>
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
    
    <div class="section">
        <h2>Rapport de diagnostic</h2>
        <p>Vous pouvez générer un rapport de diagnostic complet pour le support technique. Ce rapport ne contient pas d'informations sensibles comme les mots de passe.</p>
        <form id="diagnostic-form" method="post" action="generate_report.php">
            <input type="hidden" name="diag_token" value="<?= htmlspecialchars($diagToken) ?>">
            <button type="submit" class="button">Générer un rapport de diagnostic</button>
        </form>
    </div>
</body>
</html>
