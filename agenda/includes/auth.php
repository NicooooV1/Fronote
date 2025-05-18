<?php
/**
 * Module d'authentification pour le module Agenda
 */

// Inclure le système d'autoloading
$autoloadPath = __DIR__ . '/../../API/autoload.php';

if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
    
    // Initialiser l'application avec le système d'autoloading
    bootstrap();
} else {
    // Fallback si le système d'autoloading n'est pas disponible
    session_start();
    
    // Fonctions d'authentification de base si le système centralisé n'est pas disponible
    if (!function_exists('isLoggedIn')) {
        function isLoggedIn() {
            return isset($_SESSION['user']) && !empty($_SESSION['user']);
        }
    }
    
    if (!function_exists('getCurrentUser')) {
        function getCurrentUser() {
            return $_SESSION['user'] ?? null;
        }
    }
    
    if (!function_exists('getUserRole')) {
        function getUserRole() {
            $user = getCurrentUser();
            return $user ? $user['profil'] : null;
        }
    }
    
    if (!function_exists('requireLogin')) {
        function requireLogin() {
            if (!isLoggedIn()) {
                $loginUrl = '/~u22405372/SAE/Pronote/login/public/index.php';
                header("Location: $loginUrl");
                exit;
            }
            return getCurrentUser();
        }
    }
}

// Si nécessaire, définir des fonctions spécifiques au module Agenda ici

// No need to redefine functions that already exist in the API
// Only declare functions that are specific to this module that aren't already defined

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