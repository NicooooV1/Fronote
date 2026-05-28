<?php
/**
 * AJAX endpoint : affectation intelligente de salle (CDC §4.3).
 * POST (JSON) { jour, creneau_id, classe_id?, salle_type?, id_exclude?, csrf_token }
 * Réponse : { success, salles: [{id, nom, type, capacite, batiment}, ...] }  (meilleure en premier)
 *
 * Lecture seule — propose des salles libres et compatibles, ne réserve rien.
 */
require_once __DIR__ . '/../../API/bootstrap.php';
requireAuth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!in_array(getUserRole(), ['administrateur', 'vie_scolaire'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

if (!\API\Core\Facades\CSRF::check($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Jeton CSRF invalide']);
    exit;
}

$jour      = trim((string) ($input['jour'] ?? ''));
$creneauId = (int) ($input['creneau_id'] ?? 0);
if ($jour === '' || $creneauId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Paramètres manquants (jour, creneau_id)']);
    exit;
}

require_once __DIR__ . '/includes/EdtService.php';
$edtService = new EdtService(getPDO());

$salles = $edtService->suggestSalle([
    'jour'       => $jour,
    'creneau_id' => $creneauId,
    'classe_id'  => (int) ($input['classe_id'] ?? 0),
    'salle_type' => $input['salle_type'] ?? null,
    'id_exclude' => (int) ($input['id_exclude'] ?? 0),
]);

$payload = array_map(static function ($s) {
    return [
        'id'       => (int) $s['id'],
        'nom'      => $s['nom'],
        'type'     => $s['type'] ?? 'standard',
        'capacite' => isset($s['capacite']) ? (int) $s['capacite'] : null,
        'batiment' => $s['batiment'] ?? null,
    ];
}, $salles);

echo json_encode(['success' => true, 'salles' => $payload]);
