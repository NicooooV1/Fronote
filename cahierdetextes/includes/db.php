<?php
/**
 * Réutilisation de la connexion à la base de données du système principal
 */

// Inclure le fichier de configuration de la base de données
require_once __DIR__ . '/../../login/config/database.php';

// La connexion $pdo est maintenant disponible
// Pas besoin de redéfinir les paramètres de connexion puisqu'ils sont déjà définis dans database.php
?>