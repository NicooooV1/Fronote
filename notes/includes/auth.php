<?php
/**
 * Module d'authentification pour le module Notes
 */

// Démarrer la session si nécessaire
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Essayer d'inclure le fichier d'authentification central
$authCentralPath = __DIR__ . '/../../API/auth_central.php';
if (file_exists($authCentralPath)) {
    require_once $authCentralPath;
} else {
    // Fallback si le fichier central n'est pas disponible
    /**
     * Vérifier si l'utilisateur est connecté
     * @return bool True si l'utilisateur est connecté
     */
    function isLoggedIn() {
        return isset($_SESSION['user']) && !empty($_SESSION['user']);
    }
    
    /**
     * Récupérer l'utilisateur connecté
     * @return array|null Données de l'utilisateur ou null si non connecté
     */
    function getCurrentUser() {
        return $_SESSION['user'] ?? null;
    }
    
    /**
     * Récupère le rôle de l'utilisateur
     * @return string|null Rôle de l'utilisateur ou null
     */
    function getUserRole() {
        $user = getCurrentUser();
        return $user ? $user['profil'] : null;
    }
    
    /**
     * Récupère le nom complet de l'utilisateur
     * @return string Nom complet de l'utilisateur ou chaîne vide
     */
    function getUserFullName() {
        $user = getCurrentUser();
        if ($user) {
            return $user['prenom'] . ' ' . $user['nom'];
        }
        return '';
    }
    
    /**
     * Vérifier si l'utilisateur est administrateur
     * @return bool True si l'utilisateur est administrateur
     */
    function isAdmin() {
        return getUserRole() === 'administrateur';
    }
    
    /**
     * Vérifier si l'utilisateur est professeur
     * @return bool True si l'utilisateur est professeur
     */
    function isTeacher() {
        return getUserRole() === 'professeur';
    }
    
    /**
     * Vérifier si l'utilisateur est membre de la vie scolaire
     * @return bool True si l'utilisateur est membre de la vie scolaire
     */
    function isVieScolaire() {
        return getUserRole() === 'vie_scolaire';
    }
    
    /**
     * Vérifie si l'utilisateur peut gérer les notes
     * @return bool True si l'utilisateur peut gérer les notes
     */
    function canManageNotes() {
        $role = getUserRole();
        return in_array($role, ['administrateur', 'professeur', 'vie_scolaire']);
    }
}
?>