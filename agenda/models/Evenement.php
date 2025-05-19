<?php
/**
 * Modèle pour la gestion des événements
 */
class Evenement {
    // Propriétés de l'événement
    private $id;
    private $titre;
    private $description;
    private $dateDebut;
    private $dateFin;
    private $typeEvenement;
    private $statut;
    private $createur;
    private $visibilite;
    private $personnesConcernees;
    private $lieu;
    private $classes;
    private $matieres;
    private $dateCreation;
    private $dateModification;
    
    /**
     * Types d'événements disponibles
     * @return array Types d'événements avec leurs configurations
     */
    public static function getTypes() {
        return [
            'cours' => ['nom' => 'Cours', 'icone' => 'book', 'couleur' => '#00843d'],
            'devoirs' => ['nom' => 'Devoirs', 'icone' => 'pencil', 'couleur' => '#4285f4'],
            'reunion' => ['nom' => 'Réunion', 'icone' => 'users', 'couleur' => '#ff9800'],
            'examen' => ['nom' => 'Examen', 'icone' => 'file-text', 'couleur' => '#f44336'],
            'sortie' => ['nom' => 'Sortie scolaire', 'icone' => 'map-pin', 'couleur' => '#00c853'],
            'autre' => ['nom' => 'Autre', 'icone' => 'calendar', 'couleur' => '#9e9e9e']
        ];
    }
    
    /**
     * Statuts d'événements disponibles
     * @return array Statuts avec leurs configurations
     */
    public static function getStatuts() {
        return [
            'actif' => ['nom' => 'Actif', 'icone' => 'check-circle', 'couleur' => '#00843d'],
            'annulé' => ['nom' => 'Annulé', 'icone' => 'ban', 'couleur' => '#f44336'],
            'reporté' => ['nom' => 'Reporté', 'icone' => 'clock', 'couleur' => '#ff9800']
        ];
    }
    
    /**
     * Options de visibilité pour un utilisateur donné
     * @param array $user Utilisateur actuel
     * @return array Options de visibilité disponibles
     */
    public static function getVisibilityOptions($user) {
        $role = isset($user['profil']) ? $user['profil'] : '';
        
        if ($role === 'administrateur' || $role === 'vie_scolaire') {
            return [
                'public' => ['nom' => 'Public (visible par tous)', 'icone' => 'globe'],
                'professeurs' => ['nom' => 'Professeurs uniquement', 'icone' => 'user-tie'],
                'eleves' => ['nom' => 'Élèves uniquement', 'icone' => 'user-graduate'],
                'classes_specifiques' => ['nom' => 'Classes spécifiques', 'icone' => 'users'],
                'administration' => ['nom' => 'Administration uniquement', 'icone' => 'user-shield'],
                'personnel' => ['nom' => 'Personnel (visible uniquement par moi)', 'icone' => 'user-lock']
            ];
        } elseif ($role === 'professeur') {
            return [
                'public' => ['nom' => 'Public (visible par tous)', 'icone' => 'globe'],
                'professeurs' => ['nom' => 'Professeurs uniquement', 'icone' => 'user-tie'],
                'eleves' => ['nom' => 'Élèves uniquement', 'icone' => 'user-graduate'],
                'classes_specifiques' => ['nom' => 'Classes spécifiques', 'icone' => 'users'],
                'personnel' => ['nom' => 'Personnel (visible uniquement par moi)', 'icone' => 'user-lock']
            ];
        } else {
            // Élèves et parents
            return [
                'personnel' => ['nom' => 'Personnel (visible uniquement par moi)', 'icone' => 'user-lock']
            ];
        }
    }
    
