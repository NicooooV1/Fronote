<?php
/**
 * /api/delete_multiple.php - Suppression multiple de conversations
 */

 require_once __DIR__ . '/../config/config.php';
 require_once __DIR__ . '/../config/constants.php';
 require_once __DIR__ . '/../includes/functions.php';
 require_once __DIR__ . '/../includes/message_functions.php';
 require_once __DIR__ . '/../includes/auth.php';

// Vérifier l'authentification
$user = checkAuth();
if (!$user) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

// Récupérer les données JSON
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['ids']) || !is_array($data['ids']) || empty($data['ids'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Aucun identifiant fourni']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    // Supprimer définitivement plusieurs conversations
    $count = deleteMultipleConversations($data['ids'], $user['id'], $user['type']);
    
    $pdo->commit();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'count' => $count]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}