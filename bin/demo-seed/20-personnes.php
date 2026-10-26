<?php
/** Démo — personnes : direction, vie scolaire, classes, professeurs, élèves, familles, comptes. */
declare(strict_types=1);

$prenomsF = ['Léa','Emma','Chloé','Inès','Jade','Louise','Manon','Camille','Zoé','Lina','Alice','Sarah','Juliette','Lola','Anna','Nina','Rose','Mila','Ambre','Margaux','Clara','Eva','Agathe','Yasmine','Lucie','Maëlys','Romane','Salomé','Héloïse','Capucine','Lou','Iris','Mia','Adèle','Charlotte','Elsa','Victoire','Aya','Nour','Lise'];
$prenomsM = ['Hugo','Lucas','Gabriel','Louis','Nathan','Arthur','Jules','Adam','Raphaël','Léo','Noah','Ethan','Tom','Sacha','Paul','Maxime','Théo','Mathis','Enzo','Nolan','Rayan','Timéo','Victor','Antoine','Baptiste','Samuel','Yanis','Clément','Malo','Axel','Gaspard','Aaron','Mohamed','Ilyes','Côme','Marius','Eliott','Martin','Simon','Lenny'];
$noms = ['Martin','Bernard','Dubois','Thomas','Robert','Richard','Petit','Durand','Leroy','Moreau','Simon','Laurent','Lefebvre','Michel','Garcia','David','Bertrand','Roux','Vincent','Fournier','Morel','Girard','André','Mercier','Dupont','Lambert','Bonnet','François','Martinez','Legrand','Garnier','Faure','Rousseau','Blanc','Guérin','Muller','Henry','Roussel','Nicolas','Perrin','Morin','Mathieu','Clément','Gauthier','Dumont','Lopez','Fontaine','Chevalier','Robin','Masson','Sanchez','Gérard','Nguyen','Boyer','Denis','Lemaire','Duval','Joly','Gautier','Roger','Roche','Roy','Noël','Meyer','Lucas','Meunier','Jean','Perez','Marchand','Dufour','Blanchard','Marie','Barbier','Brun','Dumas','Brunet','Schmitt','Leroux','Colin','Fernandez','Pierre','Renard','Arnaud','Rolland','Caron','Aubert','Giraud','Leclerc','Vidal','Bourgeois','Renaud','Lemoine','Picard','Gaillard','Philippe','Leclercq','Lacroix','Fabre','Dupuis','Olivier','Benali','Haddad','Diallo','Traoré','Kaya','Silva','Ferreira','Costa','Nowak','Kowalski'];
$metiers = ['Infirmière','Ingénieur','Commerçant','Professeure des écoles','Comptable','Agricultrice','Artisan','Médecin','Employée de banque','Technicien','Architecte','Chauffeur','Assistante sociale','Cuisinier','Pharmacienne','Électricien','Juriste','Aide-soignante','Développeur','Fonctionnaire','Boulanger','Kinésithérapeute','Agent immobilier','Mécanicien','Secrétaire médicale'];

$hash = \API\Security\PasswordPolicy::hash($demoPassword);
// Les comptes « de masse » reçoivent un hash aléatoire inutilisable : on ne se connecte qu'avec les 5 comptes démo.
$lockedHash = \API\Security\PasswordPolicy::hash(bin2hex(random_bytes(16)));
$usedIds = ['admin' => true, 'demo.direction' => true, 'demo.cpe' => true, 'demo.prof' => true, 'demo.eleve' => true, 'demo.parent' => true];
$ident = static function (string $prenom, string $nom) use (&$usedIds, $slug): string {
    $base = $slug($prenom) . '.' . $slug($nom); $id = $base; $i = 1;
    while (isset($usedIds[$id])) { $id = $base . sprintf('%02d', ++$i); }
    $usedIds[$id] = true; return $id;
};
$phone = static fn() => '06 ' . sprintf('%02d %02d %02d %02d', mt_rand(0, 99), mt_rand(0, 99), mt_rand(0, 99), mt_rand(0, 99));

