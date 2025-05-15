<?php
/**
 * Authentication functions for agenda module
 */

// Locate and include the API path helper
$path_helper = null;
$possible_paths = [
    dirname(dirname(dirname(__DIR__))) . '/API/path_helper.php', // Standard path
    dirname(dirname(__DIR__)) . '/API/path_helper.php', // Alternate path
    dirname(dirname(dirname(dirname(__DIR__)))) . '/API/path_helper.php', // Another possible path
];

foreach ($possible_paths as $path) {
    if (file_exists($path)) {
        $path_helper = $path;
        break;
    }
}

if ($path_helper) {
    // Define ABSPATH for security check in path_helper.php
    if (!defined('ABSPATH')) define('ABSPATH', dirname(dirname(__FILE__)));
    require_once $path_helper;
    
    // Include the centralized API auth file
    require_once API_AUTH_PATH;
} else {
    // Fallback to direct inclusion if path_helper.php is not found
    $api_dir = dirname(dirname(dirname(__DIR__))) . '/API';
    if (file_exists($api_dir . '/auth.php')) {
        require_once $api_dir . '/auth.php';
    } else {
        // Try another potential path
        $api_dir = dirname(__DIR__) . '/../API';
        if (file_exists($api_dir . '/auth.php')) {
            require_once $api_dir . '/auth.php';
        } else {
            die("Cannot locate the API auth file. Please check your installation.");
        }
    }
}

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