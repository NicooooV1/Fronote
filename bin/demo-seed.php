<?php
/**
 * Fronote — générateur de DONNÉES DE DÉMONSTRATION (100 % fictives).
 *
 * Remplit une instance FRAÎCHE avec un collège complet et vivant : 8 classes, ~190 élèves,
 * 18 professeurs, parents, emploi du temps sans conflit, notes du trimestre, absences,
 * retards, cahier de textes, messagerie, annonces, agenda, menus, incidents.
 * Les dates sont calculées par rapport à AUJOURD'HUI : la démo reste « fraîche » à chaque reset.
 * Déterministe (graine fixe) : mêmes noms, mêmes classes à chaque génération.
 *
 * Comptes de démonstration (mot de passe commun DEMO_PASSWORD, défaut « demo ») :
 *   demo.direction · demo.prof · demo.eleve · demo.parent · demo.cpe
 *
 * Usage (CLI, instance de démo UNIQUEMENT — refuse de tourner si DEMO_MODE n'est pas activé) :
 *   php bin/demo-seed.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI uniquement.\n"); }
require_once dirname(__DIR__) . '/API/bootstrap.php';

$truthy = static fn($v) => in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
if (!$truthy(getenv('DEMO_MODE'))) {
    fwrite(STDERR, "Refusé : DEMO_MODE n'est pas activé dans .env (ce script écrase des données).\n");
    exit(2);
}

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
mt_srand(20260901);
$t0 = microtime(true);
$log = static fn(string $m) => print('  · ' . $m . "\n");
$ETAB = 1;
$today = new DateTimeImmutable('today');
$demoPassword = getenv('DEMO_PASSWORD') ?: 'demo';

// ── helpers ──────────────────────────────────────────────────────────────────
$pick = static fn(array $a) => $a[mt_rand(0, count($a) - 1)];
$ins = static function (string $table, array $row) use ($pdo): int {
    $cols = array_keys($row);
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($row));
    return (int) $pdo->lastInsertId();
};
$gauss = static function (float $mu, float $sigma): float {
    $u = max(1e-9, mt_rand() / mt_getrandmax()); $v = mt_rand() / mt_getrandmax();
    return $mu + $sigma * sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
};
$slug = static function (string $s): string {
    $s = strtr($s, ['à'=>'a','â'=>'a','ä'=>'a','ç'=>'c','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ÿ'=>'y','É'=>'e','È'=>'e','Ç'=>'c',' '=>'-',"'"=>'']);
    return strtolower(preg_replace('/[^a-zA-Z0-9.-]/', '', $s));
};
// Jour ouvré (lun-ven) le plus proche en reculant.
$schoolDay = static function (DateTimeImmutable $d): DateTimeImmutable {
    while ((int) $d->format('N') >= 6) { $d = $d->modify('-1 day'); }
    return $d;
};

// ── année scolaire courante ──────────────────────────────────────────────────
$y = (int) $today->format('Y');
$startYear = ((int) $today->format('n') >= 8) ? $y : $y - 1;
$annee = $startYear . '-' . ($startYear + 1);
$rentree = $schoolDay(new DateTimeImmutable("$startYear-09-01"));
if ((int) $rentree->format('N') !== 1) { $rentree = new DateTimeImmutable("$startYear-09-01"); }
$periodes = [
    [1, '1er trimestre', "$startYear-09-01", "$startYear-11-30"],
    [2, '2e trimestre', "$startYear-12-01", ($startYear + 1) . '-02-28'],
    [3, '3e trimestre', ($startYear + 1) . '-03-01', ($startYear + 1) . '-07-04'],
];
$trimestreCourant = 1;
foreach ($periodes as [$n, , $deb, $fin]) { if ($today->format('Y-m-d') >= $deb && $today->format('Y-m-d') <= $fin) { $trimestreCourant = $n; } }
if ($today->format('Y-m-d') > $periodes[2][3]) { $trimestreCourant = 3; }
// Les notes couvrent du 1er jour du trimestre courant jusqu'à aujourd'hui (au moins 4 semaines).
$notesDebut = new DateTimeImmutable($periodes[$trimestreCourant - 1][2]);
if ($today->diff($notesDebut)->days < 28) { $notesDebut = $today->modify('-28 days'); }

echo "Fronote — génération de la démo ($annee, trimestre $trimestreCourant)\n";

// ── établissement ────────────────────────────────────────────────────────────
$pdo->prepare("UPDATE etablissements SET nom=?, slogan=?, code=?, type='college', adresse=?, code_postal=?, ville=?,
    telephone=?, email=?, chef_etablissement=?, academie=?, code_uai=?, annee_scolaire=?, status='active', actif=1, slug=?
    WHERE id=?")->execute([
    'Collège Victor-Hugo', 'Apprendre, grandir, réussir ensemble', 'college-victor-hugo', '12 avenue des Tilleuls', '00000',
    'Val-Fleuri', '01 00 00 00 00', 'contact@victor-hugo.demo', 'Claire Delmas', 'Académie de démonstration',
    '0000000X', $annee, 'college-victor-hugo', $ETAB,
]);
try {
    $pdo->prepare("INSERT INTO annees_scolaires (etablissement_id, code, libelle, date_debut, date_fin, actif) VALUES (?,?,?,?,?,1)")
        ->execute([$ETAB, $annee, "Année $annee", "$startYear-09-01", ($startYear + 1) . '-07-04']);
} catch (\Throwable $e) {}
$pdo->exec('DELETE FROM periodes');
foreach ($periodes as [$n, $nom, $deb, $fin]) {
    $ins('periodes', ['etablissement_id' => $ETAB, 'numero' => $n, 'nom' => $nom, 'type' => 'trimestre', 'date_debut' => $deb, 'date_fin' => $fin, 'annee_scolaire' => $annee]);
}
$periodeIds = $pdo->query('SELECT numero, id FROM periodes')->fetchAll(PDO::FETCH_KEY_PAIR);

// ── référentiels : créneaux, salles, matières ────────────────────────────────
$pdo->exec('DELETE FROM creneaux_horaires');
$creneauxDef = [
    ['M1', '08:00', '08:55', 'cours'], ['M2', '08:55', '09:50', 'cours'], ['Récré', '09:50', '10:05', 'pause'],
    ['M3', '10:05', '11:00', 'cours'], ['M4', '11:00', '11:55', 'cours'], ['Déjeuner', '11:55', '13:30', 'repas'],
    ['S1', '13:30', '14:25', 'cours'], ['S2', '14:25', '15:20', 'cours'], ['Récré', '15:20', '15:35', 'pause'],
    ['S3', '15:35', '16:30', 'cours'], ['S4', '16:30', '17:25', 'cours'],
];
$creneaux = []; // ordre => [id, debut, fin] (cours uniquement)
foreach ($creneauxDef as $i => [$label, $deb, $fin, $type]) {
    $id = $ins('creneaux_horaires', ['etablissement_id' => $ETAB, 'label' => $label, 'heure_debut' => $deb, 'heure_fin' => $fin, 'ordre' => $i + 1, 'type' => $type]);
    if ($type === 'cours') { $creneaux[] = [$id, $deb, $fin, $label]; }
}

$pdo->exec('DELETE FROM salles');
$salles = [];
foreach (['101','102','103','104','105','106','201','202','203','204','205','206'] as $n) {
    $salles['std'][] = $ins('salles', ['etablissement_id' => $ETAB, 'nom' => "Salle $n", 'batiment' => $n[0] === '1' ? 'Bâtiment A' : 'Bâtiment B', 'capacite' => 30, 'type' => 'cours', 'equipements' => 'Vidéoprojecteur, tableau blanc', 'actif' => 1]);
}
$salles['SVT'][] = $ins('salles', ['etablissement_id' => $ETAB, 'nom' => 'Labo SVT', 'batiment' => 'Bâtiment C', 'capacite' => 26, 'type' => 'laboratoire', 'equipements' => 'Paillasses, microscopes', 'actif' => 1]);
$salles['PC'][]  = $ins('salles', ['etablissement_id' => $ETAB, 'nom' => 'Labo Physique-Chimie', 'batiment' => 'Bâtiment C', 'capacite' => 26, 'type' => 'laboratoire', 'equipements' => 'Paillasses, hotte', 'actif' => 1]);
$salles['TEC'][] = $ins('salles', ['etablissement_id' => $ETAB, 'nom' => 'Salle Techno', 'batiment' => 'Bâtiment C', 'capacite' => 24, 'type' => 'informatique', 'equipements' => '24 postes, imprimante 3D', 'actif' => 1]);
$salles['EPS'][] = $ins('salles', ['etablissement_id' => $ETAB, 'nom' => 'Gymnase', 'batiment' => 'Complexe sportif', 'capacite' => 60, 'type' => 'sport', 'equipements' => 'Terrain multisport', 'actif' => 1]);
$salles['ART'][] = $ins('salles', ['etablissement_id' => $ETAB, 'nom' => 'Salle d\'arts', 'batiment' => 'Bâtiment B', 'capacite' => 28, 'type' => 'arts', 'equipements' => 'Éviers, chevalets', 'actif' => 1]);
$salles['MUS'][] = $ins('salles', ['etablissement_id' => $ETAB, 'nom' => 'Salle de musique', 'batiment' => 'Bâtiment B', 'capacite' => 28, 'type' => 'musique', 'equipements' => 'Piano, sono', 'actif' => 1]);
$ins('salles', ['etablissement_id' => $ETAB, 'nom' => 'CDI', 'batiment' => 'Bâtiment A', 'capacite' => 40, 'type' => 'cdi', 'equipements' => '3 000 ouvrages, 8 postes', 'actif' => 1]);

$pdo->exec('DELETE FROM matieres');
// code => [nom, coef, couleur, heures/semaine par niveau [6e,5e,4e,3e]]
$matDef = [
    'FRA' => ['Français', 1.0, '#2f5d50', [5, 4, 4, 4]],
    'MAT' => ['Mathématiques', 1.0, '#16243f', [4, 4, 4, 4]],
    'HG'  => ['Histoire-Géographie', 1.0, '#b9772a', [3, 3, 3, 3]],
    'ANG' => ['Anglais', 1.0, '#7a3e65', [4, 3, 3, 3]],
    'ESP' => ['Espagnol', 1.0, '#c0573e', [0, 2, 2, 2]],
    'SVT' => ['SVT', 1.0, '#4f8a3a', [2, 2, 2, 2]],
    'PC'  => ['Physique-Chimie', 1.0, '#3b6ea8', [0, 2, 2, 2]],
    'TEC' => ['Technologie', 1.0, '#5b6472', [2, 2, 2, 2]],
    'ART' => ['Arts plastiques', 1.0, '#a35d8f', [1, 1, 1, 1]],
    'MUS' => ['Éducation musicale', 1.0, '#8c6d1f', [1, 1, 1, 1]],
    'EPS' => ['EPS', 1.0, '#2a8c82', [4, 3, 3, 3]],
];
$mat = []; // code => id
foreach ($matDef as $code => [$nom, $coef, $coul]) {
    $mat[$code] = $ins('matieres', ['etablissement_id' => $ETAB, 'nom' => $nom, 'code' => $code, 'coefficient' => $coef, 'couleur' => $coul, 'actif' => 1]);
}

// ── personnes ────────────────────────────────────────────────────────────────
$prenomsF = ['Léa','Emma','Chloé','Inès','Jade','Louise','Manon','Camille','Zoé','Lina','Alice','Sarah','Juliette','Lola','Anna','Nina','Rose','Mila','Ambre','Margaux','Clara','Eva','Agathe','Yasmine','Lucie','Maëlys','Romane','Salomé','Héloïse','Capucine'];
$prenomsM = ['Hugo','Lucas','Gabriel','Louis','Nathan','Arthur','Jules','Adam','Raphaël','Léo','Noah','Ethan','Tom','Sacha','Paul','Maxime','Théo','Mathis','Enzo','Nolan','Rayan','Timéo','Victor','Antoine','Baptiste','Samuel','Yanis','Clément','Malo','Axel'];
$noms = ['Martin','Bernard','Dubois','Thomas','Robert','Richard','Petit','Durand','Leroy','Moreau','Simon','Laurent','Lefebvre','Michel','Garcia','David','Bertrand','Roux','Vincent','Fournier','Morel','Girard','André','Mercier','Dupont','Lambert','Bonnet','François','Martinez','Legrand','Garnier','Faure','Rousseau','Blanc','Guérin','Muller','Henry','Roussel','Nicolas','Perrin','Morin','Mathieu','Clément','Gauthier','Dumont','Lopez','Fontaine','Chevalier','Robin','Masson','Sanchez','Gérard','Nguyen','Boyer','Denis','Lemaire','Duval','Joly','Gautier','Roger','Roche','Roy','Noël','Meyer','Lucas','Meunier','Jean','Perez','Marchand','Dufour','Blanchard','Marie','Barbier','Brun','Dumas','Brunet','Schmitt','Leroux','Colin','Fernandez','Pierre','Renard','Arnaud','Rolland','Caron','Aubert','Giraud','Leclerc','Vidal','Bourgeois','Renaud','Lemoine','Picard','Gaillard','Philippe','Leclercq','Lacroix','Fabre','Dupuis','Olivier'];
$metiers = ['Infirmière','Ingénieur','Commerçant','Professeure des écoles','Comptable','Agricultrice','Artisan','Médecin','Employée de banque','Technicien','Architecte','Chauffeur','Assistante sociale','Cuisinier','Pharmacienne','Électricien','Juriste','Aide-soignante','Développeur','Fonctionnaire'];

$hash = \API\Security\PasswordPolicy::hash($demoPassword);
// Les comptes « de masse » reçoivent un hash aléatoire inutilisable : on ne se connecte qu'avec les 5 comptes démo.
$lockedHash = \API\Security\PasswordPolicy::hash(bin2hex(random_bytes(16)));
$usedIds = [];
$ident = static function (string $prenom, string $nom) use (&$usedIds, $slug): string {
    $base = $slug($prenom) . '.' . $slug($nom); $id = $base; $i = 1;
    while (isset($usedIds[$id])) { $id = $base . sprintf('%02d', ++$i); }
    $usedIds[$id] = true; return $id;
};
$phone = static fn() => '06 ' . sprintf('%02d %02d %02d %02d', mt_rand(0, 99), mt_rand(0, 99), mt_rand(0, 99), mt_rand(0, 99));

// Direction + administration
$adminId = $ins('administrateurs', ['etablissement_id' => $ETAB, 'nom' => 'Delmas', 'prenom' => 'Claire', 'mail' => 'direction@victor-hugo.demo',
    'identifiant' => 'demo.direction', 'mot_de_passe' => $hash, 'adresse' => '', 'role' => 'administrateur', 'actif' => 1]);
$usedIds['demo.direction'] = true; $usedIds['admin'] = true;
$ins('administrateurs', ['etablissement_id' => $ETAB, 'nom' => 'Benali', 'prenom' => 'Karim', 'mail' => 'adjoint@victor-hugo.demo',
    'identifiant' => $ident('Karim', 'Benali'), 'mot_de_passe' => $lockedHash, 'adresse' => '', 'role' => 'administrateur', 'actif' => 1]);

// Vie scolaire
$cpeId = $ins('vie_scolaire', ['etablissement_id' => $ETAB, 'nom' => 'Rivière', 'prenom' => 'Sophie', 'mail' => 'cpe@victor-hugo.demo', 'telephone' => '01 00 00 00 10',
    'identifiant' => 'demo.cpe', 'mot_de_passe' => $hash, 'est_CPE' => 'oui', 'est_infirmerie' => 'non', 'actif' => 1]);
$usedIds['demo.cpe'] = true;
$aedIds = [];
foreach ([['Yanis', 'Haddad'], ['Pauline', 'Marchetti']] as [$p, $n]) {
    $aedIds[] = $ins('vie_scolaire', ['etablissement_id' => $ETAB, 'nom' => $n, 'prenom' => $p, 'mail' => $slug("$p.$n") . '@victor-hugo.demo',
        'identifiant' => $ident($p, $n), 'mot_de_passe' => $lockedHash, 'est_CPE' => 'non', 'est_infirmerie' => 'non', 'actif' => 1]);
}
$infId = $ins('vie_scolaire', ['etablissement_id' => $ETAB, 'nom' => 'Lemoine', 'prenom' => 'Agnès', 'mail' => 'infirmerie@victor-hugo.demo',
    'identifiant' => $ident('Agnès', 'Lemoine'), 'mot_de_passe' => $lockedHash, 'est_CPE' => 'non', 'est_infirmerie' => 'oui', 'actif' => 1]);

// Classes
$classesNoms = ['6A' => '6e', '6B' => '6e', '5A' => '5e', '5B' => '5e', '4A' => '4e', '4B' => '4e', '3A' => '3e', '3B' => '3e'];
$niveauIdx = ['6e' => 0, '5e' => 1, '4e' => 2, '3e' => 3];
$classes = []; // nom => id
foreach ($classesNoms as $nom => $niv) {
    $classes[$nom] = $ins('classes', ['etablissement_id' => $ETAB, 'nom' => $nom, 'niveau' => $niv, 'annee_scolaire' => $annee, 'actif' => 1]);
}

// Professeurs : [prenom, nom, matiere, classes]
$profDef = [
    ['Marc', 'Lefort', 'MAT', ['4A', '4B', '3A', '3B']],          // = demo.prof
    ['Isabelle', 'Carré', 'MAT', ['6A', '6B', '5A', '5B']],
    ['Nathalie', 'Fabre', 'FRA', ['6A', '6B', '4A']],
    ['Julien', 'Aubry', 'FRA', ['5A', '5B', '4B']],
    ['Hélène', 'Morvan', 'FRA', ['3A', '3B']],
    ['Olivier', 'Besson', 'HG', ['6A', '6B', '5A', '5B']],
    ['Céline', 'Arnaud', 'HG', ['4A', '4B', '3A', '3B']],
    ['Sarah', 'Whitfield', 'ANG', ['6A', '6B', '5A', '5B']],
    ['Thomas', 'Keller', 'ANG', ['4A', '4B', '3A', '3B']],
    ['Lucía', 'Herrera', 'ESP', ['5A', '5B', '4A', '4B', '3A', '3B']],
    ['Damien', 'Roux', 'SVT', ['6A', '6B', '5A', '5B', '4A', '4B', '3A', '3B']],
    ['Mélanie', 'Picard', 'PC', ['5A', '5B', '4A', '4B', '3A', '3B']],
    ['Franck', 'Lambert', 'TEC', ['6A', '6B', '5A', '5B', '4A', '4B', '3A', '3B']],
    ['Élodie', 'Vasseur', 'ART', ['6A', '6B', '5A', '5B', '4A', '4B', '3A', '3B']],
    ['Antoine', 'Dorval', 'MUS', ['6A', '6B', '5A', '5B', '4A', '4B', '3A', '3B']],
    ['Karine', 'Moulin', 'EPS', ['6A', '6B', '5A', '5B']],
    ['Stéphane', 'Guillot', 'EPS', ['4A', '4B', '3A', '3B']],
    ['Aurélie', 'Tessier', 'FRA', []], // documentaliste / remplaçante
];
$pp = ['6A' => 2, '6B' => 5, '5A' => 3, '5B' => 7, '4A' => 0, '4B' => 8, '3A' => 6, '3B' => 4]; // classe => index prof principal
$profs = []; // idx => [id, prenom, nom, code]
$profDeClasse = []; // classe => code => profId
foreach ($profDef as $i => [$p, $n, $code, $cls]) {
    $isDemo = ($i === 0);
    $ppClasse = array_search($i, $pp, true);
    $id = $ins('professeurs', ['etablissement_id' => $ETAB, 'nom' => $n, 'prenom' => $p,
        'mail' => $isDemo ? 'prof@victor-hugo.demo' : $slug("$p.$n") . '@victor-hugo.demo', 'adresse' => '', 'telephone' => $phone(),
        'identifiant' => $isDemo ? 'demo.prof' : $ident($p, $n), 'mot_de_passe' => $isDemo ? $hash : $lockedHash,
        'professeur_principal' => $ppClasse !== false ? $ppClasse : 'non', 'matiere' => $matDef[$code][0], 'actif' => 1]);
    if ($isDemo) { $usedIds['demo.prof'] = true; }
    $profs[$i] = [$id, $p, $n, $code];
    foreach ($cls as $c) {
        $ins('professeur_classes', ['id_professeur' => $id, 'nom_classe' => $c]);
        $profDeClasse[$c][$code] = $id;
    }
}
foreach ($pp as $c => $idx) { $pdo->prepare('UPDATE classes SET professeur_principal_id=? WHERE id=?')->execute([$profs[$idx][0], $classes[$c]]); }
$profName = []; foreach ($profs as [$id, $p, $n]) { $profName[$id] = "$p $n"; }

// Élèves + parents. La famille Martin est la famille de démo : Léa (4A, demo.eleve) + son frère Hugo (6B).
$eleves = [];        // id => [prenom, nom, classe, niveauScolaire]
$elevesParClasse = [];
$parentIds = [];
$addEleve = function (string $p, string $n, string $classe, string $identifiant, string $pwd) use ($ins, $ETAB, $slug, $phone, &$eleves, &$elevesParClasse, $classesNoms, $startYear, $niveauIdx): int {
    $age = 11 + $niveauIdx[$classesNoms[$classe]];
    $naiss = sprintf('%d-%02d-%02d', $startYear - $age, mt_rand(1, 12), mt_rand(1, 28));
    $id = $ins('eleves', ['etablissement_id' => $ETAB, 'nom' => $n, 'prenom' => $p, 'date_naissance' => $naiss, 'classe' => $classe,
        'lieu_naissance' => 'Val-Fleuri', 'adresse' => mt_rand(1, 80) . ' rue des ' . ['Lilas', 'Écoles', 'Peupliers', 'Moulins', 'Vignes'][mt_rand(0, 4)] . ', Val-Fleuri',
        'mail' => $identifiant . '@eleve.victor-hugo.demo', 'identifiant' => $identifiant, 'mot_de_passe' => $pwd, 'actif' => 1, 'photo_consent' => 1]);
    // « niveau » latent de l'élève (moyenne autour de laquelle ses notes gravitent)
    $eleves[$id] = [$p, $n, $classe, max(6.5, min(18.5, 12.6 + (mt_rand(-100, 100) / 100) * 4.2))];
    $elevesParClasse[$classe][] = $id;
    return $id;
};
$addParent = function (string $p, string $n, string $identifiant, string $pwd) use ($ins, $ETAB, $slug, $phone, $metiers, $pick, &$parentIds): int {
    $id = $ins('parents', ['etablissement_id' => $ETAB, 'nom' => $n, 'prenom' => $p, 'mail' => $identifiant . '@famille.demo', 'adresse' => 'Val-Fleuri',
        'telephone' => $phone(), 'metier' => $pick($metiers), 'identifiant' => $identifiant, 'mot_de_passe' => $pwd, 'est_parent_eleve' => 'non', 'actif' => 1]);
    $parentIds[] = $id; return $id;
};
$lea  = $addEleve('Léa', 'Martin', '4A', 'demo.eleve', $hash); $usedIds['demo.eleve'] = true;
$hugo = $addEleve('Hugo', 'Martin', '6B', $ident('Hugo', 'Martin'), $lockedHash);
$eleves[$lea][3] = 14.2;  // bonne élève, quelques points faibles
$eleves[$hugo][3] = 11.4;
$parentDemo = $addParent('Nadia', 'Martin', 'demo.parent', $hash); $usedIds['demo.parent'] = true;
$ins('parent_eleve', ['id_parent' => $parentDemo, 'id_eleve' => $lea, 'lien' => 'parent']);
$ins('parent_eleve', ['id_parent' => $parentDemo, 'id_eleve' => $hugo, 'lien' => 'parent']);
$pereMartin = $addParent('David', 'Martin', $ident('David', 'Martin'), $lockedHash);
$ins('parent_eleve', ['id_parent' => $pereMartin, 'id_eleve' => $lea, 'lien' => 'parent']);
$ins('parent_eleve', ['id_parent' => $pereMartin, 'id_eleve' => $hugo, 'lien' => 'parent']);

foreach ($classesNoms as $classe => $niv) {
    $effectif = mt_rand(22, 26) - count($elevesParClasse[$classe] ?? []);
    for ($k = 0; $k < $effectif; $k++) {
        $fille = mt_rand(0, 1) === 1;
        $p = $pick($fille ? $prenomsF : $prenomsM); $n = $pick($noms);
        if ($n === 'Martin') { $n = 'Marchal'; }
        $eid = $addEleve($p, $n, $classe, $ident($p, $n), $lockedHash);
        $pp1 = $addParent($pick(['Sandrine','Nathalie','Céline','Julie','Karine','Aurélie','Stéphanie','Laure','Fatima','Sonia','Émilie']), $n, '', $lockedHash);
        $pdo->prepare('UPDATE parents SET identifiant=?, mail=? WHERE id=?')->execute([$id1 = $ident('p.' . $slug($eleves[$eid][0]), $n), $id1 . '@famille.demo', $pp1]);
        $ins('parent_eleve', ['id_parent' => $pp1, 'id_eleve' => $eid, 'lien' => 'parent']);
    }
}
$nbEleves = count($eleves);
$log(sprintf('%d élèves, %d parents, %d professeurs, %d classes', $nbEleves, count($parentIds), count($profs), count($classes)));

// ── comptes unifiés + liens parent↔enfant (mêmes chemins que l'application) ──
(new \API\Services\AccountService($pdo))->syncFromLegacy();
$mig = dirname(__DIR__) . '/database/migrations/2026_06_19_000001_backfill_account_relationships.php';
if (is_file($mig)) { (require $mig)->up($pdo); }

// ── emploi du temps (glouton, sans conflit prof/classe, mercredi après-midi libre) ──
$jours = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi'];
$busyProf = []; $busyClasse = [];
$edtCount = 0;
$validDebut = $rentree->format('Y-m-d'); $validFin = ($startYear + 1) . '-07-04';
foreach ($classesNoms as $classe => $niv) {
    $besoins = [];
    foreach ($matDef as $code => $def) {
        $h = $def[3][$niveauIdx[$niv]];
        // EPS et SVT/PC/TEC en blocs de 2h, le reste en heures simples
        $bloc = in_array($code, ['EPS'], true) ? 2 : 1;
        for ($k = 0; $k < intdiv($h, $bloc); $k++) { $besoins[] = [$code, $bloc]; }
        if ($h % $bloc) { $besoins[] = [$code, 1]; }
    }
    usort($besoins, static fn($a, $b) => $b[1] <=> $a[1]); // blocs d'abord
    $parJour = array_fill_keys($jours, []);
    foreach ($besoins as [$code, $bloc]) {
        $profId = $profDeClasse[$classe][$code] ?? null;
        if (!$profId) { continue; }
        $placed = false;
        $ordreJours = $jours; // répartir : jours les moins chargés d'abord, sans doubler une matière le même jour
        usort($ordreJours, static fn($a, $b) => count($parJour[$a]) <=> count($parJour[$b]) ?: mt_rand(-1, 1));
        foreach ($ordreJours as $jour) {
            if (in_array($code, $parJour[$jour], true) && $code !== 'FRA' && $code !== 'MAT') { continue; }
            $maxSlot = $jour === 'mercredi' ? 4 : count($creneaux);
            for ($s = 0; $s + $bloc <= $maxSlot; $s++) {
                if ($bloc === 2 && ($s === 3 || $s === 1 && false)) { continue; } // pas de bloc à cheval sur la pause déjeuner
                $ok = true;
                for ($b = 0; $b < $bloc; $b++) {
                    if (isset($busyProf[$profId][$jour][$s + $b]) || isset($busyClasse[$classe][$jour][$s + $b])) { $ok = false; break; }
                }
                if (!$ok) { continue; }
                $salleType = isset($salles[$code]) ? $code : 'std';
                $salle = $salles[$salleType][mt_rand(0, count($salles[$salleType]) - 1)];
                for ($b = 0; $b < $bloc; $b++) {
                    $busyProf[$profId][$jour][$s + $b] = true; $busyClasse[$classe][$jour][$s + $b] = true;
                    [$cid, $deb, $fin] = $creneaux[$s + $b];
                    $ins('emploi_du_temps', ['etablissement_id' => $ETAB, 'classe_id' => $classes[$classe], 'matiere_id' => $mat[$code], 'professeur_id' => $profId,
                        'salle_id' => $salle, 'jour' => $jour, 'creneau_id' => $cid, 'heure_debut' => $deb, 'heure_fin' => $fin, 'type_cours' => 'cours',
                        'recurrence' => 'hebdomadaire', 'date_debut_validite' => $validDebut, 'date_fin_validite' => $validFin, 'couleur' => $matDef[$code][2], 'actif' => 1]);
                    $edtCount++;
                }
                $parJour[$jour][] = $code; $placed = true; break;
            }
            if ($placed) { break; }
        }
    }
}
$log("$edtCount cours hebdomadaires dans l'emploi du temps");

// ── notes ────────────────────────────────────────────────────────────────────
$typesEval = [['Contrôle', 2.0], ['Interrogation', 1.0], ['Devoir maison', 1.0], ['Exposé', 1.0], ['Évaluation de compétences', 1.5]];
$biais = []; // élève/matière : forces et faiblesses
$appreciations = [
    'high' => ['Excellent travail, continue ainsi !', 'Très bonne maîtrise.', 'Copie remarquable.', 'Bravo, raisonnement rigoureux.'],
    'mid'  => ['Travail correct, quelques imprécisions.', 'Des progrès, persévère.', 'Bien, mais attention aux détails.', 'Ensemble satisfaisant.'],
    'low'  => ['Leçon à revoir.', 'Des efforts sont attendus.', 'Méthode à consolider, viens me voir.', 'Résultat fragile, ne te décourage pas.'],
];
$noteRows = 0;
$joursNotes = max(1, $today->diff($notesDebut)->days);
foreach ($classesNoms as $classe => $niv) {
    foreach ($matDef as $code => $def) {
        if ($def[3][$niveauIdx[$niv]] === 0) { continue; }
        $profId = $profDeClasse[$classe][$code] ?? null; if (!$profId) { continue; }
        $nbEval = $code === 'MAT' || $code === 'FRA' ? 4 : (in_array($code, ['ART', 'MUS'], true) ? 2 : 3);
        for ($e = 0; $e < $nbEval; $e++) {
            [$type, $coef] = $typesEval[$e % count($typesEval)];
            $d = $schoolDay($notesDebut->modify('+' . (int) (($e + 0.6) * $joursNotes / ($nbEval + 0.4)) . ' days'));
            if ($d > $today) { $d = $schoolDay($today->modify('-1 day')); }
            $difficulte = $gauss(0, 1.2);
            foreach ($elevesParClasse[$classe] as $eid) {
                $biais[$eid][$code] ??= $gauss(0, 1.6);
                if (mt_rand(1, 100) <= 3) { continue; } // absent à l'évaluation
                $n = round(max(0, min(20, $gauss($eleves[$eid][3] + $biais[$eid][$code] - $difficulte, 2.3))) * 2) / 2;
                $lvl = $n >= 15 ? 'high' : ($n >= 10 ? 'mid' : 'low');
                $ins('notes', ['etablissement_id' => $ETAB, 'id_eleve' => $eid, 'id_matiere' => $mat[$code], 'id_professeur' => $profId, 'note' => $n,
                    'note_sur' => 20, 'coefficient' => $coef, 'type_evaluation' => $type, 'date_note' => $d->format('Y-m-d'),
                    'commentaire' => mt_rand(1, 100) <= 35 ? $pick($appreciations[$lvl]) : null, 'trimestre' => $trimestreCourant,
                    'date_creation' => $d->format('Y-m-d') . ' 18:' . sprintf('%02d', mt_rand(0, 59)) . ':00']);
                $noteRows++;
            }
        }
    }
}
$log("$noteRows notes");

// ── absences & retards ───────────────────────────────────────────────────────
$motifs = ['Maladie', 'Rendez-vous médical', 'Raison familiale', 'Transport', 'Sans motif'];
$abs = 0; $ret = 0;
$elevesIds = array_keys($eleves);
for ($k = 0; $k < (int) ($nbEleves * 0.13); $k++) {
    $eid = $pick($elevesIds);
    $d = $schoolDay($today->modify('-' . mt_rand(1, $joursNotes) . ' days'));
    $journee = mt_rand(0, 2) === 0;
    [, $h1] = $creneaux[$journee ? 0 : mt_rand(0, 5)];
    $debut = $d->format('Y-m-d') . ' ' . $h1 . ':00';
    $fin = $d->format('Y-m-d') . ' ' . ($journee ? '17:25' : $creneaux[min(7, array_search($h1, array_column($creneaux, 1)) + 1)][2]) . ':00';
    $motif = $pick($motifs); $just = $motif !== 'Sans motif' && mt_rand(0, 3) > 0;
    $ins('absences', ['etablissement_id' => $ETAB, 'id_eleve' => $eid, 'date_debut' => $debut, 'date_fin' => $fin, 'type_absence' => $journee ? 'journee' : 'cours',
        'motif' => $motif, 'justifie' => $just ? 1 : 0, 'signale_par' => 'Vie scolaire', 'statut' => $just ? 'justifiee' : 'signalee',
        'date_signalement' => $debut]);
    $abs++;
}
for ($k = 0; $k < (int) ($nbEleves * 0.18); $k++) {
    $eid = $pick($elevesIds);
    $d = $schoolDay($today->modify('-' . mt_rand(1, $joursNotes) . ' days'));
    $ins('retards', ['etablissement_id' => $ETAB, 'id_eleve' => $eid, 'date_retard' => $d->format('Y-m-d') . ' 08:' . sprintf('%02d', mt_rand(3, 25)) . ':00',
        'duree_minutes' => mt_rand(3, 25), 'motif' => $pick(['Bus en retard', 'Panne de réveil', 'Rendez-vous', null]), 'justifie' => mt_rand(0, 1),
        'signale_par' => 'Vie scolaire', 'date_signalement' => $d->format('Y-m-d H:i:s')]);
    $ret++;
}
// Léa : une absence justifiée récente, un retard — pour que l'espace famille ait du concret
$dLea = $schoolDay($today->modify('-6 days'));
$ins('absences', ['etablissement_id' => $ETAB, 'id_eleve' => $lea, 'date_debut' => $dLea->format('Y-m-d') . ' 08:00:00', 'date_fin' => $dLea->format('Y-m-d') . ' 11:55:00',
    'type_absence' => 'cours', 'motif' => 'Rendez-vous médical', 'justifie' => 1, 'signale_par' => 'Vie scolaire', 'statut' => 'justifiee', 'date_signalement' => $dLea->format('Y-m-d') . ' 08:10:00']);
$log("$abs absences, $ret retards");

// ── cahier de textes / devoirs ───────────────────────────────────────────────
$sujets = [
    'FRA' => ['Lire les chapitres 3 et 4', 'Rédaction : décrire un lieu familier (20 lignes)', 'Apprendre la conjugaison du passé simple', 'Fiche de lecture'],
    'MAT' => ['Exercices 12 à 15 p. 84', 'Apprendre le cours sur les fractions', 'DM : problème de géométrie', 'Réviser pour le contrôle de jeudi'],
    'HG'  => ['Apprendre la leçon et les repères chronologiques', 'Compléter la carte du chapitre', 'Préparer l\'exposé en binôme'],
    'ANG' => ['Learn the vocabulary list (unit 3)', 'Write 10 sentences with the present perfect', 'Prepare your oral presentation'],
    'ESP' => ['Aprender el vocabulario de la casa', 'Ejercicios 2 y 3 p. 41'],
    'SVT' => ['Schéma légendé de la cellule', 'Revoir le TP sur la germination'],
    'PC'  => ['Compte rendu du TP', 'Exercices sur les circuits électriques'],
    'TEC' => ['Terminer la fiche projet', 'Rechercher le fonctionnement d\'un capteur'],
    'ART' => ['Apporter des magazines pour le collage'],
    'MUS' => ['Écouter le morceau étudié et noter 3 instruments'],
    'EPS' => ['Apporter une tenue adaptée (endurance)'],
];
$dv = 0;
foreach ($classesNoms as $classe => $niv) {
    foreach ($matDef as $code => $def) {
        if ($def[3][$niveauIdx[$niv]] === 0 || empty($profDeClasse[$classe][$code])) { continue; }
        $n = in_array($code, ['FRA', 'MAT', 'ANG', 'HG'], true) ? 3 : 1;
        for ($k = 0; $k < $n; $k++) {
            $ajout = $schoolDay($today->modify('-' . mt_rand(0, 12) . ' days'));
            $rendu = $schoolDay($today->modify('+' . mt_rand(1, 14) . ' days'));
            if ($k === 0 && mt_rand(0, 2) === 0) { $rendu = $schoolDay($today->modify('-' . mt_rand(1, 6) . ' days')); }
            $ins('devoirs', ['etablissement_id' => $ETAB, 'titre' => $pick($sujets[$code]), 'description' => 'À faire pour la séance du ' . $rendu->format('d/m') . '.',
                'classe' => $classe, 'nom_matiere' => $def[0], 'nom_professeur' => $profName[$profDeClasse[$classe][$code]],
                'date_ajout' => $ajout->format('Y-m-d'), 'date_rendu' => $rendu->format('Y-m-d')]);
            $dv++;
        }
    }
}
$log("$dv devoirs au cahier de textes");

// ── messagerie ───────────────────────────────────────────────────────────────
$conv = function (string $subject, string $type, array $participants, array $messages) use ($ins, $ETAB, $pdo, $today): void {
    $start = $today->modify('-' . (count($messages) + mt_rand(0, 6)) . ' days')->setTime(mt_rand(8, 18), mt_rand(0, 59));
    $cid = $ins('conversations', ['etablissement_id' => $ETAB, 'subject' => $subject, 'type' => $type, 'allow_replies' => 1,
        'created_at' => $start->format('Y-m-d H:i:s'), 'updated_at' => $start->format('Y-m-d H:i:s')]);
    $last = null; $t = $start; $lastSender = null;
    foreach ($messages as $i => [$sid, $stype, $body]) {
        $t = $t->modify('+' . mt_rand(2, 30) . ' hours');
        $h = (int) $t->format('G');
        if ($h < 8 || $h > 19) { $t = $t->setTime(mt_rand(8, 19), mt_rand(0, 59)); } // messages aux heures ouvrables
        if ($t > new DateTimeImmutable()) { $t = (new DateTimeImmutable())->modify('-' . (count($messages) - $i) * 7 . ' minutes'); }
        $last = $ins('messages', ['conversation_id' => $cid, 'sender_id' => $sid, 'sender_type' => $stype, 'body' => $body, 'created_at' => $t->format('Y-m-d H:i:s'), 'status' => 'normal']);
        $lastSender = $stype . ':' . $sid;
    }
    foreach ($participants as $k => [$uid, $utype]) {
        $ins('conversation_participants', ['conversation_id' => $cid, 'user_id' => $uid, 'user_type' => $utype, 'joined_at' => $start->format('Y-m-d H:i:s'),
            'unread_count' => ($utype . ':' . $uid) === $lastSender ? 0 : 1, 'last_read_message_id' => ($utype . ':' . $uid) === $lastSender ? $last : null, 'is_admin' => $k === 0 ? 1 : 0]);
    }
    $pdo->prepare('UPDATE conversations SET last_message_id=?, updated_at=? WHERE id=?')->execute([$last, $t->format('Y-m-d H:i:s'), $cid]);
};
$P = $profs[0][0]; // demo.prof
$profFr4A = $profDeClasse['4A']['FRA'];
$conv('Sortie au musée — autorisation', 'individuelle', [[$P, 'professeur'], [$parentDemo, 'parent']], [
    [$P, 'professeur', "Bonjour Madame Martin,\n\nLa classe de 4A visitera le musée des Sciences le jeudi 12. Merci de signer l'autorisation dans l'espace famille avant lundi.\n\nCordialement,\nM. Lefort"],
    [$parentDemo, 'parent', "Bonjour Monsieur Lefort, c'est fait, merci ! Léa est ravie. Faut-il prévoir un pique-nique ?"],
    [$P, 'professeur', "Oui, un pique-nique et une tenue confortable. Départ 8h15 devant le collège."],
]);
$conv('Question sur le DM de géométrie', 'individuelle', [[$lea, 'eleve'], [$P, 'professeur']], [
    [$lea, 'eleve', "Bonjour Monsieur, pour l'exercice 3 du DM, faut-il utiliser le théorème de Pythagore ou la trigonométrie ?"],
    [$P, 'professeur', "Bonjour Léa, Pythagore suffit pour la question a). La trigonométrie viendra au chapitre suivant. Bon courage !"],
]);
$conv('Conseil pédagogique — progression 4e', 'groupe', [[$adminId, 'administrateur'], [$P, 'professeur'], [$profFr4A, 'professeur'], [$cpeId, 'vie_scolaire']], [
    [$adminId, 'administrateur', "Bonjour à tous,\n\nJe vous propose de faire un point sur la progression des 4e mardi à 17h30 en salle des professeurs. Merci de préparer vos remarques par classe."],
    [$P, 'professeur', "Bien noté. Les 4B ont un peu de retard en calcul littéral, je ferai une proposition de soutien."],
    [$cpeId, 'vie_scolaire', "Je présenterai le point absentéisme : deux situations à suivre en 4A."],
]);
$conv('Absence de Léa — justificatif', 'individuelle', [[$cpeId, 'vie_scolaire'], [$parentDemo, 'parent']], [
    [$parentDemo, 'parent', "Bonjour, Léa était chez l'orthodontiste ce matin. Je dépose le justificatif dans l'espace famille."],
    [$cpeId, 'vie_scolaire', "Bonjour Madame Martin, justificatif bien reçu, l'absence est régularisée. Bonne journée."],
]);
$conv('Rappel : tenue d\'EPS', 'classe', [[$profDeClasse['4A']['EPS'], 'professeur'], [$lea, 'eleve']], [
    [$profDeClasse['4A']['EPS'], 'professeur', "Rappel à toute la 4A : cycle endurance à partir de lundi, prévoyez baskets et gourde."],
]);
$conv('Réunion parents-professeurs', 'information', [[$adminId, 'administrateur'], [$parentDemo, 'parent'], [$P, 'professeur'], [$cpeId, 'vie_scolaire']], [
    [$adminId, 'administrateur', "Madame, Monsieur,\n\nLes rencontres parents-professeurs du premier trimestre auront lieu le " . $schoolDay($today->modify('+16 days'))->format('d/m') . " de 16h30 à 20h. Les créneaux sont réservables dans l'espace famille.\n\nLa principale, C. Delmas"],
]);
$log('6 conversations de messagerie');

// ── annonces, agenda, menus, incidents ───────────────────────────────────────
$pdo->exec('DELETE FROM annonces');
foreach ([
    ['Réunions parents-professeurs', "Les créneaux de rencontre du 1er trimestre sont ouverts à la réservation dans l'espace famille.", 'evenement', 1],
    ['Photo de classe', 'Le photographe passera la semaine prochaine. Bons de commande distribués par les professeurs principaux.', 'info', 0],
    ['Cross du collège', 'Le cross annuel aura lieu au parc des Sports. Tous les élèves participent, tenue de sport obligatoire.', 'evenement', 0],
    ['Alerte météo — transports', 'En cas de vigilance orange, consultez les horaires de bus adaptés dans le module Transports.', 'urgent', 0],
    ['Club robotique : inscriptions ouvertes', 'Le club robotique reprend le jeudi midi en salle Techno. 16 places.', 'info', 0],
] as $i => [$titre, $contenu, $type, $epingle]) {
    $d = $today->modify('-' . ($i * 3 + 1) . ' days');
    $ins('annonces', ['etablissement_id' => $ETAB, 'titre' => $titre, 'contenu' => $contenu, 'type' => $type, 'auteur_id' => $adminId, 'auteur_type' => 'administrateur',
        'cible_roles' => null, 'publie' => 1, 'epingle' => $epingle, 'date_publication' => $d->format('Y-m-d 08:00:00'), 'date_creation' => $d->format('Y-m-d 08:00:00')]);
}
$pdo->exec('DELETE FROM evenements');
$evts = [
    [16, 'Réunion parents-professeurs', 'reunion', '16:30', '20:00', 'Collège', null],
    [9, 'Conseil de classe 4A', 'conseil', '17:30', '19:00', 'Salle 104', '4A'],
    [10, 'Conseil de classe 3A', 'conseil', '17:30', '19:00', 'Salle 104', '3A'],
    [5, 'Sortie au musée des Sciences — 4A', 'sortie', '08:15', '16:30', 'Musée des Sciences', '4A'],
    [21, 'Cross du collège', 'evenement', '09:00', '12:00', 'Parc des Sports', null],
    [-4, 'Journée portes ouvertes du CDI', 'evenement', '12:00', '14:00', 'CDI', null],
    [3, 'Brevet blanc — épreuve de mathématiques', 'examen', '08:00', '10:00', 'Gymnase', '3A,3B'],
];
foreach ($evts as [$off, $titre, $type, $h1, $h2, $lieu, $cls]) {
    $d = $schoolDay($today->modify(($off >= 0 ? '+' : '') . $off . ' days'));
    $ins('evenements', ['etablissement_id' => $ETAB, 'titre' => $titre, 'description' => $titre . ' — ' . $lieu . '.', 'date_debut' => $d->format('Y-m-d') . " $h1:00",
        'date_fin' => $d->format('Y-m-d') . " $h2:00", 'type_evenement' => $type, 'statut' => 'actif', 'createur' => 'Claire Delmas', 'visibilite' => 'public',
        'lieu' => $lieu, 'classes' => $cls]);
}
$pdo->exec('DELETE FROM menus_cantine');
$entrees = ['Carottes râpées', 'Salade de lentilles', 'Velouté de potiron', 'Taboulé', 'Betteraves vinaigrette', 'Œuf mayonnaise'];
$plats = [['Poulet rôti', 'Haricots verts'], ['Lasagnes végétariennes', 'Salade verte'], ['Filet de colin', 'Riz pilaf'], ['Bœuf bourguignon', 'Pommes vapeur'], ['Curry de pois chiches', 'Semoule'], ['Rôti de porc', 'Purée maison']];
$desserts = ['Yaourt fermier', 'Compote pomme-poire', 'Fruit de saison', 'Crème dessert vanille', 'Tarte aux pommes', 'Fromage blanc'];
for ($k = -3; $k <= 10; $k++) {
    $d = $today->modify(($k >= 0 ? '+' : '') . $k . ' days');
    if ((int) $d->format('N') >= 6 || (int) $d->format('N') === 3) { continue; }
    [$pl, $acc] = $pick($plats);
    $ins('menus_cantine', ['etablissement_id' => $ETAB, 'date_menu' => $d->format('Y-m-d'), 'entree' => $pick($entrees), 'plat_principal' => $pl,
        'accompagnement' => $acc, 'dessert' => $pick($desserts), 'allergenes' => $pick(['Gluten, lait', 'Œuf', 'Lait', 'Poisson', 'Aucun majeur']), 'regime_special' => 'Menu végétarien disponible']);
}
foreach ([[1, 'Bavardages répétés', 'mineur', 'Salle 201'], [2, 'Bousculade dans les escaliers', 'moyen', 'Escalier B'], [0, 'Oubli répété du matériel', 'mineur', 'Salle 104']] as $i => [$cls, $desc, $grav, $lieu]) {
    $cName = array_keys($classesNoms)[$i * 3 + 1];
    $eid = $elevesParClasse[$cName][mt_rand(0, count($elevesParClasse[$cName]) - 1)];
    $d = $schoolDay($today->modify('-' . mt_rand(2, 15) . ' days'));
    $iid = $ins('incidents', ['etablissement_id' => $ETAB, 'eleve_id' => $eid, 'date_incident' => $d->format('Y-m-d 10:30:00'), 'lieu' => $lieu, 'type_incident' => 'comportement',
        'gravite' => $grav, 'description' => $desc . '.', 'signale_par_id' => $cpeId, 'signale_par_type' => 'vie_scolaire', 'classe_id' => $classes[$cName], 'statut' => 'traite']);
    if ($grav === 'moyen') {
        $ins('sanctions', ['etablissement_id' => $ETAB, 'incident_id' => $iid, 'eleve_id' => $eid, 'type_sanction' => 'retenue', 'motif' => $desc,
            'date_sanction' => $d->format('Y-m-d'), 'duree' => '1 heure', 'lieu_retenue' => 'Salle 102', 'convocation_parent' => 0, 'decide_par_id' => $cpeId, 'decide_par_type' => 'vie_scolaire', 'statut' => 'prononcee']);
    }
}

// ── notifications des comptes démo ───────────────────────────────────────────
foreach ([
    [$lea, 'eleve', 'note', '3 nouvelles notes', 'Mathématiques : 16/20 — Contrôle', '/modules/notes/notes.php'],
    [$lea, 'eleve', 'devoir', 'Nouveau devoir en Français', 'Rédaction à rendre la semaine prochaine.', '/modules/cahierdetextes/'],
    [$parentDemo, 'parent', 'absence', 'Absence régularisée', "L'absence de Léa a été justifiée par la vie scolaire.", '/modules/absences/'],
    [$parentDemo, 'parent', 'message', 'Nouveau message de M. Lefort', 'Sortie au musée — autorisation', '/modules/messagerie/'],
    [$P, 'professeur', 'message', 'Nouveau message de Léa Martin', 'Question sur le DM de géométrie', '/modules/messagerie/'],
    [$cpeId, 'vie_scolaire', 'absence', '3 absences à traiter', 'Des absences non justifiées attendent un suivi.', '/modules/absences/'],
] as [$uid, $ut, $type, $titre, $contenu, $lien]) {
    $ins('notifications_globales', ['etablissement_id' => $ETAB, 'user_id' => $uid, 'user_type' => $ut, 'type' => $type, 'titre' => $titre, 'contenu' => $contenu,
        'lien' => $lien, 'importance' => 'normale', 'lu' => 0, 'group_count' => str_starts_with($titre, '3 ') ? 3 : 1, 'date_creation' => (new DateTimeImmutable())->modify('-' . mt_rand(1, 40) . ' hours')->format('Y-m-d H:i:s')]);
}

// ── mesure d'audience (30 jours, anonyme) + consentements — pour le tableau de bord direction ──
try {
    $pdo->exec('DELETE FROM audience_events'); $pdo->exec('DELETE FROM cookie_consents');
    $roleMods = [
        'eleve' => ['notes' => 30, 'cahierdetextes' => 22, 'emploi_du_temps' => 20, 'accueil' => 18, 'messagerie' => 6, 'agenda' => 4],
        'parent' => ['notes' => 30, 'absences' => 18, 'accueil' => 20, 'messagerie' => 14, 'cahierdetextes' => 10, 'bulletins' => 8],
        'professeur' => ['notes' => 28, 'appel' => 24, 'cahierdetextes' => 16, 'emploi_du_temps' => 12, 'messagerie' => 12, 'accueil' => 8],
        'vie_scolaire' => ['absences' => 40, 'discipline' => 20, 'messagerie' => 15, 'accueil' => 15, 'agenda' => 10],
        'administrateur' => ['admin' => 35, 'accueil' => 20, 'reporting' => 15, 'messagerie' => 15, 'annonces' => 15],
    ];
    $paths = ['notes' => '/modules/notes/notes.php', 'cahierdetextes' => '/modules/cahierdetextes/cahierdetextes.php', 'emploi_du_temps' => '/modules/emploi_du_temps/emploi_du_temps.php',
        'accueil' => '/accueil/accueil.php', 'messagerie' => '/modules/messagerie/', 'agenda' => '/modules/agenda/agenda.php', 'absences' => '/modules/absences/absences.php',
        'bulletins' => '/modules/bulletins/bulletins.php', 'appel' => '/modules/appel/appel.php', 'discipline' => '/modules/discipline/discipline.php',
        'admin' => '/admin/dashboard.php', 'reporting' => '/modules/reporting/reporting.php', 'annonces' => '/modules/annonces/annonces.php'];
    $hoursBy = ['eleve' => [7, 7, 12, 12, 13, 17, 17, 18, 18, 19, 20, 21], 'parent' => [7, 8, 12, 18, 19, 19, 20, 20, 21, 21, 22],
        'professeur' => [7, 8, 8, 10, 12, 13, 16, 17, 18, 20, 21], 'vie_scolaire' => [8, 8, 9, 9, 10, 11, 13, 14, 15, 16], 'administrateur' => [8, 9, 10, 11, 14, 15, 16, 17]];
    $dailyBy = ['eleve' => [55, 95], 'parent' => [35, 70], 'professeur' => [12, 17], 'vie_scolaire' => [2, 3], 'administrateur' => [1, 2]];
    $pick = static function (array $w) { $r = mt_rand(1, array_sum($w)); foreach ($w as $k => $v) { if (($r -= $v) <= 0) { return $k; } } return array_key_first($w); };
    $ins = $pdo->prepare('INSERT INTO audience_events (etablissement_id, day, hour, weekday, path, module, role, device, visitor, duration, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $pdo->beginTransaction();
    for ($dd = 29; $dd >= 0; $dd--) {
        $day = $today->modify("-{$dd} days"); $wd = (int) $day->format('N');
        $factor = $wd >= 6 ? 0.35 : ($wd === 3 ? 0.8 : 1.0);
        foreach ($dailyBy as $role => [$lo, $hi]) {
            $nv = (int) round(mt_rand($lo, $hi) * $factor);
            for ($v = 0; $v < $nv; $v++) {
                $visitor = substr(sha1($role . $v . $day->format('Ymd') . mt_rand()), 0, 16);
                $device = $role === 'professeur' || $role === 'vie_scolaire' || $role === 'administrateur' ? (mt_rand(1, 10) <= 8 ? 'ordinateur' : 'mobile') : (mt_rand(1, 10) <= 7 ? 'mobile' : (mt_rand(0, 1) ? 'ordinateur' : 'tablette'));
                $h0 = $hoursBy[$role][mt_rand(0, count($hoursBy[$role]) - 1)];
                $pv = mt_rand(2, $role === 'professeur' ? 9 : 6);
                for ($k = 0; $k < $pv; $k++) {
                    $m = $pick($roleMods[$role]);
                    $ins->execute([$ETAB, $day->format('Y-m-d'), $h0, $wd, $paths[$m], $m === 'admin' || $m === 'accueil' ? $m : $m, $role, $device, $visitor,
                        mt_rand(8, $m === 'notes' || $m === 'appel' ? 240 : 150), $day->format('Y-m-d') . sprintf(' %02d:%02d:00', $h0, mt_rand(0, 59))]);
                }
            }
        }
    }
    $insC = $pdo->prepare("INSERT INTO cookie_consents (etablissement_id, consent_id, preferences, analytics, policy_version, action, created_at) VALUES (?,?,0,?,?,?,?)");
    for ($k = 0; $k < 240; $k++) {
        $acc = mt_rand(1, 100) <= 71;
        $insC->execute([$ETAB, bin2hex(random_bytes(16)), $acc ? 1 : 0, \API\Services\Privacy\ConsentService::POLICY_VERSION, $acc ? 'accept_all' : 'reject_all',
            $today->modify('-' . mt_rand(0, 29) . ' days')->format('Y-m-d') . sprintf(' %02d:%02d:00', mt_rand(7, 21), mt_rand(0, 59))]);
    }
    $pdo->commit();
    $log('mesure d\'audience : ' . (int) $pdo->query('SELECT COUNT(*) FROM audience_events')->fetchColumn() . ' pages vues sur 30 jours');
} catch (\Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } $log('mesure d\'audience non générée : ' . $e->getMessage()); }

// Comptes démo : jamais de changement de mot de passe forcé, jamais de verrouillage.
$pdo->exec("UPDATE accounts SET must_change_password=0, failed_login_attempts=0, locked_until=NULL WHERE username LIKE 'demo.%'");
// Le compte d'installation « admin » n'a pas sa place dans une démo publique.
$pdo->exec("UPDATE administrateurs SET actif=0, mot_de_passe='" . $lockedHash . "' WHERE identifiant='admin'");
$pdo->exec("UPDATE accounts SET status='inactive', password_hash='" . $lockedHash . "' WHERE username='admin'");

printf("Démo générée en %.1f s.\n", microtime(true) - $t0);
