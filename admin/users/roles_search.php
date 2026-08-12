<?php
declare(strict_types=1);
/**
 * Recherche AJAX d'utilisateurs par NOM pour le picker de l'attribution des rôles.
 * Cloisonnée à l'établissement de l'acteur (super_admin = global) via RoleManagementService.
 * GET ?q=<nom>[&type=eleve|parent|professeur|vie_scolaire|administrateur]
 */
require_once __DIR__ . '/../../API/core.php';
require_once __DIR__ . '/../includes/admin_functions.php';

requireAuth();
tenantGate('tenant.users.view');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    $svc = new \API\Services\RoleManagementService(getPDO());
    $actorRoles = app('authz')->roleKeys();
    $q    = (string) ($_GET['q'] ?? '');
    $type = isset($_GET['type']) && $_GET['type'] !== '' ? (string) $_GET['type'] : null;
    echo json_encode(['results' => $svc->searchUsers($actorRoles, $q, $type)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['results' => [], 'error' => 'search_failed']);
}
