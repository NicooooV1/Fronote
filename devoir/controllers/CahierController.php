<?php
/**
 * Contrôleur pour la gestion du cahier de texte
 */
class CahierController {
    private $seanceModel;
    private $chapitreModel;
    private $ressourceModel;
    private $matiereModel;
    private $classeModel;
    private $userModel;
    
    public function __construct() {
        // Initialisation des modèles
        require_once ROOT_PATH . '/models/Seance.php';
        require_once ROOT_PATH . '/models/Chapitre.php';
        require_once ROOT_PATH . '/models/Ressource.php';
        require_once ROOT_PATH . '/models/Matiere.php';
        require_once ROOT_PATH . '/models/Classe.php';
        require_once ROOT_PATH . '/../login/src/auth.php';
        require_once ROOT_PATH . '/../login/src/user.php';

        $auth = new Auth($this->db->getPDO());
        
        $this->seanceModel = new Seance();
        $this->chapitreModel = new Chapitre();
        $this->ressourceModel = new Ressource();
        $this->matiereModel = new Matiere();
        $this->classeModel = new Classe();
        $this->userModel = new User();
        
        // Vérifier que l'utilisateur est connecté
        if (!isset($_SESSION['user_id'])) {
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
    }
    
    /**
     * Affiche la vue calendrier du cahier de texte
     */
    public function calendrier() {
        // Récupérer les paramètres de filtrage
        $dateDebut = isset($_GET['date_debut']) ? $_GET['date_debut'] : date('Y-m-d');
        $dateFin = isset($_GET['date_fin']) ? $_GET['date_fin'] : date('Y-m-d', strtotime('+1 month'));
        $classeId = isset($_GET['classe_id']) ? (int)$_GET['classe_id'] : null;
        $matiereId = isset($_GET['matiere_id']) ? (int)$_GET['matiere_id'] : null;
        $vue = isset($_GET['vue']) ? $_GET['vue'] : DEFAULT_CALENDAR_VIEW;
        
        // Construction des filtres
        $filters = [
            'classe_id' => $classeId,
            'matiere_id' => $matiereId
        ];
        
        // Adapter les filtres selon le type d'utilisateur
        $userType = $_SESSION['user_type'];
        $userId = $_SESSION['user_id'];
        
        if ($userType === TYPE_PROFESSEUR) {
            $filters['professeur_id'] = $userId;
        } elseif ($userType === TYPE_ELEVE) {
            // Les élèves voient les séances de leurs classes
            $classesEleve = $this->classeModel->getClassesEleve($userId);
            
            if (!empty($classesEleve)) {
                if (empty($classeId)) {
                    // Si aucune classe n'est sélectionnée, utiliser la première classe de l'élève
                    $filters['classe_id'] = $classesEleve[0]['id'];
                } else {
                    // Vérifier que l'élève appartient bien à la classe sélectionnée
                    $classeValide = false;
                    foreach ($classesEleve as $classe) {
                        if ($classe['id'] == $classeId) {
                            $classeValide = true;
                            break;
                        }
                    }
                    
                    if (!$classeValide) {
                        $filters['classe_id'] = $classesEleve[0]['id'];
                    }
                }
            }
        } elseif ($userType === TYPE_PARENT) {
            // Les parents voient les séances des classes de leurs enfants
            return $this->calendrierParent();
        }
        
        // Récupérer les séances pour le calendrier
        $evenements = $this->seanceModel->getSeancesCalendar($dateDebut, $dateFin, $filters);
        
        // Récupérer les listes pour les filtres
        $classes = $this->classeModel->getAllClasses();
        $matieres = $this->matiereModel->getAllMatieres();
        
        // Filtrer les classes selon l'utilisateur
        if ($userType === TYPE_PROFESSEUR) {
            $classes = $this->classeModel->getClassesProfesseur($userId);
        } elseif ($userType === TYPE_ELEVE) {
            $classes = $this->classeModel->getClassesEleve($userId);
        }
        
        // Convertir les événements au format JSON pour le calendrier
        $evenementsJson = json_encode($evenements);
        
        // Charger la vue
        require_once ROOT_PATH . '/views/cahier/calendrier.php';
    }
    
    /**
     * Affiche la vue calendrier pour un parent (pour ses enfants)
     */
    public function calendrierParent() {
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
        $dateDebut = isset($_GET['date_debut']) ? $_GET['date_debut'] : date('Y-m-d');
        $dateFin = isset($_GET['date_fin']) ? $_GET['date_fin'] : date('Y-m-d', strtotime('+1 month'));
        $classeId = isset($_GET['classe_id']) ? (int)$_GET['classe_id'] : null;
        $matiereId = isset($_GET['matiere_id']) ? (int)$_GET['matiere_id'] : null;
        $vue = isset($_GET['vue']) ? $_GET['vue'] : DEFAULT_CALENDAR_VIEW;
        
        // Récupérer les classes de l'enfant sélectionné
        $classesEnfant = $this->classeModel->getClassesEleve($enfantId);
        
        // Si aucune classe n'est sélectionnée, utiliser la première classe de l'enfant
        if (empty($classeId) && !empty($classesEnfant)) {
            $classeId = $classesEnfant[0]['id'];
        }
        
        // Construction des filtres
        $filters = [
            'classe_id' => $classeId,
            'matiere_id' => $matiereId
        ];
        
        // Récupérer les séances pour le calendrier
        $evenements = $this->seanceModel->getSeancesCalendar($dateDebut, $dateFin, $filters);
        
        // Récupérer les listes pour les filtres
        $classes = $classesEnfant;
        $matieres = $this->matiereModel->getAllMatieres();
        
        // Convertir les événements au format JSON pour le calendrier
        $evenementsJson = json_encode($evenements);
        
        // Charger la vue
        require_once ROOT_PATH . '/views/cahier/calendrier_parent.php';
    }
    
    /**
     * Affiche la vue semaine du cahier de texte
     */
    public function semaine() {
        // Récupérer la date de début de semaine
        $dateDebut = isset($_GET['date_debut']) ? $_GET['date_debut'] : date('Y-m-d', strtotime('monday this week'));
        
        // Calculer la date de fin de semaine (dimanche)
        $dateFin = date('Y-m-d', strtotime($dateDebut . ' +6 days'));
        
        // Récupérer les autres paramètres de filtrage
        $classeId = isset($_GET['classe_id']) ? (int)$_GET['classe_id'] : null;
        $matiereId = isset($_GET['matiere_id']) ? (int)$_GET['matiere_id'] : null;
        
        // Construction des filtres
        $filters = [
            'classe_id' => $classeId,
            'matiere_id' => $matiereId
        ];
        
        // Adapter les filtres selon le type d'utilisateur
        $userType = $_SESSION['user_type'];
        $userId = $_SESSION['user_id'];
        
        if ($userType === TYPE_PROFESSEUR) {
            $filters['professeur_id'] = $userId;
        } elseif ($userType === TYPE_ELEVE) {
            // Les élèves voient les séances de leurs classes
            $classesEleve = $this->classeModel->getClassesEleve($userId);
            
            if (!empty($classesEleve)) {
                if (empty($classeId)) {
                    // Si aucune classe n'est sélectionnée, utiliser la première classe de l'élève
                    $filters['classe_id'] = $classesEleve[0]['id'];
                } else {
                    // Vérifier que l'élève appartient bien à la classe sélectionnée
                    $classeValide = false;
                    foreach ($classesEleve as $classe) {
                        if ($classe['id'] == $classeId) {
                            $classeValide = true;
                            break;
                        }
                    }
                    
                    if (!$classeValide) {
                        $filters['classe_id'] = $classesEleve[0]['id'];
                    }
                }
            }
        } elseif ($userType === TYPE_PARENT) {
            // Les parents voient les séances des classes de leurs enfants
            return $this->semaineParent();
        }
        
        // Récupérer les séances pour la semaine
        $seances = $this->seanceModel->getSeancesCalendar($dateDebut, $dateFin, $filters);
        
        // Organiser les séances par jour
        $seancesParJour = [];
        
        // Initialiser les jours de la semaine
        $jourCourant = new DateTime($dateDebut);
        for ($i = 0; $i < 7; $i++) {
            $keyDate = $jourCourant->format('Y-m-d');
            $seancesParJour[$keyDate] = [
                'date' => $keyDate,
                'jour' => $jourCourant->format('l'),
                'jour_fr' => $this->getJourFr($jourCourant->format('l')),
                'seances' => []
            ];
            $jourCourant->modify('+1 day');
        }
        
        // Trier les séances par jour
        foreach ($seances as $seance) {
            $dateSeance = (new DateTime($seance['start']))->format('Y-m-d');
            if (isset($seancesParJour[$dateSeance])) {
                $seancesParJour[$dateSeance]['seances'][] = $seance;
            }
        }
        
        // Récupérer les listes pour les filtres
        $classes = $this->classeModel->getAllClasses();
        $matieres = $this->matiereModel->getAllMatieres();
        
        // Filtrer les classes selon l'utilisateur
        if ($userType === TYPE_PROFESSEUR) {
            $classes = $this->classeModel->getClassesProfesseur($userId);
        } elseif ($userType === TYPE_ELEVE) {
            $classes = $this->classeModel->getClassesEleve($userId);
        }
        
        // Semaine précédente et suivante
        $semainePrecedente = date('Y-m-d', strtotime($dateDebut . ' -1 week'));
        $semaineSuivante = date('Y-m-d', strtotime($dateDebut . ' +1 week'));
        
        // Charger la vue
        require_once ROOT_PATH . '/views/cahier/semaine.php';
    }
    
    /**
     * Affiche la vue semaine pour un parent (pour ses enfants)
     */
    public function semaineParent() {
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
        
        // Récupérer la date de début de semaine
        $dateDebut = isset($_GET['date_debut']) ? $_GET['date_debut'] : date('Y-m-d', strtotime('monday this week'));
        
        // Calculer la date de fin de semaine (dimanche)
        $dateFin = date('Y-m-d', strtotime($dateDebut . ' +6 days'));
        
        // Récupérer les autres paramètres de filtrage
        $classeId = isset($_GET['classe_id']) ? (int)$_GET['classe_id'] : null;
        $matiereId = isset($_GET['matiere_id']) ? (int)$_GET['matiere_id'] : null;
        
        // Récupérer les classes de l'enfant sélectionné
        $classesEnfant = $this->classeModel->getClassesEleve($enfantId);
        
        // Si aucune classe n'est sélectionnée, utiliser la première classe de l'enfant
        if (empty($classeId) && !empty($classesEnfant)) {
            $classeId = $classesEnfant[0]['id'];
        }
        
        // Construction des filtres
        $filters = [
            'classe_id' => $classeId,
            'matiere_id' => $matiereId
        ];
        
        // Récupérer les séances pour la semaine
        $seances = $this->seanceModel->getSeancesCalendar($dateDebut, $dateFin, $filters);
        
        // Organiser les séances par jour (même logique que dans la méthode semaine())
        $seancesParJour = [];
        
        $jourCourant = new DateTime($dateDebut);
        for ($i = 0; $i < 7; $i++) {
            $keyDate = $jourCourant->format('Y-m-d');
            $seancesParJour[$keyDate] = [
                'date' => $keyDate,
                'jour' => $jourCourant->format('l'),
                'jour_fr' => $this->getJourFr($jourCourant->format('l')),
                'seances' => []
            ];
            $jourCourant->modify('+1 day');
        }
        
        foreach ($seances as $seance) {
            $dateSeance = (new DateTime($seance['start']))->format('Y-m-d');
            if (isset($seancesParJour[$dateSeance])) {
                $seancesParJour[$dateSeance]['seances'][] = $seance;
            }
        }
        
        // Récupérer les listes pour les filtres
        $classes = $classesEnfant;
        $matieres = $this->matiereModel->getAllMatieres();
        
        // Semaine précédente et suivante
        $semainePrecedente = date('Y-m-d', strtotime($dateDebut . ' -1 week'));
        $semaineSuivante = date('Y-m-d', strtotime($dateDebut . ' +1 week'));
        
        // Charger la vue
        require_once ROOT_PATH . '/views/cahier/semaine_parent.php';
    }
    
    /**
     * Affiche les détails d'une séance
     */
    public function details() {
        // Récupérer l'ID de la séance depuis l'URL
        $id = isset($_GET['id']) ? $_GET['id'] : '';
        
        if (empty($id)) {
            $_SESSION['error'] = "ID de séance non spécifié.";
            header('Location: ' . BASE_URL . '/cahier/calendrier.php');
            exit;
        }
        
        // Récupérer les informations de la séance
        $seance = $this->seanceModel->getSeanceById($id);
        
        if (!$seance) {
            $_SESSION['error'] = "Séance non trouvée.";
            header('Location: ' . BASE_URL . '/cahier/calendrier.php');
            exit;
        }
        
        // Vérifier les autorisations
        $userType = $_SESSION['user_type'];
        $userId = $_SESSION['user_id'];
        
        // Si l'utilisateur est un élève, vérifier qu'il appartient à la classe de la séance
        if ($userType === TYPE_ELEVE) {
            $classeEleve = $this->classeModel->verifierEleveClasse($userId, $seance['classe_id']);
            
            if (!$classeEleve) {
                $_SESSION['error'] = "Vous n'êtes pas autorisé à accéder à cette séance.";
                header('Location: ' . BASE_URL . '/cahier/calendrier.php');
                exit;
            }
            
            // Charger la vue élève
            require_once ROOT_PATH . '/views/cahier/seance_eleve.php';
        } 
        // Si l'utilisateur est un professeur, vérifier s'il est l'auteur de la séance
        elseif ($userType === TYPE_PROFESSEUR) {
            if ($seance['professeur_id'] != $userId && !$_SESSION['is_admin']) {
                $_SESSION['error'] = "Vous n'êtes pas autorisé à accéder à cette séance.";
                header('Location: ' . BASE_URL . '/cahier/calendrier.php');
                exit;
            }
            
            // Charger la vue professeur
            require_once ROOT_PATH . '/views/cahier/seance_prof.php';
        }
        // Si l'utilisateur est un parent, vérifier si son enfant appartient à la classe de la séance
        elseif ($userType === TYPE_PARENT) {
            $enfantId = isset($_GET['enfant_id']) ? (int)$_GET['enfant_id'] : 0;
            $enfants = $this->userModel->getEnfantsParent($userId);
            
            $enfantAutorise = false;
            foreach ($enfants as $enfant) {
                if ($enfant['id'] == $enfantId) {
                    $classeEnfant = $this->classeModel->verifierEleveClasse($enfantId, $seance['classe_id']);
                    if ($classeEnfant) {
                        $enfantAutorise = true;
                        break;
                    }
                }
            }
            
            if (!$enfantAutorise) {
                $_SESSION['error'] = "Vous n'êtes pas autorisé à accéder à cette séance.";
                header('Location: ' . BASE_URL . '/cahier/calendrier.php');
                exit;
            }
            
            // Charger la vue parent
            require_once ROOT_PATH . '/views/cahier/seance_parent.php';
        } else {
            // Administrateur
            require_once ROOT_PATH . '/views/cahier/seance_admin.php';
        }
    }
    
    /**
     * Affiche le formulaire de création d'une séance
     */
    public function creer() {
        // Vérifier si l'utilisateur est un professeur ou admin
        if ($_SESSION['user_type'] !== TYPE_PROFESSEUR && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à créer des séances.";
            header('Location: ' . BASE_URL . '/cahier/calendrier.php');
            exit;
        }
        
        // Récupérer les paramètres d'initialisation du formulaire
        $dateDebut = isset($_GET['date_debut']) ? $_GET['date_debut'] : date('Y-m-d');
        $classeId = isset($_GET['classe_id']) ? (int)$_GET['classe_id'] : null;
        $matiereId = isset($_GET['matiere_id']) ? (int)$_GET['matiere_id'] : null;
        
        // Récupérer les listes pour le formulaire
        $classes = $this->classeModel->getAllClasses();
        $matieres = $this->matiereModel->getAllMatieres();
        
        // Si le professeur a des classes assignées, filtrer la liste
        if ($_SESSION['user_type'] === TYPE_PROFESSEUR) {
            $classesProf = $this->classeModel->getClassesProfesseur($_SESSION['user_id']);
            $classes = $classesProf;
        }
        
        // Récupérer les chapitres (filtré dynamiquement par JS selon la matière et la classe)
        $chapitres = [];
        
        if ($matiereId && $classeId) {
            $chapitres = $this->chapitreModel->getChapitresByMatiereAndClasse($matiereId, $classeId);
        }
        
        // Récupérer les ressources du professeur
        $ressources = $this->ressourceModel->getRessourcesByProfesseur($_SESSION['user_id']);
        
        // Charger la vue
        require_once ROOT_PATH . '/views/cahier/creer.php';
    }
    
    /**
     * Traite la soumission du formulaire de création d'une séance
     */
    public function store() {
        // Vérifier si l'utilisateur est un professeur ou admin
        if ($_SESSION['user_type'] !== TYPE_PROFESSEUR && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à créer des séances.";
            header('Location: ' . BASE_URL . '/cahier/calendrier.php');
            exit;
        }
        
        // Vérifier la méthode de requête
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['error'] = "Méthode non autorisée.";
            header('Location: ' . BASE_URL . '/cahier/creer.php');
            exit;
        }
        
        // Récupérer et valider les données du formulaire
        $titre = isset($_POST['titre']) ? trim($_POST['titre']) : '';
        $dateDebut = isset($_POST['date_debut']) ? $_POST['date_debut'] : '';
        $heureDebut = isset($_POST['heure_debut']) ? $_POST['heure_debut'] : '';
        $duree = isset($_POST['duree']) ? (int)$_POST['duree'] : 60; // Durée en minutes
        $lieu = isset($_POST['lieu']) ? trim($_POST['lieu']) : '';
        $classeId = isset($_POST['classe_id']) ? (int)$_POST['classe_id'] : 0;
        $matiereId = isset($_POST['matiere_id']) ? (int)$_POST['matiere_id'] : 0;
        $chapitreId = isset($_POST['chapitre_id']) ? (int)$_POST['chapitre_id'] : null;
        $contenu = isset($_POST['contenu']) ? trim($_POST['contenu']) : '';
        $objectifs = isset($_POST['objectifs']) ? trim($_POST['objectifs']) : '';
        $modalites = isset($_POST['modalites']) ? trim($_POST['modalites']) : '';
        $estRecurrente = isset($_POST['est_recurrente']) ? 1 : 0;
        $recurrence = isset($_POST['recurrence']) ? $_POST['recurrence'] : '';
        $dateFinRecurrence = isset($_POST['date_fin_recurrence']) ? $_POST['date_fin_recurrence'] : '';
        $ressources = isset($_POST['ressources']) ? $_POST['ressources'] : [];
        $competences = isset($_POST['competences']) ? $_POST['competences'] : [];
        
        // Validation des données
        $errors = [];
        
        if (empty($titre)) {
            $errors[] = "Le titre est obligatoire.";
        }
        
        if (empty($dateDebut) || empty($heureDebut)) {
            $errors[] = "La date et l'heure de début sont obligatoires.";
        }
        
        if ($duree <= 0) {
            $errors[] = "La durée doit être supérieure à 0.";
        }
        
        if ($classeId <= 0) {
            $errors[] = "Veuillez sélectionner une classe.";
        }
        
        if ($matiereId <= 0) {
            $errors[] = "Veuillez sélectionner une matière.";
        }
        
        if (empty($contenu)) {
            $errors[] = "Le contenu de la séance est obligatoire.";
        }
        
        // Vérification supplémentaire pour la récurrence
        if ($estRecurrente && empty($recurrence)) {
            $errors[] = "Veuillez spécifier une règle de récurrence.";
        }
        
        if ($estRecurrente && empty($dateFinRecurrence)) {
            $errors[] = "Veuillez spécifier une date de fin pour la récurrence.";
        }
        
        // Si des erreurs sont présentes, afficher les erreurs et retourner au formulaire
        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            $_SESSION['form_data'] = $_POST; // Conserver les données du formulaire
            header('Location: ' . BASE_URL . '/cahier/creer.php');
            exit;
        }
        
