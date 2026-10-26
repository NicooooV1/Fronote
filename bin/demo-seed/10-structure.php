<?php
/** Démo — structure : établissement, année, périodes, créneaux, salles, matières. */
declare(strict_types=1);

$pdo->prepare("UPDATE etablissements SET nom=?, slogan=?, code=?, type='college', adresse=?, code_postal=?, ville=?,
    telephone=?, email=?, chef_etablissement=?, academie=?, code_uai=?, annee_scolaire=?, status='active', actif=1, slug=?
    WHERE id=?")->execute([
    'Collège Victor-Hugo', 'Apprendre, grandir, réussir ensemble', 'college-victor-hugo', '12 avenue des Tilleuls', '00000',
    'Val-Fleuri', '01 00 00 00 00', 'contact@victor-hugo.demo', 'Claire Delmas', 'Académie de démonstration',
    '0000000X', $annee, 'college-victor-hugo', $ETAB,
]);
$wipe('annees_scolaires', 'periodes', 'creneaux_horaires', 'salles', 'matieres');
foreach ([[$anneePrec, 0], [$annee, 1]] as [$code, $actif]) {
    [$a1, $a2] = explode('-', $code);
    $ins('annees_scolaires', ['etablissement_id' => $ETAB, 'code' => $code, 'libelle' => "Année $code", 'date_debut' => "$a1-09-01", 'date_fin' => "$a2-07-04", 'actif' => $actif]);
}
foreach ($periodes as [$n, $nom, $deb, $fin]) {
    $ins('periodes', ['etablissement_id' => $ETAB, 'numero' => $n, 'nom' => $nom, 'type' => 'trimestre', 'date_debut' => $deb, 'date_fin' => $fin, 'annee_scolaire' => $annee]);
}
$periodeIds = $pdo->query('SELECT numero, id FROM periodes')->fetchAll(PDO::FETCH_KEY_PAIR);

$creneauxDef = [
    ['M1', '08:00', '08:55', 'cours'], ['M2', '08:55', '09:50', 'cours'], ['Récré', '09:50', '10:05', 'pause'],
    ['M3', '10:05', '11:00', 'cours'], ['M4', '11:00', '11:55', 'cours'], ['Déjeuner', '11:55', '13:30', 'repas'],
    ['S1', '13:30', '14:25', 'cours'], ['S2', '14:25', '15:20', 'cours'], ['Récré', '15:20', '15:35', 'pause'],
    ['S3', '15:35', '16:30', 'cours'], ['S4', '16:30', '17:25', 'cours'],
];
$creneaux = []; // index => [id, debut, fin, label] (cours uniquement)
foreach ($creneauxDef as $i => [$label, $deb, $fin, $type]) {
    $id = $ins('creneaux_horaires', ['etablissement_id' => $ETAB, 'label' => $label, 'heure_debut' => $deb, 'heure_fin' => $fin, 'ordre' => $i + 1, 'type' => $type]);
    if ($type === 'cours') { $creneaux[] = [$id, $deb, $fin, $label]; }
}

$salles = []; $salleNom = [];
foreach (['101','102','103','104','105','106','107','108','201','202','203','204','205','206','207','208'] as $n) {
    $id = $ins('salles', ['etablissement_id' => $ETAB, 'nom' => "Salle $n", 'batiment' => $n[0] === '1' ? 'Bâtiment A' : 'Bâtiment B', 'capacite' => 30, 'type' => 'cours', 'equipements' => 'Vidéoprojecteur, tableau blanc', 'actif' => 1]);
    $salles['std'][] = $id; $salleNom[$id] = "Salle $n";
}
foreach ([
    ['SVT', 'Labo SVT 1', 'laboratoire', 'Paillasses, microscopes'], ['SVT', 'Labo SVT 2', 'laboratoire', 'Paillasses, loupes binoculaires'],
    ['PC', 'Labo Physique-Chimie 1', 'laboratoire', 'Paillasses, hotte'], ['PC', 'Labo Physique-Chimie 2', 'laboratoire', 'Paillasses, oscilloscopes'],
    ['TEC', 'Salle Techno 1', 'informatique', '24 postes, imprimante 3D'], ['TEC', 'Salle Techno 2', 'informatique', '24 postes, découpe laser'],
    ['EPS', 'Gymnase', 'sport', 'Terrain multisport'], ['EPS', 'Plateau sportif', 'sport', 'Piste, terrain de hand'],
    ['ART', 'Salle d\'arts', 'arts', 'Éviers, chevalets'], ['MUS', 'Salle de musique', 'musique', 'Piano, sono'],
] as [$k, $nom, $type, $eq]) {
    $id = $ins('salles', ['etablissement_id' => $ETAB, 'nom' => $nom, 'batiment' => $k === 'EPS' ? 'Complexe sportif' : 'Bâtiment C', 'capacite' => $k === 'EPS' ? 60 : 26, 'type' => $type, 'equipements' => $eq, 'actif' => 1]);
    $salles[$k][] = $id; $salleNom[$id] = $nom;
}
foreach ([['CDI', 'cdi', 40, '3 000 ouvrages, 8 postes'], ['Salle polyvalente', 'reunion', 120, 'Estrade, sono, 120 chaises'], ['Salle des professeurs', 'reunion', 40, 'Photocopieur, casiers'], ['Salle de réunion', 'reunion', 16, 'Écran, visioconférence'], ['Permanence', 'etude', 60, 'Tables individuelles']] as [$nom, $type, $cap, $eq]) {
    $id = $ins('salles', ['etablissement_id' => $ETAB, 'nom' => $nom, 'batiment' => 'Bâtiment A', 'capacite' => $cap, 'type' => $type, 'equipements' => $eq, 'actif' => 1]);
    $salles[$type][] = $id; $salleNom[$id] = $nom;
}

// code => [nom, coef, couleur, heures/semaine par niveau [6e,5e,4e,3e], classes max par professeur]
$matDef = [
    'FRA' => ['Français', 1.0, '#2f5d50', [5, 4, 4, 4], 4],
    'MAT' => ['Mathématiques', 1.0, '#16243f', [4, 4, 4, 4], 4],
    'HG'  => ['Histoire-Géographie', 1.0, '#b9772a', [3, 3, 3, 3], 6],
    'ANG' => ['Anglais', 1.0, '#7a3e65', [4, 3, 3, 3], 6],
    'ESP' => ['Espagnol', 1.0, '#c0573e', [0, 2, 2, 2], 7],
    'SVT' => ['SVT', 1.0, '#4f8a3a', [2, 2, 2, 2], 8],
    'PC'  => ['Physique-Chimie', 1.0, '#3b6ea8', [0, 2, 2, 2], 8],
    'TEC' => ['Technologie', 1.0, '#5b6472', [2, 2, 2, 2], 8],
    'ART' => ['Arts plastiques', 1.0, '#a35d8f', [1, 1, 1, 1], 16],
    'MUS' => ['Éducation musicale', 1.0, '#8c6d1f', [1, 1, 1, 1], 16],
    'EPS' => ['EPS', 1.0, '#2a8c82', [4, 3, 3, 3], 6],
];
$mat = []; $matNom = [];
foreach ($matDef as $code => [$nom, $coef, $coul]) {
    $mat[$code] = $ins('matieres', ['etablissement_id' => $ETAB, 'nom' => $nom, 'code' => $code, 'coefficient' => $coef, 'couleur' => $coul, 'actif' => 1]);
    $matNom[$mat[$code]] = $nom;
}
$log('structure : ' . count($creneaux) . ' créneaux, ' . count($salleNom) . ' salles, ' . count($mat) . ' matières');
