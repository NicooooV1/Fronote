<?php
// api/devoirs/index.php - API for homework management
header('Content-Type: application/json');
require_once '../../config.php';
require_once __DIR__ . '/../../../../API/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$userProfile = getUserRole();
$userId = getUserId();

$method = $_SERVER['REQUEST_METHOD'];
$path = explode('/', trim($_SERVER['PATH_INFO'] ?? '', '/'));
$id = $path[0] ?? null;

function canModifyDevoirs() {
    global $userProfile;
    return in_array($userProfile, ['professeur', 'administrateur']);
}

function sendNotification($type, $devoir) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        INSERT INTO notifications (type, id_devoir, statut, date_creation)
        VALUES (?, ?, 'en_attente', NOW())
    ");
    
    return $stmt->execute([$type, $devoir['id']]);
}

if ($method === 'GET') {
    if ($id) { // Return a single homework
        $stmt = $pdo->prepare('SELECT * FROM devoirs WHERE id=?');
        $stmt->execute([$id]);
        $devoir = $stmt->fetch();
        
        if ($userProfile === 'professeur' && $devoir['id_professeur'] != $userId && !$auth->hasRole('administrateur')) {
            http_response_code(403);
            echo json_encode(['error' => 'Accès non autorisé']);
            exit;
        }
        
        echo json_encode($devoir);
    } else {
        // Filtering
        $where = []; 
        $params = [];
        
        // Teachers only see their homeworks (except admins)
        if ($userProfile === 'professeur' && !$auth->hasRole('administrateur')) {
            $where[] = 'id_professeur=?';
            $params[] = $userId;
        }
        
        // Standard filters
        if (isset($_GET['matiere']) && $_GET['matiere'] !== '') {
            $where[] = 'matiere=?';
            $params[] = $_GET['matiere'];
        }
        if (isset($_GET['classe']) && $_GET['classe'] !== '') {
            $where[] = 'classe=?';
            $params[] = $_GET['classe'];
        }
        if (isset($_GET['date_remise']) && $_GET['date_remise'] !== '') {
            $where[] = 'DATE(date_remise)=?';
            $params[] = $_GET['date_remise'];
        }
        if (isset($_GET['id_cahier_texte']) && $_GET['id_cahier_texte'] !== '') {
            $where[] = 'id_cahier_texte=?';
            $params[] = $_GET['id_cahier_texte'];
        }
        
        $sql = 'SELECT id, titre, matiere, classe, date_remise, 
                CONCAT("/uploads/", fichier_sujet) AS url_sujet, 
                IF(fichier_corrige<>"",CONCAT("/uploads/",fichier_corrige),NULL) AS url_corrige,
                id_professeur, date_publication, id_cahier_texte, description
                FROM devoirs';
        
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        
        $sql .= ' ORDER BY date_remise DESC';
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
    }
}