// Direction + administration
$adminId = $ins('administrateurs', ['etablissement_id' => $ETAB, 'nom' => 'Delmas', 'prenom' => 'Claire', 'mail' => 'direction@victor-hugo.demo',
    'identifiant' => 'demo.direction', 'mot_de_passe' => $hash, 'adresse' => '', 'role' => 'administrateur', 'actif' => 1]);
$adjointId = $ins('administrateurs', ['etablissement_id' => $ETAB, 'nom' => 'Benali', 'prenom' => 'Karim', 'mail' => 'adjoint@victor-hugo.demo',
    'identifiant' => $ident('Karim', 'Benali'), 'mot_de_passe' => $lockedHash, 'adresse' => '', 'role' => 'administrateur', 'actif' => 1]);
$gestId = $ins('administrateurs', ['etablissement_id' => $ETAB, 'nom' => 'Ferrand', 'prenom' => 'Sylvie', 'mail' => 'gestion@victor-hugo.demo',
    'identifiant' => $ident('Sylvie', 'Ferrand'), 'mot_de_passe' => $lockedHash, 'adresse' => '', 'role' => 'administrateur', 'actif' => 1]);
$staffName = ["administrateur:$adminId" => 'Claire Delmas', "administrateur:$adjointId" => 'Karim Benali', "administrateur:$gestId" => 'Sylvie Ferrand'];

// Vie scolaire : 2 CPE (dont le compte démo), 4 AED, 1 infirmière
$cpeId = $ins('vie_scolaire', ['etablissement_id' => $ETAB, 'nom' => 'Rivière', 'prenom' => 'Sophie', 'mail' => 'cpe@victor-hugo.demo', 'telephone' => '01 00 00 00 10',
    'identifiant' => 'demo.cpe', 'mot_de_passe' => $hash, 'est_CPE' => 'oui', 'est_infirmerie' => 'non', 'actif' => 1]);
$cpe2Id = $ins('vie_scolaire', ['etablissement_id' => $ETAB, 'nom' => 'Mercier', 'prenom' => 'Julien', 'mail' => 'cpe2@victor-hugo.demo', 'telephone' => '01 00 00 00 11',
    'identifiant' => $ident('Julien', 'Mercier'), 'mot_de_passe' => $lockedHash, 'est_CPE' => 'oui', 'est_infirmerie' => 'non', 'actif' => 1]);
$staffName["vie_scolaire:$cpeId"] = 'Sophie Rivière'; $staffName["vie_scolaire:$cpe2Id"] = 'Julien Mercier';
$aedIds = [];
foreach ([['Yanis', 'Haddad'], ['Pauline', 'Marchetti'], ['Inès', 'Ouali'], ['Thibault', 'Royer']] as [$p, $n]) {
    $id = $ins('vie_scolaire', ['etablissement_id' => $ETAB, 'nom' => $n, 'prenom' => $p, 'mail' => $slug("$p.$n") . '@victor-hugo.demo',
        'identifiant' => $ident($p, $n), 'mot_de_passe' => $lockedHash, 'est_CPE' => 'non', 'est_infirmerie' => 'non', 'actif' => 1]);
    $aedIds[] = $id; $staffName["vie_scolaire:$id"] = "$p $n";
}
$infId = $ins('vie_scolaire', ['etablissement_id' => $ETAB, 'nom' => 'Lemoine', 'prenom' => 'Agnès', 'mail' => 'infirmerie@victor-hugo.demo',
    'identifiant' => $ident('Agnès', 'Lemoine'), 'mot_de_passe' => $lockedHash, 'est_CPE' => 'non', 'est_infirmerie' => 'oui', 'actif' => 1]);
$staffName["vie_scolaire:$infId"] = 'Agnès Lemoine';

// Classes : 4 divisions par niveau
$niveaux = ['6e' => 0, '5e' => 1, '4e' => 2, '3e' => 3];
$classesNoms = [];
foreach (['6', '5', '4', '3'] as $nv) { foreach (['A', 'B', 'C', 'D'] as $l) { $classesNoms[$nv . $l] = $nv . 'e'; } }
$niveauIdx = $niveaux;
$classes = []; $classeNomParId = [];
foreach ($classesNoms as $nom => $niv) {
    $classes[$nom] = $ins('classes', ['etablissement_id' => $ETAB, 'nom' => $nom, 'niveau' => $niv, 'annee_scolaire' => $annee, 'actif' => 1]);
    $classeNomParId[$classes[$nom]] = $nom;
}

