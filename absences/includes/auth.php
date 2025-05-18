<?php
/**
 * Module d'authentification pour le module Absences
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

// Vérifier si l'utilisateur peut gérer les absences
function canManageAbsences() {
    $role = getUserRole();
    return in_array($role, ['administrateur', 'professeur', 'vie_scolaire']);
}

// Vérifier si l'utilisateur peut gérer les notes
function canManageNotes() {
    $role = getUserRole();
    return in_array($role, ['administrateur', 'professeur', 'vie_scolaire']);
}

// Rediriger si l'utilisateur n'est pas connecté
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /~u22405372/SAE/Pronote/login/public/index.php');
        exit;
    }
    return getCurrentUser();
}
