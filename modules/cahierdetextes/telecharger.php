<?php
declare(strict_types=1);
/**
 * telecharger.php — Téléchargement sécurisé de pièces jointes (PJ-3)
 *
 * Les fichiers sont servis via ce script (pas d'accès direct à uploads/).
 * Vérifie l'authentification avant de servir le fichier.
 */
require_once __DIR__ . '/../../API/core.php';
require_once __DIR__ . '/includes/DevoirService.php';

requireAuth();

$pdo     = getPDO();
$service = new DevoirService($pdo);

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    http_response_code(400);
    exit('Paramètre manquant.');
}

$fichier = $service->getFichierById($id);
if (!$fichier) {
    http_response_code(404);
    exit('Fichier introuvable.');
}

// Contrôle d'accès (anti-IDOR) : au-delà du scope établissement, l'utilisateur
// doit avoir accès au devoir (élève/parent de la classe, ou prof/staff).
// Fail-closed : même réponse 404 qu'un fichier inexistant (pas d'énumération).
$user = getCurrentUser();
if (!$service->userCanAccessFichier($fichier, $user['id'] ?? null, getUserRole(), getUserFullName())) {
    http_response_code(404);
    exit('Fichier introuvable.');
}

// Servir le fichier via le service centralisé
$uploader = new \API\Services\FileUploadService('devoirs');
$uploader->serve($fichier['nom_stockage'], $fichier['nom_original']);
