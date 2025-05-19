<?php
/**
 * Classe d'authentification pour Pronote
 */
require_once __DIR__ . '/../../API/core/Security.php';

class Auth {
    private $errorMessage = '';
    
    /**
     * Authentifie un utilisateur
     * 
     * @param string $profil Type de profil (élève, parent, professeur, administrateur)
     * @param string $identifiant Identifiant de l'utilisateur
     * @param string $password Mot de passe en clair
     * @return bool True si l'authentification est réussie
     */
    public function login($profil, $identifiant, $password) {
        global $pdo;
        
        // Vérifier que le profil est valide
        $validProfiles = ['eleve', 'parent', 'professeur', 'administrateur', 'vie_scolaire'];
        if (!in_array($profil, $validProfiles)) {
            $this->errorMessage = "Type de profil non valide.";
            return false;
        }
        
        try {
            // Établir la connexion à la base de données si ce n'est pas déjà fait
            if (!isset($pdo)) {
                require_once __DIR__ . '/../../API/database.php';
                $pdo = getDBConnection();
            }
            
            // Requête selon le type de profil
            $table = $this->getTableName($profil);
            
            // Utilisation de requêtes préparées pour éviter les injections SQL
            $stmt = $pdo->prepare("SELECT * FROM $table WHERE identifiant = ? LIMIT 1");
            $stmt->execute([$identifiant]);
            $user = $stmt->fetch();
            
            // Vérifier si l'utilisateur existe et si le mot de passe correspond
            if ($user && \Pronote\Security\verify_password($password, $user['mot_de_passe'])) {
                // Stockage des informations de l'utilisateur en session
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'nom' => $user['nom'],
                    'prenom' => $user['prenom'],
                    'profil' => $profil,
                    'mail' => $user['mail'],
                    'identifiant' => $user['identifiant']
                ];
                
                // Ajout d'informations spécifiques selon le profil
                if ($profil === 'eleve') {
                    $_SESSION['user']['classe'] = $user['classe'];
                    $_SESSION['user']['date_naissance'] = $user['date_naissance'];
                } elseif ($profil === 'professeur') {
                    $_SESSION['user']['matiere'] = $user['matiere'];
                    $_SESSION['user']['est_pp'] = $user['professeur_principal'];
                }
                
                // Enregistrement du temps d'authentification
                $_SESSION['auth_time'] = time();
                
                // Journaliser la connexion
                $this->logLogin($user['id'], $profil, true);
                
                return true;
            } else {
                // Journaliser la tentative échouée
                $this->logLogin(0, $profil, false, $identifiant);
                $this->errorMessage = "Identifiant ou mot de passe incorrect.";
                return false;
            }
        } catch (PDOException $e) {
            error_log("Erreur d'authentification: " . $e->getMessage());
            $this->errorMessage = "Une erreur est survenue lors de l'authentification.";
            return false;
        }
    }
    
    /**
     * Obtient le nom de la table correspondant au profil
     * 
     * @param string $profil Type de profil
     * @return string Nom de la table
     */
    private function getTableName($profil) {
        switch ($profil) {
            case 'eleve':
                return 'eleves';
            case 'parent':
                return 'parents';
            case 'professeur':
                return 'professeurs';
            case 'vie_scolaire':
                return 'vie_scolaire';
            case 'administrateur':
                return 'administrateurs';
            default:
                return '';
        }
    }
    
    /**
     * Journalise une tentative de connexion
     * 
     * @param int $userId ID de l'utilisateur (0 si échec)
     * @param string $profil Type de profil
     * @param bool $success True si la connexion est réussie
     * @param string $identifiant Identifiant utilisé (uniquement en cas d'échec)
     * @return void
     */
    private function logLogin($userId, $profil, $success, $identifiant = '') {
        $message = $success 
            ? "Connexion réussie: Utilisateur ID=$userId, Profil=$profil" 
            : "Échec de connexion: Profil=$profil" . ($identifiant ? ", Identifiant=$identifiant" : "");
        
        error_log($message);
        
        // Utiliser le système de journalisation central si disponible
        if (function_exists('\\Pronote\\Logging\\authAction')) {
            \Pronote\Logging\authAction('login', $identifiant, $success);
        }
    }
    
    /**
     * Récupère le message d'erreur
     * 
     * @return string Message d'erreur
     */
    public function getErrorMessage() {
        return $this->errorMessage;
    }
}