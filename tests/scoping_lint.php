<?php
/**
 * Linter CI : tout Service / Repository / WidgetProvider de module métier doit
 * scoper ses requêtes par etablissement_id (ou explicitement opter pour un état
 * global via la marque @global-scope dans son docblock).
 *
 * Le linter est volontairement simple : il scanne `modules/<m>/includes/*Service.php`,
 * `*Repository.php`, `*Provider.php` et vérifie la présence d'un des marqueurs.
 * Faux positifs : whitelist explicite ci-dessous pour les services qui sont par
 * conception cross-établissement (marketplace, notifications globales).
 *
 * Exit 0 si tout est OK, 1 sinon. À ajouter à .github/workflows/validate.yml.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);

/** Services dont l'absence de scope est volontaire (registre global, etc.). */
$whitelist = [
    'modules/marketplace/includes',          // marketplace côté instance = global
    'modules/onboarding',                    // assistant de mise en route
    'modules/hello_world',                   // module de démonstration (non déployé)
    // Sous-classes vides déléguant à un parent DÉJÀ scopé (API\Services\Scolaire\*) :
    'modules/tableau_de_bord/Services/AdminDashboardService.php',
    'modules/tableau_de_bord/Services/ClasseService.php',
];

$markers = ['etablissement_id', 'EstablishmentContext', '@global-scope'];

$paths = [];
// Couvre les deux conventions : legacy includes/ ET services namespacés Services/.
$globRoots = [
    "{$root}/modules/*/includes",
    "{$root}/modules/*/Services",
];
foreach (['*Service.php', '*Repository.php', '*Provider.php'] as $suffix) {
    foreach ($globRoots as $gr) {
        foreach (glob("{$gr}/{$suffix}") ?: [] as $p) {
            $paths[] = $p;
        }
    }
}
$paths = array_values(array_unique($paths));

$problems = [];
foreach ($paths as $abs) {
    $rel = str_replace('\\', '/', substr($abs, strlen($root) + 1));
    foreach ($whitelist as $allowed) {
        if (str_starts_with($rel, $allowed)) continue 2;
    }
    $content = (string) @file_get_contents($abs);
    $matched = false;
    foreach ($markers as $m) {
        if (strpos($content, $m) !== false) { $matched = true; break; }
    }
    if (!$matched) {
        $problems[] = $rel;
    }
}

if (!empty($problems)) {
    echo "Services/Repositories/Providers de module qui n'évoquent jamais l'établissement courant :\n";
    foreach ($problems as $p) echo "  - {$p}\n";
    echo "\nAjoutez \\API\\Core\\EstablishmentContext::id() là où ça filtre, ou marquez\n";
    echo "le fichier @global-scope dans son docblock si l'absence est volontaire.\n";
    exit(1);
}

echo count($paths) . " service(s)/repository/provider(s) — scope OK.\n";
