<?php
header('Content-Type: application/json');
session_start();
include '../../config.php'; 
require_once '../../login/src/auth.php'; 

// Vérification de l'authentification
$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

// Récupérer le profil de l'utilisateur
$userProfile = $_SESSION['user']['profil'];
$userId = $_SESSION['user']['id'];

$method = $_SERVER['REQUEST_METHOD'];
$path = explode('/', trim($_SERVER['PATH_INFO'] ?? '', '/'));
$id = $path[0] ?? null;

if ($method === 'GET') {
    if ($userProfile === 'eleve') {
        // Un élève ne voit que ses propres statuts
        $stmt = $pdo->prepare('
            SELECT ds.*, d.titre, d.matiere, d.classe, d.date_remise 
            FROM devoirs_status ds
            JOIN devoirs d ON ds.id_devoir = d.id
            WHERE ds.id_eleve = ?
        ');
        $stmt->execute([$userId]);
    } elseif ($userProfile === 'professeur') {
        // Un professeur voit les statuts des élèves pour ses devoirs
        if (isset($_GET['id_devoir'])) {
            $stmt = $pdo->prepare('
                SELECT ds.*, e.nom, e.prenom 
                FROM devoirs_status ds
                JOIN eleves e ON ds.id_eleve = e.id
                JOIN devoirs d ON ds.id_devoir = d.id
                WHERE d.id = ? AND d.id_professeur = ?
            ');
            $stmt->execute([$_GET['id_devoir'], $userId]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Paramètre id_devoir requis']);
            exit;
        }
    } elseif ($userProfile === 'administrateur') {
        // Un administrateur peut tout voir
        if (isset($_GET['id_devoir'])) {
            $stmt = $pdo->prepare('
                SELECT ds.*, e.nom, e.prenom 
                FROM devoirs_status ds
                JOIN eleves e ON ds.id_eleve = e.id
                WHERE ds.id_devoir = ?
            ');
            $stmt->execute([$_GET['id_devoir']]);
        } else {
            $stmt = $pdo->prepare('
                SELECT ds.*, e.nom, e.prenom, d.titre 
                FROM devoirs_status ds
                JOIN eleves e ON ds.id_eleve = e.id
                JOIN devoirs d ON ds.id_devoir = d.id
                ORDER BY ds.date_derniere_modif DESC
                LIMIT 100
            ');
            $stmt->execute();
        }
    } else {
        http_response_code(403);
        echo json_encode(['error' => 'Accès non autorisé']);
        exit;
    }
    
    echo json_encode($stmt->fetchAll());
}

if ($method === 'POST' || $method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['id_devoir'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Paramètre id_devoir requis']);
        exit;
    }
    
    // Un élève ne peut mettre à jour que son propre statut
    if ($userProfile === 'eleve') {
        $id_eleve = $userId;
    } else {
        http_response_code(403);
        echo json_encode(['error' => 'Seuls les élèves peuvent mettre à jour leur statut']);
        exit;
    }
    
    // Vérifier si l'entrée existe déjà
    $stmt = $pdo->prepare('SELECT id FROM devoirs_status WHERE id_devoir = ? AND id_eleve = ?');
    $stmt->execute([$data['id_devoir'], $id_eleve]);
    $existingStatus = $stmt->fetch();
    
    if ($existingStatus) {
        // Mise à jour
        $stmt = $pdo->prepare('UPDATE devoirs_status SET status = ? WHERE id_devoir = ? AND id_eleve = ?');
        $result = $stmt->execute([$data['status'], $data['id_devoir'], $id_eleve]);
        
        if ($result) {
            echo json_encode(['status' => 'updated']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de la mise à jour']);
        }
    } else {
        // Création
        $stmt = $pdo->prepare('INSERT INTO devoirs_status (id_devoir, id_eleve, status) VALUES (?, ?, ?)');
        $result = $stmt->execute([$data['id_devoir'], $id_eleve, $data['status']]);
        
        if ($result) {
            echo json_encode(['id' => $pdo->lastInsertId(), 'status' => 'created']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de la création']);
        }
    }
}
?>