<?php
// Désactivation de l’affichage des erreurs PHP au format HTML
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

// Forcer l’en-tête JSON
header('Content-Type: application/json; charset=utf-8');

// Démarrage de la session pour récupérer l’ID utilisateur
session_start();

try {
    // Connexion à la base (à adapter selon ta config)
    require_once __DIR__ . '/config.php'; // définit DSN, DB_USER, DB_PASS
    $pdo = new PDO(DSN, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Récupérer le nombre de notifications non lues pour l’utilisateur courant
    $stmt = $pdo->prepare('
        SELECT COUNT(*) AS new_notifications
        FROM notifications
        WHERE user_id = :uid
          AND is_read = 0
    ');
    $stmt->execute(['uid' => $_SESSION['user_id']]);
    $row = $stmt->fetch();
    $count = isset($row['new_notifications']) ? (int)$row['new_notifications'] : 0;

    // Réponse JSON
    echo json_encode([
        'has_errors'         => false,
        'new_notifications'  => $count
    ]);
    exit;
}
catch (Exception $e) {
    // Log interne de l’erreur
    error_log('Erreur check_notifications.php : '.$e->getMessage());

    // Réponse JSON d’erreur
    echo json_encode([
        'has_errors' => true,
        'error'      => 'Une erreur est survenue lors de la récupération des notifications.'
    ]);
    exit;
}
