<?php
/**
 * Contrôleur pour la gestion des rendus de devoirs
 */
class RendusController {
    private $renduModel;
    private $devoirModel;
    private $classeModel;
    private $fileUpload;
    
    /**
     * Constructeur
     */
    public function __construct() {
        $this->renduModel = new Rendu();
        $this->devoirModel = new Devoir();
        $this->classeModel = new Classe();
        $this->fileUpload = new FileUpload();
    }
    
    /**
     * Affiche le formulaire pour rendre un devoir
     */
    public function create() {
        // Vérifier que l'utilisateur est connecté
        requireAuthentication();
        
        // Vérifier que l'utilisateur est un élève
        if ($_SESSION['user_type'] !== TYPE_ELEVE) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour rendre un devoir.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer l'ID du devoir
        $id = isset($_GET['id']) ? $_GET['id'] : null;
        
        if (!$id) {
            $_SESSION['error'] = "Devoir non spécifié.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du devoir
        $devoir = $this->devoirModel->getDevoirById($id);
        
        if (!$devoir) {
            $_SESSION['error'] = "Devoir introuvable.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier que l'élève appartient à la classe
        if (!$this->classeModel->verifierEleveClasse($_SESSION['user_id'], $devoir['classe_id'])) {
            $_SESSION['error'] = "Vous n'avez pas accès à ce devoir.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier que le devoir est visible
        if (!$devoir['est_visible']) {
            $_SESSION['error'] = "Ce devoir n'est pas encore visible.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier que la date limite n'est pas dépassée
        if (strtotime($devoir['date_limite']) < time()) {
            $_SESSION['error'] = "La date limite pour rendre ce devoir est dépassée.";
            header('Location: ' . BASE_URL . '/devoirs/details.php?id=' . $id);
            exit;
        }
        
        // Vérifier que l'élève n'a pas déjà rendu ce devoir
        if ($this->renduModel->verifierRenduExistant($id, $_SESSION['user_id'])) {
            $_SESSION['error'] = "Vous avez déjà rendu ce devoir.";
            header('Location: ' . BASE_URL . '/devoirs/details.php?id=' . $id);
            exit;
        }
        
        // Charger la vue
        require_once ROOT_PATH . '/views/devoirs/rendre.php';
    }
    
    /**
     * Traite la soumission d'un rendu
     */
    public function store() {
        // Vérifier que l'utilisateur est connecté
        requireAuthentication();
        
        // Vérifier que l'utilisateur est un élève
        if ($_SESSION['user_type'] !== TYPE_ELEVE) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour rendre un devoir.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer l'ID du devoir
        $devoirId = isset($_POST['devoir_id']) ? $_POST['devoir_id'] : null;
        
        if (!$devoirId) {
            $_SESSION['error'] = "Devoir non spécifié.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du devoir
        $devoir = $this->devoirModel->getDevoirById($devoirId);
        
        if (!$devoir) {
            $_SESSION['error'] = "Devoir introuvable.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier que l'élève appartient à la classe
        if (!$this->classeModel->verifierEleveClasse($_SESSION['user_id'], $devoir['classe_id'])) {
            $_SESSION['error'] = "Vous n'avez pas accès à ce devoir.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier que le devoir est visible
        if (!$devoir['est_visible']) {
            $_SESSION['error'] = "Ce devoir n'est pas encore visible.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier que la date limite n'est pas dépassée
        if (strtotime($devoir['date_limite']) < time()) {
            $_SESSION['error'] = "La date limite pour rendre ce devoir est dépassée.";
            header('Location: ' . BASE_URL . '/devoirs/details.php?id=' . $devoirId);
            exit;
        }
        
        // Vérifier que l'élève n'a pas déjà rendu ce devoir
        if ($this->renduModel->verifierRenduExistant($devoirId, $_SESSION['user_id'])) {
            $_SESSION['error'] = "Vous avez déjà rendu ce devoir.";
            header('Location: ' . BASE_URL . '/devoirs/details.php?id=' . $devoirId);
            exit;
        }
        
        // Récupérer les données du formulaire
        $data = [
            'devoir_id' => $devoirId,
            'eleve_id' => $_SESSION['user_id'],
            'commentaire' => $_POST['commentaire'] ?? '',
            'fichiers' => []
        ];
        
        // Vérifier qu'il y a soit un commentaire, soit des fichiers
        if (empty($data['commentaire']) && empty($_FILES['fichiers']['name'][0])) {
            $_SESSION['error'] = "Vous devez fournir un commentaire ou des fichiers.";
            header('Location: ' . BASE_URL . '/devoirs/rendre.php?id=' . $devoirId);
            exit;
        }
        
        // Traiter les fichiers uploadés
        if (!empty($_FILES['fichiers']['name'][0])) {
            $uploads = $this->handleFileUploads($_FILES['fichiers']);
            
            if (isset($uploads['errors']) && !empty($uploads['errors'])) {
                $_SESSION['errors'] = $uploads['errors'];
                header('Location: ' . BASE_URL . '/devoirs/rendre.php?id=' . $devoirId);
                exit;
            }
            
            $data['fichiers'] = $uploads['fichiers'];
        }
        
        // Créer le rendu
        $renduId = $this->renduModel->createRendu($data);
        
        if (!$renduId) {
            $_SESSION['error'] = "Une erreur est survenue lors de la création du rendu.";
            header('Location: ' . BASE_URL . '/devoirs/rendre.php?id=' . $devoirId);
            exit;
        }
        
        $_SESSION['success'] = "Votre devoir a été rendu avec succès.";
        header('Location: ' . BASE_URL . '/devoirs/details.php?id=' . $devoirId);
        exit;
    }
    
    /**
     * Affiche le formulaire d'édition d'un rendu
     */
    public function edit() {
        // Vérifier que l'utilisateur est connecté
        requireAuthentication();
        
        // Vérifier que l'utilisateur est un élève
        if ($_SESSION['user_type'] !== TYPE_ELEVE) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour modifier un rendu.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer l'ID du rendu
        $id = isset($_GET['id']) ? $_GET['id'] : null;
        
        if (!$id) {
            $_SESSION['error'] = "Rendu non spécifié.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du rendu
        $rendu = $this->renduModel->getRenduById($id);
        
        if (!$rendu) {
            $_SESSION['error'] = "Rendu introuvable.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier que l'élève est bien l'auteur du rendu
        if ($rendu['eleve_id'] != $_SESSION['user_id']) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour modifier ce rendu.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier que le rendu n'est pas déjà corrigé
        if ($rendu['statut'] === STATUT_CORRIGE) {
            $_SESSION['error'] = "Ce rendu a déjà été corrigé et ne peut plus être modifié.";
            header('Location: ' . BASE_URL . '/devoirs/details.php?id=' . $rendu['devoir_id']);
            exit;
        }
        
        // Récupérer les informations du devoir
        $devoir = $this->devoirModel->getDevoirById($rendu['devoir_id']);
        
        // Vérifier que la date limite n'est pas dépassée
        if (strtotime($devoir['date_limite']) < time()) {
            $_SESSION['error'] = "La date limite pour modifier ce rendu est dépassée.";
            header('Location: ' . BASE_URL . '/devoirs/details.php?id=' . $rendu['devoir_id']);
            exit;
        }
        
        // Charger la vue
        require_once ROOT_PATH . '/views/devoirs/editer_rendu.php';
    }
    
    /**
     * Traite le formulaire d'édition d'un rendu
     */
    public function update() {
        // Vérifier que l'utilisateur est connecté
        requireAuthentication();
        
        // Vérifier que l'utilisateur est un élève
        if ($_SESSION['user_type'] !== TYPE_ELEVE) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour modifier un rendu.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer l'ID du rendu
        $id = isset($_POST['id']) ? $_POST['id'] : null;
        
        if (!$id) {
            $_SESSION['error'] = "Rendu non spécifié.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du rendu
        $rendu = $this->renduModel->getRenduById($id);
        
        if (!$rendu) {
            $_SESSION['error'] = "Rendu introuvable.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier que l'élève est bien l'auteur du rendu
        if ($rendu['eleve_id'] != $_SESSION['user_id']) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour modifier ce rendu.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier que le rendu n'est pas déjà corrigé
        if ($rendu['statut'] === STATUT_CORRIGE) {
            $_SESSION['error'] = "Ce rendu a déjà été corrigé et ne peut plus être modifié.";
            header('Location: ' . BASE_URL . '/devoirs/details.php?id=' . $rendu['devoir_id']);
            exit;
        }
        
        // Récupérer les informations du devoir
        $devoir = $this->devoirModel->getDevoirById($rendu['devoir_id']);
        
        // Vérifier que la date limite n'est pas dépassée
        if (strtotime($devoir['date_limite']) < time()) {
            $_SESSION['error'] = "La date limite pour modifier ce rendu est dépassée.";
            header('Location: ' . BASE_URL . '/devoirs/details.php?id=' . $rendu['devoir_id']);
            exit;
        }
        
        // Récupérer les données du formulaire
        $data = [
            'commentaire' => $_POST['commentaire'] ?? '',
            'nouveaux_fichiers' => []
        ];
        
        // Traiter les fichiers uploadés
        if (!empty($_FILES['fichiers']['name'][0])) {
            $uploads = $this->handleFileUploads($_FILES['fichiers']);
            
            if (isset($uploads['errors']) && !empty($uploads['errors'])) {
                $_SESSION['errors'] = $uploads['errors'];
                header('Location: ' . BASE_URL . '/devoirs/editer_rendu.php?id=' . $id);
                exit;
            }
            
            $data['nouveaux_fichiers'] = $uploads['fichiers'];
        }
        
        // Mettre à jour le rendu
        $success = $this->renduModel->updateRendu($id, $data);
        
        if (!$success) {
            $_SESSION['error'] = "Une erreur est survenue lors de la mise à jour du rendu.";
            header('Location: ' . BASE_URL . '/devoirs/editer_rendu.php?id=' . $id);
            exit;
        }
        
        $_SESSION['success'] = "Votre rendu a été mis à jour avec succès.";
        header('Location: ' . BASE_URL . '/devoirs/details.php?id=' . $rendu['devoir_id']);
        exit;
    }
    
    /**
     * Affiche la page de correction d'un rendu
     */
    public function correction() {
        // Vérifier que l'utilisateur est connecté
        requireAuthentication();
        
        // Vérifier que l'utilisateur est un professeur
        if ($_SESSION['user_type'] !== TYPE_PROFESSEUR && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour corriger un rendu.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer l'ID du rendu
        $id = isset($_GET['id']) ? $_GET['id'] : null;
        
        if (!$id) {
            $_SESSION['error'] = "Rendu non spécifié.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du rendu
        $rendu = $this->renduModel->getRenduById($id);
        
        if (!$rendu) {
            $_SESSION['error'] = "Rendu introuvable.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier que le professeur est bien l'auteur du devoir
        if ($rendu['professeur_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour corriger ce rendu.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du devoir
        $devoir = $this->devoirModel->getDevoirById($rendu['devoir_id']);
        
        // Charger la vue
        require_once ROOT_PATH . '/views/devoirs/corriger.php';
    }
    
    /**
     * Traite le formulaire de correction d'un rendu
     */
    public function saveCorrection() {
        // Vérifier que l'utilisateur est connecté
        requireAuthentication();
        
        // Vérifier que l'utilisateur est un professeur
        if ($_SESSION['user_type'] !== TYPE_PROFESSEUR && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour corriger un rendu.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer l'ID du rendu
        $id = isset($_POST['id']) ? $_POST['id'] : null;
        
        if (!$id) {
            $_SESSION['error'] = "Rendu non spécifié.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du rendu
        $rendu = $this->renduModel->getRenduById($id);
        
        if (!$rendu) {
            $_SESSION['error'] = "Rendu introuvable.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier que le professeur est bien l'auteur du devoir
        if ($rendu['professeur_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour corriger ce rendu.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les données du formulaire
        $data = [
            'note' => isset($_POST['note']) ? $_POST['note'] : null,
            'commentaire_prof' => $_POST['commentaire_prof'] ?? '',
            'statut' => STATUT_CORRIGE,
            'date_correction' => date('Y-m-d H:i:s')
        ];
        
        // Vérifier que la note est valide
        if (!empty($data['note'])) {
            $data['note'] = (float) $data['note'];
            
            // Récupérer le barème du devoir
            $devoir = $this->devoirModel->getDevoirById($rendu['devoir_id']);
            
            if ($devoir && !empty($devoir['bareme_id']) && $data['note'] > $devoir['bareme_id']) {
                $_SESSION['error'] = "La note ne peut pas dépasser le barème (" . $devoir['bareme_id'] . ").";
                header('Location: ' . BASE_URL . '/devoirs/corriger.php?id=' . $id);
                exit;
            }
        }
        
        // Mettre à jour le rendu
        $success = $this->renduModel->updateRendu($id, $data);
        
        if (!$success) {
            $_SESSION['error'] = "Une erreur est survenue lors de la correction du rendu.";
            header('Location: ' . BASE_URL . '/devoirs/corriger.php?id=' . $id);
            exit;
        }
        
        $_SESSION['success'] = "Le rendu a été corrigé avec succès.";
        header('Location: ' . BASE_URL . '/devoirs/rendus.php?devoir_id=' . $rendu['devoir_id']);
        exit;
    }
    
    /**
     * Affiche la liste des rendus d'un devoir
     */
    public function index() {
        // Vérifier que l'utilisateur est connecté
        requireAuthentication();
        
        // Vérifier que l'utilisateur est un professeur
        if ($_SESSION['user_type'] !== TYPE_PROFESSEUR && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour voir les rendus.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer l'ID du devoir
        $devoirId = isset($_GET['devoir_id']) ? $_GET['devoir_id'] : null;
        
        if (!$devoirId) {
            $_SESSION['error'] = "Devoir non spécifié.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du devoir
        $devoir = $this->devoirModel->getDevoirById($devoirId);
        
        if (!$devoir) {
            $_SESSION['error'] = "Devoir introuvable.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier que le professeur est bien l'auteur du devoir
        if ($devoir['auteur_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour voir les rendus de ce devoir.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les filtres et la pagination
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $filters = $this->getFilters();
        
        // Ajouter l'ID du devoir aux filtres
        $filters['devoir_id'] = $devoirId;
        
        // Construire la chaîne de requête pour la pagination
        $queryString = '';
        foreach ($filters as $key => $value) {
            if ($key !== 'page' && $key !== 'devoir_id') {
                $queryString .= "&$key=" . urlencode($value);
            }
        }
        
        // Récupérer les rendus
        $rendus = $this->renduModel->getRendusByDevoir($devoirId, $filters, $page, ITEMS_PER_PAGE);
        $totalRendus = $this->renduModel->countRendusByDevoir($devoirId, $filters);
        
        // Récupérer les statistiques des rendus
        $stats = $this->renduModel->getStatistiquesRendu($devoirId);
        
        // Calculer le nombre total de pages
        $totalPages = ceil($totalRendus / ITEMS_PER_PAGE);
        
        // Charger la vue
        require_once ROOT_PATH . '/views/devoirs/rendus.php';
    }
    
    /**
     * Affiche les détails d'un rendu
     */
    public function show() {
        // Vérifier que l'utilisateur est connecté
        requireAuthentication();
        
        // Récupérer l'ID du rendu
        $id = isset($_GET['id']) ? $_GET['id'] : null;
        
        if (!$id) {
            $_SESSION['error'] = "Rendu non spécifié.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du rendu
        $rendu = $this->renduModel->getRenduById($id);
        
        if (!$rendu) {
            $_SESSION['error'] = "Rendu introuvable.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier les permissions
        if ($_SESSION['user_type'] === TYPE_ELEVE) {
            // Vérifier que l'élève est bien l'auteur du rendu ou que le devoir est en mode classe
            if ($rendu['eleve_id'] != $_SESSION['user_id']) {
                // Récupérer les informations du devoir
                $devoir = $this->devoirModel->getDevoirById($rendu['devoir_id']);
                
                if (!$devoir || $devoir['confidentialite'] !== 'classe' || !$this->classeModel->verifierEleveClasse($_SESSION['user_id'], $devoir['classe_id'])) {
                    $_SESSION['error'] = "Vous n'avez pas les droits pour voir ce rendu.";
                    header('Location: ' . BASE_URL . '/devoirs/details.php?id=' . $rendu['devoir_id']);
                    exit;
                }
            }
        } elseif ($_SESSION['user_type'] === TYPE_PROFESSEUR) {
            // Vérifier que le professeur est bien l'auteur du devoir
            if ($rendu['professeur_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
                $_SESSION['error'] = "Vous n'avez pas les droits pour voir ce rendu.";
                header('Location: ' . BASE_URL . '/devoirs/index.php');
                exit;
            }
        } elseif ($_SESSION['user_type'] === TYPE_PARENT) {
            // Vérifier que l'enfant est bien l'auteur du rendu
            $enfants = $this->userModel->getEnfantsParent($_SESSION['user_id']);
            $estParent = false;
            
            foreach ($enfants as $enfant) {
                if ($enfant['id'] == $rendu['eleve_id']) {
                    $estParent = true;
                    break;
                }
            }
            
            if (!$estParent && !$_SESSION['is_admin']) {
                $_SESSION['error'] = "Vous n'avez pas les droits pour voir ce rendu.";
                header('Location: ' . BASE_URL . '/devoirs/index.php');
                exit;
            }
        } elseif (!$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour voir ce rendu.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du devoir
        $devoir = $this->devoirModel->getDevoirById($rendu['devoir_id']);
        
        // Charger la vue
        require_once ROOT_PATH . '/views/devoirs/rendu_details.php';
    }
    
    /**
     * Exporte les rendus d'un devoir
     */
    public function export() {
        // Vérifier que l'utilisateur est connecté
        requireAuthentication();
        
        // Vérifier que l'utilisateur est un professeur
        if ($_SESSION['user_type'] !== TYPE_PROFESSEUR && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour exporter les rendus.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer l'ID du devoir
        $devoirId = isset($_GET['devoir_id']) ? $_GET['devoir_id'] : null;
        
        if (!$devoirId) {
            $_SESSION['error'] = "Devoir non spécifié.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du devoir
        $devoir = $this->devoirModel->getDevoirById($devoirId);
        
        if (!$devoir) {
            $_SESSION['error'] = "Devoir introuvable.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier que le professeur est bien l'auteur du devoir
        if ($devoir['auteur_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour exporter les rendus de ce devoir.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer tous les rendus du devoir
        $rendus = $this->renduModel->getRendusByDevoir($devoirId);
        
        // Exporter en PDF
        $pdf = new PDF("Rendus du devoir: " . $devoir['titre']);
        $result = $pdf->generateDevoirPDF($devoir, $rendus, "rendus_devoir_" . $devoirId . ".pdf", "D");
        
        // Pas besoin de redirection, le PDF est téléchargé
        exit;
    }
    
    /**
     * Traite les fichiers uploadés
     * @param array $files Tableau $_FILES['fichiers']
     * @return array Résultat du traitement
     */
    private function handleFileUploads($files) {
        $result = [
            'fichiers' => [],
            'errors' => []
        ];
        
        // Vérifier s'il y a des fichiers à traiter
        if (empty($files['name'][0])) {
            return $result;
        }
        
        // Créer le répertoire de destination s'il n'existe pas
        if (!file_exists(RENDUS_UPLOADS)) {
            mkdir(RENDUS_UPLOADS, 0755, true);
        }
        
        // Traiter chaque fichier
        $filesCount = count($files['name']);
        for ($i = 0; $i < $filesCount; $i++) {
            // Ignorer les fichiers vides
            if (empty($files['name'][$i])) {
                continue;
            }
            
            // Préparer le fichier pour l'upload
            $file = [
                'name' => $files['name'][$i],
                'type' => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i],
                'size' => $files['size'][$i]
            ];
            
            // Uploader le fichier
            $upload = $this->fileUpload->uploadFile($file, RENDUS_UPLOADS);
            
            if (!$upload['success']) {
                $result['errors'][] = "Erreur lors de l'upload du fichier {$file['name']}: {$upload['message']}";
                continue;
            }
            
            // Ajouter le fichier aux résultats
            $result['fichiers'][] = [
                'nom' => $file['name'],
                'type' => $file['type'],
                'fichier' => $upload['filename']
            ];
        }
        
        return $result;
    }
    
    /**
     * Filtre les paramètres de la requête
     * @return array Filtres à appliquer
     */
    private function getFilters() {
        $filters = [];
        
        // Filtrer par statut
        if (isset($_GET['statut']) && !empty($_GET['statut'])) {
            $filters['statut'] = $_GET['statut'];
        }
        
        // Filtrer par note
        if (isset($_GET['note_min']) && !empty($_GET['note_min'])) {
            $filters['note_min'] = $_GET['note_min'];
        }
        
        if (isset($_GET['note_max']) && !empty($_GET['note_max'])) {
            $filters['note_max'] = $_GET['note_max'];
        }
        
        // Recherche textuelle
        if (isset($_GET['search']) && !empty($_GET['search'])) {
            $filters['search'] = $_GET['search'];
        }
        
        // Page courante
        if (isset($_GET['page']) && !empty($_GET['page'])) {
            $filters['page'] = $_GET['page'];
        }
        
        return $filters;
    }
}