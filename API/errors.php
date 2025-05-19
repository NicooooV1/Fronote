<?php
/**
 * Initialisation du système de gestion d'erreurs pour Pronote
 */

// Charger le système de gestion d'erreurs
require_once __DIR__ . '/core/errors.php';

// Enregistrer les gestionnaires d'erreurs personnalisés
\Pronote\Errors\registerErrorHandlers();
