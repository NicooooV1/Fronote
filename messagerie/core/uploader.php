<?php
/**
 * Gestion sécurisée des téléchargements de fichiers
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/constants.php';

/**
 * Configuration de la validation des fichiers
 */
$ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'jpg', 'jpeg', 'png', 'gif'];
$MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 Mo
$UPLOAD_DIRECTORY = UPLOAD_DIR;

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
 * Vérifie l'extension d'un fichier
 * @param string $filename
 * @param array $allowedExtensions
 * @return bool
 */
function isAllowedExtension($filename, $allowedExtensions) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, $allowedExtensions);
}

/**
 * Génère un nom de fichier sécurisé
 * @param string $originalName
 * @return string
 */
function generateSecureFilename($originalName) {
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    return sprintf('%s_%s.%s', 
        bin2hex(random_bytes(8)),
        date('Ymd_His'),
        $extension
    );
}

/**
 * Vérifie le type MIME du fichier
 * @param string $tempPath
 * @return bool
 */
function validateMimeType($tempPath) {
    // Liste des types MIME autorisés
    $allowedMimeTypes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain',
        'image/jpeg',
        'image/png',
        'image/gif'
    ];
    
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($tempPath);
    
    return in_array($mimeType, $allowedMimeTypes);
}

/**
 * Gère le téléchargement des fichiers joints à un message
 * @param array $filesData
 * @return array
 */
function handleFileUploads($filesData) {
    global $ALLOWED_EXTENSIONS, $MAX_FILE_SIZE, $UPLOAD_DIRECTORY;
    $uploadedFiles = [];
    
    // Si aucun fichier n'est téléchargé, retourner immédiatement un tableau vide
    if (empty($filesData) || !isset($filesData['name']) || empty($filesData['name'][0])) {
        return $uploadedFiles;
    }
    
    // Créer le répertoire avec les droits appropriés
    if (!checkUploadPath($UPLOAD_DIRECTORY)) {
        throw new Exception("Impossible de créer le répertoire de téléchargement");
    }
    
    // Créer un sous-dossier pour le jour actuel
    $dailyDir = $UPLOAD_DIRECTORY . date('Y/m/d') . '/';
    if (!checkUploadPath($dailyDir)) {
        throw new Exception("Impossible de créer le sous-répertoire de téléchargement");
    }
    
    foreach ($filesData['name'] as $key => $name) {
        // Vérifier les erreurs de téléchargement
        if ($filesData['error'][$key] !== UPLOAD_ERR_OK) {
            $errorMessage = getUploadErrorMessage($filesData['error'][$key]);
            throw new Exception("Erreur de téléchargement: " . $errorMessage);
        }
        
        // Vérifier la taille du fichier
        if ($filesData['size'][$key] > $MAX_FILE_SIZE) {
            throw new Exception("Le fichier est trop volumineux. Taille maximale: " . 
                                formatFileSize($MAX_FILE_SIZE));
        }
        
        // Vérifier l'extension du fichier
        if (!isAllowedExtension($name, $ALLOWED_EXTENSIONS)) {
            throw new Exception("Type de fichier non autorisé. Extensions autorisées: " . 
                                implode(', ', $ALLOWED_EXTENSIONS));
        }
        
        // Vérifier le type MIME
        $tempPath = $filesData['tmp_name'][$key];
        if (!validateMimeType($tempPath)) {
            throw new Exception("Type de contenu non autorisé");
        }
        
        // Générer un nom de fichier sécurisé
        $secureFilename = generateSecureFilename($name);
        $filePath = $dailyDir . $secureFilename;
        
        // Déplacer le fichier téléchargé
        if (move_uploaded_file($tempPath, $filePath)) {
            $uploadedFiles[] = [
                'name' => $name,
                'path' => str_replace(UPLOAD_DIR, '', $filePath), // Chemin relatif
                'size' => $filesData['size'][$key],
                'type' => pathinfo($name, PATHINFO_EXTENSION)
            ];
        } else {
            throw new Exception("Échec du téléchargement du fichier");
        }
    }
    
    return $uploadedFiles;
}

/**
 * Obtient un message d'erreur lisible pour les codes d'erreur de téléchargement
 * @param int $errorCode
 * @return string
 */
function getUploadErrorMessage($errorCode) {
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
            return "Le fichier dépasse la taille maximale définie dans php.ini";
        case UPLOAD_ERR_FORM_SIZE:
            return "Le fichier dépasse la taille maximale définie dans le formulaire HTML";
        case UPLOAD_ERR_PARTIAL:
            return "Le fichier n'a été que partiellement téléchargé";
        case UPLOAD_ERR_NO_FILE:
            return "Aucun fichier n'a été téléchargé";
        case UPLOAD_ERR_NO_TMP_DIR:
            return "Dossier temporaire manquant";
        case UPLOAD_ERR_CANT_WRITE:
            return "Échec de l'écriture du fichier sur le disque";
        case UPLOAD_ERR_EXTENSION:
            return "Une extension PHP a arrêté le téléchargement";
        default:
            return "Erreur de téléchargement inconnue";
    }
}

/**
 * Formate la taille de fichier en unités lisibles
 * @param int $bytes
 * @return string
 */
function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
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
        INSERT INTO message_attachments (message_id, file_name, file_path, file_size, file_type, uploaded_at) 
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    
    foreach ($uploadedFiles as $file) {
        if (!$stmt->execute([
            $messageId,
            $file['name'],
            $file['path'],
            $file['size'],
            $file['type']
        ])) {
            return false;
        }
    }
    
    return true;
}