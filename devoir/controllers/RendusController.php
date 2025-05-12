<?php
/**
 * Contrôleur pour la gestion des rendus de devoirs
 */
class RendusController {
    private $renduModel;
    private $devoirModel;
    private $classeModel;
    private $userModel;
    
    public function __construct() {
        // Initialisation des modèles
        require_once ROOT_PATH . '/models/Rendu.php';
        require_once ROOT_PATH . '/models/Devoir.php';
        require_once ROOT_PATH . '/models/Classe.php';
        require_once ROOT_PATH . '/../login/src/auth.php';
        require_once ROOT_PATH . '/../login/src/user.php';

        $auth = new Auth($this->db->getPDO());
        
        $this->renduModel = new Rendu();
        $this->devoirModel = new Devoir();
        $this->classeModel = new Classe();
        $this->userModel = new User();
        
        // Vérifier que l'utilisateur est connecté
        if (!isset($_SESSION['user_id'])) {
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
    }
    
    /**
     * Affiche tous les rendus d'un devoir (vue professeur)
     */
    public function rendusDevoir($devoirId) {
        // Vérifier que l'utilisateur est un professeur ou administrateur
        if ($_SESSION['user_type'] !== TYPE_PROFESSEUR && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à accéder à cette page.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du devoir
        $devoir = $this->devoirModel->getDevoirById($devoirId);
        
        if (!$devoir) {
            $_SESSION['error'] = "Devoir non trouvé.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier si le professeur est l'auteur du devoir
        if ($devoir['auteur_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à accéder à ce devoir.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer tous les rendus pour ce devoir
        $rendus = $this->renduModel->getRendusByDevoir($devoirId);
        
        // Récupérer les statistiques de rendu
        $stats = $this->devoirModel->getStatistiquesRendu($devoirId);
        
        // Récupérer les élèves de la classe qui n'ont pas rendu le devoir
        $eleves = $this->userModel->getElevesClasse($devoir['classe_id']);
        $elevesSansRendu = [];
        
        foreach ($eleves as $eleve) {
            $rendu = false;
            foreach ($rendus as $r) {
                if ($r['eleve_id'] == $eleve['id']) {
                    $rendu = true;
                    break;
                }
            }
            
            if (!$rendu) {
                $elevesSansRendu[] = $eleve;
            }
        }
        
        // Charger la vue
        require_once ROOT_PATH . '/views/devoirs/rendus.php';
    }
    
    /**
     * Affiche le formulaire de rendu d'un devoir (vue élève)
     */
    public function rendreDevoir($devoirId) {
        // Vérifier que l'utilisateur est un élève
        if ($_SESSION['user_type'] !== TYPE_ELEVE) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à accéder à cette page.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du devoir
        $devoir = $this->devoirModel->getDevoirById($devoirId);
        
        if (!$devoir) {
            $_SESSION['error'] = "Devoir non trouvé.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier que l'élève a accès à ce devoir (appartient à la classe)
        $classeEleve = $this->classeModel->verifierEleveClasse($_SESSION['user_id'], $devoir['classe_id']);
        
        if (!$classeEleve) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à accéder à ce devoir.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier si l'élève a déjà rendu ce devoir
        $renduExistant = $this->renduModel->getRenduEleve($devoirId, $_SESSION['user_id']);
        
        // Charger la vue
        require_once ROOT_PATH . '/views/devoirs/rendre.php';
    }
    
    /**
     * Traite la soumission du formulaire de rendu
     */
    public function soumettreRendu() {
        // Vérifier que l'utilisateur est un élève
        if ($_SESSION['user_type'] !== TYPE_ELEVE) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à effectuer cette action.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier la méthode de requête
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['error'] = "Méthode non autorisée.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les données du formulaire
        $devoirId = isset($_POST['devoir_id']) ? (int)$_POST['devoir_id'] : 0;
        $commentaire = isset($_POST['commentaire']) ? trim($_POST['commentaire']) : '';
        
        if ($devoirId === 0) {
            $_SESSION['error'] = "ID de devoir non spécifié.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du devoir
        $devoir = $this->devoirModel->getDevoirById($devoirId);
        
        if (!$devoir) {
            $_SESSION['error'] = "Devoir non trouvé.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier que l'élève a accès à ce devoir
        $classeEleve = $this->classeModel->verifierEleveClasse($_SESSION['user_id'], $devoir['classe_id']);
        
        if (!$classeEleve) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à accéder à ce devoir.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier si l'élève a déjà rendu ce devoir
        $renduExistant = $this->renduModel->getRenduEleve($devoirId, $_SESSION['user_id']);
        
        if ($renduExistant) {
            $_SESSION['error'] = "Vous avez déjà rendu ce devoir. Vous pouvez le modifier en allant sur la page détaillée du devoir.";
            header('Location: ' . BASE_URL . '/devoirs/details.php?id=' . $devoirId);
            exit;
        }
        
        // Traitement des fichiers
        $fichiers = [];
        
        if (isset($_FILES['fichiers']) && !empty($_FILES['fichiers']['name'][0])) {
            require_once ROOT_PATH . '/utils/FileUpload.php';
            $fileUpload = new FileUpload();
            
            for ($i = 0; $i < count($_FILES['fichiers']['name']); $i++) {
                $file = [
                    'name' => $_FILES['fichiers']['name'][$i],
                    'type' => $_FILES['fichiers']['type'][$i],
                    'tmp_name' => $_FILES['fichiers']['tmp_name'][$i],
                    'error' => $_FILES['fichiers']['error'][$i],
                    'size' => $_FILES['fichiers']['size'][$i]
                ];
                
                $uploadResult = $fileUpload->uploadFile($file, RENDUS_UPLOADS);
                
                if ($uploadResult['success']) {
                    $fichiers[] = [
                        'nom' => $file['name'],
                        'type' => $this->determinerTypeFichier($file['name']),
                        'fichier' => $uploadResult['filename']
                    ];
                } else {
                    $_SESSION['error'] = "Erreur lors du téléchargement du fichier {$file['name']}: {$uploadResult['message']}";
                    header('Location: ' . BASE_URL . '/devoirs/rendre.php?id=' . $devoirId);
                    exit;
                }
            }
        }
        
        // Si aucun fichier n'a été téléchargé et pas de commentaire
        if (empty($fichiers) && empty($commentaire)) {
            $_SESSION['error'] = "Vous devez fournir au moins un fichier ou un commentaire.";
            header('Location: ' . BASE_URL . '/devoirs/rendre.php?id=' . $devoirId);
            exit;
        }
        
        // Créer le rendu
        $renduData = [
            'devoir_id' => $devoirId,
            'eleve_id' => $_SESSION['user_id'],
            'commentaire' => $commentaire,
            'fichiers' => $fichiers
        ];
        
        $renduId = $this->renduModel->createRendu($renduData);
        
        if ($renduId) {
            // Envoi de notification au professeur
            require_once ROOT_PATH . '/utils/Notification.php';
            $notificationModel = new Notification();
            
            $notificationData = [
                'destinataire_id' => $devoir['auteur_id'],
                'titre' => "Nouveau rendu pour le devoir: {$devoir['titre']}",
                'contenu' => "Un nouveau rendu a été soumis par {$_SESSION['user_fullname']} pour le devoir {$devoir['titre']}.",
                'devoir_id' => $devoirId,
                'rendu_id' => $renduId
            ];
            
            $notificationModel->createNotification($notificationData);
            
            $_SESSION['success'] = "Votre rendu a été soumis avec succès.";
            header('Location: ' . BASE_URL . '/devoirs/details.php?id=' . $devoirId);
            exit;
        } else {
            $_SESSION['error'] = "Une erreur est survenue lors de la soumission du rendu.";
            header('Location: ' . BASE_URL . '/devoirs/rendre.php?id=' . $devoirId);
            exit;
        }
    }
    
    /**
     * Affiche le formulaire de correction d'un rendu (vue professeur)
     */
    public function corrigerRendu($renduId) {
        // Vérifier que l'utilisateur est un professeur ou administrateur
        if ($_SESSION['user_type'] !== TYPE_PROFESSEUR && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à accéder à cette page.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du rendu
        $rendu = $this->renduModel->getRenduById($renduId);
        
        if (!$rendu) {
            $_SESSION['error'] = "Rendu non trouvé.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du devoir
        $devoir = $this->devoirModel->getDevoirById($rendu['devoir_id']);
        
        // Vérifier que le professeur est l'auteur du devoir
        if ($devoir['auteur_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à corriger ce rendu.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Charger la vue
        require_once ROOT_PATH . '/views/devoirs/corriger.php';
    }
    
    /**
     * Traite la soumission du formulaire de correction
     */
    public function soumettreCorrection() {
        // Vérifier que l'utilisateur est un professeur ou administrateur
        if ($_SESSION['user_type'] !== TYPE_PROFESSEUR && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à effectuer cette action.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier la méthode de requête
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['error'] = "Méthode non autorisée.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les données du formulaire
        $renduId = isset($_POST['rendu_id']) ? (int)$_POST['rendu_id'] : 0;
        $note = isset($_POST['note']) ? (float)$_POST['note'] : null;
        $commentaireProf = isset($_POST['commentaire_prof']) ? trim($_POST['commentaire_prof']) : '';
        
        if ($renduId === 0) {
            $_SESSION['error'] = "ID de rendu non spécifié.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du rendu
        $rendu = $this->renduModel->getRenduById($renduId);
        
        if (!$rendu) {
            $_SESSION['error'] = "Rendu non trouvé.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du devoir
        $devoir = $this->devoirModel->getDevoirById($rendu['devoir_id']);
        
        // Vérifier que le professeur est l'auteur du devoir
        if ($devoir['auteur_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à corriger ce rendu.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier si le barème est défini
        if ($devoir['bareme_id']) {
            require_once ROOT_PATH . '/models/Bareme.php';
            $baremeModel = new Bareme();
            
            $bareme = $baremeModel->getBaremeById($devoir['bareme_id']);
            
            if ($bareme && $note !== null) {
                // Vérifier que la note est dans les limites du barème
                if ($note < 0 || $note > $bareme['note_max']) {
                    $_SESSION['error'] = "La note doit être comprise entre 0 et {$bareme['note_max']}.";
                    header('Location: ' . BASE_URL . '/devoirs/corriger.php?id=' . $renduId);
                    exit;
                }
            }
        }
        
        // Mettre à jour le rendu
        $updateData = [
            'statut' => STATUT_CORRIGE,
            'note' => $note,
            'commentaire_prof' => $commentaireProf,
            'date_correction' => date('Y-m-d H:i:s')
        ];
        
        $success = $this->renduModel->updateRendu($renduId, $updateData);
        
        if ($success) {
            // Envoi de notification à l'élève
            require_once ROOT_PATH . '/utils/Notification.php';
            $notificationModel = new Notification();
            
            $notificationData = [
                'destinataire_id' => $rendu['eleve_id'],
                'titre' => "Correction du devoir: {$devoir['titre']}",
                'contenu' => "Votre rendu pour le devoir {$devoir['titre']} a été corrigé par {$_SESSION['user_fullname']}.",
                'devoir_id' => $rendu['devoir_id'],
                'rendu_id' => $renduId
            ];
            
            $notificationModel->createNotification($notificationData);
            
            $_SESSION['success'] = "La correction a été enregistrée avec succès.";
            header('Location: ' . BASE_URL . '/devoirs/rendus.php?devoir_id=' . $rendu['devoir_id']);
            exit;
        } else {
            $_SESSION['error'] = "Une erreur est survenue lors de l'enregistrement de la correction.";
            header('Location: ' . BASE_URL . '/devoirs/corriger.php?id=' . $renduId);
            exit;
        }
    }
    
    /**
     * Télécharge un fichier de rendu
     */
    public function telechargerFichier($fichierId) {
        // Récupérer les informations du fichier
        $sql = "SELECT fr.*, r.devoir_id, r.eleve_id 
                FROM fichiers_rendu fr
                JOIN rendus r ON fr.rendu_id = r.id
                WHERE fr.id = :id";
        
        $fichier = $this->db->fetch($sql, [':id' => $fichierId]);
        
        if (!$fichier) {
            $_SESSION['error'] = "Fichier non trouvé.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du devoir
        $devoir = $this->devoirModel->getDevoirById($fichier['devoir_id']);
        
        // Vérifier les autorisations
        if ($_SESSION['user_type'] === TYPE_ELEVE) {
            // L'élève ne peut télécharger que ses propres fichiers
            if ($fichier['eleve_id'] != $_SESSION['user_id']) {
                $_SESSION['error'] = "Vous n'êtes pas autorisé à télécharger ce fichier.";
                header('Location: ' . BASE_URL . '/devoirs/index.php');
                exit;
            }
        } elseif ($_SESSION['user_type'] === TYPE_PROFESSEUR) {
            // Le professeur ne peut télécharger que les fichiers des devoirs qu'il a créés
            if ($devoir['auteur_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
                $_SESSION['error'] = "Vous n'êtes pas autorisé à télécharger ce fichier.";
                header('Location: ' . BASE_URL . '/devoirs/index.php');
                exit;
            }
        } elseif ($_SESSION['user_type'] === TYPE_PARENT) {
            // Le parent ne peut télécharger que les fichiers de ses enfants
            $enfants = $this->userModel->getEnfantsParent($_SESSION['user_id']);
            $enfantAutorise = false;
            
            foreach ($enfants as $enfant) {
                if ($enfant['id'] == $fichier['eleve_id']) {
                    $enfantAutorise = true;
                    break;
                }
            }
            
            if (!$enfantAutorise) {
                $_SESSION['error'] = "Vous n'êtes pas autorisé à télécharger ce fichier.";
                header('Location: ' . BASE_URL . '/devoirs/index.php');
                exit;
            }
        } elseif (!$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à télécharger ce fichier.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier que le fichier existe
        $cheminFichier = RENDUS_UPLOADS . '/' . $fichier['fichier'];
        
        if (!file_exists($cheminFichier)) {
            $_SESSION['error'] = "Le fichier n'existe pas sur le serveur.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Déterminer le type MIME
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $cheminFichier);
        finfo_close($finfo);
        
        // Envoyer le fichier
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $fichier['nom'] . '"');
        header('Content-Length: ' . filesize($cheminFichier));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Lire et envoyer le fichier
        readfile($cheminFichier);
        exit;
    }
    
    /**
     * Supprime un fichier de rendu
     */
    public function supprimerFichier() {
        // Vérifier la méthode de requête
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['error'] = "Méthode non autorisée.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer l'ID du fichier
        $fichierId = isset($_POST['fichier_id']) ? (int)$_POST['fichier_id'] : 0;
        
        if ($fichierId === 0) {
            $_SESSION['error'] = "ID de fichier non spécifié.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du fichier
        $sql = "SELECT fr.*, r.devoir_id, r.eleve_id 
                FROM fichiers_rendu fr
                JOIN rendus r ON fr.rendu_id = r.id
                WHERE fr.id = :id";
        
        $fichier = $this->db->fetch($sql, [':id' => $fichierId]);
        
        if (!$fichier) {
            $_SESSION['error'] = "Fichier non trouvé.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier les autorisations
        if ($_SESSION['user_type'] === TYPE_ELEVE) {
            // L'élève ne peut supprimer que ses propres fichiers
            if ($fichier['eleve_id'] != $_SESSION['user_id']) {
                $_SESSION['error'] = "Vous n'êtes pas autorisé à supprimer ce fichier.";
                header('Location: ' . BASE_URL . '/devoirs/index.php');
                exit;
            }
            
            // Vérifier que le devoir n'est pas déjà corrigé
            $rendu = $this->renduModel->getRenduById($fichier['rendu_id']);
            if ($rendu && $rendu['statut'] === STATUT_CORRIGE) {
                $_SESSION['error'] = "Vous ne pouvez pas supprimer un fichier d'un devoir déjà corrigé.";
                header('Location: ' . BASE_URL . '/devoirs/details.php?id=' . $fichier['devoir_id']);
                exit;
            }
        } elseif (!$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à supprimer ce fichier.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Supprimer le fichier
        $success = $this->renduModel->supprimerFichier($fichierId);
        
        if ($success) {
            $_SESSION['success'] = "Le fichier a été supprimé avec succès.";
        } else {
            $_SESSION['error'] = "Une erreur est survenue lors de la suppression du fichier.";
        }
        
        // Rediriger vers la page de détails du devoir
        header('Location: ' . BASE_URL . '/devoirs/details.php?id=' . $fichier['devoir_id']);
        exit;
    }
    
    /**
     * Détermine le type de fichier à partir de son extension
     * @param string $filename Nom du fichier
     * @return string Type de fichier ('PDF', 'IMG', 'DOC', 'OTHER')
     */
    private function determinerTypeFichier($filename) {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $typeMap = [
            'pdf' => 'PDF',
            'jpg' => 'IMG',
            'jpeg' => 'IMG',
            'png' => 'IMG',
            'gif' => 'IMG',
            'doc' => 'DOC',
            'docx' => 'DOC',
            'xls' => 'DOC',
            'xlsx' => 'DOC',
            'ppt' => 'DOC',
            'pptx' => 'DOC'
        ];
        
        return isset($typeMap[$extension]) ? $typeMap[$extension] : 'OTHER';
    }
}