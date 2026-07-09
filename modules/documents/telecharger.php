<?php
/**
 * M16 – Documents — Télécharger un document
 */
require_once __DIR__ . '/../../API/bootstrap.php';
requireAuth();

require_once __DIR__ . '/includes/DocumentService.php';
$docService = new DocumentService(getPDO());

$id = (int)($_GET['id'] ?? 0);
$doc = $docService->getDocument($id);

if (!$doc) {
    http_response_code(404);
    die('Document introuvable.');
}

// Cloisonnement établissement (anti-IDOR cross-tenant) : un document d'un autre
// établissement est traité comme introuvable. Repli sur le contrôle de visibilité
// par rôle ci-dessous si le contexte établissement est indéterminé.
if (isset($doc['etablissement_id'])) {
    $__etab = 0;
    try { $__etab = (int) \API\Core\EstablishmentContext::id(); }
    catch (\Throwable $e) { error_log('[telecharger] contexte établissement indéterminé: ' . $e->getMessage()); }
    if ($__etab > 0 && (int) $doc['etablissement_id'] !== $__etab) {
        http_response_code(404);
        die('Document introuvable.');
    }
}

// Confinement anti path-traversal : le chemin (issu de la BDD) doit rester sous le module.
$baseDir  = realpath(__DIR__);
$filepath = realpath(__DIR__ . '/' . $doc['fichier_chemin']);
if ($filepath === false || $baseDir === false
    || strpos($filepath, $baseDir . DIRECTORY_SEPARATOR) !== 0
    || !is_file($filepath)) {
    http_response_code(404);
    die('Fichier introuvable sur le serveur.');
}

// Contrôle de visibilité (évite l'IDOR : un élève/parent ne télécharge pas un
// document non destiné à son rôle ; visibilité vide = visible par tous).
$role    = getUserRole();
$vis     = json_decode($doc['visibilite'] ?? '[]', true) ?: [];
$isStaff = in_array($role, ['administrateur', 'professeur', 'vie_scolaire'], true);
if (!$isStaff && !empty($vis) && !in_array($role, $vis, true)) {
    http_response_code(403);
    die('Accès non autorisé.');
}

$docService->incrementerTelechargements($id);

// MIME recalculé sur le contenu réel (le type stocké provient du client → non fiable).
$realMime     = (new \finfo(FILEINFO_MIME_TYPE))->file($filepath) ?: 'application/octet-stream';
$downloadName = preg_replace('/[\r\n"]+/', '', basename((string) $doc['fichier_nom']));

header('Content-Description: File Transfer');
header('Content-Type: ' . $realMime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: no-store');
readfile($filepath);
exit;
