<?php
/**
 * Contrôleur pour la gestion des devoirs
 */
class DevoirsController {
    private $devoirModel;
    private $classeModel;
    private $renduModel;
    private $fileUpload;
    
    /**
     * Constructeur
     */
    public function __construct() {
        $this->devoirModel = new Devoir();
        $this->classeModel = new Classe();
        $this->renduModel = new Rendu();
        $this->fileUpload = new FileUpload();
    }
    
    /**
     * Affiche la liste des devoirs
     */
    public function index() {
        // Vérifier que l'utilisateur est connecté
        requireAuthentication();
        
        // Récupérer les filtres et la pagination
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $filters = $this->getFilters();
        
        // Construire la chaîne de requête pour la pagination
        $queryString = '';
        foreach ($filters as $key => $value) {
            if ($key !== 'page') {
                $queryString .= "&$key=" . urlencode($value);
            }
        }
        
        // Récupérer les devoirs selon le type d'utilisateur
        if ($_SESSION['user_type'] === TYPE_ELEVE) {
            // Devoirs de l'élève
            $devoirs = $this->devoirModel->getDevoirsEleve($_SESSION['user_id'], $filters);
            $totalDevoirs = count($devoirs); // Simplification
            
            // Récupérer les classes de l'élève
            $classes = $this->classeModel->getClassesEleve($_SESSION['user_id']);
        } elseif ($_SESSION['user_type'] === TYPE_PROFESSEUR) {
            // Devoirs du professeur
            $filters['auteur_id'] = $_SESSION['user_id'];
            $devoirs = $this->devoirModel->getAllDevoirs($filters, $page, ITEMS_PER_PAGE);
            $totalDevoirs = $this->devoirModel->countDevoirs($filters);
            
            // Récupérer les classes du professeur
            $classes = $this->classeModel->getClassesProfesseur($_SESSION['user_id']);
        } elseif ($_SESSION['user_type'] === TYPE_PARENT) {
            // Récupérer les enfants du parent
            $enfants = $this->userModel->getEnfantsParent($_SESSION['user_id']);
            
            // Si un enfant est spécifié, récupérer ses devoirs
            $enfantId = isset($_GET['enfant_id']) ? $_GET['enfant_id'] : (isset($enfants[0]) ? $enfants[0]['id'] : null);
            
            if ($enfantId) {
                $devoirs = $this->devoirModel->getDevoirsEleve($enfantId, $filters);
                $totalDevoirs = count($devoirs); // Simplification
            } else {
                $devoirs = [];
                $totalDevoirs = 0;
            }
        } else {
            // Administrateur ou autre type
            $devoirs = $this->devoirModel->getAllDevoirs($filters, $page, ITEMS_PER_PAGE);
            $totalDevoirs = $this->devoirModel->countDevoirs($filters);
            
            // Récupérer toutes les classes
            $classes = $this->classeModel->getAllClasses();
        }
        
        // Calculer le nombre total de pages
        $totalPages = ceil($totalDevoirs / ITEMS_PER_PAGE);
        
        // Charger la vue
        require_once ROOT_PATH . '/views/devoirs/liste.php';
    }
    
