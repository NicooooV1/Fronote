<?php
/**
 * /api/get_participants.php - Récupération des participants disponibles
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
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

$type = isset($_GET['type']) ? $_GET['type'] : '';
$convId = isset($_GET['conv_id']) ? (int)$_GET['conv_id'] : 0;

if (empty($type) || empty($convId)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Paramètres manquants']);
    exit;
}

// Vérifier que l'utilisateur est participant à la conversation
$participantCheck = $pdo->prepare("
    SELECT id FROM conversation_participants
    WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
");
$participantCheck->execute([$convId, $user['id'], $user['type']]);
if (!$participantCheck->fetch()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Droits insuffisants']);
    exit;
}

// Récupérer la liste des participants déjà dans la conversation
$currentParticipants = [];
$stmt = $pdo->prepare("
    SELECT user_id FROM conversation_participants 
    WHERE conversation_id = ? AND user_type = ? AND is_deleted = 0
");
$stmt->execute([$convId, $type]);
$currentParticipants = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Récupérer la liste des participants potentiels
$participants = [];
$table = '';
$champs = '';

switch ($type) {
    case 'eleve':
        $table = 'eleves';
        $champs = "id, CONCAT(prenom, ' ', nom, ' (', classe, ')') as nom_complet";
        break;
    case 'parent':
        $table = 'parents';
        $champs = "id, CONCAT(prenom, ' ', nom) as nom_complet";
        break;
    case 'professeur':
        $table = 'professeurs';
        $champs = "id, CONCAT(prenom, ' ', nom, ' (', matiere, ')') as nom_complet";
        break;
    case 'vie_scolaire':
        $table = 'vie_scolaire';
        $champs = "id, CONCAT(prenom, ' ', nom) as nom_complet";
        break;
    case 'administrateur':
        $table = 'administrateurs';
        $champs = "id, CONCAT(prenom, ' ', nom) as nom_complet";
        break;
}

if (!empty($table)) {
    $sql = "SELECT $champs FROM $table";
    
    if (!empty($currentParticipants)) {
        $placeholders = implode(',', array_fill(0, count($currentParticipants), '?'));
        $sql .= " WHERE id NOT IN ($placeholders)";
    }
    
    $sql .= " ORDER BY nom";
    
    $stmt = $pdo->prepare($sql);
    
    if (!empty($currentParticipants)) {
        $stmt->execute($currentParticipants);
    } else {
        $stmt->execute();
    }
    
    $participants = $stmt->fetchAll();
}

header('Content-Type: application/json');
echo json_encode($participants);