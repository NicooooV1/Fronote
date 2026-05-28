<?php
/**
 * AJAX endpoint : génération automatique d'emploi du temps (moteur glouton, CDC §6).
 * POST (JSON) {
 *   action: "preview" | "apply",
 *   source: "maquette" | "existing",   // d'où viennent les besoins
 *   classe_id?: int,                     // restreindre à une classe (optionnel)
 *   replace?: bool,                      // (apply) désactiver l'EDT existant des classes concernées
 *   csrf_token
 * }
 *
 * preview = dry-run (n'écrit rien) ; apply = persiste le plan.
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

$action   = $input['action'] ?? 'preview';
$source   = $input['source'] ?? 'maquette';
$classeId = !empty($input['classe_id']) ? (int) $input['classe_id'] : null;

require_once __DIR__ . '/includes/EdtService.php';
$edt = new EdtService(getPDO());

$requirements = ($source === 'existing')
    ? $edt->buildRequirementsFromExisting($classeId)
    : $edt->getMaquette($classeId);

if (empty($requirements)) {
    echo json_encode([
        'success' => false,
        'message' => 'Aucun besoin à planifier (maquette vide ou EDT existant vide).',
    ]);
    exit;
}

$plan = $edt->generateTimetable($requirements);

if ($action === 'apply') {
    $replace = !empty($input['replace']);
    $n = $edt->applyGeneratedPlan($plan['placed'], $replace);
    try {
        app('audit')->log('edt_generate_apply', null, ['new' => [
            'inserted'  => $n,
            'unplaced'  => count($plan['unplaced']),
            'classe_id' => $classeId,
            'source'    => $source,
        ]]);
    } catch (\Throwable $e) { /* audit non bloquant */ }
    echo json_encode([
        'success'  => $n > 0,
        'applied'  => $n,
        'unplaced' => $plan['unplaced'],
        'score'    => $plan['score'],
    ]);
    exit;
}

// preview (dry-run)
echo json_encode([
    'success'      => true,
    'placed_count' => count($plan['placed']),
    'unplaced'     => $plan['unplaced'],
    'score'        => $plan['score'],
]);
