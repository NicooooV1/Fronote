<?php
// api/cahier_texte/index.php - API pour la gestion du cahier de texte
header('Content-Type: application/json');
require_once '../../config.php';
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

// Fonction pour uploader des documents
function uploadDocuments($files) {
    if (empty($files['documents']['name'][0])) {
        return [];
    }
    
    $uploadedFiles = [];
    $fileCount = count($files['documents']['name']);
    
    for ($i = 0; $i < $fileCount; $i++) {
        // Vérification de la taille (10 Mo max)
        if ($files['documents']['size'][$i] > 10 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['error' => 'Les fichiers ne doivent pas dépasser 10 Mo']);
            exit;
        }
        
        // Vérification du type MIME
        $allowedTypes = [
            'application/pdf', 
            'image/jpeg', 
            'image/png', 
            'image/gif',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation'
        ];
        
        if (!in_array($files['documents']['type'][$i], $allowedTypes)) {
            http_response_code(400);
            echo json_encode(['error' => 'Type de fichier non autorisé']);
            exit;
        }
        
        $ext = pathinfo($files['documents']['name'][$i], PATHINFO_EXTENSION);
        $fileName = uniqid() . ".$ext";
        $uploadPath = ROOT_PATH . '/uploads/' . $fileName;
        
        if (move_uploaded_file($files['documents']['tmp_name'][$i], $uploadPath)) {
            $uploadedFiles[] = '/uploads/' . $fileName;
        }
    }
    
    return $uploadedFiles;
}

if ($method === 'GET') {
    if ($id) { // retourner une entrée spécifique
        $stmt = $pdo->prepare('SELECT * FROM cahier_texte WHERE id=?');
        $stmt->execute([$id]);
        $entry = $stmt->fetch();
        
        if (!$entry) {
            http_response_code(404);
            echo json_encode(['error' => 'Entrée non trouvée']);
            exit;
        }
        
        // Vérifier si le professeur peut accéder à cette entrée
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
        
        // Les professeurs ne voient que leurs entrées (sauf admin)
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
        if (isset($_GET['date_cours']) && $_GET['date_cours'] !== '') {
            $where[] = 'DATE(date_cours)=?';
            $params[] = $_GET['date_cours'];
        }
        
        // Filtre par période
        if (isset($_GET['date_debut']) && $_GET['date_debut'] !== '') {
            $where[] = 'date_cours >= ?';
            $params[] = $_GET['date_debut'];
            
            if (isset($_GET['date_fin']) && $_GET['date_fin'] !== '') {
                $where[] = 'date_cours <= ?';
                $params[] = $_GET['date_fin'];
            }
        }
        
        $sql = 'SELECT * FROM cahier_texte';
        
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        
        // Tri par date du cours (plus récents en premier)
        $sql .= ' ORDER BY date_cours DESC';
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
    }
}

