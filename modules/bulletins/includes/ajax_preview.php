<?php
/**
 * AJAX endpoint — Live preview of a bulletin.
 * GET: bulletin_id
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../API/core.php';
require_once __DIR__ . '/BulletinService.php';

try {
    requireAuth();

    // La preview est un outil de gestion : reservee au staff (admin / prof / vie scolaire).
    // Empeche un eleve/parent de prefetch n'importe quel bulletin via cet endpoint.
    if (!isAdmin() && !isProfesseur() && !isVieScolaire()) {
        http_response_code(403);
        echo json_encode(['error' => 'Accès refusé']);
        exit;
    }

    $bulletinId = (int) ($_GET['bulletin_id'] ?? 0);
    if (!$bulletinId) {
        http_response_code(400);
        echo json_encode(['error' => 'ID bulletin manquant']);
        exit;
    }

    $pdo = getPDO();
    $service = new BulletinService($pdo);
    $html = $service->generatePreviewHtml($bulletinId);

    echo json_encode(['html' => $html]);
} catch (\Exception $e) {
    error_log("ajax_preview error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur']);
}
