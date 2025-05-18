<?php
/**
 * Gestion des téléchargements de fichiers
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/constants.php';

/**
 * Fonction de journalisation pour débuguer les uploads
 * @param string $message Message à journaliser
 * @param mixed $data Données supplémentaires (facultatif)
 */
function logUpload($message, $data = null) {
    $logFile = __DIR__ . '/../logs/upload_' . date('Y-m-d') . '.log';
    $dir = dirname($logFile);
    
    // S'assurer que le répertoire de logs existe
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}";
    
    if ($data !== null) {
        $logMessage .= " - Data: " . json_encode($data);
    }
    
    file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
}

/**
 * Vérifie si un chemin d'upload existe, le crée sinon
 * @param string $path
 * @return bool
 */
function checkUploadPath($path) {
    if (!is_dir($path)) {
        $created = @mkdir($path, 0755, true);
        if (!$created) {
            logUpload("Échec de création du répertoire: " . $path . " - Erreur: " . error_get_last()['message']);
            return false;
        }
        logUpload("Répertoire créé avec succès: " . $path);
    }
    
    if (!is_writable($path)) {
        logUpload("Le répertoire n'est pas accessible en écriture: " . $path);
        return false;
    }
    
    return true;
}

/**
 * Obtient le message d'erreur approprié pour un code d'erreur d'upload
 * @param int $errorCode
 * @return string
 */
function getUploadErrorMessage($errorCode) {
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
            return "Le fichier dépasse la taille maximale autorisée par PHP (" . ini_get('upload_max_filesize') . ")";
        case UPLOAD_ERR_FORM_SIZE:
            return "Le fichier dépasse la taille maximale autorisée par le formulaire";
        case UPLOAD_ERR_PARTIAL:
            return "Le fichier n'a été que partiellement téléchargé";
        case UPLOAD_ERR_NO_FILE:
            return "Aucun fichier n'a été téléchargé";
        case UPLOAD_ERR_NO_TMP_DIR:
            return "Le dossier temporaire est manquant";
        case UPLOAD_ERR_CANT_WRITE:
            return "Échec de l'écriture du fichier sur le disque";
        case UPLOAD_ERR_EXTENSION:
            return "Une extension PHP a arrêté le téléchargement";
        default:
            return "Erreur inconnue lors du téléchargement";
    }
}

/**
 * Gère le téléchargement des fichiers joints à un message
 * @param array $filesData
 * @return array
 */
function handleFileUploads($filesData) {
    $uploadedFiles = [];
    
    logUpload("Démarrage du processus d'upload", $filesData);
    
    // Si aucun fichier n'est téléchargé, retourner immédiatement un tableau vide
    if (empty($filesData) || !isset($filesData['name']) || empty($filesData['name'][0])) {
        logUpload("Aucun fichier à uploader");
        return $uploadedFiles;
    }
    
    $uploadDir = UPLOAD_DIR;
    logUpload("Répertoire d'upload: " . $uploadDir);
    
    // Créer le répertoire avec les droits appropriés
    if (!checkUploadPath($uploadDir)) {
        throw new Exception("Impossible d'accéder ou de créer le répertoire d'upload: " . $uploadDir);
    }
    
    // Limite de taille: 1MB
    $maxFileSize = 1048576; // 1MB en octets
    logUpload("Limite de taille par fichier: " . $maxFileSize . " octets");
    
    // Vérifier les limites PHP
    logUpload("Configuration PHP - upload_max_filesize: " . ini_get('upload_max_filesize'));
    logUpload("Configuration PHP - post_max_size: " . ini_get('post_max_size'));
    logUpload("Configuration PHP - max_file_uploads: " . ini_get('max_file_uploads'));
    
    foreach ($filesData['name'] as $key => $name) {
        // Vérifier les erreurs d'upload
        if ($filesData['error'][$key] !== UPLOAD_ERR_OK) {
            $errorMessage = getUploadErrorMessage($filesData['error'][$key]);
            logUpload("Erreur upload pour le fichier {$name}: " . $errorMessage);
            throw new Exception("Erreur lors de l'upload de {$name}: {$errorMessage}");
        }
        
        // Vérifier la taille du fichier
        if ($filesData['size'][$key] > $maxFileSize) {
            logUpload("Fichier trop volumineux: " . $name . " (" . $filesData['size'][$key] . " octets)");
            throw new Exception("Le fichier " . htmlspecialchars($name) . " dépasse la limite de 1Mo autorisée.");
        }
        
        // Générer un nom de fichier unique
        $fileName = time() . '_' . bin2hex(random_bytes(8)) . '_' . $name;
        $filePath = $uploadDir . $fileName;
        
        logUpload("Tentative d'upload vers: " . $filePath);
        
        // Déplacer le fichier téléchargé vers le répertoire cible
        if (move_uploaded_file($filesData['tmp_name'][$key], $filePath)) {
            logUpload("Upload réussi: " . $filePath);
            $uploadedFiles[] = [
                'name' => $name,
                'path' => str_replace(BASE_PATH, '/', $filePath)
            ];
        } else {
            // Récupérer l'erreur
            $error = error_get_last();
            logUpload("Échec de l'upload: " . $filePath, $error);
            throw new Exception("Impossible d'enregistrer le fichier " . htmlspecialchars($name) . ". Erreur: " . ($error ? $error['message'] : 'Inconnue'));
        }
    }
    
    logUpload("Résultat final de l'upload", $uploadedFiles);
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
    
    logUpload("Sauvegarde des pièces jointes en base de données pour le message #{$messageId}", $uploadedFiles);
    
    $stmt = $pdo->prepare("
        INSERT INTO message_attachments (message_id, file_name, file_path, uploaded_at) 
        VALUES (?, ?, ?, NOW())
    ");
    
    foreach ($uploadedFiles as $file) {
        if (!$stmt->execute([$messageId, $file['name'], $file['path']])) {
            logUpload("Erreur lors de l'insertion en base de données", [
                'error' => $stmt->errorInfo(),
                'file' => $file
            ]);
            return false;
        }
    }
    
    logUpload("Toutes les pièces jointes ont été sauvegardées en base de données pour le message #{$messageId}");
    return true;
}