    /**
     * Récupérer un événement par son ID
     * @param PDO $pdo Connexion PDO
     * @param int $id ID de l'événement
     * @return Evenement|null Instance de l'événement ou null
     */
    public static function getById($pdo, $id) {
        try {
            $stmt = $pdo->prepare('SELECT * FROM evenements WHERE id = ?');
            $stmt->execute([$id]);
            $data = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($data) {
                return self::fromArray($data);
            }
            
            return null;
        } catch (\PDOException $e) {
            error_log("Erreur lors de la récupération de l'événement ID=$id: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Créer une instance d'Evenement à partir d'un tableau
     * @param array $data Données de l'événement
     * @return Evenement Instance créée
     */
    public static function fromArray(array $data) {
        $evenement = new self();
        
        $evenement->id = $data['id'] ?? null;
        $evenement->titre = $data['titre'] ?? '';
        $evenement->description = $data['description'] ?? '';
        $evenement->dateDebut = $data['date_debut'] ?? null;
        $evenement->dateFin = $data['date_fin'] ?? null;
        $evenement->typeEvenement = $data['type_evenement'] ?? 'autre';
        $evenement->statut = $data['statut'] ?? 'actif';
        $evenement->createur = $data['createur'] ?? '';
        $evenement->visibilite = $data['visibilite'] ?? 'personnel';
        $evenement->personnesConcernees = $data['personnes_concernees'] ?? null;
        $evenement->lieu = $data['lieu'] ?? '';
        $evenement->classes = $data['classes'] ?? '';
        $evenement->matieres = $data['matieres'] ?? '';
        $evenement->dateCreation = $data['date_creation'] ?? null;
        $evenement->dateModification = $data['date_modification'] ?? null;
        
        return $evenement;
    }
    
    /**
     * Vérifier si l'utilisateur peut voir cet événement
     * @param array $user Utilisateur à vérifier
     * @return bool True si l'utilisateur peut voir l'événement
     */
    public function canViewBy($user) {
        if (!$user) {
            return false;
        }
        
        $role = isset($user['profil']) ? $user['profil'] : '';
        $fullName = (isset($user['prenom']) ? $user['prenom'] . ' ' : '') . 
                    (isset($user['nom']) ? $user['nom'] : '');
        
        // Administrateurs et vie scolaire peuvent tout voir
        if ($role === 'administrateur' || $role === 'vie_scolaire') {
            return true;
        }
        
        // Le créateur peut toujours voir son événement
        if ($this->createur === $fullName) {
            return true;
        }
        
        // Événement public
        if ($this->visibilite === 'public') {
            return true;
        }
        
        // Visibilité par rôle
        if ($this->visibilite === 'professeurs' && $role === 'professeur') {
            return true;
        }
        
        if ($this->visibilite === 'eleves' && $role === 'eleve') {
            return true;
        }
        
        // Visibilité par classe
        if (strpos($this->visibilite, 'classes:') === 0) {
            $classes_concernees = explode(',', substr($this->visibilite, 8));
            
            if ($role === 'professeur') {
                return true; // Les professeurs peuvent voir tous les événements de classe
            }
            
            if ($role === 'eleve' && isset($user['classe'])) {
                return in_array($user['classe'], $classes_concernees);
            }
        }
        
        return false;
    }
    
    /**
     * Vérifier si l'utilisateur peut modifier cet événement
     * @param array $user Utilisateur à vérifier
     * @return bool True si l'utilisateur peut modifier l'événement
     */
    public function canEditBy($user) {
        if (!$user) {
            return false;
        }
        
        $role = isset($user['profil']) ? $user['profil'] : '';
        $fullName = (isset($user['prenom']) ? $user['prenom'] . ' ' : '') . 
                    (isset($user['nom']) ? $user['nom'] : '');
        
        // Administrateurs et vie scolaire peuvent tout modifier
        if ($role === 'administrateur' || $role === 'vie_scolaire') {
            return true;
        }
        
        // Le créateur peut modifier son propre événement
        if ($role === 'professeur' && $this->createur === $fullName) {
            return true;
        }
        
        return false;
    }
    
    // Getters et setters
    public function getId() {
        return $this->id;
    }
    
    public function getTitre() {
        return $this->titre;
    }
    
    public function setTitre($titre) {
        $this->titre = $titre;
        return $this;
    }
    
    // Autres getters et setters similaires...
    
    /**
     * Convertit l'événement en tableau associatif
     * @return array Représentation en tableau de l'événement
     */
    public function toArray() {
        return [
            'id' => $this->id,
            'titre' => $this->titre,
            'description' => $this->description,
            'date_debut' => $this->dateDebut,
            'date_fin' => $this->dateFin,
            'type_evenement' => $this->typeEvenement,
            'statut' => $this->statut,
            'createur' => $this->createur,
            'visibilite' => $this->visibilite,
            'personnes_concernees' => $this->personnesConcernees,
            'lieu' => $this->lieu,
            'classes' => $this->classes,
            'matieres' => $this->matieres,
            'date_creation' => $this->dateCreation,
            'date_modification' => $this->dateModification
        ];
    }
}
