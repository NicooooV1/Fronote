<?php
// api/devoir_status/index.php - API for homework status management
header('Content-Type: application/json');
require_once '../../config.php';
require_once __DIR__ . '/../../../../API/auth.php';

// Authentication check
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Get user profile information
$userProfile = getUserRole();
$userId = getUserId();

$method = $_SERVER['REQUEST_METHOD'];
$path = explode('/', trim($_SERVER['PATH_INFO'] ?? '', '/'));
$id = $path[0] ?? null;

if ($method === 'GET') {
    if ($userProfile === 'eleve') {
        // Un élève ne voit que ses propres statuts
        $sql = '
            SELECT ds.*, d.titre, d.matiere, d.classe, d.date_remise 
            FROM devoirs_status ds
            JOIN devoirs d ON ds.id_devoir = d.id
            WHERE ds.id_eleve = ?
        ';
        $params = [$userId];
        
        // Filtrage par devoir si spécifié
        if (isset($_GET['id_devoir'])) {
            $sql .= ' AND ds.id_devoir = ?';
            $params[] = $_GET['id_devoir'];
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
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
            echo json_encode($stmt->fetchAll());
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Paramètre id_devoir requis']);
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
        echo json_encode($stmt->fetchAll());
    } else {
        http_response_code(403);
        echo json_encode(['error' => 'Accès non autorisé']);
    }
}

if ($method === 'POST' || $method === 'PUT') {
    // Accepter JSON ou form data
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    if (strpos($contentType, 'application/json') !== false) {
        $data = json_decode(file_get_contents('php://input'), true);
    } else {
        $data = $_POST;
    }
    
    if (empty($data['id_devoir'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Paramètre id_devoir requis']);
        exit;
    }
    
    if (empty($data['status']) || !in_array($data['status'], ['non_fait', 'en_cours', 'termine'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Statut invalide']);
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
    
    // Vérifier si le devoir existe
    $stmt = $pdo->prepare('SELECT id FROM devoirs WHERE id = ?');
    $stmt->execute([$data['id_devoir']]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Devoir non trouvé']);
        exit;
    }
    
    // Vérifier si l'entrée existe déjà
    $stmt = $pdo->prepare('SELECT id FROM devoirs_status WHERE id_devoir = ? AND id_eleve = ?');
    $stmt->execute([$data['id_devoir'], $id_eleve]);
    $existingStatus = $stmt->fetch();
    
    if ($existingStatus) {
        // Mise à jour
        $stmt = $pdo->prepare('UPDATE devoirs_status SET status = ?, date_derniere_modif = NOW() WHERE id_devoir = ? AND id_eleve = ?');
        $result = $stmt->execute([$data['status'], $data['id_devoir'], $id_eleve]);
        
        if ($result) {
            echo json_encode(['status' => 'updated']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de la mise à jour']);
        }
    } else {
        // Création
        $stmt = $pdo->prepare('INSERT INTO devoirs_status (id_devoir, id_eleve, status, date_derniere_modif) VALUES (?, ?, ?, NOW())');
        $result = $stmt->execute([$data['id_devoir'], $id_eleve, $data['status']]);
        
        if ($result) {
            echo json_encode(['id' => $pdo->lastInsertId(), 'status' => 'created']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de la création']);
        }
    }
} else if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
}