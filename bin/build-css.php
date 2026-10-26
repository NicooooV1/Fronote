<?php
declare(strict_types=1);
/**
 * Assemble assets/css/design-system.css (34 @import) en un seul fichier
 * assets/css/design-system.bundle.css, dans le même ordre (= même cascade).
 *
 * Pourquoi : les @import sont découverts en série par le navigateur (une 2e vague de requêtes
 * après le téléchargement de design-system.css) et ne sont pas versionnés.
 * shared_header.php sert l'assemblage tant qu'il est plus récent que toutes ses sources,
 * sinon il retombe sur design-system.css : relancer ce script après toute modification
 * du design system (fait aussi par la publication).
 *
 * Usage : php bin/build-css.php [--check]   (--check : code 1 si l'assemblage est périmé)
 */
$root  = dirname(__DIR__);
$entry = $root . '/assets/css/design-system.css';
$out   = $root . '/assets/css/design-system.bundle.css';

$src = file_get_contents($entry);
if ($src === false) { fwrite(STDERR, "entrée introuvable\n"); exit(2); }
preg_match_all('/@import\s+url\(\s*["\']?([^"\')]+)["\']?\s*\)\s*;/', $src, $m);
if (!$m[1]) { fwrite(STDERR, "aucun @import trouvé\n"); exit(2); }

$parts = ["/* FRONOTE — design system assemblé par bin/build-css.php — NE PAS MODIFIER : éditer les sources. */\n"];
foreach ($m[1] as $rel) {
    $file = dirname($entry) . '/' . $rel;
    $css = file_get_contents($file);
    if ($css === false) { fwrite(STDERR, "source manquante : $rel\n"); exit(2); }
    if (preg_match('/url\(\s*["\']?(?!data:|https?:|\/)/i', $css)) {
        fwrite(STDERR, "url() relative dans $rel : chemins à réécrire avant d'assembler\n"); exit(2);
    }
    if (stripos($css, '@import') !== false) { fwrite(STDERR, "@import imbriqué dans $rel\n"); exit(2); }
    $parts[] = "\n/* ── $rel ── */\n" . rtrim($css) . "\n";
}
$bundle = implode('', $parts);

if (in_array('--check', $argv, true)) {
    $cur = @file_get_contents($out);
    if ($cur !== $bundle) { fwrite(STDERR, "design-system.bundle.css périmé : lancer php bin/build-css.php\n"); exit(1); }
    echo "design-system.bundle.css à jour\n";
    exit(0);
}
file_put_contents($out, $bundle);
echo 'design-system.bundle.css : ' . count($m[1]) . ' fichiers, ' . round(strlen($bundle) / 1024) . " Ko\n";
