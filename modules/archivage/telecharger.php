<?php
declare(strict_types=1);
/**
 * M35 – Archivage — Télécharger
 */
require_once __DIR__ . '/includes/header.php';

$id = (int)($_GET['id'] ?? 0);
$path = $archiveService->getCheminFichier($id);

if (!$path) {
    header('Location: archivage.php');
    exit;
}

// Confinement anti path-traversal : le chemin (issu de la BDD) doit se résoudre
// SOUS la racine applicative et pointer un fichier réel, sinon on refuse.
$baseDir = realpath(defined('BASE_PATH') ? BASE_PATH : __DIR__ . '/../..');
if ($path[0] !== '/' && $baseDir !== false) {
    $path = $baseDir . '/' . ltrim($path, '/');
}
$real = realpath($path);
if ($real === false || $baseDir === false
    || strpos($real, $baseDir . DIRECTORY_SEPARATOR) !== 0
    || !is_file($real)) {
    http_response_code(404);
    exit('Fichier introuvable');
}

$filename = str_replace(['"', "\r", "\n"], '', basename($real));
header('Content-Type: application/octet-stream');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($real));
readfile($real);
exit;
