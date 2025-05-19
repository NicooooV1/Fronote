<?php
/**
 * Script de débogage pour identifier les problèmes d'authentification et de redirection
 */

// Démarrer ou reprendre une session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Fonctions de débogage
function debugInfo($label, $data) {
    echo "<h3>$label</h3>";
    echo "<pre>";
    print_r($data);
    echo "</pre>";
    echo "<hr>";
}

// Créer un répertoire de logs si nécessaire
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

// Journaliser les informations de débogage
$log = date('Y-m-d H:i:s') . " - Debug page accessed\n";
$log .= "SESSION: " . print_r($_SESSION, true) . "\n";
$log .= "COOKIES: " . print_r($_COOKIE, true) . "\n";
$log .= "SERVER: " . print_r($_SERVER, true) . "\n";
$log .= "-------------------------------\n";

file_put_contents($logDir . '/debug_' . date('Y-m-d') . '.log', $log, FILE_APPEND);

// En-tête HTML
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Débogage Pronote</title>
    <link rel="stylesheet" href="assets/css/pronote-style.css">
    <style>
        body { padding: 20px; display: block; }
        h1, h2 { color: #009b72; }
        h3 { color: #444; background: #f1f1f1; padding: 10px; }
        pre { background: #f9f9f9; padding: 10px; overflow: auto; border: 1px solid #ddd; }
        hr { border: 0; border-top: 1px solid #eee; margin: 20px 0; }
        .action { margin: 20px 0; }
    </style>
</head>
<body>
    <h1>Page de débogage Pronote</h1>
    <p>Cette page affiche des informations techniques pour aider à résoudre les problèmes d'authentification et de redirection.</p>
    
    <div class="action">
        <a href="../public/index.php" class="btn-connect">Page de connexion</a>
        <a href="../../accueil/accueil.php" class="btn-connect">Page d'accueil</a>
        <?php if (isset($_SESSION['user'])): ?>
            <a href="../public/logout.php" class="btn-cancel">Déconnexion</a>
        <?php endif; ?>
    </div>
    
    <h2>État de la session</h2>
    <?php if (isset($_SESSION['user'])): ?>
        <p>✅ Utilisateur <strong><?= htmlspecialchars($_SESSION['user']['prenom'] . ' ' . $_SESSION['user']['nom']) ?></strong> 
           connecté en tant que <strong><?= htmlspecialchars($_SESSION['user']['profil']) ?></strong>.</p>
    <?php else: ?>
        <p class="warning">⚠️ Aucun utilisateur n'est connecté.</p>
    <?php endif; ?>
    
    <h2>Informations de débogage</h2>
    
    <?php
    // Afficher les informations de la session
    debugInfo("Détails de la SESSION", $_SESSION);
    
    // Afficher les cookies
    debugInfo("COOKIES", $_COOKIE);
    
    // Afficher quelques variables importantes du serveur
    $serverInfo = [
        'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? 'N/A',
        'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? 'N/A',
        'PHP_SELF' => $_SERVER['PHP_SELF'] ?? 'N/A',
        'HTTP_REFERER' => $_SERVER['HTTP_REFERER'] ?? 'N/A',
        'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? 'N/A',
        'HTTP_USER_AGENT' => $_SERVER['HTTP_USER_AGENT'] ?? 'N/A'
    ];
    debugInfo("Informations SERVER (sélection)", $serverInfo);
    
    // Vérifier les définitions de constantes importantes
    $constants = [
        'APP_ROOT' => defined('APP_ROOT') ? APP_ROOT : 'Non définie',
        'BASE_URL' => defined('BASE_URL') ? BASE_URL : 'Non définie',
        'APP_URL' => defined('APP_URL') ? APP_URL : 'Non définie',
        'LOGIN_URL' => defined('LOGIN_URL') ? LOGIN_URL : 'Non définie',
        'HOME_URL' => defined('HOME_URL') ? HOME_URL : 'Non définie'
    ];
    debugInfo("Constantes importantes", $constants);
    ?>
</body>
</html>
