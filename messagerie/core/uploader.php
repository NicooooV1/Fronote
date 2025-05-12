<?php
/**
 * Gestion des téléchargements de fichiers
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/constants.php';

/**
 * Vérifie si un chemin d'upload existe, le crée sinon
 * @param string $path
 * @return bool
 */
function checkUploadPath($path) {
    if (!is_dir($path)) {
        return mkdir($path, 0755, true);
    }
    return true;
}

/**
 * Gère le téléchargement des fichiers joints à un message
 * @param array $filesData
 * @return array
 */
function handleFileUploads($filesData) {
    $uploadedFiles = [];
    
    // Si aucun fichier n'est téléchargé, retourner immédiatement un tableau vide
    if (empty($filesData) || !isset($filesData['name']) || empty($filesData['name'][0])) {
        return $uploadedFiles;
    }
    
    $uploadDir = UPLOAD_DIR;
    
    // Créer le répertoire avec les droits appropriés
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Vérifier que le dossier est accessible en écriture
    // Retirez cette partie si vous n'avez pas les droits
    // if (!is_writable($uploadDir)) {
    //     chmod($uploadDir, 0755);
    // }
    
    foreach ($filesData['name'] as $key => $name) {
        if ($filesData['error'][$key] === UPLOAD_ERR_OK) {
            // Reste du code...
        }
    }
    
    return $uploadedFiles;
}

/**
 * Ajoute les pièces jointes à un message dans la base de données
 * @param PDO $pdo
 * @param int $messageId
 * @param array $uploadedFiles
 * @return bool
 */
function saveAttachments($pdo, $messageId, $uploadedFiles) {
    if (empty($uploadedFiles)) {
        return true;
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO message_attachments (message_id, file_name, file_path, uploaded_at) 
        VALUES (?, ?, ?, NOW())
    ");
    
    foreach ($uploadedFiles as $file) {
        if (!$stmt->execute([$messageId, $file['name'], $file['path']])) {
            return false;
        }
    }
    
    return true;
}