<?php
/**
 * Page de diagnostic pour Pronote - RÉSERVÉE AUX ADMINISTRATEURS
 * Cette page permet de diagnostiquer les problèmes de configuration, de permissions et de redirections
 */

// Démarrer la session pour la vérification d'authentification
if (session_status() === PHP_SESSION_NONE) {
    // Configuration sécurisée des cookies de session
    ini_set('session.cookie_httponly', 1);
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', 1);
    }
    session_start();
}

// Vérifier si l'utilisateur est administrateur avant même de charger d'autres fichiers
if (!isset($_SESSION['user']) || !isset($_SESSION['user']['profil']) || $_SESSION['user']['profil'] !== 'administrateur') {
    // Rediriger vers la page de connexion ou afficher un message d'erreur
    http_response_code(403); // Forbidden
    echo '<!DOCTYPE html><html><head><title>Accès refusé</title><meta charset="UTF-8"></head>';
    echo '<body><h1>Accès refusé</h1><p>Seuls les administrateurs peuvent accéder à cette page.</p>';
    echo '<p><a href="login/public/index.php">Connexion</a></p></body></html>';
    exit;
}

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
            $testFile = $directory . '/test_' . md5(uniqid(mt_rand(), true)) . '.txt';
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
        if (!function_exists('curl_init')) {
            throw new \Exception('cURL n\'est pas disponible. Utilisez la vérification manuelle.');
        }
        
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \Exception('Impossible d\'initialiser cURL');
        }
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Pronote-Diagnostic/1.0');
        
        // Ajouter un cookie de session pour les pages qui nécessitent l'authentification
        if (isset($_COOKIE[session_name()])) {
            curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . $_COOKIE[session_name()]);
        }
        
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

// Fonction pour masquer les informations sensibles
function sanitizeSensitiveData($content) {
    // Masquer les mots de passe
    $content = preg_replace('/([\'"]DB_PASS[\'"]\s*,\s*[\'"]).*?([\'"])/', '$1********$2', $content);
    $content = preg_replace('/(\$db_pass\s*=\s*[\'"]).*?([\'"])/', '$1********$2', $content);
    
    // Masquer les jetons de sécurité
    $content = preg_replace('/(csrf_token|token|secret|key)\s*=\s*[\'"].*?[\'"]/', '$1="********"', $content);
    
    // Masquer les identifiants de session
    $content = preg_replace('/PHPSESSID=([a-zA-Z0-9]{3}).*?;/', 'PHPSESSID=$1***;', $content);
    
    // Masquer les chemins du système de fichiers
    $content = preg_replace('/(\/[\/\w\.-]+)+/', '[CHEMIN]', $content);
    
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
    if (!isset($_SESSION['diag_token']) || empty($_SESSION['diag_token'])) {
        try {
            $_SESSION['diag_token'] = bin2hex(random_bytes(32));
        } catch (\Exception $e) {
            // Fallback si random_bytes n'est pas disponible
            $_SESSION['diag_token'] = md5(uniqid(mt_rand(), true));
        }
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
    
    $results['session.gc_maxlifetime'] = [
        'name' => 'session.gc_maxlifetime',
        'value' => ini_get('session.gc_maxlifetime'),
        'recommendation' => '≥ 1800 (30 minutes)',
        'status' => (ini_get('session.gc_maxlifetime') >= 1800) ? 'success' : 'warning'
    ];
    
    $results['expose_php'] = [
        'name' => 'expose_php',
        'value' => ini_get('expose_php'),
        'recommendation' => 'Off en production',
        'status' => ini_get('expose_php') ? 'warning' : 'success'
    ];
    
    return $results;
}

// Charger le système d'autoloading si disponible
if (file_exists(__DIR__ . '/API/autoload.php')) {
    require_once __DIR__ . '/API/autoload.php';
} else {
    // Charger manuellement les fichiers nécessaires
    if (file_exists(__DIR__ . '/API/config/config.php')) {
        require_once __DIR__ . '/API/config/config.php';
    }
}

// Génération d'un token pour la protection du formulaire de diagnostic
$diagToken = generateDiagToken();

// Répertoires à tester - Utiliser des chemins relatifs pour plus de portabilité
$directories = [
    'API' => __DIR__ . '/API',
    'API/logs' => __DIR__ . '/API/logs',
    'API/config' => __DIR__ . '/API/config',
    'uploads' => __DIR__ . '/uploads',
    'login/logs' => __DIR__ . '/login/logs',
    'temp' => __DIR__ . '/temp'
];

// Déterminer dynamiquement l'URL de base
$baseUrl = '';
if (!empty($_SERVER['HTTP_HOST']) && !empty($_SERVER['SCRIPT_NAME'])) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'];
    $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
    if ($scriptPath !== '/' && $scriptPath !== '\\') {
        $baseUrl .= $scriptPath;
    }
}

