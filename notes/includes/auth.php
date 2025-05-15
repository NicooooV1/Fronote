<?php
/**
 * Authentication functions for notes module
 */

// Include the path helper and auth
require_once dirname(dirname(dirname(__DIR__))) . '/API/path_helper.php';
require_once API_AUTH_PATH;

// No need to redefine core authentication functions since they're in the API
// Add only module-specific functions here

/**
 * Check if user can manage notes
 * 
 * @return bool
 */
function canManageNotes() {
    return isTeacher() || isAdmin() || isVieScolaire();
}

// Other module-specific auth functions...
?>