    /**
     * Affiche les détails d'un devoir
     */
    public function show() {
        // Vérifier que l'utilisateur est connecté
        requireAuthentication();
        
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
        
        // Vérifier les permissions
        if ($_SESSION['user_type'] === TYPE_ELEVE) {
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
            
            // Récupérer le rendu de l'élève
            $rendu = $this->renduModel->getRenduEleve($id, $_SESSION['user_id']);
        } elseif ($_SESSION['user_type'] === TYPE_PROFESSEUR && $devoir['auteur_id'] != $_SESSION['user_id']) {
            // Vérifier que le professeur est bien l'auteur du devoir
            if (!$_SESSION['is_admin']) {
                $_SESSION['error'] = "Vous n'avez pas accès à ce devoir.";
                header('Location: ' . BASE_URL . '/devoirs/index.php');
                exit;
            }
            
            // Récupérer les rendus du devoir
            $rendus = $this->renduModel->getRendusByDevoir($id);
            
            // Récupérer les statistiques des rendus
            $stats = $this->renduModel->getStatistiquesRendu($id);
        } elseif ($_SESSION['user_type'] === TYPE_PARENT) {
            // Récupérer les enfants du parent
            $enfants = $this->userModel->getEnfantsParent($_SESSION['user_id']);
            
            // Vérifier qu'au moins un enfant est dans la classe du devoir
            $enfantTrouve = false;
            foreach ($enfants as $enfant) {
                if ($this->classeModel->verifierEleveClasse($enfant['id'], $devoir['classe_id'])) {
                    $enfantTrouve = true;
                    
                    // Récupérer le rendu de l'enfant
                    $rendu = $this->renduModel->getRenduEleve($id, $enfant['id']);
                    break;
                }
            }
            
            if (!$enfantTrouve) {
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
        } else {
            // Professeur auteur ou administrateur
            $rendus = $this->renduModel->getRendusByDevoir($id);
            
            // Récupérer les statistiques des rendus
            $stats = $this->renduModel->getStatistiquesRendu($id);
        }
        
        // Récupérer les pièces jointes du devoir
        $piecesJointes = $this->devoirModel->getPiecesJointes($id);
        
        // Charger la vue
        require_once ROOT_PATH . '/views/devoirs/details.php';
    }
    
    /**
     * Affiche le formulaire de création d'un devoir
     */
    public function create() {
        // Vérifier que l'utilisateur est connecté
        requireAuthentication();
        
        // Vérifier que l'utilisateur est un professeur
        if ($_SESSION['user_type'] !== TYPE_PROFESSEUR && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour créer un devoir.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les classes du professeur
        $classes = $this->classeModel->getClassesProfesseur($_SESSION['user_id']);
        
        // Charger la vue
        require_once ROOT_PATH . '/views/devoirs/creer.php';
    }
    
    /**
     * Traite le formulaire de création d'un devoir
     */
    public function store() {
        // Vérifier que l'utilisateur est connecté
        requireAuthentication();
        
        // Vérifier que l'utilisateur est un professeur
        if ($_SESSION['user_type'] !== TYPE_PROFESSEUR && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour créer un devoir.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les données du formulaire
        $data = [
            'titre' => $_POST['titre'] ?? '',
            'description' => $_POST['description'] ?? '',
            'instructions' => $_POST['instructions'] ?? '',
            'date_debut' => $_POST['date_debut'] ?? '',
            'date_limite' => $_POST['date_limite'] ?? '',
            'classe_id' => $_POST['classe_id'] ?? null,
            'groupes' => isset($_POST['groupes']) ? $_POST['groupes'] : [],
            'travail_groupe' => isset($_POST['travail_groupe']) ? 1 : 0,
            'est_obligatoire' => isset($_POST['est_obligatoire']) ? 1 : 0,
            'est_visible' => isset($_POST['est_visible']) ? 1 : 0,
            'confidentialite' => $_POST['confidentialite'] ?? 'eleve',
            'bareme_id' => !empty($_POST['bareme']) ? $_POST['bareme'] : null,
            'auteur_id' => $_SESSION['user_id'],
            'pieces_jointes' => []
        ];
        
        // Valider les données
        $errors = $this->validateDevoir($data);
        
        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            $_SESSION['form_data'] = $data;
            header('Location: ' . BASE_URL . '/devoirs/creer.php');
            exit;
        }
        
        // Traiter les fichiers uploadés
        if (isset($_FILES['fichiers']) && !empty($_FILES['fichiers']['name'][0])) {
            $uploads = $this->handleFileUploads($_FILES['fichiers']);
            
            if (isset($uploads['errors']) && !empty($uploads['errors'])) {
                $_SESSION['errors'] = $uploads['errors'];
                $_SESSION['form_data'] = $data;
                header('Location: ' . BASE_URL . '/devoirs/creer.php');
                exit;
            }
            
            $data['pieces_jointes'] = $uploads['piece_jointe_ids'];
        }
        
        // Créer le devoir
        $devoirId = $this->devoirModel->createDevoir($data);
        
        if (!$devoirId) {
            $_SESSION['error'] = "Une erreur est survenue lors de la création du devoir.";
            $_SESSION['form_data'] = $data;
            header('Location: ' . BASE_URL . '/devoirs/creer.php');
            exit;
        }
        
        $_SESSION['success'] = "Le devoir a été créé avec succès.";
        header('Location: ' . BASE_URL . '/devoirs/details.php?id=' . $devoirId);
        exit;
    }
    
    /**
     * Affiche le formulaire d'édition d'un devoir
     */
    public function edit() {
        // Vérifier que l'utilisateur est connecté
        requireAuthentication();
        
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
        
        // Vérifier que l'utilisateur est bien l'auteur du devoir
        if ($devoir['auteur_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour modifier ce devoir.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les classes du professeur
        $classes = $this->classeModel->getClassesProfesseur($_SESSION['user_id']);
        
        // Récupérer les groupes de la classe
        $groupes = $this->classeModel->getGroupes($devoir['classe_id']);
        
        // Récupérer les IDs des groupes associés au devoir
        $groupesIds = [];
        foreach ($devoir['groupes'] as $groupe) {
            $groupesIds[] = $groupe['id'];
        }
        
        // Récupérer les pièces jointes du devoir
        $piecesJointes = $this->devoirModel->getPiecesJointes($id);
        
        // Charger la vue
        require_once ROOT_PATH . '/views/devoirs/editer.php';
    }
    
    /**
     * Traite le formulaire d'édition d'un devoir
     */
    public function update() {
        // Vérifier que l'utilisateur est connecté
        requireAuthentication();
        
        // Récupérer l'ID du devoir
        $id = isset($_POST['id']) ? $_POST['id'] : null;
        
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
        
        // Vérifier que l'utilisateur est bien l'auteur du devoir
        if ($devoir['auteur_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour modifier ce devoir.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les données du formulaire
        $data = [
            'titre' => $_POST['titre'] ?? '',
            'description' => $_POST['description'] ?? '',
            'instructions' => $_POST['instructions'] ?? '',
            'date_debut' => $_POST['date_debut'] ?? '',
            'date_limite' => $_POST['date_limite'] ?? '',
            'classe_id' => $_POST['classe_id'] ?? null,
            'groupes' => isset($_POST['groupes']) ? $_POST['groupes'] : [],
            'travail_groupe' => isset($_POST['travail_groupe']) ? 1 : 0,
            'est_obligatoire' => isset($_POST['est_obligatoire']) ? 1 : 0,
            'est_visible' => isset($_POST['est_visible']) ? 1 : 0,
            'confidentialite' => $_POST['confidentialite'] ?? 'eleve',
            'bareme_id' => !empty($_POST['bareme']) ? $_POST['bareme'] : null
        ];
        
        // Valider les données
        $errors = $this->validateDevoir($data);
        
        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            $_SESSION['form_data'] = $data;
            header('Location: ' . BASE_URL . '/devoirs/editer.php?id=' . $id);
            exit;
        }
        
        // Traiter les fichiers uploadés
        if (isset($_FILES['fichiers']) && !empty($_FILES['fichiers']['name'][0])) {
            $uploads = $this->handleFileUploads($_FILES['fichiers']);
            
            if (isset($uploads['errors']) && !empty($uploads['errors'])) {
                $_SESSION['errors'] = $uploads['errors'];
                $_SESSION['form_data'] = $data;
                header('Location: ' . BASE_URL . '/devoirs/editer.php?id=' . $id);
                exit;
            }
            
            $data['pieces_jointes'] = $uploads['piece_jointe_ids'];
        }
        
        // Mettre à jour le devoir
        $success = $this->devoirModel->updateDevoir($id, $data);
        
        if (!$success) {
            $_SESSION['error'] = "Une erreur est survenue lors de la mise à jour du devoir.";
            $_SESSION['form_data'] = $data;
            header('Location: ' . BASE_URL . '/devoirs/editer.php?id=' . $id);
            exit;
        }
        
        $_SESSION['success'] = "Le devoir a été mis à jour avec succès.";
        header('Location: ' . BASE_URL . '/devoirs/details.php?id=' . $id);
        exit;
    }
    
    /**
     * Traite la suppression d'un devoir
     */
    public function delete() {
        // Vérifier que l'utilisateur est connecté
        requireAuthentication();
        
        // Récupérer l'ID du devoir
        $id = isset($_POST['id']) ? $_POST['id'] : null;
        
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
        
        // Vérifier que l'utilisateur est bien l'auteur du devoir
        if ($devoir['auteur_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour supprimer ce devoir.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Supprimer le devoir
        $success = $this->devoirModel->deleteDevoir($id);
        
        if (!$success) {
            $_SESSION['error'] = "Une erreur est survenue lors de la suppression du devoir.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        $_SESSION['success'] = "Le devoir a été supprimé avec succès.";
        header('Location: ' . BASE_URL . '/devoirs/index.php');
        exit;
    }
    
    /**
     * Filtre les paramètres de la requête
     * @return array Filtres à appliquer
     */
    private function getFilters() {
        $filters = [];
        
        // Filtrer par classe
        if (isset($_GET['classe_id']) && !empty($_GET['classe_id'])) {
            $filters['classe_id'] = $_GET['classe_id'];
        }
        
        // Filtrer par statut
        if (isset($_GET['statut']) && !empty($_GET['statut'])) {
            $filters['statut'] = $_GET['statut'];
        }
        
        // Filtrer par date
        if (isset($_GET['date_debut']) && !empty($_GET['date_debut'])) {
            $filters['date_debut'] = $_GET['date_debut'];
        }
        
        if (isset($_GET['date_fin']) && !empty($_GET['date_fin'])) {
            $filters['date_fin'] = $_GET['date_fin'];
        }
        
        // Recherche textuelle
        if (isset($_GET['search']) && !empty($_GET['search'])) {
            $filters['search'] = $_GET['search'];
        }
        
        // Tri
        if (isset($_GET['order_by']) && !empty($_GET['order_by'])) {
            $filters['order_by'] = $_GET['order_by'];
        }
        
        // Enfant (pour les parents)
        if (isset($_GET['enfant_id']) && !empty($_GET['enfant_id'])) {
            $filters['enfant_id'] = $_GET['enfant_id'];
        }
        
        // Page courante
        if (isset($_GET['page']) && !empty($_GET['page'])) {
            $filters['page'] = $_GET['page'];
        }
        
        return $filters;
    }
    
    /**
     * Valide les données d'un devoir
     * @param array $data Données à valider
     * @return array Erreurs de validation
     */
    private function validateDevoir($data) {
        $errors = [];
        
        // Vérifier le titre
        if (empty($data['titre'])) {
            $errors[] = "Le titre du devoir est obligatoire.";
        }
        
        // Vérifier la classe
        if (empty($data['classe_id'])) {
            $errors[] = "La classe est obligatoire.";
        }
        
        // Vérifier les dates
        if (empty($data['date_debut'])) {
            $errors[] = "La date de début est obligatoire.";
        }
        
        if (empty($data['date_limite'])) {
            $errors[] = "La date limite est obligatoire.";
        }
        
        // Vérifier que la date de début est antérieure à la date limite
        if (!empty($data['date_debut']) && !empty($data['date_limite'])) {
            $debut = new DateTime($data['date_debut']);
            $limite = new DateTime($data['date_limite']);
            
            if ($debut > $limite) {
                $errors[] = "La date de début doit être antérieure à la date limite.";
            }
        }
        
        return $errors;
    }
    
    /**
     * Traite les fichiers uploadés
     * @param array $files Tableau $_FILES['fichiers']
     * @return array Résultat du traitement
     */
    private function handleFileUploads($files) {
        $result = [
            'piece_jointe_ids' => [],
            'errors' => []
        ];
        
        // Vérifier s'il y a des fichiers à traiter
        if (empty($files['name'][0])) {
            return $result;
        }
        
        // Créer le répertoire de destination s'il n'existe pas
        if (!file_exists(DEVOIRS_UPLOADS)) {
            mkdir(DEVOIRS_UPLOADS, 0755, true);
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
            $upload = $this->fileUpload->uploadFile($file, DEVOIRS_UPLOADS);
            
            if (!$upload['success']) {
                $result['errors'][] = "Erreur lors de l'upload du fichier {$file['name']}: {$upload['message']}";
                continue;
            }
            
            // Déterminer le type de fichier
            $fileType = 'FILE';
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp'])) {
                $fileType = 'IMG';
            } elseif ($extension === 'pdf') {
                $fileType = 'PDF';
            } elseif (in_array($extension, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'])) {
                $fileType = 'DOC';
            }
            
            // Créer l'entrée dans la base de données
            $pieceJointeId = $this->pieceJointeModel->createPieceJointe([
                'nom' => $file['name'],
                'type' => $fileType,
                'fichier' => $upload['filename'],
                'date_ajout' => date('Y-m-d H:i:s')
            ]);
            
            if (!$pieceJointeId) {
                $result['errors'][] = "Erreur lors de l'enregistrement du fichier {$file['name']} dans la base de données.";
                // Supprimer le fichier physique
                @unlink(DEVOIRS_UPLOADS . '/' . $upload['filename']);
                continue;
            }
            
            $result['piece_jointe_ids'][] = $pieceJointeId;
        }
        
        return $result;
    }
}