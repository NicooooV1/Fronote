<?php
/**
 * Module d'authentification pour le module Agenda
 */

// Démarrer la session si elle n'est pas déjà active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vérifier si l'utilisateur est connecté
function isLoggedIn() {
    return isset($_SESSION['user']) && !empty($_SESSION['user']);
}

// Récupérer l'utilisateur connecté
function getCurrentUser() {
    return $_SESSION['user'] ?? null;
}

// Récupérer le rôle de l'utilisateur
function getUserRole() {
    $user = getCurrentUser();
    return $user ? $user['profil'] : null;
}

// Vérifier si l'utilisateur est administrateur
function isAdmin() {
    return getUserRole() === 'administrateur';
}

// Vérifier si l'utilisateur est professeur
function isTeacher() {
    return getUserRole() === 'professeur';
}

// Vérifier si l'utilisateur est élève
function isStudent() {
    return getUserRole() === 'eleve';
}

// Vérifier si l'utilisateur est parent
function isParent() {
    return getUserRole() === 'parent';
}

// Vérifier si l'utilisateur est membre de la vie scolaire
function isVieScolaire() {
    return getUserRole() === 'vie_scolaire';
}

// Récupérer le nom complet de l'utilisateur
function getUserFullName() {
    $user = getCurrentUser();
    if ($user) {
        return $user['prenom'] . ' ' . $user['nom'];
    }
    return '';
}

// Rediriger si l'utilisateur n'est pas connecté
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /~u22405372/SAE/Pronote/login/public/index.php');
        exit;
    }
    return getCurrentUser();
}

/**
 * Vérifier si l'utilisateur a le droit de consulter tous les événements
 * 
 * @return bool
 */
function canViewAllEvents() {
    return isAdmin() || isVieScolaire();
}

/**
 * Fonctions spécifiques pour la gestion des événements dans l'agenda
 */

/**
 * Vérifier si l'utilisateur peut modifier un événement spécifique
 * 
 * @param array $evenement Les données de l'événement
 * @return bool
 */
function canEditEvent($evenement) {
    // Administrateurs et vie scolaire peuvent tout modifier
    if (canViewAllEvents()) {
        return true;
    }
    
    // Si l'utilisateur est un professeur, il peut modifier ses propres événements
    if (isTeacher()) {
        $user_fullname = getUserFullName();
        return $evenement['createur'] === $user_fullname;
    }
    
    return false;
}

/**
 * Vérifier si l'utilisateur peut supprimer un événement spécifique
 * 
 * @param array $evenement Les données de l'événement
 * @return bool
 */
function canDeleteEvent($evenement) {
    // Utiliser la même logique que pour la modification
    return canEditEvent($evenement);
}

/**
 * Vérifier si l'utilisateur peut voir un événement spécifique
 * 
 * @param array $evenement Les données de l'événement
 * @return bool
 */
function canViewEvent($evenement) {
    // Administrateurs et vie scolaire peuvent tout voir
    if (canViewAllEvents()) {
        return true;
    }
    
    $user_fullname = getUserFullName();
    $role = getUserRole();
    
    // Si l'utilisateur est le créateur de l'événement
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

// Module-specific functions can be added here, checking if they already exist first
if (!function_exists('canManageAgendaEvents')) {
    /**
     * Check if user can manage agenda events
     * 
     * @return bool
     */
    function canManageAgendaEvents() {
        return isTeacher() || isAdmin() || isVieScolaire();
    }
}
?>