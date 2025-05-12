<?php
/**
 * Point d'entrée principal de l'application ENT Scolaire
 */

// Initialisation de l'application
require_once 'config/init.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Rediriger vers le tableau de bord approprié selon le type d'utilisateur
$userType = $_SESSION['user_type'];

switch ($userType) {
    case TYPE_ELEVE:
        header('Location: dashboard_eleve.php');
        break;
        
    case TYPE_PROFESSEUR:
        header('Location: dashboard_prof.php');
        break;
        
    case TYPE_PARENT:
        header('Location: dashboard_parent.php');
        break;
        
    case TYPE_ADMIN:
        header('Location: admin/index.php');
        break;
        
    default:
        header('Location: login.php');
        break;
}
exit;