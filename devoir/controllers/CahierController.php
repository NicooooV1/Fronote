<?php
/**
 * Contrôleur pour la gestion du cahier de texte
 */
class CahierController {
    private $chapitreModel;
    private $seanceModel;
    private $classeModel;
    private $ressourceModel;
    
    /**
     * Constructeur
     */
    public function __construct() {
        $this->chapitreModel = new Chapitre();
        $this->seanceModel = new Seance();
        $this->classeModel = new Classe();
        $this->ressourceModel = new Ressource();
    }
    
    /**
     * Affiche la page des chapitres
     */
    public function chapitres() {
        // Vérifier que l'utilisateur est connecté
        requireAuthentication();
        
        // Récupérer les filtres
        $filters = $this->getFilters();
        
        // Récupérer les chapitres selon le type d'utilisateur
        if ($_SESSION['user_type'] === TYPE_ELEVE) {
            $filters['classes'] = $this->classeModel->getClassesEleve($_SESSION['user_id']);
            $classes = $filters['classes'];
            
            // Récupérer les matières de ces classes
            $matieres = [];
            foreach ($classes as $classe) {
                $matiereClasse = $this->matiereModel->getMatieresByClasse($classe['id']);
                $matieres = array_merge($matieres, $matiereClasse);
            }
            
            // Utiliser la première classe comme filtre par défaut si aucun filtre n'est défini
            if (empty($filters['classe_id']) && !empty($classes)) {
                $filters['classe_id'] = $classes[0]['id'];
            }
        } elseif ($_SESSION['user_type'] === TYPE_PROFESSEUR) {
            // Récupérer les classes du professeur
            $classes = $this->classeModel->getClassesProfesseur($_SESSION['user_id']);
            
            // Récupérer les matières du professeur
            $matieres = $this->matiereModel->getMatieresByProfesseur($_SESSION['user_id']);
            
            // Ajouter l'ID du professeur aux filtres
            $filters['professeur_id'] = $_SESSION['user_id'];
        } else {
            // Administrateur ou autre type
            $classes = $this->classeModel->getAllClasses();
            $matieres = $this->matiereModel->getAllMatieres();
        }
        
        // Récupérer les chapitres selon les filtres
        $chapitres = $this->chapitreModel->getAllChapitres($filters);
        
        // Récupérer la progression pour chaque chapitre
        foreach ($chapitres as &$chapitre) {
            $chapitre['progression'] = $this->chapitreModel->getProgressionChapitre($chapitre['id']);
        }
        
        // Charger la vue
        require_once ROOT_PATH . '/views/cahier/chapitres.php';
    }
    
    /**
     * Affiche les détails d'un chapitre
     */
    public function chapitre() {
        // Vérifier que l'utilisateur est connecté
        requireAuthentication();
        
        // Récupérer l'ID du chapitre
        $id = isset($_GET['id']) ? $_GET['id'] : null;
        
        if (!$id) {
            $_SESSION['error'] = "Chapitre non spécifié.";
            header('Location: ' . BASE_URL . '/cahier/chapitres.php');
            exit;
        }
        
        // Récupérer les informations du chapitre
        $chapitre = $this->chapitreModel->getChapitreById($id);
        
        if (!$chapitre) {
            $_SESSION['error'] = "Chapitre introuvable.";
            header('Location: ' . BASE_URL . '/cahier/chapitres.php');
            exit;
        }
        
        // Vérifier les permissions
        if ($_SESSION['user_type'] === TYPE_ELEVE) {
            // Vérifier que l'élève appartient à la classe
            if (!$this->classeModel->verifierEleveClasse($_SESSION['user_id'], $chapitre['classe_id'])) {
                $_SESSION['error'] = "Vous n'avez pas accès à ce chapitre.";
                header('Location: ' . BASE_URL . '/cahier/chapitres.php');
                exit;
            }
        } elseif ($_SESSION['user_type'] === TYPE_PROFESSEUR && $chapitre['professeur_id'] != $_SESSION['user_id']) {
            // Vérifier que le professeur est bien l'auteur du chapitre
            if (!$_SESSION['is_admin']) {
                $_SESSION['error'] = "Vous n'avez pas accès à ce chapitre.";
                header('Location: ' . BASE_URL . '/cahier/chapitres.php');
                exit;
            }
        }
        
        // Récupérer la progression du chapitre
        $chapitre['progression'] = $this->chapitreModel->getProgressionChapitre($chapitre['id']);
        
        // Charger la vue
        require_once ROOT_PATH . '/views/cahier/chapitre.php';
    }
    