if (($method === 'POST' || $method === 'PUT') && canModifyCahierTexte()) {
    // Si la demande est en JSON
    if (strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validation de base
        if (empty($data['matiere']) || empty($data['classe']) || empty($data['date_cours']) || empty($data['contenu'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Tous les champs obligatoires doivent être remplis']);
            exit;
        }
        
        if ($method === 'POST') {
            $stmt = $pdo->prepare('INSERT INTO cahier_texte (id_professeur, matiere, classe, date_cours, titre, contenu, documents, heure_debut, heure_fin) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $result = $stmt->execute([
                $userId, 
                $data['matiere'], 
                $data['classe'], 
                $data['date_cours'], 
                $data['titre'] ?? '', 
                $data['contenu'], 
                $data['documents'] ?? null,
                $data['heure_debut'] ?? null,
                $data['heure_fin'] ?? null
            ]);
            
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
            
            $stmt = $pdo->prepare('UPDATE cahier_texte SET matiere=?, classe=?, date_cours=?, titre=?, contenu=?, documents=?, heure_debut=?, heure_fin=? WHERE id=?');
            $result = $stmt->execute([
                $data['matiere'], 
                $data['classe'], 
                $data['date_cours'], 
                $data['titre'] ?? '', 
                $data['contenu'], 
                $data['documents'] ?? null,
                $data['heure_debut'] ?? null,
                $data['heure_fin'] ?? null,
                $id
            ]);
            
            if ($result) {
                // Mettre à jour les associations avec les devoirs
                if (isset($data['devoirs'])) {
                    // Dissocier tous les devoirs actuellement associés
                    $stmt = $pdo->prepare('UPDATE devoirs SET id_cahier_texte=NULL WHERE id_cahier_texte=?');
                    $stmt->execute([$id]);
                    
                    // Associer les nouveaux devoirs
                    foreach ($data['devoirs'] as $devoirId) {
                        $stmt = $pdo->prepare('UPDATE devoirs SET id_cahier_texte=? WHERE id=?');
                        $stmt->execute([$id, $devoirId]);
                    }
                }
                
                echo json_encode(['status' => 'updated']);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Erreur lors de la mise à jour']);
            }
        }
    }
    // Si la demande est en multipart/form-data (pour l'upload de fichiers)
    else {
        $matiere = $_POST['matiere'] ?? '';
        $classe = $_POST['classe'] ?? '';
        $date_cours = $_POST['date_cours'] ?? '';
        $titre = $_POST['titre'] ?? '';
        $contenu = $_POST['contenu'] ?? '';
        $heure_debut = $_POST['heure_debut'] ?? null;
        $heure_fin = $_POST['heure_fin'] ?? null;
        
        // Validation de base
        if (empty($matiere) || empty($classe) || empty($date_cours) || empty($contenu)) {
            http_response_code(400);
            echo json_encode(['error' => 'Tous les champs obligatoires doivent être remplis']);
            exit;
        }
        
        // Upload des documents si présents
        $uploadedDocs = [];
        if (!empty($_FILES['documents']['name'][0])) {
            $uploadedDocs = uploadDocuments($_FILES);
        }
        
        $documentsJson = !empty($uploadedDocs) ? json_encode($uploadedDocs) : null;
        
        if ($method === 'POST') {
            $stmt = $pdo->prepare('INSERT INTO cahier_texte (id_professeur, matiere, classe, date_cours, titre, contenu, documents, heure_debut, heure_fin) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $result = $stmt->execute([
                $userId, 
                $matiere, 
                $classe, 
                $date_cours, 
                $titre, 
                $contenu, 
                $documentsJson,
                $heure_debut,
                $heure_fin
            ]);
            
            if ($result) {
                $id = $pdo->lastInsertId();
                
                // Créer des liens avec les devoirs associés si spécifiés
                if (!empty($_POST['devoirs'])) {
                    $devoirs = is_array($_POST['devoirs']) ? $_POST['devoirs'] : [$_POST['devoirs']];
                    foreach ($devoirs as $devoirId) {
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
                $stmt = $pdo->prepare('SELECT id_professeur, documents FROM cahier_texte WHERE id = ?');
                $stmt->execute([$id]);
                $entry = $stmt->fetch();
                
                if (!$entry || $entry['id_professeur'] != $userId) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Vous n\'êtes pas autorisé à modifier cette entrée']);
                    exit;
                }
                
                // Si pas de nouveaux documents, conserver les anciens
                if (empty($uploadedDocs) && $entry['documents']) {
                    $documentsJson = $entry['documents'];
                }
            }
            
            $stmt = $pdo->prepare('UPDATE cahier_texte SET matiere=?, classe=?, date_cours=?, titre=?, contenu=?, documents=?, heure_debut=?, heure_fin=? WHERE id=?');
            $result = $stmt->execute([
                $matiere, 
                $classe, 
                $date_cours,
                $titre,
                $contenu, 
                $documentsJson,
                $heure_debut,
                $heure_fin,
                $id
            ]);
            
            if ($result) {
                // Mettre à jour les associations avec les devoirs
                if (isset($_POST['devoirs'])) {
                    // Dissocier tous les devoirs actuellement associés
                    $stmt = $pdo->prepare('UPDATE devoirs SET id_cahier_texte=NULL WHERE id_cahier_texte=?');
                    $stmt->execute([$id]);
                    
                    // Associer les nouveaux devoirs
                    $devoirs = is_array($_POST['devoirs']) ? $_POST['devoirs'] : [$_POST['devoirs']];
                    foreach ($devoirs as $devoirId) {
                        $stmt = $pdo->prepare('UPDATE devoirs SET id_cahier_texte=? WHERE id=?');
                        $stmt->execute([$id, $devoirId]);
                    }
                }
                
                echo json_encode(['status' => 'updated']);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Erreur lors de la mise à jour']);
            }
        }
    }
} elseif ($method === 'DELETE' && canModifyCahierTexte()) {
    // Vérifier que le professeur est le propriétaire
    if (!$auth->hasRole('administrateur')) {
        $stmt = $pdo->prepare('SELECT id_professeur, documents FROM cahier_texte WHERE id = ?');
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
    
    // Supprimer l'entrée
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