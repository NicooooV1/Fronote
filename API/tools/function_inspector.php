<?php
/**
 * Outil de diagnostic pour les fonctions d'authentification
 */

// Afficher les erreurs pour le diagnostic
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Démarrer la session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Lister les fichiers importants
$files = [
    'auth_central.php' => __DIR__ . '/../../API/auth_central.php',
    'auth_bridge.php' => __DIR__ . '/../../API/auth_bridge.php',
    'autoload.php' => __DIR__ . '/../../API/autoload.php',
    'bootstrap.php' => __DIR__ . '/../../API/bootstrap.php',
    'notes/includes/auth.php' => __DIR__ . '/../../notes/includes/auth.php',
    'absences/includes/auth.php' => __DIR__ . '/../../absences/includes/auth.php',
    'cahierdetextes/includes/auth.php' => __DIR__ . '/../../cahierdetextes/includes/auth.php',
];

// Fonctions à vérifier pour éviter les redéclarations
$functions_to_check = [
    'isLoggedIn',
    'isSessionExpired',
    'refreshAuthTime',
    'getCurrentUser',
    'getUserRole',
    'isAdmin',
    'isTeacher',
    'isStudent',
    'isParent',
    'isVieScolaire',
    'getUserFullName',
    'canManageNotes',
    'canManageAbsences',
    'canManageDevoirs',
    'requireLogin'
];

// Vérifier quelles fonctions sont déclarées
$function_declarations = [];
foreach ($functions_to_check as $function) {
    $function_declarations[$function] = [];
}

// Scanner chaque fichier pour les déclarations de fonctions
foreach ($files as $name => $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        
        foreach ($functions_to_check as $function) {
            // Rechercher les déclarations de fonction
            if (preg_match("/function\s+$function\s*\(/", $content)) {
                $function_declarations[$function][] = $name;
            }
        }
    }
}

// Afficher l'en-tête HTML
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnostic des fonctions d'authentification</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; }
        th { background-color: #f2f2f2; }
        .warning { color: #f39c12; }
        .error { color: #e74c3c; }
        .success { color: #2ecc71; }
    </style>
</head>
<body>
    <h1>Diagnostic des fonctions d'authentification</h1>
    
    <h2>Déclarations de fonctions par fichier</h2>
    <table>
        <tr>
            <th>Fonction</th>
            <th>Déclarée dans</th>
            <th>Statut</th>
        </tr>
        <?php foreach ($function_declarations as $function => $files_declared): ?>
        <tr>
            <td><?= htmlspecialchars($function) ?></td>
            <td>
                <?php if (empty($files_declared)): ?>
                Non déclarée
                <?php else: ?>
                <?= htmlspecialchars(implode(', ', $files_declared)) ?>
                <?php endif; ?>
            </td>
            <td>
                <?php if (empty($files_declared)): ?>
                <span class="warning">Non trouvée</span>
                <?php elseif (count($files_declared) > 1): ?>
                <span class="error">Redéclarée <?= count($files_declared) ?> fois</span>
                <?php else: ?>
                <span class="success">OK</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <h2>Solutions recommandées</h2>
    <ol>
        <li>Assurez-vous que <code>auth_central.php</code> est le seul fichier qui déclare les fonctions d'authentification principales.</li>
        <li>Modifiez <code>auth_bridge.php</code> pour qu'il ne déclare que des fonctions d'alias ou de compatibilité.</li>
        <li>Vérifiez que les modules utilisent <code>require_once</code> pour inclure le système d'authentification central.</li>
        <li>Si nécessaire, utilisez <code>function_exists()</code> pour éviter les redéclarations.</li>
    </ol>
    
    <h2>État actuel des fonctions</h2>
    <p>
        <?php
        foreach ($functions_to_check as $function) {
            echo htmlspecialchars($function) . ': ';
            echo function_exists($function) ? '<span class="success">Disponible</span>' : '<span class="error">Non disponible</span>';
            echo '<br>';
        }
        ?>
    </p>
</body>
</html>