// Définir l'URL de base si elle est déjà configurée
if (defined('BASE_URL')) {
    $baseUrl = BASE_URL;
}

// Pages à tester
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
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$applicationPath = dirname($scriptPath);

// Détection des configurations importantes
$configFiles = [
    'API/config/env.php' => __DIR__ . '/API/config/env.php',
    'API/config/config.php' => __DIR__ . '/API/config/config.php'
];

// Lire le contenu des fichiers de configuration de manière sécurisée
$configContents = [];
foreach ($configFiles as $name => $path) {
    if (file_exists($path) && is_readable($path)) {
        // Charger le contenu mais masquer les informations sensibles
        $content = file_get_contents($path);
        $content = sanitizeSensitiveData($content);
        $configContents[$name] = $content;
    } else {
        $configContents[$name] = 'Fichier non trouvé ou inaccessible';
    }
}

// Informations de session sécurisées
$sessionInfo = [
    'session_id' => '********' . (session_id() ? substr(session_id(), -4) : '****'),
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
        $dbStatus['connected'] = ($pdo instanceof \PDO);
        
        // Vérifier les tables critiques
        if ($dbStatus['connected']) {
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
                } catch (\PDOException $e) {
                    $dbStatus['tables'][$table] = [
                        'exists' => false,
                        'count' => 'N/A',
                        'error' => $e->getMessage()
                    ];
                }
            }
        }
    }
} catch (\Exception $e) {
    $dbStatus = [
        'connected' => false,
        'error' => $e->getMessage()
    ];
}

// Recherche de problèmes de sécurité courants dans les fichiers PHP
$securityIssues = [];

function scanDirectoryForSecurityIssues($dir, &$issues) {
    if (!is_dir($dir)) return;
    
    $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
    );
    
    $patterns = [
        'eval' => '/eval\s*\(/i',
        'shell_exec' => '/shell_exec\s*\(/i',
        'exec' => '/\bexec\s*\(/i',
        'system' => '/\bsystem\s*\(/i',
        'include_user_input' => '/include\s*\(\s*\$_/i',
        'require_user_input' => '/require\s*\(\s*\$_/i',
        'sql_injection' => '/\$sql\s*=.*\$_/i',
        'xss_output' => '/echo\s+\$_/i',
        'debug_info' => '/var_dump\s*\(/i',
        'hardcoded_credentials' => '/password\s*=\s*[\'"][^\'"]+[\'"]/i',
    ];
    
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() == 'php') {
            $content = file_get_contents($file->getPathname());
            
            foreach ($patterns as $type => $pattern) {
                if (preg_match($pattern, $content, $matches)) {
                    $issues[] = [
                        'file' => sanitizePath($file->getPathname()),
                        'type' => $type,
                        'description' => "Problème de sécurité potentiel détecté: $type"
                    ];
                }
            }
        }
    }
}

// Ne pas scanner si le système est trop lent ou si le scan a déjà été fait
$scanPerformed = false;
$maxScanTime = 5; // secondes maximum pour le scan

