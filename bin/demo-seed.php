<?php
/**
 * Fronote — générateur de DONNÉES DE DÉMONSTRATION (100 % fictives).
 *
 * Remplit une instance FRAÎCHE avec un collège complet et VIVANT depuis la rentrée :
 * 16 classes, ~420 élèves, ~30 professeurs, familles, emploi du temps sans conflit, et toute
 * l'activité d'une année jour après jour (appel de chaque cours, notes régulières, cahier de
 * textes, absences/retards/justificatifs, discipline, infirmerie, cantine, CDI, clubs,
 * orientation, stages, compétences, examens, réunions, messagerie, agenda, enquêtes…).
 * Les dates sont calculées par rapport à AUJOURD'HUI : la démo reste « fraîche » à chaque reset
 * et s'étoffe au fil de l'année (bulletins et conseils de classe des trimestres écoulés).
 * Déterministe (graine fixe) : mêmes noms, mêmes classes à chaque génération.
 *
 * Comptes de démonstration (mot de passe commun DEMO_PASSWORD, défaut « demo ») :
 *   demo.direction · demo.prof · demo.eleve · demo.parent · demo.cpe
 *
 * Usage (CLI, instance de démo UNIQUEMENT — refuse de tourner si DEMO_MODE n'est pas activé) :
 *   php bin/demo-seed.php            (DEMO_NOW="2027-03-15 10:00" pour simuler une autre date)
 *
 * Organisation : ce fichier prépare le contexte (aides, calendrier) puis exécute dans l'ordre
 * les parties de bin/demo-seed/ (qui partagent ses variables).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI uniquement.\n"); }
require_once dirname(__DIR__) . '/API/bootstrap.php';

$truthy = static fn($v) => in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
if (!$truthy(getenv('DEMO_MODE'))) {
    fwrite(STDERR, "Refusé : DEMO_MODE n'est pas activé dans .env (ce script écrase des données).\n");
    exit(2);
}
ini_set('memory_limit', '1024M');

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('SET SESSION foreign_key_checks = 0');
mt_srand(20260901);
$t0 = microtime(true);
$log = static function (string $m) use ($t0): void { printf("  · %-62s %5.1f s\n", $m, microtime(true) - $t0); };
$ETAB = 1;
$NOW = new DateTimeImmutable((string) (getenv('DEMO_NOW') ?: 'now'));
$today = $NOW->setTime(0, 0);
$demoPassword = getenv('DEMO_PASSWORD') ?: 'demo';

// ── aides génériques ─────────────────────────────────────────────────────────
$pick = static fn(array $a) => $a[mt_rand(0, count($a) - 1)];
$chance = static fn(float $p): bool => mt_rand() / mt_getrandmax() < $p;
$gauss = static function (float $mu, float $sigma): float {
    $u = max(1e-9, mt_rand() / mt_getrandmax()); $v = mt_rand() / mt_getrandmax();
    return $mu + $sigma * sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
};
$weighted = static function (array $w) {
    $r = mt_rand(1, (int) array_sum($w));
    foreach ($w as $k => $v) { if (($r -= $v) <= 0) { return $k; } }
    return array_key_first($w);
};
$slug = static function (string $s): string {
    $s = strtr($s, ['à'=>'a','â'=>'a','ä'=>'a','ç'=>'c','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ÿ'=>'y','É'=>'e','È'=>'e','Ç'=>'c','í'=>'i','ñ'=>'n','á'=>'a','ó'=>'o',' '=>'-',"'"=>'']);
    return strtolower(preg_replace('/[^a-zA-Z0-9.-]/', '', $s));
};
$fmt = static fn(DateTimeInterface $d, string $f = 'Y-m-d') => $d->format($f);
$moisFr = static fn(DateTimeInterface $d) => ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'][(int) $d->format('n')] . ' ' . $d->format('Y');
$dateFr = static fn(DateTimeInterface $d) => ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'][(int) $d->format('N')] . ' ' . (int) $d->format('j') . ' ' . ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'][(int) $d->format('n')];

/** Colonnes réelles d'une table (cache) — les parties n'écrivent que ce qui existe. */
$colsCache = [];
$cols = function (string $table) use ($pdo, &$colsCache): array {
    if (!isset($colsCache[$table])) {
        try {
            $colsCache[$table] = array_flip($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Throwable $e) { $colsCache[$table] = []; }
    }
    return $colsCache[$table];
};
$hasTable = fn(string $t): bool => $cols($t) !== [];
$warned = [];
$filter = function (string $table, array $row) use ($cols, &$warned): array {
    $c = $cols($table);
    foreach (array_diff_key($row, $c) as $k => $_) {
        if (!isset($warned["$table.$k"])) { $warned["$table.$k"] = true; fwrite(STDERR, "    (colonne ignorée : $table.$k)\n"); }
    }
    return array_intersect_key($row, $c);
};
/** Insère une ligne, retourne son id. */
$ins = function (string $table, array $row) use ($pdo, $filter): int {
    $row = $filter($table, $row);
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($row));
    return (int) $pdo->lastInsertId();
};
/** Insertion massive (lignes de même forme), par paquets. Retourne le nombre de lignes. */
$bulk = function (string $table, array $rows, int $chunk = 400) use ($pdo, $filter): int {
    if (!$rows) { return 0; }
    $keys = array_keys($filter($table, $rows[array_key_first($rows)]));
    if (!$keys) { return 0; }
    $n = 0;
    foreach (array_chunk($rows, $chunk) as $part) {
        $place = '(' . implode(',', array_fill(0, count($keys), '?')) . ')';
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $keys) . '`) VALUES ' . implode(',', array_fill(0, count($part), $place));
        $vals = [];
        foreach ($part as $r) { foreach ($keys as $k) { $vals[] = $r[$k] ?? null; } }
        $pdo->prepare($sql)->execute($vals);
        $n += count($part);
    }
    return $n;
};
$wipe = function (string ...$tables) use ($pdo, $hasTable): void {
    foreach ($tables as $t) { if ($hasTable($t)) { $pdo->exec("DELETE FROM `$t`"); } }
};
/** Une section en échec ne doit pas faire tomber toute la génération (journalisée, on continue). */
$fail = function (string $label, \Throwable $e) use ($log, $pdo): void {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $log("⚠ $label : " . substr($e->getMessage(), 0, 120));
    fwrite(STDERR, "    $label : " . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n");
};

// ── calendrier scolaire ──────────────────────────────────────────────────────
$y = (int) $today->format('Y');
$startYear = ((int) $today->format('n') >= 8) ? $y : $y - 1;
$annee = $startYear . '-' . ($startYear + 1);
$anneePrec = ($startYear - 1) . '-' . $startYear;
$rentree = new DateTimeImmutable("$startYear-09-01");
while ((int) $rentree->format('N') >= 6) { $rentree = $rentree->modify('+1 day'); }
$finAnnee = new DateTimeImmutable(($startYear + 1) . '-07-04');
$periodes = [
    [1, '1er trimestre', "$startYear-09-01", "$startYear-11-30"],
    [2, '2e trimestre', "$startYear-12-01", ($startYear + 1) . '-02-28'],
    [3, '3e trimestre', ($startYear + 1) . '-03-01', ($startYear + 1) . '-07-04'],
];
$trimestreDe = static function (string $ymd) use ($periodes): int {
    foreach ($periodes as [$n, , $deb, $fin]) { if ($ymd >= $deb && $ymd <= $fin) { return $n; } }
    return $ymd < $periodes[0][2] ? 1 : 3;
};
$trimestreCourant = $trimestreDe($today->format('Y-m-d'));
// Jours de classe (lun-ven) depuis la rentrée jusqu'à aujourd'hui inclus — la démo n'a pas de vacances :
// chaque jour ouvré est un jour de cours, pour qu'on puisse toujours essayer l'application « en classe ».
$joursClasse = [];
for ($d = $rentree; $d <= $today; $d = $d->modify('+1 day')) {
    if ((int) $d->format('N') <= 5) { $joursClasse[] = $d; }
}
if (!$joursClasse) { $joursClasse[] = $rentree; }
$jourNom = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi'];
$schoolDay = static function (DateTimeImmutable $d): DateTimeImmutable {
    while ((int) $d->format('N') >= 6) { $d = $d->modify('-1 day'); }
    return $d;
};
$nextSchoolDay = static function (DateTimeImmutable $d): DateTimeImmutable {
    while ((int) $d->format('N') >= 6) { $d = $d->modify('+1 day'); }
    return $d;
};
$jourPasse = static fn(DateTimeImmutable $d) => $d < $today; // strictement avant aujourd'hui
$heurePassee = static fn(DateTimeImmutable $d, string $hm) => $d < $today || ($d == $today && $NOW->format('H:i') >= substr($hm, 0, 5));
$nbSemaines = max(1, (int) ceil(count($joursClasse) / 5));

echo "Fronote — génération de la démo ($annee, trimestre $trimestreCourant, " . count($joursClasse) . " jours de classe)\n";

// ── parties ──────────────────────────────────────────────────────────────────
foreach (glob(__DIR__ . '/demo-seed/[0-9]*.php') as $__part) {
    require $__part;
}

// ── finitions ────────────────────────────────────────────────────────────────
// Comptes démo : jamais de changement de mot de passe forcé, jamais de verrouillage.
$pdo->exec("UPDATE accounts SET must_change_password=0, failed_login_attempts=0, locked_until=NULL WHERE username LIKE 'demo.%'");
foreach (['administrateurs', 'professeurs', 'eleves', 'parents', 'vie_scolaire'] as $__t) {
    try { $pdo->exec("UPDATE `$__t` SET password_changed_at = NOW() WHERE identifiant LIKE 'demo.%'"); } catch (\Throwable $e) {}
}
// Le compte d'installation « admin » n'a pas sa place dans une démo publique.
$lockedHash = \API\Security\PasswordPolicy::hash(bin2hex(random_bytes(16)));
$pdo->prepare("UPDATE administrateurs SET actif=0, mot_de_passe=? WHERE identifiant='admin'")->execute([$lockedHash]);
$pdo->prepare("UPDATE accounts SET status='inactive', password_hash=? WHERE username='admin'")->execute([$lockedHash]);
$pdo->exec('SET SESSION foreign_key_checks = 1');
try { app('cache')->flush(); } catch (\Throwable $e) {}

printf("Démo générée en %.1f s.\n", microtime(true) - $t0);
