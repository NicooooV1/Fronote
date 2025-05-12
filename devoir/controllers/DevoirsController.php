<?php
/**
 * Contrôleur pour la gestion des devoirs
 */
class DevoirsController {
    private $devoirModel;
    private $renduModel;
    private $classeModel;
    private $userModel;
    
    public function __construct() {
        // Initialisation des modèles
        require_once ROOT_PATH . '/models/Devoir.php';
        require_once ROOT_PATH . '/models/Rendu.php';
        require_once ROOT_PATH . '/models/Classe.php';
        require_once ROOT_PATH . '/models/User.php';
        
        $this->devoirModel = new Devoir();
        $this->renduModel = new Rendu();
        $this->classeModel = new Classe();
        $this->userModel = new User();
        
        // Vérifier que l'utilisateur est connecté
        if (!isset($_SESSION['user_id'])) {
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
    }
    
    /**
     * Affiche la liste des devoirs
     */
    public function index() {
        // Récupérer les paramètres de filtrage et pagination
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $search = isset($_GET['search']) ? $_GET['search'] : '';
        $classeId = isset($_GET['classe_id']) ? (int)$_GET['classe_id'] : null;
        $statut = isset($_GET['statut']) ? $_GET['statut'] : null;
        $orderBy = isset($_GET['order_by']) ? $_GET['order_by'] : 'date_limite ASC';
        
        // Dates pour filtrer
        $dateDebut = isset($_GET['date_debut']) ? $_GET['date_debut'] : null;
        $dateFin = isset($_GET['date_fin']) ? $_GET['date_fin'] : null;
        
        // Construction des filtres
        $filters = [
            'search' => $search,
            'classe_id' => $classeId,
            'statut' => $statut,
            'order_by' => $orderBy,
            'date_debut' => $dateDebut,
            'date_fin' => $dateFin
        ];
        
        // Adapter les filtres selon le type d'utilisateur
        $userType = $_SESSION['user_type'];
        $userId = $_SESSION['user_id'];
        
        if ($userType === TYPE_PROFESSEUR) {
            // Les professeurs voient leurs devoirs et peuvent filtrer par classe
            $filters['auteur_id'] = $userId;
        } elseif ($userType === TYPE_ELEVE) {
            // Les élèves voient les devoirs de leurs classes
            // Redirigez vers la vue élève spécifique
            return $this->devoirsEleve();
        } elseif ($userType === TYPE_PARENT) {
            // Les parents verront les devoirs de leurs enfants
            // Redirigez vers la vue parent spécifique
            return $this->devoirsParent();
        }
        
        // Récupérer les devoirs selon les filtres
        $devoirs = $this->devoirModel->getAllDevoirs($filters, $page);
        $totalDevoirs = $this->devoirModel->countDevoirs($filters);
        
        // Calculer le nombre total de pages
        $totalPages = ceil($totalDevoirs / ITEMS_PER_PAGE);
        
        // Récupérer la liste des classes pour le filtre
        $classes = $this->classeModel->getAllClasses();
        
        // Charger la vue
        require_once ROOT_PATH . '/views/devoirs/liste.php';
    }
    
    /**
     * Affiche les devoirs pour un élève
     */
    public function devoirsEleve() {
        $userId = $_SESSION['user_id'];
        
        // Récupérer les paramètres de filtrage
        $search = isset($_GET['search']) ? $_GET['search'] : '';
        $statut = isset($_GET['statut']) ? $_GET['statut'] : null;
        $orderBy = isset($_GET['order_by']) ? $_GET['order_by'] : 'date_limite ASC';
        
        // Dates pour filtrer
        $dateDebut = isset($_GET['date_debut']) ? $_GET['date_debut'] : null;
        $dateFin = isset($_GET['date_fin']) ? $_GET['date_fin'] : null;
        
        // Construction des filtres
        $filters = [
            'search' => $search,
            'statut' => $statut,
            'order_by' => $orderBy,
            'date_debut' => $dateDebut,
            'date_fin' => $dateFin
        ];
        
        // Récupérer les devoirs de l'élève
        $devoirs = $this->devoirModel->getDevoirsEleve($userId, $filters);
        
        // Récupérer les classes de l'élève pour le filtre
        $classes = $this->classeModel->getClassesEleve($userId);
        
        // Charger la vue
        require_once ROOT_PATH . '/views/devoirs/liste_eleve.php';
    }
    
    /**
     * Affiche les devoirs pour un parent (pour ses enfants)
     */
    public function devoirsParent() {
        $userId = $_SESSION['user_id'];
        
        // Récupérer les enfants du parent
        $enfants = $this->userModel->getEnfantsParent($userId);
        
        if (empty($enfants)) {
            // Rediriger ou afficher un message si aucun enfant trouvé
            $_SESSION['message'] = "Aucun enfant associé à ce compte parent.";
            header('Location: ' . BASE_URL . '/dashboard.php');
            exit;
        }
        
        // Récupérer l'ID de l'enfant sélectionné
        $enfantId = isset($_GET['enfant_id']) ? (int)$_GET['enfant_id'] : $enfants[0]['id'];
        
        // Récupérer les paramètres de filtrage
        $search = isset($_GET['search']) ? $_GET['search'] : '';
        $statut = isset($_GET['statut']) ? $_GET['statut'] : null;
        $orderBy = isset($_GET['order_by']) ? $_GET['order_by'] : 'date_limite ASC';
        
        // Dates pour filtrer
        $dateDebut = isset($_GET['date_debut']) ? $_GET['date_debut'] : null;
        $dateFin = isset($_GET['date_fin']) ? $_GET['date_fin'] : null;
        
        // Construction des filtres
        $filters = [
            'search' => $search,
            'statut' => $statut,
            'order_by' => $orderBy,
            'date_debut' => $dateDebut,
            'date_fin' => $dateFin
        ];
        
        // Récupérer les devoirs de l'enfant sélectionné
        $devoirs = $this->devoirModel->getDevoirsEleve($enfantId, $filters);
        
        // Récupérer les classes de l'enfant pour le filtre
        $classes = $this->classeModel->getClassesEleve($enfantId);
        
        // Charger la vue
        require_once ROOT_PATH . '/views/devoirs/liste_parent.php';
    }
    
    /**
     * Affiche les détails d'un devoir
     */
    public function details() {
        // Récupérer l'ID du devoir depuis l'URL
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        
        if ($id === 0) {
            $_SESSION['error'] = "ID de devoir non spécifié.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du devoir
        $devoir = $this->devoirModel->getDevoirById($id);
        
        if (!$devoir) {
            $_SESSION['error'] = "Devoir non trouvé.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier les autorisations
        $userType = $_SESSION['user_type'];
        $userId = $_SESSION['user_id'];
        
        // Récupérer les pièces jointes
        $piecesJointes = $this->devoirModel->getPiecesJointes($id);
        
        // Traitement spécifique selon le type d'utilisateur
        if ($userType === TYPE_ELEVE) {
            // Récupérer le rendu de l'élève pour ce devoir
            $rendu = $this->renduModel->getRenduEleve($id, $userId);
            
            // Charger la vue élève
            require_once ROOT_PATH . '/views/devoirs/details_eleve.php';
        } elseif ($userType === TYPE_PROFESSEUR) {
            // Vérifier si le professeur est l'auteur du devoir
            if ($devoir['auteur_id'] != $userId && !$_SESSION['is_admin']) {
                $_SESSION['error'] = "Vous n'êtes pas autorisé à accéder à ce devoir.";
                header('Location: ' . BASE_URL . '/devoirs/index.php');
                exit;
            }
            
            // Récupérer tous les rendus pour ce devoir
            $rendus = $this->renduModel->getRendusByDevoir($id);
            
            // Récupérer les statistiques de rendu
            $stats = $this->devoirModel->getStatistiquesRendu($id);
            
            // Charger la vue professeur
            require_once ROOT_PATH . '/views/devoirs/details_prof.php';
        } elseif ($userType === TYPE_PARENT) {
            // Vérifier si l'enfant a accès à ce devoir
            $enfantId = isset($_GET['enfant_id']) ? (int)$_GET['enfant_id'] : 0;
            $enfants = $this->userModel->getEnfantsParent($userId);
            
            $enfantAuthorise = false;
            foreach ($enfants as $enfant) {
                if ($enfant['id'] == $enfantId) {
                    $enfantAuthorise = true;
                    break;
                }
            }
            
            if (!$enfantAuthorise) {
                $_SESSION['error'] = "Vous n'êtes pas autorisé à accéder à ce devoir.";
                header('Location: ' . BASE_URL . '/devoirs/index.php');
                exit;
            }
            
            // Récupérer le rendu de l'enfant pour ce devoir
            $rendu = $this->renduModel->getRenduEleve($id, $enfantId);
            
            // Charger la vue parent
            require_once ROOT_PATH . '/views/devoirs/details_parent.php';
        } else {
            // Administrateur
            // Récupérer tous les rendus pour ce devoir
            $rendus = $this->renduModel->getRendusByDevoir($id);
            
            // Récupérer les statistiques de rendu
            $stats = $this->devoirModel->getStatistiquesRendu($id);
            
            // Charger la vue admin
            require_once ROOT_PATH . '/views/devoirs/details_admin.php';
        }
    }
    
    /**
     * Affiche le formulaire de création d'un devoir
     */
    public function creer() {
        // Vérifier si l'utilisateur est un professeur ou admin
        if ($_SESSION['user_type'] !== TYPE_PROFESSEUR && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à créer des devoirs.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer la liste des classes pour le formulaire
        $classes = $this->classeModel->getAllClasses();
        
        // Si le professeur a des classes assignées, filtrer la liste
        if ($_SESSION['user_type'] === TYPE_PROFESSEUR) {
            $classesProf = $this->classeModel->getClassesProfesseur($_SESSION['user_id']);
            $classes = $classesProf;
        }
        
        // Récupérer la liste des barèmes
        require_once ROOT_PATH . '/models/Bareme.php';
        $baremeModel = new Bareme();
        $baremes = $baremeModel->getAllBaremes();
        
        // Charger la vue
        require_once ROOT_PATH . '/views/devoirs/creer.php';
    }
    
    /**
     * Traite la soumission du formulaire de création d'un devoir
     */
    public function store() {
        // Vérifier si l'utilisateur est un professeur ou admin
        if ($_SESSION['user_type'] !== TYPE_PROFESSEUR && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à créer des devoirs.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier la méthode de requête
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['error'] = "Méthode non autorisée.";
            header('Location: ' . BASE_URL . '/devoirs/creer.php');
            exit;
        }
        
        // Récupérer et valider les données du formulaire
        $titre = isset($_POST['titre']) ? trim($_POST['titre']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        $instructions = isset($_POST['instructions']) ? trim($_POST['instructions']) : '';
        $dateDebut = isset($_POST['date_debut']) ? $_POST['date_debut'] : '';
        $dateLimite = isset($_POST['date_limite']) ? $_POST['date_limite'] : '';
        $classeId = isset($_POST['classe_id']) ? (int)$_POST['classe_id'] : 0;
        $baremeId = isset($_POST['bareme_id']) ? (int)$_POST['bareme_id'] : null;
        $travailGroupe = isset($_POST['travail_groupe']) ? 1 : 0;
        $estVisible = isset($_POST['est_visible']) ? 1 : 0;
        $estObligatoire = isset($_POST['est_obligatoire']) ? 1 : 0;
        $confidentialite = isset($_POST['confidentialite']) ? $_POST['confidentialite'] : 'classe';
        $groupes = isset($_POST['groupes']) ? $_POST['groupes'] : [];
        
        // Validation des données
        $errors = [];
        
        if (empty($titre)) {
            $errors[] = "Le titre est obligatoire.";
        }
        
        if (empty($description)) {
            $errors[] = "La description est obligatoire.";
        }
        
        if (empty($dateDebut)) {
            $errors[] = "La date de début est obligatoire.";
        }
        
        if (empty($dateLimite)) {
            $errors[] = "La date limite est obligatoire.";
        }
        
        if ($classeId <= 0) {
            $errors[] = "Veuillez sélectionner une classe.";
        }
        
        // Vérifier que la date limite est après la date de début
        if (!empty($dateDebut) && !empty($dateLimite)) {
            $debut = new DateTime($dateDebut);
            $limite = new DateTime($dateLimite);
            
            if ($limite <= $debut) {
                $errors[] = "La date limite doit être après la date de début.";
            }
        }
        
        // Si des erreurs sont présentes, afficher les erreurs et retourner au formulaire
        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            $_SESSION['form_data'] = $_POST; // Conserver les données du formulaire
            header('Location: ' . BASE_URL . '/devoirs/creer.php');
            exit;
        }
        
        // Traitement des pièces jointes
        $piecesJointes = [];
        
        if (isset($_FILES['pieces_jointes']) && !empty($_FILES['pieces_jointes']['name'][0])) {
            require_once ROOT_PATH . '/utils/FileUpload.php';
            $fileUpload = new FileUpload();
            
            for ($i = 0; $i < count($_FILES['pieces_jointes']['name']); $i++) {
                $file = [
                    'name' => $_FILES['pieces_jointes']['name'][$i],
                    'type' => $_FILES['pieces_jointes']['type'][$i],
                    'tmp_name' => $_FILES['pieces_jointes']['tmp_name'][$i],
                    'error' => $_FILES['pieces_jointes']['error'][$i],
                    'size' => $_FILES['pieces_jointes']['size'][$i]
                ];
                
                $uploadResult = $fileUpload->uploadFile($file, DEVOIRS_UPLOADS);
                
                if ($uploadResult['success']) {
                    // Créer une entrée dans la table pieces_jointes
                    require_once ROOT_PATH . '/models/PieceJointe.php';
                    $pieceJointeModel = new PieceJointe();
                    
                    $pieceJointeId = $pieceJointeModel->createPieceJointe([
                        'nom' => $file['name'],
                        'type' => $this->determinerTypeFichier($file['name']),
                        'fichier' => $uploadResult['filename'],
                        'description' => ''
                    ]);
                    
                    if ($pieceJointeId) {
                        $piecesJointes[] = $pieceJointeId;
                    }
                } else {
                    $errors[] = "Erreur lors du téléchargement du fichier {$file['name']}: {$uploadResult['message']}";
                }
            }
        }
        
        // Créer le devoir dans la base de données
        $devoirData = [
            'titre' => $titre,
            'description' => $description,
            'instructions' => $instructions,
            'date_debut' => $dateDebut,
            'date_limite' => $dateLimite,
            'auteur_id' => $_SESSION['user_id'],
            'classe_id' => $classeId,
            'bareme_id' => $baremeId,
            'travail_groupe' => $travailGroupe,
            'est_visible' => $estVisible,
            'est_obligatoire' => $estObligatoire,
            'confidentialite' => $confidentialite,
            'groupes' => $groupes,
            'pieces_jointes' => $piecesJointes
        ];
        
        $devoirId = $this->devoirModel->createDevoir($devoirData);
        
        if ($devoirId) {
            // Envoi de notifications aux élèves concernés
            $this->envoyerNotifications($devoirId, 'creation');
            
            $_SESSION['success'] = "Le devoir a été créé avec succès.";
            header('Location: ' . BASE_URL . '/devoirs/details.php?id=' . $devoirId);
            exit;
        } else {
            $_SESSION['error'] = "Une erreur est survenue lors de la création du devoir.";
            $_SESSION['form_data'] = $_POST;
            header('Location: ' . BASE_URL . '/devoirs/creer.php');
            exit;
        }
    }
    
    /**
     * Affiche le formulaire d'édition d'un devoir
     */
    public function editer() {
        // Récupérer l'ID du devoir depuis l'URL
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        
        if ($id === 0) {
            $_SESSION['error'] = "ID de devoir non spécifié.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du devoir
        $devoir = $this->devoirModel->getDevoirById($id);
        
        if (!$devoir) {
            $_SESSION['error'] = "Devoir non trouvé.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier si l'utilisateur est le créateur du devoir ou un admin
        if ($devoir['auteur_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à modifier ce devoir.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer la liste des classes pour le formulaire
        $classes = $this->classeModel->getAllClasses();
        
        // Si le professeur a des classes assignées, filtrer la liste
        if ($_SESSION['user_type'] === TYPE_PROFESSEUR) {
            $classesProf = $this->classeModel->getClassesProfesseur($_SESSION['user_id']);
            $classes = $classesProf;
        }
        
        // Récupérer la liste des barèmes
        require_once ROOT_PATH . '/models/Bareme.php';
        $baremeModel = new Bareme();
        $baremes = $baremeModel->getAllBaremes();
        
        // Récupérer les groupes associés au devoir
        $groupesDevoir = [];
        if (!empty($devoir['groupes'])) {
            foreach ($devoir['groupes'] as $groupe) {
                $groupesDevoir[] = $groupe['id'];
            }
        }
        
        // Récupérer les pièces jointes du devoir
        $piecesJointes = $this->devoirModel->getPiecesJointes($id);
        
        // Charger la vue
        require_once ROOT_PATH . '/views/devoirs/editer.php';
    }
    
    /**
     * Traite la soumission du formulaire d'édition d'un devoir
     */
    public function update() {
        // Vérifier la méthode de requête
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['error'] = "Méthode non autorisée.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer l'ID du devoir depuis le formulaire
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        
        if ($id === 0) {
            $_SESSION['error'] = "ID de devoir non spécifié.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du devoir
        $devoir = $this->devoirModel->getDevoirById($id);
        
        if (!$devoir) {
            $_SESSION['error'] = "Devoir non trouvé.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier si l'utilisateur est le créateur du devoir ou un admin
        if ($devoir['auteur_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à modifier ce devoir.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer et valider les données du formulaire
        $titre = isset($_POST['titre']) ? trim($_POST['titre']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        $instructions = isset($_POST['instructions']) ? trim($_POST['instructions']) : '';
        $dateDebut = isset($_POST['date_debut']) ? $_POST['date_debut'] : '';
        $dateLimite = isset($_POST['date_limite']) ? $_POST['date_limite'] : '';
        $classeId = isset($_POST['classe_id']) ? (int)$_POST['classe_id'] : 0;
        $baremeId = isset($_POST['bareme_id']) ? (int)$_POST['bareme_id'] : null;
        $statut = isset($_POST['statut']) ? $_POST['statut'] : STATUT_A_FAIRE;
        $travailGroupe = isset($_POST['travail_groupe']) ? 1 : 0;
        $estVisible = isset($_POST['est_visible']) ? 1 : 0;
        $estObligatoire = isset($_POST['est_obligatoire']) ? 1 : 0;
        $confidentialite = isset($_POST['confidentialite']) ? $_POST['confidentialite'] : 'classe';
        $groupes = isset($_POST['groupes']) ? $_POST['groupes'] : [];
        
        // Validation des données (similaire à la méthode store)
        $errors = [];
        
        if (empty($titre)) {
            $errors[] = "Le titre est obligatoire.";
        }
        
        if (empty($description)) {
            $errors[] = "La description est obligatoire.";
        }
        
        if (empty($dateDebut)) {
            $errors[] = "La date de début est obligatoire.";
        }
        
        if (empty($dateLimite)) {
            $errors[] = "La date limite est obligatoire.";
        }
        
        if ($classeId <= 0) {
            $errors[] = "Veuillez sélectionner une classe.";
        }
        
        // Vérifier que la date limite est après la date de début
        if (!empty($dateDebut) && !empty($dateLimite)) {
            $debut = new DateTime($dateDebut);
            $limite = new DateTime($dateLimite);
            
            if ($limite <= $debut) {
                $errors[] = "La date limite doit être après la date de début.";
            }
        }
        
        // Si des erreurs sont présentes, afficher les erreurs et retourner au formulaire
        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            $_SESSION['form_data'] = $_POST;
            header('Location: ' . BASE_URL . '/devoirs/editer.php?id=' . $id);
            exit;
        }
        
        // Traitement des nouvelles pièces jointes
        $piecesJointes = [];
        
        // Récupérer les pièces jointes existantes
        if (isset($_POST['pieces_jointes_existantes']) && is_array($_POST['pieces_jointes_existantes'])) {
            $piecesJointes = $_POST['pieces_jointes_existantes'];
        }
        
        if (isset($_FILES['nouvelles_pieces_jointes']) && !empty($_FILES['nouvelles_pieces_jointes']['name'][0])) {
            require_once ROOT_PATH . '/utils/FileUpload.php';
            $fileUpload = new FileUpload();
            
            for ($i = 0; $i < count($_FILES['nouvelles_pieces_jointes']['name']); $i++) {
                $file = [
                    'name' => $_FILES['nouvelles_pieces_jointes']['name'][$i],
                    'type' => $_FILES['nouvelles_pieces_jointes']['type'][$i],
                    'tmp_name' => $_FILES['nouvelles_pieces_jointes']['tmp_name'][$i],
                    'error' => $_FILES['nouvelles_pieces_jointes']['error'][$i],
                    'size' => $_FILES['nouvelles_pieces_jointes']['size'][$i]
                ];
                
                $uploadResult = $fileUpload->uploadFile($file, DEVOIRS_UPLOADS);
                
                if ($uploadResult['success']) {
                    // Créer une entrée dans la table pieces_jointes
                    require_once ROOT_PATH . '/models/PieceJointe.php';
                    $pieceJointeModel = new PieceJointe();
                    
                    $pieceJointeId = $pieceJointeModel->createPieceJointe([
                        'nom' => $file['name'],
                        'type' => $this->determinerTypeFichier($file['name']),
                        'fichier' => $uploadResult['filename'],
                        'description' => ''
                    ]);
                    
                    if ($pieceJointeId) {
                        $piecesJointes[] = $pieceJointeId;
                    }
                } else {
                    $errors[] = "Erreur lors du téléchargement du fichier {$file['name']}: {$uploadResult['message']}";
                }
            }
        }
        
        // Mise à jour du devoir dans la base de données
        $devoirData = [
            'titre' => $titre,
            'description' => $description,
            'instructions' => $instructions,
            'date_debut' => $dateDebut,
            'date_limite' => $dateLimite,
            'classe_id' => $classeId,
            'bareme_id' => $baremeId,
            'statut' => $statut,
            'travail_groupe' => $travailGroupe,
            'est_visible' => $estVisible,
            'est_obligatoire' => $estObligatoire,
            'confidentialite' => $confidentialite,
            'groupes' => $groupes,
            'pieces_jointes' => $piecesJointes
        ];
        
        $success = $this->devoirModel->updateDevoir($id, $devoirData);
        
        if ($success) {
            // Envoi de notifications aux élèves concernés pour la mise à jour
            $this->envoyerNotifications($id, 'modification');
            
            $_SESSION['success'] = "Le devoir a été mis à jour avec succès.";
            header('Location: ' . BASE_URL . '/devoirs/details.php?id=' . $id);
            exit;
        } else {
            $_SESSION['error'] = "Une erreur est survenue lors de la mise à jour du devoir.";
            $_SESSION['form_data'] = $_POST;
            header('Location: ' . BASE_URL . '/devoirs/editer.php?id=' . $id);
            exit;
        }
    }
    
    /**
     * Supprime un devoir
     */
    public function supprimer() {
        // Vérifier la méthode de requête ou utiliser un token CSRF pour plus de sécurité
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['error'] = "Méthode non autorisée.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer l'ID du devoir depuis le formulaire
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        
        if ($id === 0) {
            $_SESSION['error'] = "ID de devoir non spécifié.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du devoir
        $devoir = $this->devoirModel->getDevoirById($id);
        
        if (!$devoir) {
            $_SESSION['error'] = "Devoir non trouvé.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier si l'utilisateur est le créateur du devoir ou un admin
        if ($devoir['auteur_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à supprimer ce devoir.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Supprimer le devoir
        $success = $this->devoirModel->deleteDevoir($id);
        
        if ($success) {
            $_SESSION['success'] = "Le devoir a été supprimé avec succès.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        } else {
            $_SESSION['error'] = "Une erreur est survenue lors de la suppression du devoir.";
            header('Location: ' . BASE_URL . '/devoirs/details.php?id=' . $id);
            exit;
        }
    }
    
    /**
     * Change le statut d'un devoir
     */
    public function changerStatut() {
        // Vérifier la méthode de requête
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['error'] = "Méthode non autorisée.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer l'ID du devoir et le nouveau statut
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $statut = isset($_POST['statut']) ? $_POST['statut'] : '';
        
        if ($id === 0 || empty($statut)) {
            $_SESSION['error'] = "Paramètres manquants.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Récupérer les informations du devoir
        $devoir = $this->devoirModel->getDevoirById($id);
        
        if (!$devoir) {
            $_SESSION['error'] = "Devoir non trouvé.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Vérifier si l'utilisateur est le créateur du devoir ou un admin
        if ($devoir['auteur_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à modifier ce devoir.";
            header('Location: ' . BASE_URL . '/devoirs/index.php');
            exit;
        }
        
        // Changer le statut du devoir
        $success = $this->devoirModel->changerStatut($id, $statut);
        
        if ($success) {
            $_SESSION['success'] = "Le statut du devoir a été mis à jour.";
        } else {
            $_SESSION['error'] = "Une erreur est survenue lors de la mise à jour du statut.";
        }
        
        header('Location: ' . BASE_URL . '/devoirs/details.php?id=' . $id);
        exit;
    }
    
    /**
     * Envoie des notifications aux élèves concernés par un devoir
     * @param int $devoirId ID du devoir
     * @param string $type Type de notification ('creation', 'modification', 'rappel')
     */
    private function envoyerNotifications($devoirId, $type) {
        // Récupérer les informations du devoir
        $devoir = $this->devoirModel->getDevoirById($devoirId);
        
        if (!$devoir) {
            return false;
        }
        
        // Récupérer les élèves concernés
        $eleves = [];
        
        if (!empty($devoir['groupes'])) {
            // Si des groupes spécifiques sont ciblés
            foreach ($devoir['groupes'] as $groupe) {
                $elevesGroupe = $this->userModel->getElevesGroupe($groupe['id']);
                $eleves = array_merge($eleves, $elevesGroupe);
            }
        } else {
            // Sinon tous les élèves de la classe
            $eleves = $this->userModel->getElevesClasse($devoir['classe_id']);
        }
        
        // Créer une notification pour chaque élève
        require_once ROOT_PATH . '/models/Notification.php';
        $notificationModel = new Notification();
        
        // Définir le titre et le contenu de la notification selon le type
        $titre = "";
        $contenu = "";
        
        switch ($type) {
            case 'creation':
                $titre = "Nouveau devoir : {$devoir['titre']}";
                $contenu = "Un nouveau devoir a été créé pour la classe {$devoir['classe_nom']}. Date limite : " . date(DATETIME_FORMAT, strtotime($devoir['date_limite']));
                break;
                
            case 'modification':
                $titre = "Devoir modifié : {$devoir['titre']}";
                $contenu = "Le devoir pour la classe {$devoir['classe_nom']} a été modifié. Date limite : " . date(DATETIME_FORMAT, strtotime($devoir['date_limite']));
                break;
                
            case 'rappel':
                $titre = "Rappel : {$devoir['titre']}";
                $contenu = "Rappel : le devoir pour la classe {$devoir['classe_nom']} est à rendre avant le " . date(DATETIME_FORMAT, strtotime($devoir['date_limite']));
                break;
        }
        
        foreach ($eleves as $eleve) {
            // Créer la notification pour l'élève
            $notificationModel->createNotification([
                'destinataire_id' => $eleve['id'],
                'titre' => $titre,
                'contenu' => $contenu,
                'devoir_id' => $devoirId,
                'est_rappel' => ($type === 'rappel') ? 1 : 0
            ]);
            
            // Notifier aussi les parents de l'élève
            $parents = $this->userModel->getParentsEleve($eleve['id']);
            
            foreach ($parents as $parent) {
                $notificationModel->createNotification([
                    'destinataire_id' => $parent['id'],
                    'titre' => "Pour votre enfant : " . $titre,
                    'contenu' => "Pour votre enfant {$eleve['first_name']} {$eleve['last_name']} : " . $contenu,
                    'devoir_id' => $devoirId,
                    'est_rappel' => ($type === 'rappel') ? 1 : 0
                ]);
            }
        }
        
        return true;
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