if (!isset($_SESSION['security_scan_done']) && !isset($_GET['skip_scan'])) {
    $startTime = microtime(true);
    set_time_limit(30); // Augmenter la limite de temps d'exécution
    
    try {
        // Limiter le scan à des répertoires spécifiques pour éviter de scanner tout
        $dirsToScan = [
            __DIR__ . '/API',
            __DIR__ . '/login',
            __DIR__ . '/notes'
        ];
        
        foreach ($dirsToScan as $dir) {
            if (is_dir($dir)) {
                scanDirectoryForSecurityIssues($dir, $securityIssues);
            }
            
            // Arrêter si ça prend trop de temps
            if ((microtime(true) - $startTime) > $maxScanTime) {
                $securityIssues[] = [
                    'file' => 'Scan incomplet',
                    'type' => 'timeout',
                    'description' => "Le scan a été interrompu car il prenait trop de temps."
                ];
                break;
            }
        }
        
        $scanPerformed = true;
        $_SESSION['security_scan_done'] = true;
    } catch (\Exception $e) {
        $securityIssues[] = [
            'file' => 'Erreur de scan',
            'type' => 'error',
            'description' => "Erreur lors du scan: " . $e->getMessage()
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnostic Pronote - Administration</title>
    <meta name="robots" content="noindex, nofollow">
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
            font-size: 13px;
            white-space: pre-wrap;
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
            border: none;
            cursor: pointer;
        }
        .button:hover {
            background: #2980b9;
        }
        .security-success {
            color: #2ecc71;
        }
        .security-warning {
            color: #f39c12;
        }
        .security-error {
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
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 1px solid #ddd;
        }
        .tab {
            padding: 10px 20px;
            cursor: pointer;
        }
        .tab.active {
            border-bottom: 3px solid #3498db;
            font-weight: bold;
        }
        .security-issue-high {
            background-color: #ffecec;
        }
        .security-issue-medium {
            background-color: #fff8e1;
        }
        .security-issue-low {
            background-color: #e3f2fd;
        }
    </style>
</head>
<body>
    <div class="section">
        <h1>Diagnostic Pronote - Administration</h1>
        <p>Cette page est réservée aux administrateurs pour diagnostiquer les problèmes de configuration, de permissions et de redirections dans l'application Pronote.</p>
        <div class="tabs">
            <div class="tab active" data-tab="general">Général</div>
            <div class="tab" data-tab="permissions">Permissions</div>
            <div class="tab" data-tab="database">Base de données</div>
            <div class="tab" data-tab="security">Sécurité</div>
            <div class="tab" data-tab="config">Configuration</div>
        </div>
    </div>
    
    <div id="general" class="tab-content active">
        <div class="section">
            <h2>Informations de base</h2>
            <ul>
                <li><strong>Chemin d'application détecté:</strong> <?= htmlspecialchars(sanitizePath($applicationPath)) ?></li>
                <li><strong>Répertoire courant:</strong> <?= htmlspecialchars(sanitizePath(__DIR__)) ?></li>
                <li><strong>URL de base:</strong> <?= htmlspecialchars($baseUrl) ?></li>
                <li><strong>PHP Version:</strong> <?= htmlspecialchars(PHP_VERSION) ?></li>
                <li><strong>Serveur Web:</strong> <?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Inconnu') ?></li>
                <li><strong>Date de diagnostic:</strong> <?= date('Y-m-d H:i:s') ?></li>
            </ul>
        </div>
        
        <div class="section">
            <h2>Accessibilité des pages</h2>
            <table>
                <tr>
                    <th>Page</th>
                    <th>URL</th>
                    <th>Code HTTP</th>
                    <th>Accessible</th>
                    <th>Erreur</th>
                </tr>
                <?php foreach ($pages as $name => $url): ?>
                    <?php $access = testPageAccess($url); ?>
                    <tr>
                        <td><?= htmlspecialchars($name) ?></td>
                        <td><a href="<?= htmlspecialchars($url) ?>" target="_blank"><?= htmlspecialchars($url) ?></a></td>
                        <td><?= $access['code'] ?></td>
                        <td class="<?= $access['accessible'] ? 'success' : 'error' ?>"><?= $access['accessible'] ? 'Oui' : 'Non' ?></td>
                        <td class="error"><?= htmlspecialchars($access['error'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
        
        <div class="section">
            <h2>Variables du serveur</h2>
            <pre><?= htmlspecialchars(print_r([
                'SERVER_NAME' => $_SERVER['SERVER_NAME'] ?? 'Non défini',
                'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? 'Non défini',