<?php
/**
 * AJAX endpoint for drag-and-drop EDT operations.
 * POST (JSON) { cours_id, new_jour, new_creneau_id, csrf_token }
 *   new_jour : enum string ('lundi'..'samedi')
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

$pdo = getPDO();
require_once __DIR__ . '/includes/EdtService.php';
$edtService = new EdtService($pdo);

$coursId      = (int) ($input['cours_id'] ?? 0);
$newJour      = trim((string) ($input['new_jour'] ?? ''));
$newCreneauId = (int) ($input['new_creneau_id'] ?? 0);

$joursValides = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
if (!$coursId || !$newCreneauId || !in_array($newJour, $joursValides, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Paramètres manquants ou invalides']);
    exit;
}

// Charger le cours actuel
$cours = $edtService->getCours($coursId);
if (!$cours) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Cours introuvable']);
    exit;
}

// Récupérer les heures du nouveau créneau
$stmt = $pdo->prepare("SELECT heure_debut, heure_fin FROM creneaux_horaires WHERE id = ?");
$stmt->execute([$newCreneauId]);
$creneau = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$creneau) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Créneau introuvable']);
    exit;
}

try {
    $data = [
        'classe_id'     => $cours['classe_id'],
        'matiere_id'    => $cours['matiere_id'],
        'professeur_id' => $cours['professeur_id'],
        'salle_id'      => $cours['salle_id'],
        'jour'          => $newJour,
        'creneau_id'    => $newCreneauId,
        'heure_debut'   => $creneau['heure_debut'],
        'heure_fin'     => $creneau['heure_fin'],
        'groupe'        => $cours['groupe'] ?? null,
        'type_cours'    => $cours['type_cours'] ?? 'cours',
        'recurrence'    => $cours['recurrence'] ?? 'hebdomadaire',
        'couleur'       => $cours['couleur'] ?? null,
    ];

    $ok = $edtService->updateCours($coursId, $data);
    echo json_encode(['success' => $ok, 'message' => $ok ? 'Cours déplacé' : 'Échec']);
} catch (\PDOException $e) {
    // Ne jamais exposer le détail SQL au client : logge côté serveur, message générique.
    error_log('ajax_move_cours PDOException: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur lors du déplacement du cours']);
} catch (\RuntimeException $e) {
    // Conflit métier détecté par EdtService::detecterConflits (message contrôlé, sûr à afficher).
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (\Throwable $e) {
    // Toute autre erreur inattendue : ne pas fuiter le détail au client.
    error_log('ajax_move_cours error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur lors du déplacement du cours']);
}
