<?php
/**
 * Centralized authentication API for Pronote
 * Relies on the existing login system but provides unified access across all modules
 */

// Include the core API if not already included
if (!isset($GLOBALS['pdo'])) {
    require_once __DIR__ . '/core.php';
}

// Include the original Auth class
require_once __DIR__ . '/../login/src/auth.php';

// Initialize Auth object with the database connection
if (!isset($GLOBALS['auth'])) {
    $GLOBALS['auth'] = new Auth($GLOBALS['pdo']);
}
$auth = $GLOBALS['auth'];

/**
 * Check if user is logged in
 * 
 * @return bool
 */
function isLoggedIn() {
    global $auth;
    try {
        return $auth->isLoggedIn();
    } catch (Exception $e) {
        error_log("Auth error in isLoggedIn: " . $e->getMessage());
        // Default to not logged in if there's an error
        return false;
    }
}

/**
 * Require user to be logged in to access the page
 * Redirects to login page if not authenticated
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /~u22405372/SAE/Pronote/login/public/index.php');
        exit;
    }
}

/**
 * Check if current user has specific role
 * Safe handling to avoid SQL errors
 * 
 * @param string $role The role to check
 * @return bool
 */
function hasRole($role) {
    global $auth;
    try {
        return isLoggedIn() && $auth->hasRole($role);
    } catch (Exception $e) {
        error_log("Auth error in hasRole: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if current user is a teacher
 * 
 * @return bool
 */
function isTeacher() {
    return hasRole('professeur');
}

/**
 * Check if current user is a student
 * 
 * @return bool
 */
function isStudent() {
    return hasRole('eleve');
}

/**
 * Check if current user is a parent
 * 
 * @return bool
 */
function isParent() {
    return hasRole('parent');
}

/**
 * Check if current user is an administrator
 * 
 * @return bool
 */
function isAdmin() {
    return hasRole('administrateur');
}

/**
 * Check if current user is school life staff
 * 
 * @return bool
 */
function isVieScolaire() {
    return hasRole('vie_scolaire');
}

/**
 * Check if user can manage notes
 * 
 * @return bool
 */
function canManageNotes() {
    return isTeacher() || isAdmin() || isVieScolaire();
}

/**
 * Check if user can manage homework
 * 
 * @return bool
 */
function canManageDevoirs() {
    return isTeacher() || isAdmin() || isVieScolaire();
}

/**
 * Check if user can manage events
 * 
 * @return bool
 */
function canManageEvents() {
    return isTeacher() || isAdmin() || isVieScolaire();
}

/**
 * Check if user can view all events
 * 
 * @return bool
 */
function canViewAllEvents() {
    return isAdmin() || isVieScolaire();
}

/**
 * Get logged in user data
 * Returns cached version if available to reduce database calls
 * 
 * @return array|null User data or null if not logged in
 */
function getCurrentUser() {
    static $cached_user = null;
    
    if ($cached_user !== null) {
        return $cached_user;
    }
    
    if (!isset($_SESSION['user'])) {
        return null;
    }
    
    // Cache user data to reduce database load
    $cached_user = $_SESSION['user'];
    return $cached_user;
}

/**
 * Get user's full name
 * 
 * @return string
 */
function getUserFullName() {
    $user = getCurrentUser();
    return $user ? ($user['prenom'] . ' ' . $user['nom']) : '';
}

/**
 * Get user ID
 * 
 * @return int|null
 */
function getUserId() {
    $user = getCurrentUser();
    return $user ? $user['id'] : null;
}

/**
 * Get user role/profile
 * 
 * @return string|null
 */
function getUserRole() {
    $user = getCurrentUser();
    return $user ? $user['profil'] : null;
}

// Require login by default unless this file is included in a special context
if (!defined('SKIP_LOGIN_CHECK')) {
    requireLogin();
}
?>