    /**
     * Affiche le formulaire de création d'un chapitre
     */
    public function create() {
        // Vérifier que l'utilisateur est connecté
        requireAuthentication();
        
        // Vérifier que l'utilisateur est un professeur
        if ($_SESSION['user_type'] !== TYPE_PROFESSEUR && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour créer un chapitre.";
            header('Location: ' . BASE_URL . '/cahier/chapitres.php');
            exit;
        }
        
        // Récupérer les classes du professeur
        $classes = $this->classeModel->getClassesProfesseur($_SESSION['user_id']);
        
        // Récupérer les matières du professeur
        $matieres = $this->matiereModel->getMatieresByProfesseur($_SESSION['user_id']);
        
        // Récupérer les compétences
        $competences = $this->competenceModel->getAllCompetences();
        
        // Charger la vue
        require_once ROOT_PATH . '/views/cahier/creer_chapitre.php';
    }
    
    /**
     * Traite le formulaire de création d'un chapitre
     */
    public function store() {
        // Vérifier que l'utilisateur est connecté
        requireAuthentication();
        
        // Vérifier que l'utilisateur est un professeur
        if ($_SESSION['user_type'] !== TYPE_PROFESSEUR && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour créer un chapitre.";
            header('Location: ' . BASE_URL . '/cahier/chapitres.php');
            exit;
        }
        
        // Récupérer les données du formulaire
        $data = [
            'titre' => $_POST['titre'] ?? '',
            'description' => $_POST['description'] ?? '',
            'objectifs' => $_POST['objectifs'] ?? '',
            'matiere_id' => $_POST['matiere_id'] ?? null,
            'classe_id' => $_POST['classe_id'] ?? null,
            'professeur_id' => $_SESSION['user_id'],
            'competences' => isset($_POST['competences']) ? $_POST['competences'] : []
        ];
        
        // Valider les données
        $errors = $this->validateChapitre($data);
        
        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            $_SESSION['form_data'] = $data;
            header('Location: ' . BASE_URL . '/cahier/creer_chapitre.php');
            exit;
        }
        
        // Créer le chapitre
        $chapitreId = $this->chapitreModel->createChapitre($data);
        
        if (!$chapitreId) {
            $_SESSION['error'] = "Une erreur est survenue lors de la création du chapitre.";
            $_SESSION['form_data'] = $data;
            header('Location: ' . BASE_URL . '/cahier/creer_chapitre.php');
            exit;
        }
        