// Professeurs : répartis automatiquement par matière (service ≈ 18 h). Le compte démo est fixé :
// Marc Lefort, mathématiques en 4A, 4B, 3A, 3B, professeur principal de 4A.
$profNoms = [
    'MAT' => [['Marc', 'Lefort'], ['Isabelle', 'Carré'], ['Rémi', 'Gallet'], ['Fanny', 'Delorme']],
    'FRA' => [['Nathalie', 'Fabre'], ['Julien', 'Aubry'], ['Hélène', 'Morvan'], ['Bastien', 'Leca']],
    'HG'  => [['Olivier', 'Besson'], ['Céline', 'Arnaud'], ['Vincent', 'Labbé']],
    'ANG' => [['Sarah', 'Whitfield'], ['Thomas', 'Keller'], ['Amandine', 'Royer']],
    'ESP' => [['Lucía', 'Herrera'], ['Diego', 'Navarro']],
    'SVT' => [['Damien', 'Roux'], ['Émilie', 'Chapuis']],
    'PC'  => [['Mélanie', 'Picard'], ['Hugo', 'Vasseur']],
    'TEC' => [['Franck', 'Lambert'], ['Nadia', 'Bouzid']],
    'ART' => [['Élodie', 'Vasseur']],
    'MUS' => [['Antoine', 'Dorval']],
    'EPS' => [['Karine', 'Moulin'], ['Stéphane', 'Guillot'], ['Laetitia', 'Pons']],
];
$profs = []; $profName = []; $profMatiere = []; $profDeClasse = []; $classesDuProf = [];
$P = 0; // id de demo.prof
foreach ($matDef as $code => $def) {
    $cls = array_values(array_filter(array_keys($classesNoms), fn($c) => $def[3][$niveauIdx[$classesNoms[$c]]] > 0));
    if ($code === 'MAT') { // le compte démo a ses classes ; les autres se répartissent le reste
        $reste = array_values(array_diff($cls, ['4A', '4B', '3A', '3B']));
        $groupes = [['4A', '4B', '3A', '3B'], array_slice($reste, 0, 4), array_slice($reste, 4, 4), array_slice($reste, 8)];
    } else {
        $nb = count($profNoms[$code]);
        $groupes = array_chunk($cls, (int) ceil(count($cls) / $nb));
    }
    foreach ($profNoms[$code] as $i => [$p, $n]) {
        $isDemo = $code === 'MAT' && $i === 0;
        $id = $ins('professeurs', ['etablissement_id' => $ETAB, 'nom' => $n, 'prenom' => $p,
            'mail' => $isDemo ? 'prof@victor-hugo.demo' : $slug("$p.$n") . '@victor-hugo.demo', 'adresse' => '', 'telephone' => $phone(),
            'identifiant' => $isDemo ? 'demo.prof' : $ident($p, $n), 'mot_de_passe' => $isDemo ? $hash : $lockedHash,
            'professeur_principal' => 'non', 'matiere' => $def[0], 'actif' => 1]);
        if ($isDemo) { $P = $id; }
        $profs[] = [$id, $p, $n, $code]; $profName[$id] = "$p $n"; $profMatiere[$id] = $code;
        foreach ($groupes[$i] ?? [] as $c) {
            $ins('professeur_classes', ['id_professeur' => $id, 'nom_classe' => $c]);
            $profDeClasse[$c][$code] = $id; $classesDuProf[$id][] = $c;
        }
    }
}
// Documentaliste + titulaire remplaçant (sans classe : remplacements, CDI)
foreach ([['Aurélie', 'Tessier', 'Documentation'], ['Grégoire', 'Hamon', 'Mathématiques']] as [$p, $n, $m]) {
    $id = $ins('professeurs', ['etablissement_id' => $ETAB, 'nom' => $n, 'prenom' => $p, 'mail' => $slug("$p.$n") . '@victor-hugo.demo', 'adresse' => '',
        'telephone' => $phone(), 'identifiant' => $ident($p, $n), 'mot_de_passe' => $lockedHash, 'professeur_principal' => 'non', 'matiere' => $m, 'actif' => 1]);
    $profName[$id] = "$p $n"; $profMatiere[$id] = $m === 'Documentation' ? 'DOC' : 'MAT';
    if ($m === 'Documentation') { $docId = $id; } else { $tzrId = $id; }
}
// Professeurs principaux : de préférence français / maths / histoire-géo de la classe
$ppDe = [];
$dejaPP = [];
foreach (array_keys($classesNoms) as $c) {
    $cand = $c === '4A' ? [$P] : array_values(array_filter([$profDeClasse[$c]['FRA'] ?? 0, $profDeClasse[$c]['HG'] ?? 0, $profDeClasse[$c]['MAT'] ?? 0, $profDeClasse[$c]['ANG'] ?? 0, $profDeClasse[$c]['SVT'] ?? 0, $profDeClasse[$c]['TEC'] ?? 0]));
    foreach ($cand as $pid) { if ($pid && !isset($dejaPP[$pid]) && !($pid === $P && $c !== '4A')) { $ppDe[$c] = $pid; $dejaPP[$pid] = $c; break; } }
    $ppDe[$c] ??= $cand[0];
    $pdo->prepare('UPDATE classes SET professeur_principal_id=? WHERE id=?')->execute([$ppDe[$c], $classes[$c]]);
    $pdo->prepare('UPDATE professeurs SET professeur_principal=? WHERE id=?')->execute([$c, $ppDe[$c]]);
}

