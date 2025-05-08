<?php
/**
 * check_notifications.php
 * Renvoie en JSON le nombre de notifications non lues pour l’utilisateur connecté.
 */

// 1. Désactivation de l’affichage HTML des erreurs PHP
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

// 2. Définition de l’en-tête JSON
header('Content-Type: application/json; charset=utf-8');

// 3. Démarrage de la session (vérifier que c’est bien le bon chemin)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 4. Vérification basique de l’authentification
if (empty($_SESSION['user_id'])) {
    // on renvoie quand même du JSON pour éviter le 500
    echo json_encode([
        'has_errors' => true,
        'error'      => 'Utilisateur non authentifié.'
    ]);
    exit;
}

try {
    // 5. Connexion à la base de données
    // Attention au chemin vers ton fichier de config
    require_once __DIR__ . '/config.php'; 
    $pdo = new PDO(DSN, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // 6. Requête pour compter les notifications non lues
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS new_notifications
        FROM notifications
        WHERE user_id = :uid
          AND is_read = 0
    ");
    $stmt->execute([
        'uid' => (int) $_SESSION['user_id']
    ]);
    $row = $stmt->fetch();

    // 7. Construction et envoi de la réponse JSON
    $count = isset($row['new_notifications']) ? (int) $row['new_notifications'] : 0;
    echo json_encode([
        'has_errors'        => false,
        'new_notifications' => $count
    ]);
    exit;
}
catch (Throwable $e) {
    // 8. Journalisation interne de l’erreur
    error_log('[check_notifications.php] Erreur : ' . $e->getMessage());

    // 9. Réponse JSON d’erreur (pas de HTML)
    echo json_encode([
        'has_errors' => true,
        'error'      => 'Impossible de récupérer les notifications.'
    ]);
    exit;
}