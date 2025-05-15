<?php
/**
 * Système d'authentification pour le module agenda
 * Ne dépend pas du système d'authentification principal
 */

// Démarrer la session si elle n'est pas déjà démarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Rediriger vers la page de connexion si l'utilisateur n'est pas connecté
function requireLogin() {
    if (!isset($_SESSION['user'])) {
        header('Location: ../login/public/login.php');
        exit;
    }
}

// Vérifier si l'utilisateur est connecté
function isLoggedIn() {
    return isset($_SESSION['user']);
}

// Vérifier si l'utilisateur est un professeur
function isTeacher() {
    return isLoggedIn() && $_SESSION['user']['profil'] === 'professeur';
}

// Vérifier si l'utilisateur est un élève
function isStudent() {
    return isLoggedIn() && $_SESSION['user']['profil'] === 'eleve';
}

// Vérifier si l'utilisateur est un parent
function isParent() {
    return isLoggedIn() && $_SESSION['user']['profil'] === 'parent';
}

// Vérifier si l'utilisateur est un administrateur
function isAdmin() {
    return isLoggedIn() && $_SESSION['user']['profil'] === 'administrateur';
}

// Vérifier si l'utilisateur est du personnel de vie scolaire
function isVieScolaire() {
    return isLoggedIn() && $_SESSION['user']['profil'] === 'vie_scolaire';
}

// Vérifier si l'utilisateur a le droit de modifier les notes
function canManageNotes() {
    return isTeacher() || isAdmin() || isVieScolaire();
}

/**
 * Fonctions spécifiques pour la gestion des événements
 */

// Vérifier si l'utilisateur a le droit de gérer les événements
function canManageEvents() {
    return isTeacher() || isAdmin() || isVieScolaire();
}

// Vérifier si l'utilisateur a le droit de consulter tous les événements
function canViewAllEvents() {
    return isAdmin() || isVieScolaire();
}

// Vérifier si l'utilisateur peut modifier un événement spécifique
function canEditEvent($evenement) {
    // Administrateurs et vie scolaire peuvent tout modifier
    if (canViewAllEvents()) {
        return true;
    }
    
    // Si l'utilisateur est un professeur, il peut modifier ses propres événements
    if (isTeacher() && isset($_SESSION['user'])) {
        $user_fullname = $_SESSION['user']['prenom'] . ' ' . $_SESSION['user']['nom'];
        return $evenement['createur'] === $user_fullname;
    }
    
    return false;
}

// Vérifier si l'utilisateur peut supprimer un événement spécifique
function canDeleteEvent($evenement) {
    // Utiliser la même logique que pour la modification
    return canEditEvent($evenement);
}

// Vérifier si l'utilisateur peut voir un événement spécifique
function canViewEvent($evenement) {
    // Administrateurs et vie scolaire peuvent tout voir
    if (canViewAllEvents()) {
        return true;
    }
    
    // Si l'utilisateur est le créateur de l'événement
    if (isset($_SESSION['user'])) {
        $user_fullname = $_SESSION['user']['prenom'] . ' ' . $_SESSION['user']['nom'];
        $role = $_SESSION['user']['profil'];
        
        if ($evenement['createur'] === $user_fullname) {
            return true;
        }
        
        // Vérifier la visibilité de l'événement
        switch ($evenement['visibilite']) {
            case 'public':
                return true;
                
            case 'professeurs':
                return $role === 'professeur';
                
            case 'eleves':
                return $role === 'eleve';
                
            default:
                // Pour les événements avec visibilité aux classes spécifiques
                if (strpos($evenement['visibilite'], 'classes:') === 0) {
                    // Si l'utilisateur est un professeur, il peut voir tous les événements liés aux classes
                    if ($role === 'professeur') {
                        return true;
                    }
                    
                    // Si l'utilisateur est un élève, vérifier si sa classe est concernée
                    if ($role === 'eleve') {
                        // Pour le moment, on accepte toutes les visibilités classes pour les élèves
                        // jusqu'à ce qu'on puisse récupérer la classe de l'élève
                        return true;
                    }
                }
                
                return false;
        }
    }
    
    return false;
}

// Appelons la fonction requireLogin() pour s'assurer que l'utilisateur est connecté
requireLogin();
?>