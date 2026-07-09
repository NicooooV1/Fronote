<?php
declare(strict_types=1);
/**
 * M44 – Téléchargement fichier diplôme
 */
require_once __DIR__ . '/includes/header.php';

$id = (int)($_GET['id'] ?? 0);
$diplome = $diplService->getDiplome($id);
if (!$diplome || !$diplome['fichier_path']) { redirect('/modules/diplomes/diplomes.php'); }

// Accès: admin/VS toujours, eleve si c'est le sien, parent si enfant
if (!isAdmin() && !isPersonnelVS()) {
    if (isEleve() && $diplome['eleve_id'] != getUserId()) { redirect('/modules/diplomes/diplomes.php'); }
    if (isParent()) {
        $check = $pdo->prepare("SELECT 1 FROM parent_eleve WHERE id_parent = ? AND id_eleve = ?");
        $check->execute([getUserId(), $diplome['eleve_id']]);
        if (!$check->fetchColumn()) { redirect('/modules/diplomes/diplomes.php'); }
    }
}

// Confinement anti path-traversal : le fichier doit se résoudre SOUS uploads/.
$baseDir = realpath(__DIR__ . '/uploads');
$real    = realpath(__DIR__ . '/uploads/' . $diplome['fichier_path']);
if ($real === false || $baseDir === false
    || strpos($real, $baseDir . DIRECTORY_SEPARATOR) !== 0
    || !is_file($real)) {
    redirect('/modules/diplomes/diplomes.php');
}

$downloadName = str_replace(['"', "\r", "\n"], '', basename($diplome['fichier_path']));
header('Content-Type: application/octet-stream');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($real));
readfile($real);
exit;
