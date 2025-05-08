<?php
// 1. désactiver l’output HTML des erreurs
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
header('Content-Type: application/json; charset=utf-8');
session_start();

// 2. authentification basique
if (empty($_SESSION['user_id']) || empty($_SESSION['user_type'])) {
  echo json_encode([
    'has_errors' => true,
    'error'      => 'Utilisateur non authentifié.'
  ]);
  exit;
}

// 3. connexion PDO (ton config.php doit définir DSN, DB_USER, DB_PASS)
require_once __DIR__ . '/config.php';
$pdo = new PDO(DSN, DB_USER, DB_PASS, [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

try {
  // 4. compter les notifications non lues dans message_notifications
  $stmt = $pdo->prepare("
    SELECT COUNT(*) AS new_notifications
      FROM message_notifications
     WHERE user_id   = :uid
       AND user_type = :utype
       AND is_read   = 0
  ");
  $stmt->execute([
    'uid'   => (int)$_SESSION['user_id'],
    'utype' => $_SESSION['user_type'],
  ]);
  $row = $stmt->fetch();
  $count = isset($row['new_notifications']) ? (int)$row['new_notifications'] : 0;

  // 5. renvoyer le JSON
  echo json_encode([
    'has_errors'        => false,
    'new_notifications' => $count
  ]);
}
catch (Throwable $e) {
  error_log('[check_notifications] '.$e->getMessage());
  echo json_encode([
    'has_errors' => true,
    'error'      => 'Impossible de récupérer les notifications.'
  ]);
}