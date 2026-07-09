<?php
declare(strict_types=1);
/**
 * telecharger_rendu.php — Téléchargement sécurisé d'un fichier de rendu d'élève.
 *
 * Les fichiers de rendus sont servis via ce script (pas d'accès direct à uploads/,
 * qui est 403 par .htaccess). Vérifie l'authentification ET la propriété :
 *   - l'élève qui a déposé le rendu,
 *   - le professeur propriétaire du devoir,
 *   - les rôles admin / vie scolaire.
 */
require_once __DIR__ . '/../../API/core.php';
require_once __DIR__ . '/includes/RenduService.php';

requireAuth();

$pdo     = getPDO();
$service = new RenduService($pdo);

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    http_response_code(400);
    exit('Paramètre manquant.');
}

$rendu = $service->getRenduById($id);
if (!$rendu || empty($rendu['fichier_chemin'])) {
    http_response_code(404);
    exit('Fichier introuvable.');
}

// Contrôle d'accès
$user         = getCurrentUser();
$userFullname = getUserFullName();
$isPrivileged = isAdmin() || isVieScolaire();

$isOwnerEleve = isStudent() && (int)$rendu['eleve_id'] === (int)($user['id'] ?? 0);
$isOwnerProf  = $service->profOwnsRendu($id, $userFullname, $isPrivileged);

if (!$isOwnerEleve && !$isOwnerProf) {
    http_response_code(403);
    exit('Accès refusé.');
}

// Servir le fichier via le service centralisé (chemin relatif à uploads/)
$uploader = new \API\Services\FileUploadService('rendus');
$uploader->serve($rendu['fichier_chemin'], $rendu['fichier_nom'] ?? null);
