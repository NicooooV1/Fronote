<?php
/**
 * M16 – Documents — Supprimer un document
 */
require_once __DIR__ . '/../../API/bootstrap.php';
requireAuth();

if (!isAdmin()) {
    redirect('accueil/accueil.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: documents.php');
    exit;
}

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['error_message'] = 'Jeton de sécurité invalide. Veuillez recharger la page.';
    header('Location: documents.php');
    exit;
}

require_once __DIR__ . '/includes/DocumentService.php';
$docService = new DocumentService(getPDO());

$id = (int)($_POST['id'] ?? 0);

if ($docService->supprimer($id)) {
    $_SESSION['success_message'] = 'Document supprimé.';
} else {
    $_SESSION['error_message'] = 'Erreur lors de la suppression.';
}

header('Location: documents.php');
exit;
