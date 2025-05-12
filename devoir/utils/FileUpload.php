<?php
/**
 * Classe pour la gestion des téléchargements de fichiers
 */
class FileUpload {
    private $allowedExtensions;
    private $maxFileSize;
    
    /**
     * Constructeur
     * @param array $allowedExtensions Extensions autorisées
     * @param int $maxFileSize Taille maximale en octets
     */
    public function __construct($allowedExtensions = null, $maxFileSize = null) {
        $this->allowedExtensions = $allowedExtensions ?? ALLOWED_EXTENSIONS;
        $this->maxFileSize = $maxFileSize ?? MAX_UPLOAD_SIZE;
    }
    
    /**
     * Télécharge un fichier depuis un formulaire
     * @param array $file Tableau $_FILES pour le fichier
     * @param string $uploadDir Répertoire de destination
     * @param string $newFilename Nouveau nom du fichier (null pour conserver le nom original)
     * @return array Résultat du téléchargement (success, filename, message)
     */
    public function uploadFile($file, $uploadDir, $newFilename = null) {
        // Vérifier si le fichier a été correctement téléchargé
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return $this->handleUploadError($file['error']);
        }
        
        // Vérifier la taille du fichier
        if ($file['size'] > $this->maxFileSize) {
            return [
                'success' => false,
                'message' => 'Le fichier est trop volumineux. Taille maximale: ' . formatBytes($this->maxFileSize)
            ];
        }
        