// Élèves + parents. La famille Martin est la famille de démo : Léa (4A, demo.eleve) + son frère Hugo (6B).
$eleves = [];            // id => [prenom, nom, classe, niveauLatent, assiduite]
$elevesParClasse = [];
$parentsDe = [];         // eleveId => [parentIds]
$parentIds = []; $parentName = [];
$addEleve = function (string $p, string $n, string $classe, string $identifiant, string $pwd) use ($ins, $ETAB, &$eleves, &$elevesParClasse, $classesNoms, $startYear, $niveauIdx, $gauss): int {
    $age = 11 + $niveauIdx[$classesNoms[$classe]];
    $naiss = sprintf('%d-%02d-%02d', $startYear - $age - (mt_rand(1, 100) <= 4 ? 1 : 0), mt_rand(1, 12), mt_rand(1, 28));
    $id = $ins('eleves', ['etablissement_id' => $ETAB, 'nom' => $n, 'prenom' => $p, 'date_naissance' => $naiss, 'classe' => $classe,
        'lieu_naissance' => mt_rand(1, 100) <= 70 ? 'Val-Fleuri' : ['Lyon', 'Nantes', 'Lille', 'Toulouse', 'Bordeaux', 'Marseille'][mt_rand(0, 5)],
        'adresse' => mt_rand(1, 80) . ' rue des ' . ['Lilas', 'Écoles', 'Peupliers', 'Moulins', 'Vignes', 'Acacias', 'Tanneurs'][mt_rand(0, 6)] . ', Val-Fleuri',
        'mail' => $identifiant . '@eleve.victor-hugo.demo', 'identifiant' => $identifiant, 'mot_de_passe' => $pwd, 'actif' => 1, 'photo_consent' => mt_rand(1, 100) <= 92 ? 1 : 0]);
    // niveau latent (moyenne autour de laquelle gravitent ses notes) et assiduité (probabilité d'absence)
    $eleves[$id] = [$p, $n, $classe, max(6.0, min(18.8, $gauss(12.8, 3.0))), max(0.002, min(0.09, abs($gauss(0.018, 0.02))))];
    $elevesParClasse[$classe][] = $id;
    return $id;
};
$addParent = function (string $p, string $n, string $identifiant, string $pwd) use ($ins, $ETAB, $phone, $metiers, $pick, &$parentIds, &$parentName): int {
    $id = $ins('parents', ['etablissement_id' => $ETAB, 'nom' => $n, 'prenom' => $p, 'mail' => $identifiant . '@famille.demo', 'adresse' => 'Val-Fleuri',
        'telephone' => $phone(), 'metier' => $pick($metiers), 'identifiant' => $identifiant, 'mot_de_passe' => $pwd, 'est_parent_eleve' => 'non', 'actif' => 1]);
    $parentIds[] = $id; $parentName[$id] = "$p $n"; return $id;
};
$lien = function (int $parent, int $eleve) use ($ins, &$parentsDe): void {
    $ins('parent_eleve', ['id_parent' => $parent, 'id_eleve' => $eleve, 'lien' => 'parent']);
    $parentsDe[$eleve][] = $parent;
};
$lea  = $addEleve('Léa', 'Martin', '4A', 'demo.eleve', $hash);
$hugo = $addEleve('Hugo', 'Martin', '6B', $ident('Hugo', 'Martin'), $lockedHash);
$eleves[$lea][3] = 14.2; $eleves[$lea][4] = 0.012;  // bonne élève, quelques points faibles
$eleves[$hugo][3] = 11.2; $eleves[$hugo][4] = 0.03;
$parentDemo = $addParent('Nadia', 'Martin', 'demo.parent', $hash);
$pereMartin = $addParent('David', 'Martin', $ident('David', 'Martin'), $lockedHash);
foreach ([$lea, $hugo] as $e) { $lien($parentDemo, $e); $lien($pereMartin, $e); }