        // Calculer la date et heure de début complète
        $dateHeureDebut = $dateDebut . ' ' . $heureDebut;
        
        // Calculer la date et heure de fin
        $dateFin = date('Y-m-d H:i:s', strtotime($dateHeureDebut . ' +' . $duree . ' minutes'));
        
        // Créer la séance dans la base de données
        $seanceData = [
            'titre' => $titre,
            'date_debut' => $dateHeureDebut,
            'date_fin' => $dateFin,
            'lieu' => $lieu,
            'matiere_id' => $matiereId,
            'classe_id' => $classeId,
            'professeur_id' => $_SESSION['user_id'],
            'chapitre_id' => $chapitreId,
            'contenu' => $contenu,
            'objectifs' => $objectifs,
            'modalites' => $modalites,
            'est_recurrente' => $estRecurrente,
            'recurrence' => $recurrence,
            'date_fin_recurrence' => $dateFinRecurrence,
            'ressources' => $ressources,
            'competences' => $competences
        ];
        
        $seanceId = $this->seanceModel->createSeance($seanceData);
        
        if ($seanceId) {
            // Envoi de notifications aux élèves concernés
            $this->envoyerNotifications($seanceId, 'creation');
            
            $_SESSION['success'] = "La séance a été créée avec succès.";
            header('Location: ' . BASE_URL . '/cahier/details.php?id=' . $seanceId);
            exit;
        } else {
            $_SESSION['error'] = "Une erreur est survenue lors de la création de la séance.";
            $_SESSION['form_data'] = $_POST;
            header('Location: ' . BASE_URL . '/cahier/creer.php');
            exit;
        }
    }
    
    /**
     * Affiche le formulaire d'édition d'une séance
     */
    public function editer() {
        // Récupérer l'ID de la séance depuis l'URL
        $id = isset($_GET['id']) ? $_GET['id'] : '';
        
        if (empty($id)) {
            $_SESSION['error'] = "ID de séance non spécifié.";
            header('Location: ' . BASE_URL . '/cahier/calendrier.php');
            exit;
        }
        
        // Récupérer les informations de la séance
        $seance = $this->seanceModel->getSeanceById($id);
        
        if (!$seance) {
            $_SESSION['error'] = "Séance non trouvée.";
            header('Location: ' . BASE_URL . '/cahier/calendrier.php');
            exit;
        }
        
        // Vérifier si l'utilisateur est le créateur de la séance ou un admin
        if ($seance['professeur_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à modifier cette séance.";
            header('Location: ' . BASE_URL . '/cahier/calendrier.php');
            exit;
        }
        
        // Récupérer les listes pour le formulaire
        $classes = $this->classeModel->getAllClasses();
        $matieres = $this->matiereModel->getAllMatieres();
        
        // Si le professeur a des classes assignées, filtrer la liste
        if ($_SESSION['user_type'] === TYPE_PROFESSEUR) {
            $classesProf = $this->classeModel->getClassesProfesseur($_SESSION['user_id']);
            $classes = $classesProf;
        }
        
        // Récupérer les chapitres pour la matière et la classe de la séance
        $chapitres = $this->chapitreModel->getChapitresByMatiereAndClasse($seance['matiere_id'], $seance['classe_id']);
        
        // Récupérer les ressources du professeur
        $ressources = $this->ressourceModel->getRessourcesByProfesseur($_SESSION['user_id']);
        
        // Récupérer les compétences associées à la séance
        $competencesSeance = $this->seanceModel->getCompetencesIds($id);
        
        // Récupérer les ressources associées à la séance
        $ressourcesSeance = $this->seanceModel->getRessourcesIds($id);
        
        // Extraire l'heure de début pour le formulaire
        $heureDebut = date('H:i', strtotime($seance['date_debut']));
        
        // Calculer la durée en minutes
        $duree = $seance['duree_minutes'];
        
        // Charger la vue
        require_once ROOT_PATH . '/views/cahier/editer.php';
    }
    
    /**
     * Traite la soumission du formulaire d'édition d'une séance
     */
    public function update() {
        // Vérifier la méthode de requête
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['error'] = "Méthode non autorisée.";
            header('Location: ' . BASE_URL . '/cahier/calendrier.php');
            exit;
        }
        
        // Récupérer l'ID de la séance depuis le formulaire
        $id = isset($_POST['id']) ? $_POST['id'] : '';
        
        if (empty($id)) {
            $_SESSION['error'] = "ID de séance non spécifié.";
            header('Location: ' . BASE_URL . '/cahier/calendrier.php');
            exit;
        }
        
        // Récupérer les informations de la séance
        $seance = $this->seanceModel->getSeanceById($id);
        
        if (!$seance) {
            $_SESSION['error'] = "Séance non trouvée.";
            header('Location: ' . BASE_URL . '/cahier/calendrier.php');
            exit;
        }
        
        // Vérifier si l'utilisateur est le créateur de la séance ou un admin
        if ($seance['professeur_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à modifier cette séance.";
            header('Location: ' . BASE_URL . '/cahier/calendrier.php');
            exit;
        }
        
        // Récupérer et valider les données du formulaire
        $titre = isset($_POST['titre']) ? trim($_POST['titre']) : '';
        $dateDebut = isset($_POST['date_debut']) ? $_POST['date_debut'] : '';
        $heureDebut = isset($_POST['heure_debut']) ? $_POST['heure_debut'] : '';
        $duree = isset($_POST['duree']) ? (int)$_POST['duree'] : 60; // Durée en minutes
        $lieu = isset($_POST['lieu']) ? trim($_POST['lieu']) : '';
        $classeId = isset($_POST['classe_id']) ? (int)$_POST['classe_id'] : 0;
        $matiereId = isset($_POST['matiere_id']) ? (int)$_POST['matiere_id'] : 0;
        $chapitreId = isset($_POST['chapitre_id']) ? (int)$_POST['chapitre_id'] : null;
        $contenu = isset($_POST['contenu']) ? trim($_POST['contenu']) : '';
        $objectifs = isset($_POST['objectifs']) ? trim($_POST['objectifs']) : '';
        $modalites = isset($_POST['modalites']) ? trim($_POST['modalites']) : '';
        $statut = isset($_POST['statut']) ? $_POST['statut'] : STATUT_PREVISIONNELLE;
        $updateRecurrence = isset($_POST['update_recurrence']) ? true : false;
        $ressources = isset($_POST['ressources']) ? $_POST['ressources'] : [];
        $competences = isset($_POST['competences']) ? $_POST['competences'] : [];
        
        // Validation des données
        $errors = [];
        
        if (empty($titre)) {
            $errors[] = "Le titre est obligatoire.";
        }
        
        if (empty($dateDebut) || empty($heureDebut)) {
            $errors[] = "La date et l'heure de début sont obligatoires.";
        }
        
        if ($duree <= 0) {
            $errors[] = "La durée doit être supérieure à 0.";
        }
        
        if ($classeId <= 0) {
            $errors[] = "Veuillez sélectionner une classe.";
        }
        
        if ($matiereId <= 0) {
            $errors[] = "Veuillez sélectionner une matière.";
        }
        
        if (empty($contenu)) {
            $errors[] = "Le contenu de la séance est obligatoire.";
        }
        
        // Si des erreurs sont présentes, afficher les erreurs et retourner au formulaire
        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            $_SESSION['form_data'] = $_POST; // Conserver les données du formulaire
            header('Location: ' . BASE_URL . '/cahier/editer.php?id=' . $id);
            exit;
        }
        
        // Calculer la date et heure de début complète
        $dateHeureDebut = $dateDebut . ' ' . $heureDebut;
        
        // Calculer la date et heure de fin
        $dateFin = date('Y-m-d H:i:s', strtotime($dateHeureDebut . ' +' . $duree . ' minutes'));
        
        // Mettre à jour la séance dans la base de données
        $seanceData = [
            'titre' => $titre,
            'date_debut' => $dateHeureDebut,
            'date_fin' => $dateFin,
            'lieu' => $lieu,
            'matiere_id' => $matiereId,
            'classe_id' => $classeId,
            'chapitre_id' => $chapitreId,
            'contenu' => $contenu,
            'objectifs' => $objectifs,
            'modalites' => $modalites,
            'statut' => $statut,
            'update_recurrence' => $updateRecurrence,
            'ressources' => $ressources,
            'competences' => $competences
        ];
        
        $success = $this->seanceModel->updateSeance($id, $seanceData);
        
        if ($success) {
            // Envoi de notifications aux élèves concernés pour la mise à jour
            $this->envoyerNotifications($id, 'modification');
            
            $_SESSION['success'] = "La séance a été mise à jour avec succès.";
            header('Location: ' . BASE_URL . '/cahier/details.php?id=' . $id);
            exit;
        } else {
            $_SESSION['error'] = "Une erreur est survenue lors de la mise à jour de la séance.";
            $_SESSION['form_data'] = $_POST;
            header('Location: ' . BASE_URL . '/cahier/editer.php?id=' . $id);
            exit;
        }
    }
    
    /**
     * Supprime une séance
     */
    public function supprimer() {
        // Vérifier la méthode de requête ou utiliser un token CSRF pour plus de sécurité
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['error'] = "Méthode non autorisée.";
            header('Location: ' . BASE_URL . '/cahier/calendrier.php');
            exit;
        }
        
        // Récupérer l'ID de la séance depuis le formulaire
        $id = isset($_POST['id']) ? $_POST['id'] : '';
        $deleteRecurrences = isset($_POST['delete_recurrences']) ? true : false;
        
        if (empty($id)) {
            $_SESSION['error'] = "ID de séance non spécifié.";
            header('Location: ' . BASE_URL . '/cahier/calendrier.php');
            exit;
        }
        
        // Récupérer les informations de la séance
        $seance = $this->seanceModel->getSeanceById($id);
        
        if (!$seance) {
            $_SESSION['error'] = "Séance non trouvée.";
            header('Location: ' . BASE_URL . '/cahier/calendrier.php');
            exit;
        }
        
        // Vérifier si l'utilisateur est le créateur de la séance ou un admin
        if ($seance['professeur_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à supprimer cette séance.";
            header('Location: ' . BASE_URL . '/cahier/calendrier.php');
            exit;
        }
        
        // Supprimer la séance
        $success = $this->seanceModel->deleteSeance($id, $deleteRecurrences);
        
        if ($success) {
            $_SESSION['success'] = "La séance a été supprimée avec succès.";
            header('Location: ' . BASE_URL . '/cahier/calendrier.php');
            exit;
        } else {
            $_SESSION['error'] = "Une erreur est survenue lors de la suppression de la séance.";
            header('Location: ' . BASE_URL . '/cahier/details.php?id=' . $id);
            exit;
        }
    }
    
    /**
     * Change le statut d'une séance
     */
    public function changerStatut() {
        // Vérifier la méthode de requête
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['error'] = "Méthode non autorisée.";
            header('Location: ' . BASE_URL . '/cahier/calendrier.php');
            exit;
        }
        
        // Récupérer l'ID de la séance et le nouveau statut
        $id = isset($_POST['id']) ? $_POST['id'] : '';
        $statut = isset($_POST['statut']) ? $_POST['statut'] : '';
        
        if (empty($id) || empty($statut)) {
            $_SESSION['error'] = "Paramètres manquants.";
            header('Location: ' . BASE_URL . '/cahier/calendrier.php');
            exit;
        }
        
        // Récupérer les informations de la séance
        $seance = $this->seanceModel->getSeanceById($id);
        
        if (!$seance) {
            $_SESSION['error'] = "Séance non trouvée.";
            header('Location: ' . BASE_URL . '/cahier/calendrier.php');
            exit;
        }
        
        // Vérifier si l'utilisateur est le créateur de la séance ou un admin
        if ($seance['professeur_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à modifier cette séance.";
            header('Location: ' . BASE_URL . '/cahier/calendrier.php');
            exit;
        }
        
        // Changer le statut de la séance
        $success = $this->seanceModel->changerStatut($id, $statut);
        
        if ($success) {
            $_SESSION['success'] = "Le statut de la séance a été mis à jour.";
        } else {
            $_SESSION['error'] = "Une erreur est survenue lors de la mise à jour du statut.";
        }
        
        header('Location: ' . BASE_URL . '/cahier/details.php?id=' . $id);
        exit;
    }
    
    /**
     * Duplique une séance à une nouvelle date
     */
    public function dupliquer() {
        // Vérifier la méthode de requête
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['error'] = "Méthode non autorisée.";
            header('Location: ' . BASE_URL . '/cahier/calendrier.php');
            exit;
        }
        
        // Récupérer l'ID de la séance et la nouvelle date
        $id = isset($_POST['id']) ? $_POST['id'] : '';
        $nouvelleDate = isset($_POST['nouvelle_date']) ? $_POST['nouvelle_date'] : '';
        
        if (empty($id) || empty($nouvelleDate)) {
            $_SESSION['error'] = "Paramètres manquants.";
            header('Location: ' . BASE_URL . '/cahier/calendrier.php');
            exit;
        }
        
        // Récupérer les informations de la séance
        $seance = $this->seanceModel->getSeanceById($id);
        
        if (!$seance) {
            $_SESSION['error'] = "Séance non trouvée.";
            header('Location: ' . BASE_URL . '/cahier/calendrier.php');
            exit;
        }
        
        // Vérifier si l'utilisateur est le créateur de la séance ou un admin
        if ($seance['professeur_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à dupliquer cette séance.";
            header('Location: ' . BASE_URL . '/cahier/calendrier.php');
            exit;
        }
        
        // Dupliquer la séance
        $nouvelleSeanceId = $this->seanceModel->dupliquerSeance($id, $nouvelleDate);
        
        if ($nouvelleSeanceId) {
            $_SESSION['success'] = "La séance a été dupliquée avec succès.";
            header('Location: ' . BASE_URL . '/cahier/details.php?id=' . $nouvelleSeanceId);
            exit;
        } else {
            $_SESSION['error'] = "Une erreur est survenue lors de la duplication de la séance.";
            header('Location: ' . BASE_URL . '/cahier/details.php?id=' . $id);
            exit;
        }
    }
    
    /**
     * Affiche la liste des chapitres d'un cours
     */
    public function chapitres() {
        // Récupérer les paramètres de filtrage
        $classeId = isset($_GET['classe_id']) ? (int)$_GET['classe_id'] : null;
        $matiereId = isset($_GET['matiere_id']) ? (int)$_GET['matiere_id'] : null;
        
        // Construction des filtres
        $filters = [
            'classe_id' => $classeId,
            'matiere_id' => $matiereId
        ];
        
        // Adapter les filtres selon le type d'utilisateur
        $userType = $_SESSION['user_type'];
        $userId = $_SESSION['user_id'];
        
        if ($userType === TYPE_PROFESSEUR) {
            $filters['professeur_id'] = $userId;
        } elseif ($userType === TYPE_ELEVE) {
            // Les élèves voient les chapitres de leurs classes
            $classesEleve = $this->classeModel->getClassesEleve($userId);
            
            if (!empty($classesEleve)) {
                if (empty($classeId)) {
                    // Si aucune classe n'est sélectionnée, utiliser la première classe de l'élève
                    $filters['classe_id'] = $classesEleve[0]['id'];
                } else {
                    // Vérifier que l'élève appartient bien à la classe sélectionnée
                    $classeValide = false;
                    foreach ($classesEleve as $classe) {
                        if ($classe['id'] == $classeId) {
                            $classeValide = true;
                            break;
                        }
                    }
                    
                    if (!$classeValide) {
                        $filters['classe_id'] = $classesEleve[0]['id'];
                    }
                }
            }
        } elseif ($userType === TYPE_PARENT) {
            // Les parents voient les chapitres des classes de leurs enfants
            return $this->chapitresParent();
        }
        
        // Récupérer les chapitres selon les filtres
        $chapitres = $this->chapitreModel->getAllChapitres($filters);
        
        // Récupérer les listes pour les filtres
        $classes = $this->classeModel->getAllClasses();
        $matieres = $this->matiereModel->getAllMatieres();
        
        // Filtrer les classes selon l'utilisateur
        if ($userType === TYPE_PROFESSEUR) {
            $classes = $this->classeModel->getClassesProfesseur($userId);
        } elseif ($userType === TYPE_ELEVE) {
            $classes = $this->classeModel->getClassesEleve($userId);
        }
        
        // Charger la vue
        require_once ROOT_PATH . '/views/cahier/chapitres.php';
    }
    
    /**
     * Envoie des notifications aux élèves concernés par une séance
     * @param string $seanceId ID de la séance
     * @param string $type Type de notification ('creation', 'modification', 'annulation')
     */
    private function envoyerNotifications($seanceId, $type) {
        // Récupérer les informations de la séance
        $seance = $this->seanceModel->getSeanceById($seanceId);
        
        if (!$seance) {
            return false;
        }
        
        // Récupérer les élèves de la classe
        $eleves = $this->userModel->getElevesClasse($seance['classe_id']);
        
        // Créer une notification pour chaque élève
        require_once ROOT_PATH . '/models/Notification.php';
        $notificationModel = new Notification();
        
        // Définir le titre et le contenu de la notification selon le type
        $titre = "";
        $contenu = "";
        
        switch ($type) {
            case 'creation':
                $titre = "Nouvelle séance : {$seance['titre']}";
                $contenu = "Une nouvelle séance de {$seance['matiere_nom']} a été programmée pour la classe {$seance['classe_nom']} le " . date(DATETIME_FORMAT, strtotime($seance['date_debut']));
                break;
                
            case 'modification':
                $titre = "Séance modifiée : {$seance['titre']}";
                $contenu = "La séance de {$seance['matiere_nom']} pour la classe {$seance['classe_nom']} le " . date(DATETIME_FORMAT, strtotime($seance['date_debut'])) . " a été modifiée.";
                break;
                
            case 'annulation':
                $titre = "Séance annulée : {$seance['titre']}";
                $contenu = "La séance de {$seance['matiere_nom']} pour la classe {$seance['classe_nom']} le " . date(DATETIME_FORMAT, strtotime($seance['date_debut'])) . " a été annulée.";
                break;
        }
        
        foreach ($eleves as $eleve) {
            // Créer la notification pour l'élève
            $notificationModel->createNotification([
                'destinataire_id' => $eleve['id'],
                'titre' => $titre,
                'contenu' => $contenu,
                'seance_id' => $seanceId
            ]);
            
            // Notifier aussi les parents de l'élève
            $parents = $this->userModel->getParentsEleve($eleve['id']);
            
            foreach ($parents as $parent) {
                $notificationModel->createNotification([
                    'destinataire_id' => $parent['id'],
                    'titre' => "Pour votre enfant : " . $titre,
                    'contenu' => "Pour votre enfant {$eleve['first_name']} {$eleve['last_name']} : " . $contenu,
                    'seance_id' => $seanceId
                ]);
            }
        }
        
        return true;
    }
    
    /**
     * Traduit le nom d'un jour de la semaine en anglais vers le français
     * @param string $jourEn Jour en anglais
     * @return string Jour en français
     */
    private function getJourFr($jourEn) {
        $jours = [
            'Monday' => 'Lundi',
            'Tuesday' => 'Mardi',
            'Wednesday' => 'Mercredi',
            'Thursday' => 'Jeudi',
            'Friday' => 'Vendredi',
            'Saturday' => 'Samedi',
            'Sunday' => 'Dimanche'
        ];
        
        return isset($jours[$jourEn]) ? $jours[$jourEn] : $jourEn;
    }
}