        // Vérifier l'extension du fichier
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $this->allowedExtensions)) {
            return [
                'success' => false,
                'message' => 'Type de fichier non autorisé. Extensions autorisées: ' . implode(', ', $this->allowedExtensions)
            ];
        }
        
        // Créer le répertoire de destination s'il n'existe pas
        if (!file_exists($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                return [
                    'success' => false,
                    'message' => 'Impossible de créer le répertoire de destination'
                ];
            }
        }
        
        // Générer un nom de fichier unique si nécessaire
        if ($newFilename === null) {
            $filename = $this->generateUniqueFilename($file['name']);
        } else {
            $filename = $newFilename . '.' . $extension;
        }
        
        $destination = rtrim($uploadDir, '/') . '/' . $filename;
        
        // Déplacer le fichier
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return [
                'success' => false,
                'message' => 'Erreur lors du déplacement du fichier'
            ];
        }
        
        // Vérifier les fichiers image
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) {
            if (!$this->validateImage($destination)) {
                // Supprimer le fichier s'il n'est pas valide
                unlink($destination);
                return [
                    'success' => false,
                    'message' => 'Le fichier image n\'est pas valide'
                ];
            }
        }
        
        return [
            'success' => true,
            'filename' => $filename,
            'path' => $destination,
            'message' => 'Fichier téléchargé avec succès'
        ];
    }
    
    /**
     * Télécharge plusieurs fichiers depuis un formulaire
     * @param array $files Tableau $_FILES
     * @param string $uploadDir Répertoire de destination
     * @return array Résultats des téléchargements
     */
    public function uploadMultipleFiles($files, $uploadDir) {
        $results = [];
        
        // Réorganiser le tableau $_FILES
        $filesCount = count($files['name']);
        
        for ($i = 0; $i < $filesCount; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            
            $file = [
                'name' => $files['name'][$i],
                'type' => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i],
                'size' => $files['size'][$i]
            ];
            
            $results[] = $this->uploadFile($file, $uploadDir);
        }
        
        return $results;
    }
    
    /**
     * Supprime un fichier
     * @param string $filepath Chemin du fichier
     * @return bool Succès ou échec
     */
    public function deleteFile($filepath) {
        if (file_exists($filepath)) {
            return unlink($filepath);
        }
        
        return false;
    }
    
    /**
     * Génère un nom de fichier unique
     * @param string $originalName Nom original du fichier
     * @return string Nom de fichier unique
     */
    private function generateUniqueFilename($originalName) {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $basename = pathinfo($originalName, PATHINFO_FILENAME);
        
        // Nettoyer le nom du fichier (enlever les caractères spéciaux)
        $basename = preg_replace('/[^a-z0-9_-]/i', '_', $basename);
        
        // Limiter la longueur du nom de base
        if (strlen($basename) > 40) {
            $basename = substr($basename, 0, 40);
        }
        
        // Ajouter un timestamp et un identifiant aléatoire
        $uniqueId = time() . '_' . substr(md5(uniqid(rand(), true)), 0, 8);
        
        return $basename . '_' . $uniqueId . '.' . $extension;
    }
    
    /**
     * Valide un fichier image
     * @param string $filepath Chemin du fichier
     * @return bool Vrai si l'image est valide
     */
    private function validateImage($filepath) {
        $imageInfo = getimagesize($filepath);
        
        if ($imageInfo === false) {
            return false;
        }
        
        // Vérifier les types d'images supportés (IMAGETYPE constants)
        $allowedTypes = [
            IMAGETYPE_JPEG,
            IMAGETYPE_PNG,
            IMAGETYPE_GIF
        ];
        
        return in_array($imageInfo[2], $allowedTypes);
    }
    
    /**
     * Gère les codes d'erreur de téléchargement
     * @param int $errorCode Code d'erreur
     * @return array Résultat avec message d'erreur
     */
    private function handleUploadError($errorCode) {
        $message = '';
        
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $message = 'Le fichier est trop volumineux';
                break;
                
            case UPLOAD_ERR_PARTIAL:
                $message = 'Le fichier n\'a été que partiellement téléchargé';
                break;
                
            case UPLOAD_ERR_NO_FILE:
                $message = 'Aucun fichier n\'a été téléchargé';
                break;
                
            case UPLOAD_ERR_NO_TMP_DIR:
                $message = 'Répertoire temporaire manquant';
                break;
                
            case UPLOAD_ERR_CANT_WRITE:
                $message = 'Échec de l\'écriture du fichier sur le disque';
                break;
                
            case UPLOAD_ERR_EXTENSION:
                $message = 'Une extension PHP a arrêté le téléchargement du fichier';
                break;
                
            default:
                $message = 'Erreur inconnue lors du téléchargement';
                break;
        }
        
        return [
            'success' => false,
            'message' => $message
        ];
    }
    
    /**
     * Récupère les types MIME autorisés basés sur les extensions autorisées
     * @return array Types MIME autorisés
     */
    public function getAllowedMimeTypes() {
        $mimeTypes = [];
        
        foreach ($this->allowedExtensions as $ext) {
            switch ($ext) {
                case 'jpg':
                case 'jpeg':
                    $mimeTypes[] = 'image/jpeg';
                    break;
                    
                case 'png':
                    $mimeTypes[] = 'image/png';
                    break;
                    
                case 'gif':
                    $mimeTypes[] = 'image/gif';
                    break;
                    
                case 'pdf':
                    $mimeTypes[] = 'application/pdf';
                    break;
                    
                case 'doc':
                    $mimeTypes[] = 'application/msword';
                    break;
                    
                case 'docx':
                    $mimeTypes[] = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                    break;
                    
                case 'xls':
                    $mimeTypes[] = 'application/vnd.ms-excel';
                    break;
                    
                case 'xlsx':
                    $mimeTypes[] = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                    break;
                    
                case 'ppt':
                    $mimeTypes[] = 'application/vnd.ms-powerpoint';
                    break;
                    
                case 'pptx':
                    $mimeTypes[] = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
                    break;
                    
                case 'zip':
                    $mimeTypes[] = 'application/zip';
                    $mimeTypes[] = 'application/x-zip-compressed';
                    break;
                    
                case 'rar':
                    $mimeTypes[] = 'application/x-rar-compressed';
                    break;
                    
                case 'txt':
                    $mimeTypes[] = 'text/plain';
                    break;
            }
        }
        
        return array_unique($mimeTypes);
    }
}