if (($method === 'POST' || $method === 'PUT') && canModifyDevoirs()) {
    // Form validation
    $titre = $_POST['titre'] ?? '';
    $matiere = $_POST['matiere'] ?? '';
    $classe = $_POST['classe'] ?? '';
    $date_remise = $_POST['date_remise'] ?? '';
    $description = $_POST['description'] ?? '';
    
    // Basic validation
    if (empty($titre) || empty($matiere) || empty($classe) || empty($date_remise)) {
        http_response_code(400);
        echo json_encode(['error' => 'Tous les champs obligatoires doivent être remplis']);
        exit;
    }
    
    function upload($key) {
        if (!empty($_FILES[$key]['name'])) {
            // Check file size (5 MB max)
            if ($_FILES[$key]['size'] > 5 * 1024 * 1024) {
                http_response_code(400);
                echo json_encode(['error' => 'Le fichier ne doit pas dépasser 5 Mo']);
                exit;
            }
            
            // Check MIME type
            $allowedTypes = [
                'application/pdf', 
                'image/jpeg', 
                'image/png', 
                'image/gif',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];
            
            if (!in_array($_FILES[$key]['type'], $allowedTypes)) {
                http_response_code(400);
                echo json_encode(['error' => 'Type de fichier non autorisé']);
                exit;
            }
            
            $ext = pathinfo($_FILES[$key]['name'], PATHINFO_EXTENSION);
            $name = uniqid() . ".$ext";
            $uploadPath = ROOT_PATH . '/uploads/' . $name;
            
            if (!move_uploaded_file($_FILES[$key]['tmp_name'], $uploadPath)) {
                http_response_code(500);
                echo json_encode(['error' => 'Erreur lors de l\'upload du fichier']);
                exit;
            }
            return $name;
        }
        return null;
    }
    
    $sujet = upload('fichier_sujet');
    $corrige = upload('fichier_corrige');
    
    // In creation mode, subject file is required
    if ($method === 'POST' && !$sujet) {
        http_response_code(400);
        echo json_encode(['error' => 'Le fichier sujet est obligatoire']);
        exit;
    }
    
    if ($method === 'POST') {
        // Check if homework should be associated with a text notebook
        $id_cahier_texte = isset($_POST['id_cahier_texte']) ? $_POST['id_cahier_texte'] : null;
        
        $stmt = $pdo->prepare('INSERT INTO devoirs (titre, matiere, classe, date_remise, fichier_sujet, fichier_corrige, id_professeur, date_publication, description, id_cahier_texte) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)');
        $success = $stmt->execute([$titre, $matiere, $classe, $date_remise, $sujet, $corrige, $userId, $description, $id_cahier_texte]);
        
        if ($success) {
            $devoirId = $pdo->lastInsertId();
            
            // Get created homework info for notifications
            $stmt = $pdo->prepare('SELECT * FROM devoirs WHERE id = ?');
            $stmt->execute([$devoirId]);
            $devoir = $stmt->fetch();
            
            sendNotification('creation', $devoir);
            
            echo json_encode(['id' => $devoirId, 'status' => 'created']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de la création du devoir']);
        }
    } else {
        $id = $id ?: $_POST['id']; // Use ID from URL or form
        
        // Check if the teacher owns this homework
        if (!$auth->hasRole('administrateur')) {
            $stmt = $pdo->prepare('SELECT id_professeur FROM devoirs WHERE id = ?');
            $stmt->execute([$id]);
            $devoir = $stmt->fetch();
            
            if (!$devoir || $devoir['id_professeur'] != $userId) {
                http_response_code(403);
                echo json_encode(['error' => 'Vous n\'êtes pas autorisé à modifier ce devoir']);
                exit;
            }
        }
        
        $sql = 'UPDATE devoirs SET titre=?, matiere=?, classe=?, date_remise=?, description=?';
        $params = [$titre, $matiere, $classe, $date_remise, $description];
        
        if ($sujet) {
            $sql .= ', fichier_sujet=?';
            $params[] = $sujet;
        }
        if ($corrige) {
            $sql .= ', fichier_corrige=?';
            $params[] = $corrige;
        }
        
        $sql .= ' WHERE id=?';
        $params[] = $id;
        
        $stmt = $pdo->prepare($sql);
        $success = $stmt->execute($params);
        
        if ($success) {
            echo json_encode(['status' => 'updated']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de la mise à jour']);
        }
    }
} elseif ($method === 'DELETE' && canModifyDevoirs()) {
    // Check if the teacher owns this homework
    if (!$auth->hasRole('administrateur')) {
        $stmt = $pdo->prepare('SELECT id_professeur FROM devoirs WHERE id = ?');
        $stmt->execute([$id]);
        $devoir = $stmt->fetch();
        
        if (!$devoir || $devoir['id_professeur'] != $userId) {
            http_response_code(403);
            echo json_encode(['error' => 'Vous n\'êtes pas autorisé à supprimer ce devoir']);
            exit;
        }
    }
    
    // Get file names before deletion
    $stmt = $pdo->prepare('SELECT fichier_sujet, fichier_corrige FROM devoirs WHERE id = ?');
    $stmt->execute([$id]);
    $files = $stmt->fetch();
    
    // Delete homework from database
    $stmt = $pdo->prepare('DELETE FROM devoirs WHERE id = ?');
    $result = $stmt->execute([$id]);
    
    if ($result) {
        // Delete associated files
        if ($files['fichier_sujet']) {
            $path = ROOT_PATH . '/uploads/' . $files['fichier_sujet'];
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        if ($files['fichier_corrige']) {
            $path = ROOT_PATH . '/uploads/' . $files['fichier_corrige'];
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        
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