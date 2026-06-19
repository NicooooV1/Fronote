<?php
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

$file = __DIR__ . '/uploads/' . $diplome['fichier_path'];
if (!file_exists($file)) { redirect('/modules/diplomes/diplomes.php'); }

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($diplome['fichier_path']) . '"');
header('Content-Length: ' . filesize($file));
readfile($file);
exit;
