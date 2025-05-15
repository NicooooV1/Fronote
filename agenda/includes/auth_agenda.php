<?php
/**
 * Fichier d'authentification spécifique pour le module Agenda
 * Inclut le fichier d'authentification principal et ajoute les fonctions spécifiques
 */

// Inclure le fichier auth.php principal
require_once __DIR__ . '/../../includes/auth.php';

/**
 * Vérifie si l'utilisateur a le droit de gérer les événements
 * (ajouter, modifier, supprimer)
 * 
 * @return bool
 */
function canManageEvents() {
    // Vérifier si l'une des fonctions de vérification de rôle existe
    if (function_exists('isTeacher')) {
        return isTeacher() || isAdmin() || isVieScolaire();
    } else {
        // Fallback si les fonctions de vérification de rôle n'existent pas
        if (isset($_SESSION['user']) && isset($_SESSION['user']['profil'])) {
            $role = $_SESSION['user']['profil'];
            return in_array($role, ['professeur', 'administrateur', 'vie_scolaire']);
        }
        return false;
    }
}

/**
 * Vérifie si l'utilisateur a le droit de consulter tous les événements
 * 
 * @return bool
 */
function canViewAllEvents() {
    if (function_exists('isAdmin')) {
        return isAdmin() || isVieScolaire();
    } else {
        // Fallback
        if (isset($_SESSION['user']) && isset($_SESSION['user']['profil'])) {
            $role = $_SESSION['user']['profil'];
            return in_array($role, ['administrateur', 'vie_scolaire']);
        }
        return false;
    }
}

/**
 * Vérifie si l'utilisateur peut modifier un événement spécifique
 * 
 * @param array $evenement Données de l'événement
 * @return bool
 */
function canEditEvent($evenement) {
    // Administrateurs et vie scolaire peuvent tout modifier
    if (canViewAllEvents()) {
        return true;
    }
    
    // Si l'utilisateur est un professeur, il peut modifier ses propres événements
    if (isset($_SESSION['user'])) {
        $user_fullname = $_SESSION['user']['prenom'] . ' ' . $_SESSION['user']['nom'];
        $role = $_SESSION['user']['profil'];
        
        if ($role === 'professeur' && $evenement['createur'] === $user_fullname) {
            return true;
        }
    }
    
    return false;
}

/**
 * Vérifie si l'utilisateur peut supprimer un événement spécifique
 * 
 * @param array $evenement Données de l'événement
 * @return bool
 */
function canDeleteEvent($evenement) {
    // Utiliser la même logique que pour la modification
    return canEditEvent($evenement);
}

/**
 * Vérifie si l'utilisateur peut voir un événement spécifique
 * en fonction de son rôle et de la visibilité de l'événement
 * 
 * @param array $evenement Données de l'événement
 * @return bool
 */
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
                        // Il faudrait avoir un moyen de récupérer la classe de l'élève
                        // Pour l'instant, on accepte toutes les visibilités classes pour les élèves
                        return true;
                    }
                }
                
                return false;
        }
    }
    
    return false;
}
?>