        $_SESSION['success'] = "Le chapitre a été créé avec succès.";
        header('Location: ' . BASE_URL . '/cahier/chapitres.php');
        exit;
    }
    
    /**
     * Affiche le formulaire d'édition d'un chapitre
     */
    public function edit() {
        // Vérifier que l'utilisateur est connecté
        requireAuthentication();
        
        // Récupérer l'ID du chapitre
        $id = isset($_GET['id']) ? $_GET['id'] : null;
        
        if (!$id) {
            $_SESSION['error'] = "Chapitre non spécifié.";
            header('Location: ' . BASE_URL . '/cahier/chapitres.php');
            exit;
        }
        
        // Récupérer les informations du chapitre
        $chapitre = $this->chapitreModel->getChapitreById($id);
        
        if (!$chapitre) {
            $_SESSION['error'] = "Chapitre introuvable.";
            header('Location: ' . BASE_URL . '/cahier/chapitres.php');
            exit;
        }
        
        // Vérifier que l'utilisateur est bien l'auteur du chapitre
        if ($chapitre['professeur_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour modifier ce chapitre.";
            header('Location: ' . BASE_URL . '/cahier/chapitres.php');
            exit;
        }
        
        // Récupérer les classes du professeur
        $classes = $this->classeModel->getClassesProfesseur($_SESSION['user_id']);
        
        // Récupérer les matières du professeur
        $matieres = $this->matiereModel->getMatieresByProfesseur($_SESSION['user_id']);
        
        // Récupérer les compétences
        $competences = $this->competenceModel->getAllCompetences();
        
        // Récupérer les IDs des compétences associées au chapitre
        $competencesIds = array_column($chapitre['competences'], 'id');
        
        // Charger la vue
        require_once ROOT_PATH . '/views/cahier/editer_chapitre.php';
    }
    
    /**
     * Traite le formulaire d'édition d'un chapitre
     */
    public function update() {
        // Vérifier que l'utilisateur est connecté
        requireAuthentication();
        
        // Récupérer l'ID du chapitre
        $id = isset($_POST['id']) ? $_POST['id'] : null;
        
        if (!$id) {
            $_SESSION['error'] = "Chapitre non spécifié.";
            header('Location: ' . BASE_URL . '/cahier/chapitres.php');
            exit;
        }
        
        // Récupérer les informations du chapitre
        $chapitre = $this->chapitreModel->getChapitreById($id);
        
        if (!$chapitre) {
            $_SESSION['error'] = "Chapitre introuvable.";
            header('Location: ' . BASE_URL . '/cahier/chapitres.php');
            exit;
        }
        
        // Vérifier que l'utilisateur est bien l'auteur du chapitre
        if ($chapitre['professeur_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour modifier ce chapitre.";
            header('Location: ' . BASE_URL . '/cahier/chapitres.php');
            exit;
        }
        
        // Récupérer les données du formulaire
        $data = [
            'titre' => $_POST['titre'] ?? '',
            'description' => $_POST['description'] ?? '',
            'objectifs' => $_POST['objectifs'] ?? '',
            'competences' => isset($_POST['competences']) ? $_POST['competences'] : []
        ];
        
        // Valider les données
        $errors = $this->validateChapitreUpdate($data);
        
        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            $_SESSION['form_data'] = $data;
            header('Location: ' . BASE_URL . '/cahier/editer_chapitre.php?id=' . $id);
            exit;
        }
        
        // Mettre à jour le chapitre
        $success = $this->chapitreModel->updateChapitre($id, $data);
        
        if (!$success) {
            $_SESSION['error'] = "Une erreur est survenue lors de la mise à jour du chapitre.";
            $_SESSION['form_data'] = $data;
            header('Location: ' . BASE_URL . '/cahier/editer_chapitre.php?id=' . $id);
            exit;
        }
        
        $_SESSION['success'] = "Le chapitre a été mis à jour avec succès.";
        header('Location: ' . BASE_URL . '/cahier/chapitres.php');
        exit;
    }
    
    /**
     * Traite la suppression d'un chapitre
     */
    public function delete() {
        // Vérifier que l'utilisateur est connecté
        requireAuthentication();
        
        // Récupérer l'ID du chapitre
        $id = isset($_POST['id']) ? $_POST['id'] : null;
        
        if (!$id) {
            $_SESSION['error'] = "Chapitre non spécifié.";
            header('Location: ' . BASE_URL . '/cahier/chapitres.php');
            exit;
        }
        
        // Récupérer les informations du chapitre
        $chapitre = $this->chapitreModel->getChapitreById($id);
        
        if (!$chapitre) {
            $_SESSION['error'] = "Chapitre introuvable.";
            header('Location: ' . BASE_URL . '/cahier/chapitres.php');
            exit;
        }
        
        // Vérifier que l'utilisateur est bien l'auteur du chapitre
        if ($chapitre['professeur_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'avez pas les droits pour supprimer ce chapitre.";
            header('Location: ' . BASE_URL . '/cahier/chapitres.php');
            exit;
        }
        
        // Supprimer le chapitre
        $success = $this->chapitreModel->deleteChapitre($id);
        
        if (!$success) {
            $_SESSION['error'] = "Une erreur est survenue lors de la suppression du chapitre.";
            header('Location: ' . BASE_URL . '/cahier/chapitres.php');
            exit;
        }
        
        $_SESSION['success'] = "Le chapitre a été supprimé avec succès.";
        header('Location: ' . BASE_URL . '/cahier/chapitres.php');
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
        
        // Filtrer par matière
        if (isset($_GET['matiere_id']) && !empty($_GET['matiere_id'])) {
            $filters['matiere_id'] = $_GET['matiere_id'];
        }
        
        // Recherche textuelle
        if (isset($_GET['search']) && !empty($_GET['search'])) {
            $filters['search'] = $_GET['search'];
        }
        
        return $filters;
    }
    
    /**
     * Valide les données d'un chapitre
     * @param array $data Données à valider
     * @return array Erreurs de validation
     */
    private function validateChapitre($data) {
        $errors = [];
        
        // Vérifier le titre
        if (empty($data['titre'])) {
            $errors[] = "Le titre du chapitre est obligatoire.";
        }
        
        // Vérifier la matière
        if (empty($data['matiere_id'])) {
            $errors[] = "La matière est obligatoire.";
        }
        
        // Vérifier la classe
        if (empty($data['classe_id'])) {
            $errors[] = "La classe est obligatoire.";
        }
        
        return $errors;
    }
    
    /**
     * Valide les données de mise à jour d'un chapitre
     * @param array $data Données à valider
     * @return array Erreurs de validation
     */
    private function validateChapitreUpdate($data) {
        $errors = [];
        
        // Vérifier le titre
        if (empty($data['titre'])) {
            $errors[] = "Le titre du chapitre est obligatoire.";
        }
        
        return $errors;
    }
}