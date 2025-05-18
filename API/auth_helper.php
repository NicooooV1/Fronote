<?php
/**
 * Helper d'authentification pour Pronote
 * Utilitaire pour diagnostiquer les problèmes d'authentification
 */

// Vérifier si l'utilisateur est connecté
function checkAuthStatus() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $isLoggedIn = isset($_SESSION['user']) && !empty($_SESSION['user']);
    $user = $isLoggedIn ? $_SESSION['user'] : null;
    
    return [
        'logged_in' => $isLoggedIn,
        'user' => $user,
        'session_id' => session_id(),
        'session_name' => session_name(),
        'session_cookie_exists' => isset($_COOKIE[session_name()]),
        'session_path' => session_save_path()
    ];
}

// Fonction pour fixer la session si elle ne fonctionne pas
function repairSession() {
    // Vérifier si le cookie de session existe
    if (!isset($_COOKIE[session_name()])) {
        // Redémarrer la session avec des options plus permissives
        session_destroy();
        $params = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => $params['lifetime'],
            'path' => '/', // Utiliser une path racine
            'domain' => '', // Ne pas spécifier de domaine
            'secure' => false,
            'httponly' => true
        ]);
        session_start();
        return "Session redémarrée avec des options plus permissives";
    }
    
    return "Aucune réparation nécessaire";
}

// Vérification de la disponibilité des constantes
function checkAuthConstants() {
    $constants = [
        'APP_ROOT', 'API_DIR', 'BASE_URL', 'APP_URL',
        'LOGIN_URL', 'LOGOUT_URL', 'HOME_URL',
        'USER_TYPE_ADMIN', 'USER_TYPE_TEACHER', 'USER_TYPE_STUDENT',
        'USER_TYPE_PARENT', 'USER_TYPE_STAFF'
    ];
    
    $results = [];
    
    foreach ($constants as $const) {
        $results[$const] = defined($const) ? constant($const) : 'Non définie';
    }
    
    return $results;
}

// Si appelé directement, afficher le diagnostic
if (basename($_SERVER['SCRIPT_NAME']) === basename(__FILE__)) {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Diagnostic d\'authentification Pronote</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            h1 { color: #2c3e50; }
            h2 { color: #3498db; margin-top: 20px; }
            pre { background: #f8f9fa; padding: 10px; border-radius: 5px; overflow-x: auto; }
            .status { padding: 10px; margin: 10px 0; border-radius: 5px; }
            .success { background: #d4edda; color: #155724; }
            .error { background: #f8d7da; color: #721c24; }
            table { border-collapse: collapse; width: 100%; margin: 15px 0; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f2f2f2; }
            .fixed { background: #fff3cd; color: #856404; padding: 10px; border-radius: 5px; margin: 10px 0; }
            .actions { margin-top: 20px; }
            .actions a { display: inline-block; padding: 8px 16px; background: #3498db; color: white; text-decoration: none; border-radius: 4px; margin-right: 10px; }
            .actions a:hover { background: #2980b9; }
        </style>
    </head>
    <body>
        <h1>Diagnostic d\'authentification Pronote</h1>';
    
    // Vérifier le statut de l'authentification
    $status = checkAuthStatus();
    echo '<h2>Statut d\'authentification</h2>';
    
    if ($status['logged_in']) {
        echo '<div class="status success">
            <p>✅ <strong>Authentifié</strong> - Vous êtes connecté en tant que: ' . htmlspecialchars($status['user']['prenom'] . ' ' . $status['user']['nom']) . ' (' . htmlspecialchars($status['user']['profil']) . ')</p>
        </div>';
    } else {
        echo '<div class="status error">
            <p>❌ <strong>Non authentifié</strong> - Vous n\'êtes pas connecté.</p>
        </div>';
    }
    
    echo '<pre>' . print_r($status, true) . '</pre>';
    
    // Vérifier les constantes
    $constants = checkAuthConstants();
    echo '<h2>Constantes d\'authentification</h2>';
    echo '<table>
        <tr>
            <th>Constante</th>
            <th>Valeur</th>
        </tr>';
    
    foreach ($constants as $const => $value) {
        echo '<tr>
            <td>' . htmlspecialchars($const) . '</td>
            <td>' . htmlspecialchars($value) . '</td>
        </tr>';
    }
    
    echo '</table>';
    
    // Réparer la session si nécessaire
    if (!$status['logged_in'] && isset($_GET['repair'])) {
        $repairResult = repairSession();
        echo '<div class="fixed">
            <p><strong>Tentative de réparation:</strong> ' . htmlspecialchars($repairResult) . '</p>
            <p>Veuillez vous reconnecter pour tester si la réparation a fonctionné.</p>
        </div>';
    }
    
    echo '<div class="actions">
        <a href="?">Rafraîchir</a>';
    
    if (!$status['logged_in']) {
        echo '<a href="?repair=1">Réparer la session</a>';
    }
    
    $baseUrl = defined('BASE_URL') ? BASE_URL : '/~u22405372/SAE/Pronote';
    echo '<a href="' . htmlspecialchars($baseUrl) . '/login/public/index.php">Page de connexion</a>';
    echo '</div>';
    
    echo '</body></html>';
    exit;
}
