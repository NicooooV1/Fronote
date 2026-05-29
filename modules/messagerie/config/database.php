<?php
/**
 * Fallback de connexion BDD pour la messagerie.
 *
 * Les contrôleurs/utils chargent ce fichier UNIQUEMENT si $pdo n'est pas déjà
 * disponible (ex. appel d'un contrôleur hors du flux index.php/config.php).
 * Il s'appuie sur l'API centralisée — pas de connexion parallèle ni de
 * credentials en dur.
 */
require_once dirname(__DIR__, 3) . '/API/core.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $pdo = getPDO();
}
$GLOBALS['pdo'] = $pdo;
