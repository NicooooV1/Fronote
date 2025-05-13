<?php
// index.php - Redirection vers le cahier de texte
require_once 'config.php';

// Vérification de l'authentification
if (!isAuthenticated()) {
    redirect('/../login/public/index.php');
}

// Redirection vers le cahier de texte
header('Location: cahier_texte.php');
exit;