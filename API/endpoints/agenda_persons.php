<?php
/**
 * API centralisée — Agenda : récupérer les personnes par type (AJAX)
 *
 * GET ?visibility=eleves|professeurs|parents|vie_scolaire|administration|classes:NomClasse
 * → JSON { success: true, persons: [{ id, name, info, type }, …] }
 *
 * Déplacé depuis agenda/api/get_persons.php pour centralisation.
 */
require_once __DIR__ . '/../core.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Seuls admin / prof / vie_scolaire peuvent lister
if (!isAdmin() && !isTeacher() && !isVieScolaire()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès non autorisé']);
    exit;
}

// Rate limiting : cet endpoint énumère des personnes (élèves/profs/parents) ;
// on plafonne les rafales par utilisateur+IP. Fail-open si le limiteur est
// indisponible pour ne pas casser l'agenda sur incident infra.
try {
    $rl = app('rate_limiter');
    $rlKey = 'agenda_persons.' . (int) getUserId();
    $rl->setMaxAttempts(60)->setDecayMinutes(1);
    if ($rl->tooManyAttempts($rlKey)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Trop de requêtes. Veuillez patienter.']);
        exit;
    }
    $rl->hit($rlKey);
} catch (\Throwable $e) {
    error_log('agenda_persons rate limiter unavailable: ' . $e->getMessage());
}

$visibility = filter_input(INPUT_GET, 'visibility', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';
$pdo = getPDO();
// Scope établissement obligatoire — fail-closed (pas de fallback sur l'étab. 1).
try {
    $etabId = \API\Core\EstablishmentContext::id();
} catch (\Throwable $e) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Établissement non résolu pour cette session.']);
    exit;
}
$persons = [];

try {
    switch ($visibility) {
        case 'eleves':
            $stmt = $pdo->prepare("SELECT id, nom, prenom, classe FROM eleves WHERE etablissement_id = ? ORDER BY nom, prenom");
            $stmt->execute([$etabId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $persons[] = [
                    'id'   => $row['id'],
                    'name' => htmlspecialchars($row['prenom'] . ' ' . $row['nom']),
                    'info' => htmlspecialchars($row['classe']),
                    'type' => 'eleve',
                ];
            }
            break;

        case 'professeurs':
            $stmt = $pdo->prepare("SELECT id, nom, prenom, matiere FROM professeurs WHERE etablissement_id = ? ORDER BY nom, prenom");
            $stmt->execute([$etabId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $persons[] = [
                    'id'   => $row['id'],
                    'name' => htmlspecialchars($row['prenom'] . ' ' . $row['nom']),
                    'info' => htmlspecialchars($row['matiere'] ?? 'Professeur'),
                    'type' => 'professeur',
                ];
            }
            break;

        case 'parents':
            $stmt = $pdo->prepare(
                "SELECT p.id, p.nom, p.prenom,
                        GROUP_CONCAT(DISTINCT e.prenom SEPARATOR ', ') AS enfants
                 FROM parents p
                 LEFT JOIN parent_eleve pe ON p.id = pe.id_parent
                 LEFT JOIN eleves e ON pe.id_eleve = e.id
                 WHERE p.etablissement_id = ?
                 GROUP BY p.id
                 ORDER BY p.nom, p.prenom"
            );
            $stmt->execute([$etabId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $persons[] = [
                    'id'   => $row['id'],
                    'name' => htmlspecialchars($row['prenom'] . ' ' . $row['nom']),
                    'info' => 'Parent de : ' . htmlspecialchars($row['enfants'] ?: 'Non défini'),
                    'type' => 'parent',
                ];
            }
            break;

        case 'vie_scolaire':
            // Table réelle = `vie_scolaire` (la table `personnels` n'existe pas), scopée par établissement.
            $stmt = $pdo->prepare(
                "SELECT id, nom, prenom, est_CPE, est_infirmerie FROM vie_scolaire
                 WHERE etablissement_id = ? AND actif = 1
                 ORDER BY nom, prenom"
            );
            $stmt->execute([$etabId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $fonction = ($row['est_CPE'] ?? 'non') === 'oui' ? 'CPE'
                          : (($row['est_infirmerie'] ?? 'non') === 'oui' ? 'Infirmerie' : 'Vie scolaire');
                $persons[] = [
                    'id'   => $row['id'],
                    'name' => htmlspecialchars($row['prenom'] . ' ' . $row['nom']),
                    'info' => htmlspecialchars($fonction),
                    'type' => 'personnel',
                ];
            }
            break;

        case 'administration':
            // Table réelle = `administrateurs` (la table `personnels` n'existe pas), scopée par établissement.
            $stmt = $pdo->prepare(
                "SELECT id, nom, prenom, role FROM administrateurs
                 WHERE etablissement_id = ? AND actif = 1
                 ORDER BY nom, prenom"
            );
            $stmt->execute([$etabId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $persons[] = [
                    'id'   => $row['id'],
                    'name' => htmlspecialchars($row['prenom'] . ' ' . $row['nom']),
                    'info' => htmlspecialchars($row['role'] ?? 'Administration'),
                    'type' => 'personnel',
                ];
            }
            break;

        default:
            // Classes spécifiques (format "classes:NomClasse")
            if (strpos($visibility, 'classes:') === 0) {
                $classe = substr($visibility, 8);
                $stmt = $pdo->prepare("SELECT id, nom, prenom FROM eleves WHERE classe = ? AND etablissement_id = ? ORDER BY nom, prenom");
                $stmt->execute([$classe, $etabId]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $persons[] = [
                        'id'   => $row['id'],
                        'name' => htmlspecialchars($row['prenom'] . ' ' . $row['nom']),
                        'info' => htmlspecialchars($classe),
                        'type' => 'eleve',
                    ];
                }
            }
            break;
    }

    echo json_encode(['success' => true, 'persons' => $persons]);

} catch (Exception $e) {
    error_log("API agenda/persons: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la récupération des données']);
}
