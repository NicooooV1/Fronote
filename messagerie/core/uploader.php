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
    
    if (empty($filesData) || !isset($filesData['name'])) {
        return $uploadedFiles;
    }
    
    $uploadDir = UPLOAD_DIR;
    
    // Créer le répertoire si nécessaire
    if (!checkUploadPath($uploadDir)) {
        throw new Exception("Impossible de créer le répertoire d'upload");
    }
    
    foreach ($filesData['name'] as $key => $name) {
        if ($filesData['error'][$key] === UPLOAD_ERR_OK) {
            $tmp_name = $filesData['tmp_name'][$key];
            $filename = uniqid() . '_' . basename($name);
            $filePath = $uploadDir . $filename;
            
            if (move_uploaded_file($tmp_name, $filePath)) {
                $uploadedFiles[] = [
                    'name' => $name,
                    'path' => $filePath
                ];
            }
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