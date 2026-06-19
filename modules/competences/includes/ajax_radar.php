<?php
/**
 * AJAX endpoint — Radar chart data for competences.
 * GET: type (eleve|classe), eleve_id|classe_id, periode_id
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../API/core.php';
require_once __DIR__ . '/CompetenceService.php';

try {
    requireAuth();

    $pdo = getPDO();
    $compService = new CompetenceService($pdo);

    $type      = $_GET['type'] ?? 'eleve';
    $eleveId   = (int) ($_GET['eleve_id'] ?? 0);
    $classeId  = (int) ($_GET['classe_id'] ?? 0);
    $periodeId = (int) ($_GET['periode_id'] ?? 0);

    // Sécurité (anti-IDOR) : un élève ne consulte que ses propres données ;
    // un parent uniquement celles de ses enfants. Le staff garde l'accès complet.
    if (!isAdmin() && !isTeacher() && !isVieScolaire()) {
        // Détermine les eleve_id autorisés pour l'utilisateur courant
        $allowedEleveIds = [];
        if (isEleve()) {
            $allowedEleveIds = [getUserId()];
        } elseif (isParent()) {
            $stmtPe = $pdo->prepare("SELECT id_eleve FROM parent_eleve WHERE id_parent = ?");
            $stmtPe->execute([getUserId()]);
            $allowedEleveIds = array_map('intval', $stmtPe->fetchAll(PDO::FETCH_COLUMN));
        }

        if ($type === 'eleve') {
            if (!in_array($eleveId, $allowedEleveIds, true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Accès refusé']);
                return;
            }
        } elseif ($type === 'classe') {
            // Autorisé seulement si la classe est celle d'un élève autorisé.
            // NB : eleves.classe est un varchar (nom de classe), à rapprocher de classes.nom.
            $classeAutorisee = false;
            if (!empty($allowedEleveIds) && $classeId > 0) {
                $ph = implode(',', array_fill(0, count($allowedEleveIds), '?'));
                $stmtCl = $pdo->prepare("SELECT 1 FROM eleves WHERE id IN ($ph) AND classe = (SELECT nom FROM classes WHERE id = ?) LIMIT 1");
                $stmtCl->execute(array_merge($allowedEleveIds, [$classeId]));
                $classeAutorisee = (bool) $stmtCl->fetchColumn();
            }
            if (!$classeAutorisee) {
                http_response_code(403);
                echo json_encode(['error' => 'Accès refusé']);
                return;
            }
        }
    }

    // Anti-IDOR inter-établissement — vaut AUSSI pour le staff (qui saute le bloc ci-dessus) :
    // les id élève/classe sont globaux, donc sans ce filtre un membre du staff de
    // l'établissement A pourrait lire les données d'une classe/d'un élève de l'établissement B.
    $etabCourant = \API\Core\EstablishmentContext::id();
    if ($type === 'eleve' && $eleveId > 0) {
        $chk = $pdo->prepare("SELECT 1 FROM eleves WHERE id = ? AND etablissement_id = ? LIMIT 1");
        $chk->execute([$eleveId, $etabCourant]);
        if (!$chk->fetchColumn()) { http_response_code(403); echo json_encode(['error' => 'Accès refusé']); return; }
    } elseif ($type === 'classe' && $classeId > 0) {
        $chk = $pdo->prepare("SELECT 1 FROM classes WHERE id = ? AND etablissement_id = ? LIMIT 1");
        $chk->execute([$classeId, $etabCourant]);
        if (!$chk->fetchColumn()) { http_response_code(403); echo json_encode(['error' => 'Accès refusé']); return; }
    }

    if ($type === 'eleve' && $eleveId > 0) {
        echo json_encode($compService->getRadarData($eleveId, $periodeId ?: null));
    } elseif ($type === 'classe' && $classeId > 0) {
        echo json_encode($compService->getRadarClasseData($classeId, $periodeId ?: null));
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Paramètres manquants']);
    }
} catch (\Exception $e) {
    error_log("ajax_radar error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur']);
}
