<?php
/**
 * Configuration de la gestion des comptes administrateurs
 * Ce fichier définit les règles de gestion des comptes administrateurs
 */

// Vérifier si la création de nouveaux comptes administrateurs est autorisée
if (!defined('ALLOW_NEW_ADMIN_ACCOUNTS')) {
    // La présence du fichier admin.lock indique qu'un administrateur a déjà été créé
    // et qu'aucun nouveau compte admin ne peut être créé via l'interface d'inscription
    define('ALLOW_NEW_ADMIN_ACCOUNTS', !file_exists(__DIR__ . '/../../admin.lock'));
}

// Les administrateurs existants peuvent toujours être modifiés ou supprimés
if (!defined('ALLOW_ADMIN_MANAGEMENT')) {
    define('ALLOW_ADMIN_MANAGEMENT', true);
}

/**
 * Vérifie si la création de nouveaux comptes administrateur est autorisée
 * @return bool True si la création est autorisée
 */
function isNewAdminAccountsAllowed() {
    return ALLOW_NEW_ADMIN_ACCOUNTS;
}

/**
 * Vérifie si la gestion des comptes administrateur existants est autorisée
 * @return bool True si la gestion est autorisée
 */
function isAdminManagementAllowed() {
    return ALLOW_ADMIN_MANAGEMENT;
}

/**
 * Vérifie si un mot de passe respecte les critères de sécurité
 * @param string $password Mot de passe à vérifier
 * @return array ['valid' => bool, 'errors' => array] Résultat de la validation
 */
function validateStrongPassword($password) {
    $errors = [];
    $result = ['valid' => true, 'errors' => []];
    
    if (strlen($password) < 12) {
        $errors[] = "Le mot de passe doit contenir au moins 12 caractères";
        $result['valid'] = false;
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Le mot de passe doit contenir au moins une lettre majuscule";
        $result['valid'] = false;
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Le mot de passe doit contenir au moins une lettre minuscule";
        $result['valid'] = false;
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Le mot de passe doit contenir au moins un chiffre";
        $result['valid'] = false;
    }
    
    if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        $errors[] = "Le mot de passe doit contenir au moins un caractère spécial";
        $result['valid'] = false;
    }
    
    $result['errors'] = $errors;
    return $result;
}
