<?php
declare(strict_types=1);
/**
 * Recherche d'ÉLÈVES — point unique utilisé par tous les sélecteurs d'élève de l'application
 * (composant assets/js/eleve-picker.js).
 *
 * GET ?q=texte[&classe=4A][&limit=20]
 *   q : un ou plusieurs mots ; chaque mot doit apparaître dans le prénom, le nom, la classe ou
 *       l'identifiant (insensible aux accents et à la casse : « lea mar » trouve « Léa Martin »).
 * → { success: true, eleves: [{ id, nom, prenom, classe, label }] }
 *
 * Périmètre : direction / vie scolaire = tout l'établissement ; professeur = élèves de SES
 * classes (même règle que assertUserCanReadEleve) ; autres rôles : refusé.
 */
require_once __DIR__ . '/../core.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

$role = (string) getUserRole();
if (!in_array($role, ['administrateur', 'vie_scolaire', 'professeur', 'super_admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès non autorisé']);
    exit;
}

try {
    $rl = app('rate_limiter');
    $rlKey = 'eleves_search.' . $role . '.' . (int) getUserId();
    $rl->setMaxAttempts(120)->setDecayMinutes(1);
    if ($rl->tooManyAttempts($rlKey)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Trop de requêtes. Veuillez patienter.']);
        exit;
    }
    $rl->hit($rlKey);
} catch (\Throwable $e) {
    error_log('eleves_search rate limiter: ' . $e->getMessage());
}

try {
    $etab = \API\Core\EstablishmentContext::id();
} catch (\Throwable $e) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Établissement non résolu.']);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$classe = trim((string) ($_GET['classe'] ?? ''));
$limit = max(1, min(50, (int) ($_GET['limit'] ?? 20)));
$tokens = array_values(array_filter(preg_split('/\s+/u', mb_substr($q, 0, 80)) ?: [], static fn($t) => $t !== ''));
if ($tokens === [] && $classe === '') {
    echo json_encode(['success' => true, 'eleves' => []]);
    exit;
}

$pdo = getPDO();
$where = ['e.etablissement_id = ?', 'e.actif = 1'];
$args = [$etab];
foreach (array_slice($tokens, 0, 5) as $t) {
    // Début de mot (prénom, nom, nom composé, classe, identifiant) : « ma » trouve Martin, pas Emma.
    $p = addcslashes($t, '%_\\');
    $where[] = "(CONCAT(' ', e.prenom, ' ', e.nom, ' ', IFNULL(e.classe, ''), ' ', IFNULL(e.identifiant, '')) LIKE ? OR CONCAT(' ', REPLACE(e.prenom, '-', ' '), ' ', REPLACE(e.nom, '-', ' ')) LIKE ?)";
    array_push($args, '% ' . $p . '%', '% ' . $p . '%');
}
if ($classe !== '') { $where[] = 'e.classe = ?'; $args[] = $classe; }

if ($role === 'professeur') {
    // Classes enseignées : professeur_classes ∪ relations actives (ScopeResolver)
    $classes = [];
    try {
        $st = $pdo->prepare('SELECT nom_classe FROM professeur_classes WHERE id_professeur = ?');
        $st->execute([(int) getUserId()]);
        $classes = $st->fetchAll(PDO::FETCH_COLUMN);
        $resolver = new \API\Security\ScopeResolver($pdo, ['id' => (int) getUserId(), 'type' => 'professeur', 'etablissement_id' => $etab]);
        $ids = $resolver->taughtClassIds();
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT nom FROM classes WHERE id IN ($in)");
            $st->execute(array_map('intval', $ids));
            $classes = array_merge($classes, $st->fetchAll(PDO::FETCH_COLUMN));
        }
    } catch (\Throwable $e) { error_log('eleves_search scope: ' . $e->getMessage()); }
    $classes = array_values(array_unique(array_filter(array_map('strval', $classes))));
    if ($classes === []) { echo json_encode(['success' => true, 'eleves' => []]); exit; }
    $where[] = 'e.classe IN (' . implode(',', array_fill(0, count($classes), '?')) . ')';
    array_push($args, ...$classes);
}

// Les débuts de nom/prénom passent devant (« mar » → Martin avant Lemarchand)
$first = $tokens[0] ?? '';
$order = 'e.nom, e.prenom';
if ($first !== '') {
    $order = '(e.nom LIKE ? OR e.prenom LIKE ?) DESC, ' . $order;
    $p = addcslashes($first, '%_\\') . '%';
    array_push($args, $p, $p);
}
$sql = 'SELECT e.id, e.nom, e.prenom, e.classe FROM eleves e WHERE ' . implode(' AND ', $where) . " ORDER BY $order LIMIT $limit";
$st = $pdo->prepare($sql);
$st->execute($args);
$out = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $out[] = ['id' => (int) $r['id'], 'nom' => $r['nom'], 'prenom' => $r['prenom'], 'classe' => $r['classe'],
        'label' => trim($r['prenom'] . ' ' . $r['nom']) . ($r['classe'] ? ' (' . $r['classe'] . ')' : '')];
}
echo json_encode(['success' => true, 'eleves' => $out], JSON_UNESCAPED_UNICODE);
