<?php
// Back-end: api/devoirs/index.php
header('Content-Type: application/json');
session_start();
include '../../config.php'; // connexion PDO
require_once '../../login/src/auth.php'; // Import de Auth

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
function canModifyDevoirs() {
    global $userProfile;
    return in_array($userProfile, ['professeur', 'administrateur']);
}

// Fonction pour envoyer une notification
function sendNotification($type, $devoir) {
    // Point d'entrée pour les notifications futures
    // À compléter avec le système d'envoi d'emails ou notifications push
    return true;
}

if ($method === 'GET') {
    if ($id) { // retourner un seul
        $stmt = $pdo->prepare('SELECT * FROM devoirs WHERE id=?');
        $stmt->execute([$id]);
        $devoir = $stmt->fetch();
        
        // Vérifier si le professeur peut accéder à ce devoir spécifique
        if ($userProfile === 'professeur' && $devoir['id_professeur'] != $userId && !$auth->hasRole('administrateur')) {
            // Un professeur ne peut voir que ses propres devoirs (sauf admin)
            http_response_code(403);
            echo json_encode(['error' => 'Accès non autorisé']);
            exit;
        }
        
        echo json_encode($devoir);
    } else {
        // Filtrage
        $where = []; 
        $params = [];
        
        // Les professeurs ne voient que leurs devoirs (sauf admin)
        if ($userProfile === 'professeur' && !$auth->hasRole('administrateur')) {
            $where[] = 'id_professeur=?';
            $params[] = $userId;
        }
        
        // Filtres standards
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
        
        $sql = 'SELECT id, titre, matiere, classe, date_remise, 
                CONCAT("/uploads/", fichier_sujet) AS url_sujet, 
                IF(fichier_corrige<>"",CONCAT("/uploads/",fichier_corrige),NULL) AS url_corrige,
                id_professeur, date_publication 
                FROM devoirs';
        
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        
        // Tri par date de remise (plus récents en premier)
        $sql .= ' ORDER BY date_remise DESC';
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
    }
}

if (($method === 'POST' || $method === 'PUT') && canModifyDevoirs()) {
    // Validation du formulaire
    $titre = $_POST['titre'] ?? '';
    $matiere = $_POST['matiere'] ?? '';
    $classe = $_POST['classe'] ?? '';
    $date_remise = $_POST['date_remise'] ?? '';
    
    // Validation de base
    if (empty($titre) || empty($matiere) || empty($classe) || empty($date_remise)) {
        http_response_code(400);
        echo json_encode(['error' => 'Tous les champs obligatoires doivent être remplis']);
        exit;
    }
    
    // Fonction pour uploader un fichier avec vérification
    function upload($key) {
        if (!empty($_FILES[$key]['name'])) {
            // Vérification de la taille (5 Mo max)
            if ($_FILES[$key]['size'] > 5 * 1024 * 1024) {
                http_response_code(400);
                echo json_encode(['error' => 'Le fichier ne doit pas dépasser 5 Mo']);
                exit;
            }
            
            // Vérification du type MIME
            $allowedTypes = [
                'application/pdf', 
                'image/jpeg', 
                'image/png', 
                'image/gif'
            ];
            
            if (!in_array($_FILES[$key]['type'], $allowedTypes)) {
                http_response_code(400);
                echo json_encode(['error' => 'Type de fichier non autorisé']);
                exit;
            }
            
            $ext = pathinfo($_FILES[$key]['name'], PATHINFO_EXTENSION);
            $name = uniqid() . ".$ext";
            if (!move_uploaded_file($_FILES[$key]['tmp_name'], __DIR__ . '/../../uploads/' . $name)) {
                http_response_code(500);
                echo json_encode(['error' => 'Erreur lors de l\'upload du fichier']);
                exit;
            }
            return $name;
        }
        return null;
    }
    
    // Upload des fichiers
    $sujet = upload('fichier_sujet');
    $corrige = upload('fichier_corrige');
    
    // En mode création, le sujet est obligatoire
    if ($method === 'POST' && !$sujet) {
        http_response_code(400);
        echo json_encode(['error' => 'Le fichier sujet est obligatoire']);
        exit;
    }
    
    if ($method === 'POST') {
        $stmt = $pdo->prepare('INSERT INTO devoirs (titre, matiere, classe, date_remise, fichier_sujet, fichier_corrige, id_professeur, date_publication) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
        $success = $stmt->execute([$titre, $matiere, $classe, $date_remise, $sujet, $corrige, $userId]);
        
        if ($success) {
            $devoirId = $pdo->lastInsertId();
            
            // Récupérer les infos du devoir créé pour les notifications
            $stmt = $pdo->prepare('SELECT * FROM devoirs WHERE id = ?');
            $stmt->execute([$devoirId]);
            $devoir = $stmt->fetch();
            
            // Envoi de notification
            sendNotification('creation', $devoir);
            
            // Planifier le rappel 24h avant la remise
            // Cette partie nécessite un système de tâches planifiées (cron)
            // qui sera implémenté séparément
        }
    } else {
        $id = $_POST['id'];
        
        // Vérifier que le professeur est le propriétaire de ce devoir
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
        
        $sql = 'UPDATE devoirs SET titre=?, matiere=?, classe=?, date_remise=?';
        $params = [$titre, $matiere, $classe, $date_remise];
        
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
    }
    
    if ($success) {
        echo json_encode(['status' => 'ok']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Erreur lors de l\'opération']);
    }
} elseif ($method === 'DELETE' && canModifyDevoirs()) {
    // Vérifier que le professeur est le propriétaire de ce devoir
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
    
    // Récupérer les noms des fichiers avant la suppression
    $stmt = $pdo->prepare('SELECT fichier_sujet, fichier_corrige FROM devoirs WHERE id = ?');
    $stmt->execute([$id]);
    $files = $stmt->fetch();
    
    // Supprimer le devoir de la base
    $stmt = $pdo->prepare('DELETE FROM devoirs WHERE id = ?');
    $result = $stmt->execute([$id]);
    
    if ($result) {
        // Supprimer les fichiers associés
        if ($files['fichier_sujet']) {
            @unlink(__DIR__ . '/../../uploads/' . $files['fichier_sujet']);
        }
        if ($files['fichier_corrige']) {
            @unlink(__DIR__ . '/../../uploads/' . $files['fichier_corrige']);
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