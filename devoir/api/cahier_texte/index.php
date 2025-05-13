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

// Vérification des droits pour les opérations de modification
function canModifyCahierTexte() {
    global $userProfile;
    return in_array($userProfile, ['professeur', 'administrateur']);
}

if ($method === 'GET') {
    if ($id) { // retourner une entrée spécifique
        $stmt = $pdo->prepare('SELECT * FROM cahier_texte WHERE id=?');
        $stmt->execute([$id]);
        $entry = $stmt->fetch();
        
        if ($userProfile === 'professeur' && $entry['id_professeur'] != $userId && !$auth->hasRole('administrateur')) {
            http_response_code(403);
            echo json_encode(['error' => 'Accès non autorisé']);
            exit;
        }
        
        echo json_encode($entry);
    } else {
        // Filtrage
        $where = []; 
        $params = [];
        
        if ($userProfile === 'professeur' && !$auth->hasRole('administrateur')) {
            $where[] = 'id_professeur=?';
            $params[] = $userId;
        }
        
        if (isset($_GET['matiere']) && $_GET['matiere'] !== '') {
            $where[] = 'matiere=?';
            $params[] = $_GET['matiere'];
        }
        if (isset($_GET['classe']) && $_GET['classe'] !== '') {
            $where[] = 'classe=?';
            $params[] = $_GET['classe'];
        }
        if (isset($_GET['date_cours']) && $_GET['date_cours'] !== '') {
            $where[] = 'DATE(date_cours)=?';
            $params[] = $_GET['date_cours'];
        }
        
        $sql = 'SELECT * FROM cahier_texte';
        
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        
        $sql .= ' ORDER BY date_cours DESC';
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
    }
}

if (($method === 'POST' || $method === 'PUT') && canModifyCahierTexte()) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validation de base
    if (empty($data['matiere']) || empty($data['classe']) || empty($data['date_cours']) || empty($data['contenu'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Tous les champs obligatoires doivent être remplis']);
        exit;
    }
    
    if ($method === 'POST') {
        $stmt = $pdo->prepare('INSERT INTO cahier_texte (id_professeur, matiere, classe, date_cours, contenu, documents) VALUES (?, ?, ?, ?, ?, ?)');
        $result = $stmt->execute([$userId, $data['matiere'], $data['classe'], $data['date_cours'], $data['contenu'], $data['documents'] ?? null]);
        
        if ($result) {
            $id = $pdo->lastInsertId();
            
            // Créer des liens avec les devoirs associés si spécifiés
            if (!empty($data['devoirs'])) {
                foreach ($data['devoirs'] as $devoirId) {
                    $stmt = $pdo->prepare('UPDATE devoirs SET id_cahier_texte=? WHERE id=?');
                    $stmt->execute([$id, $devoirId]);
                }
            }
            
            echo json_encode(['id' => $id, 'status' => 'created']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de la création']);
        }
    } else {
        // Vérifier que le professeur est le propriétaire
        if (!$auth->hasRole('administrateur')) {
            $stmt = $pdo->prepare('SELECT id_professeur FROM cahier_texte WHERE id = ?');
            $stmt->execute([$id]);
            $entry = $stmt->fetch();
            
            if (!$entry || $entry['id_professeur'] != $userId) {
                http_response_code(403);
                echo json_encode(['error' => 'Vous n\'êtes pas autorisé à modifier cette entrée']);
                exit;
            }
        }
        
        $stmt = $pdo->prepare('UPDATE cahier_texte SET matiere=?, classe=?, date_cours=?, contenu=?, documents=? WHERE id=?');
        $result = $stmt->execute([$data['matiere'], $data['classe'], $data['date_cours'], $data['contenu'], $data['documents'] ?? null, $id]);
        
        if ($result) {
            echo json_encode(['status' => 'updated']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de la mise à jour']);
        }
    }
} elseif ($method === 'DELETE' && canModifyCahierTexte()) {
    // Vérifier que le professeur est le propriétaire
    if (!$auth->hasRole('administrateur')) {
        $stmt = $pdo->prepare('SELECT id_professeur FROM cahier_texte WHERE id = ?');
        $stmt->execute([$id]);
        $entry = $stmt->fetch();
        
        if (!$entry || $entry['id_professeur'] != $userId) {
            http_response_code(403);
            echo json_encode(['error' => 'Vous n\'êtes pas autorisé à supprimer cette entrée']);
            exit;
        }
    }
    
    // D'abord, dissocier les devoirs
    $stmt = $pdo->prepare('UPDATE devoirs SET id_cahier_texte=NULL WHERE id_cahier_texte=?');
    $stmt->execute([$id]);
    
    $stmt = $pdo->prepare('DELETE FROM cahier_texte WHERE id=?');
    $result = $stmt->execute([$id]);
    
    if ($result) {
        echo json_encode(['status' => 'deleted']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Erreur lors de la suppression']);
    }
} else {
    http_response_code(403);
    echo json_encode(['error' => 'Opération non autorisée']);
}
?>