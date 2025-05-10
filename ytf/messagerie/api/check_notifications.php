<?php
// Désactiver l'output HTML des erreurs
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
header('Content-Type: application/json; charset=utf-8');
session_start();

// Authentication basique
if (empty($_SESSION['user_id']) || empty($_SESSION['user_type'])) {
  echo json_encode([
    'has_errors' => true,
    'error'      => 'Utilisateur non authentifié.'
  ]);
  exit;
}

// Inclure les fichiers nécessaires
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/message_functions.php';

try {
  // Récupérer l'ID et le type d'utilisateur
  $userId = (int)$_SESSION['user_id'];
  $userType = $_SESSION['user_type'];
  
  // Utiliser la nouvelle fonction pour compter les notifications non lues
  $count = countUnreadNotifications($userId, $userType);
  
  // Récupérer la dernière notification non lue pour les notifications du navigateur
  $latestNotification = null;
  if ($count > 0) {
    $unreadNotifications = getUnreadNotifications($userId, $userType, 1);
    if (!empty($unreadNotifications)) {
      $latestNotification = $unreadNotifications[0];
    }
  }
  
  // Préparer la réponse
  $response = [
    'has_errors'        => false,
    'new_notifications' => $count
  ];
  
  // Ajouter la dernière notification si disponible
  if ($latestNotification) {
    $response['latest_notification'] = [
      'id' => $latestNotification['id'],
      'conversation_id' => $latestNotification['conversation_id'],
      'expediteur_nom' => $latestNotification['expediteur_nom'],
      'conversation_titre' => $latestNotification['conversation_titre'],
      'notification_type' => $latestNotification['notification_type'],
      'status' => $latestNotification['status'],
      'date_creation' => $latestNotification['date_creation']
    ];
  }
  
  // Renvoyer la réponse JSON
  echo json_encode($response);
}
catch (Throwable $e) {
  error_log('[check_notifications] '.$e->getMessage());
  echo json_encode([
    'has_errors' => true,
    'error'      => 'Impossible de récupérer les notifications.'
  ]);
}