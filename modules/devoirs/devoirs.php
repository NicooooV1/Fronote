<?php
declare(strict_types=1);
/**
 * Point d'entrée « Devoirs » (le module devoirs est servi par cahierdetextes).
 *
 * La barre de navigation construit l'URL d'un module comme modules/{key}/{key}.php.
 * Le module « devoirs » n'a pas de page propre : sa fonctionnalité vit dans
 * cahierdetextes. On redirige donc vers la bonne vue selon le rôle, ce qui supprime
 * le lien mort (404) « Devoirs » présent pour tous les rôles.
 */
require_once __DIR__ . '/../../API/core.php';
requireAuth();

// Élève → « Mes devoirs » (à rendre) ; les autres → cahier de textes.
$target = (function_exists('isStudent') && isStudent())
    ? '../cahierdetextes/mes_devoirs.php'
    : '../cahierdetextes/cahierdetextes.php';

header('Location: ' . $target);
exit;
