<?php
/**
 * Export iCal (.ics) — Emploi du temps.
 * Generates a .ics file for calendar sync.
 * GET params: classe_id, prof_id, semaine (Y-W format)
 */
require_once __DIR__ . '/../../API/core.php';
require_once __DIR__ . '/includes/EdtService.php';

requireAuth();

$pdo = getPDO();
$edtService = new EdtService($pdo);
$user = getCurrentUser();
$role = getUserRole();

// Determine filters based on role
$classeId = (int) ($_GET['classe_id'] ?? 0);
$profId   = (int) ($_GET['prof_id'] ?? 0);
// Vrai pour élève/parent : l'export DOIT rester scopé à leur propre classe.
// Si cette classe ne se résout pas, on renvoie un calendrier vide plutôt
// qu'une requête non filtrée (anti-fuite) — et SANS 403 (anti-lockout).
$restrictToOwnScope = false;

// Résout l'id de la classe d'un élève (eleves.classe = varchar -> classes.nom).
$resolveClasseIdForEleve = static function (int $eleveId) use ($pdo): int {
    $stmt = $pdo->prepare("SELECT classe FROM eleves WHERE id = ?");
    $stmt->execute([$eleveId]);
    $nom = $stmt->fetchColumn();
    if (!$nom) return 0;
    $stmt = $pdo->prepare("SELECT id FROM classes WHERE nom = ? AND actif = 1 LIMIT 1");
    $stmt->execute([$nom]);
    return (int) $stmt->fetchColumn();
};

if ($role === 'professeur') {
    // Un professeur n'exporte que son propre emploi du temps : on ignore tout
    // classe_id/prof_id fourni en paramètre (anti-IDOR).
    $profId   = (int) $user['id'];
    $classeId = 0;
} elseif ($role === 'eleve') {
    // Un élève n'exporte que l'EDT de SA classe : tout paramètre est ignoré.
    // ANTI-LOCKOUT : si la classe ne se résout pas (nom non apparié / classe
    // archivée), on n'interdit PAS l'accès à SES propres données — on laisse
    // $classeId=0 produire un calendrier vide (même comportement que
    // EdtService::getEdtEleve qui retourne []).
    $profId   = 0;
    $restrictToOwnScope = true;
    $classeId = $resolveClasseIdForEleve((int) $user['id']);
} elseif ($role === 'parent') {
    // Un parent n'exporte que l'EDT d'un de SES enfants (vérif parent_eleve).
    $profId = 0;
    $restrictToOwnScope = true;
    $requested = (int) ($_GET['eleve_id'] ?? 0);
    if ($requested > 0) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM parent_eleve WHERE id_parent = ? AND id_eleve = ?");
        $chk->execute([(int) $user['id'], $requested]);
        if (!$chk->fetchColumn()) { http_response_code(403); exit('Accès refusé'); }
        $childId = $requested;
    } else {
        $stmt = $pdo->prepare("SELECT id_eleve FROM parent_eleve WHERE id_parent = ? LIMIT 1");
        $stmt->execute([(int) $user['id']]);
        $childId = (int) $stmt->fetchColumn();
    }
    if (!$childId) { http_response_code(403); exit('Accès refusé'); }
    // ANTI-LOCKOUT : la classe non résolue ne bloque pas l'accès au calendrier
    // de l'enfant légitime ; $classeId=0 -> calendrier vide (cf. garde plus bas).
    $classeId = $resolveClasseIdForEleve($childId);
} elseif (!isAdmin() && !isVieScolaire()) {
    // Tout autre rôle non-staff : pas d'accès au filtrage libre.
    http_response_code(403);
    exit('Accès refusé');
}
// Admin / vie scolaire : conservent le filtrage libre par classe_id / prof_id.

// Fetch cours for the current week (or specified week)
$semaineParam = $_GET['semaine'] ?? date('Y-W');
$parts = explode('-', str_replace('W', '', $semaineParam));
$year = (int) ($parts[0] ?? date('Y'));
$week = (int) ($parts[1] ?? date('W'));

// Get Monday of the week
$dto = new DateTime();
$dto->setISODate($year, $week, 1);
$mondayDate = $dto->format('Y-m-d');
$dto->modify('+6 days');
$sundayDate = $dto->format('Y-m-d');

// Build query
$sql = "
    SELECT edt.*, c.nom AS classe_nom, m.nom AS matiere_nom,
           CONCAT(p.prenom, ' ', p.nom) AS prof_nom, s.nom AS salle_nom,
           ch.heure_debut, ch.heure_fin
    FROM emploi_du_temps edt
    LEFT JOIN classes c ON edt.classe_id = c.id
    LEFT JOIN matieres m ON edt.matiere_id = m.id
    LEFT JOIN professeurs p ON edt.professeur_id = p.id
    LEFT JOIN salles s ON edt.salle_id = s.id
    LEFT JOIN creneaux_horaires ch ON edt.creneau_id = ch.id
    WHERE edt.jour BETWEEN ? AND ?
";
$params = [$mondayDate, $sundayDate];

if ($classeId) {
    $sql .= " AND edt.classe_id = ?";
    $params[] = $classeId;
}
if ($profId) {
    $sql .= " AND edt.professeur_id = ?";
    $params[] = $profId;
}
$sql .= " ORDER BY edt.jour, ch.heure_debut";

if ($restrictToOwnScope && !$classeId) {
    // Élève/parent dont la classe n'a pas pu être résolue : on NE lance PAS la
    // requête (sinon elle serait non filtrée -> fuite de tout l'établissement).
    // Calendrier vide = pas de lockout, pas de fuite.
    $cours = [];
} else {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $cours = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Generate iCal
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="emploi_du_temps_S' . $week . '.ics"');

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//FRONOTE//EDT//FR\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";
echo "X-WR-CALNAME:Emploi du temps FRONOTE\r\n";

foreach ($cours as $c) {
    $jour = $c['jour'];
    $heureDebut = $c['heure_debut'] ?? '08:00:00';
    $heureFin   = $c['heure_fin'] ?? '09:00:00';

    $dtStart = date('Ymd', strtotime($jour)) . 'T' . str_replace(':', '', substr($heureDebut, 0, 5)) . '00';
    $dtEnd   = date('Ymd', strtotime($jour)) . 'T' . str_replace(':', '', substr($heureFin, 0, 5)) . '00';

    $summary = ($c['matiere_nom'] ?? 'Cours');
    $location = ($c['salle_nom'] ?? '');
    $description = '';
    if (!empty($c['prof_nom'])) $description .= 'Prof: ' . $c['prof_nom'];
    if (!empty($c['classe_nom'])) $description .= ($description ? ' - ' : '') . 'Classe: ' . $c['classe_nom'];

    echo "BEGIN:VEVENT\r\n";
    echo "UID:" . md5($c['id'] . $jour . $heureDebut) . "@fronote\r\n";
    echo "DTSTART:" . $dtStart . "\r\n";
    echo "DTEND:" . $dtEnd . "\r\n";
    echo "SUMMARY:" . icalEscape($summary) . "\r\n";
    if ($location) echo "LOCATION:" . icalEscape($location) . "\r\n";
    if ($description) echo "DESCRIPTION:" . icalEscape($description) . "\r\n";
    echo "END:VEVENT\r\n";
}

echo "END:VCALENDAR\r\n";

function icalEscape(string $str): string {
    return str_replace(["\n", "\r", ";", ","], ["\\n", "", "\\;", "\\,"], $str);
}
