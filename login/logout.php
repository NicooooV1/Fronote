<?php
/**
 * Script de déconnexion pour Fronote — Version intégrée
 */

// Inclure l'API centralisée
require_once __DIR__ . '/../API/core.php';

// Effectuer la déconnexion via l'API centralisée
logout();

// Toujours rediriger vers la page de connexion, quoi qu'il arrive
if (!headers_sent()) {
    header('Location: index.php', true, 302);
}
echo '<!DOCTYPE html><meta http-equiv="refresh" content="0;url=index.php">'
   . '<a href="index.php">Se reconnecter</a>';
exit;