$prenomsMeres = ['Sandrine','Nathalie','Céline','Julie','Karine','Aurélie','Stéphanie','Laure','Fatima','Sonia','Émilie','Virginie','Hélène','Samira','Anne','Caroline','Delphine','Myriam'];
$prenomsPeres = ['Laurent','Stéphane','Nicolas','Christophe','Sébastien','Frédéric','David','Karim','Olivier','Mathieu','Jérôme','Arnaud','Yann','Rachid','Cédric','Fabrice'];
$fratries = []; // familles réutilisables (frères et sœurs dans d'autres niveaux)
foreach ($classesNoms as $classe => $niv) {
    $effectif = mt_rand(24, 27) - count($elevesParClasse[$classe] ?? []);
    for ($k = 0; $k < $effectif; $k++) {
        // ~10 % des élèves ont un frère ou une sœur déjà inscrit (même famille, autre niveau)
        $fam = null;
        if ($fratries && mt_rand(1, 100) <= 10) {
            $fam = $fratries[mt_rand(0, count($fratries) - 1)];
            if ($classesNoms[$fam['classe']] === $niv) { $fam = null; }
        }
        $fille = mt_rand(0, 1) === 1;
        $p = $pick($fille ? $prenomsF : $prenomsM);
        $n = $fam ? $fam['nom'] : $pick($noms);
        if ($n === 'Martin' && !$fam) { $n = 'Marchal'; }
        $eid = $addEleve($p, $n, $classe, $ident($p, $n), $lockedHash);
        if ($fam) {
            foreach ($fam['parents'] as $pid) { $lien($pid, $eid); }
            continue;
        }
        $ps = [];
        $mere = $addParent($pick($prenomsMeres), $n, $ident('p.' . $slug($p), $n), $lockedHash); $lien($mere, $eid); $ps[] = $mere;
        if (mt_rand(1, 100) <= 55) { $pere = $addParent($pick($prenomsPeres), $n, $ident('p2.' . $slug($p), $n), $lockedHash); $lien($pere, $eid); $ps[] = $pere; }
        $fratries[] = ['nom' => $n, 'classe' => $classe, 'parents' => $ps];
    }
}
$elevesIds = array_keys($eleves);
$nbEleves = count($eleves);
$eleveLabel = static fn(int $id) => $eleves[$id][0] . ' ' . $eleves[$id][1];
$log(sprintf('%d élèves, %d parents, %d professeurs, %d classes', $nbEleves, count($parentIds), count($profName), count($classes)));

// ── comptes unifiés + liens parent↔enfant (mêmes chemins que l'application) ──
(new \API\Services\AccountService($pdo))->syncFromLegacy();
$mig = dirname(__DIR__, 2) . '/database/migrations/2026_06_19_000001_backfill_account_relationships.php';
if (is_file($mig)) { ob_start(); (require $mig)->up($pdo); ob_end_clean(); }
$log('comptes unifiés et liens familiaux');
