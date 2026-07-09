<?php
declare(strict_types=1);
/**
 * AJAX endpoint — Statistics data for Canvas graphs.
 * GET params: type (distribution|evolution|boxplot), classe, matiere, trimestre, eleve_id
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../API/core.php';
require_once __DIR__ . '/NoteService.php';

try {
    requireAuth();

    $pdo = getPDO();
    $noteService = new NoteService($pdo);

    $type      = $_GET['type'] ?? '';
    $classe    = $_GET['classe'] ?? '';
    $matiere   = (int) ($_GET['matiere'] ?? 0);
    $trimestre = max(1, min(3, (int) ($_GET['trimestre'] ?? NoteService::getTrimestreCourant())));
    $eleveId   = (int) ($_GET['eleve_id'] ?? 0);

    // ── Autorisation (anti-IDOR + périmètre) ──
    // Sans ce garde, n'importe quel utilisateur authentifié pouvait lire les
    // notes de n'importe quel élève via ?eleve_id= ou les stats de n'importe
    // quelle classe. Périmètres : admin/vie scolaire = tout l'établissement ;
    // ENSEIGNANT = seulement SES classes/élèves ; parent = ses enfants ; élève = lui-même.
    $isFullAccess  = isAdmin() || isVieScolaire();   // périmètre établissement complet
    $isTeacher     = isTeacher();
    $isStaff       = $isFullAccess || $isTeacher;
    $currentUserId = (int) getUserId();
    $etabId        = \API\Core\EstablishmentContext::id();

    // Classes réellement enseignées par le professeur courant (scope anti sur-accès).
    $teacherClasses = [];
    if ($isTeacher && !$isFullAccess) {
        $tc = $pdo->prepare("SELECT DISTINCT nom_classe FROM professeur_classes WHERE id_professeur = ?");
        $tc->execute([$currentUserId]);
        $teacherClasses = $tc->fetchAll(PDO::FETCH_COLUMN);
    }
    // Refuse une classe hors périmètre du professeur (no-op pour admin/VS et classe vide).
    $assertClasseInScope = function (string $classe) use ($isTeacher, $isFullAccess, $teacherClasses): void {
        if ($classe === '' || $isFullAccess || !$isTeacher) return;
        if (!in_array($classe, $teacherClasses, true)) {
            http_response_code(403);
            echo json_encode(['error' => 'Accès refusé à cette classe']);
            exit;
        }
    };

    if ($type === 'evolution') {
        if ($isFullAccess) {
            // Accès complet établissement — rien à restreindre.
        } elseif ($isTeacher) {
            // Un professeur ne peut consulter l'évolution que d'un élève de SES classes.
            $st = $pdo->prepare("SELECT classe FROM eleves WHERE id = ? AND etablissement_id = ?");
            $st->execute([$eleveId, $etabId]);
            $eleveClasse = $st->fetchColumn();
            if ($eleveClasse === false || !in_array($eleveClasse, $teacherClasses, true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Accès refusé à cet élève']);
                exit;
            }
        } elseif (isEleve()) {
            // Un élève ne peut consulter que sa propre évolution.
            $eleveId = $currentUserId;
        } elseif (isParent()) {
            $stmt = $pdo->prepare("SELECT 1 FROM parent_eleve WHERE id_parent = ? AND id_eleve = ? LIMIT 1");
            $stmt->execute([$currentUserId, $eleveId]);
            if (!$stmt->fetchColumn()) {
                http_response_code(403);
                echo json_encode(['error' => 'Accès refusé à cet élève']);
                exit;
            }
        } else {
            http_response_code(403);
            echo json_encode(['error' => 'Accès refusé']);
            exit;
        }
    } else {
        // distribution / boxplot / class_stats : données agrégées de classe,
        // réservées au personnel ; un enseignant est borné à ses propres classes.
        if (!$isStaff) {
            http_response_code(403);
            echo json_encode(['error' => 'Accès refusé']);
            exit;
        }
        $assertClasseInScope($classe);
    }

    switch ($type) {
        case 'distribution':
            if (!$classe || !$matiere) {
                http_response_code(400);
                echo json_encode(['error' => 'Classe et matière requises']);
                exit;
            }
            $data = $noteService->getDistribution($classe, $matiere, $trimestre);
            echo json_encode($data);
            break;

        case 'evolution':
            if (!$eleveId) {
                http_response_code(400);
                echo json_encode(['error' => 'ID élève requis']);
                exit;
            }
            $data = $noteService->getEvolutionEleve($eleveId);
            echo json_encode($data);
            break;

        case 'boxplot':
            if (!$classe) {
                http_response_code(400);
                echo json_encode(['error' => 'Classe requise']);
                exit;
            }
            $data = $noteService->getBoxPlotClasse($classe, $trimestre);
            echo json_encode($data);
            break;

        case 'class_stats':
            if (!$classe || !$matiere) {
                http_response_code(400);
                echo json_encode(['error' => 'Classe et matière requises']);
                exit;
            }
            $stats = $noteService->getStatsClasse($classe, $matiere, $trimestre);
            $moyennes = $noteService->getMoyennesParEleve($classe, $matiere, $trimestre);
            echo json_encode(['stats' => $stats, 'moyennes' => $moyennes]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Type de statistique invalide']);
    }
} catch (\Exception $e) {
    error_log("ajax_stats error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur']);
}
