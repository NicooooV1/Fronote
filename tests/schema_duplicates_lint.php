<?php
/**
 * Linter de schéma — échoue s'il existe des tables déclarées dans PLUSIEURS sources .sql
 * (pronote.sql, chaque modules/<m>/Database/install.sql, rgpd/Database) avec des JEUX DE COLONNES
 * DIVERGENTS. SchemaSyncService fusionne aujourd'hui ces doublons silencieusement (union,
 * 1er CREATE gagne), ce qui masque toute dérive réelle (thème 4 de la revue de design).
 *
 * Usage : php tests/schema_duplicates_lint.php   → exit 0 si propre, 1 sinon.
 *
 * NB : à câbler en CI BLOQUANTE une fois les sources dédupliquées (source unique par table).
 * En l'état il reste des doublons hérités : le script les LISTE pour pilotage.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("CLI only\n"); }

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

// duplicateTables() ne lit que des fichiers ; le PDO n'est qu'un artefact du constructeur.
$svc  = new \API\Services\SchemaSyncService(new PDO('sqlite::memory:'), $root);
$dups = $svc->duplicateTables();

if (!$dups) {
    fwrite(STDOUT, "OK — aucune table dupliquée à colonnes divergentes.\n");
    exit(0);
}

fwrite(STDERR, "DÉRIVE DE SCHÉMA — " . count($dups) . " table(s) déclarée(s) dans plusieurs sources avec des colonnes divergentes :\n");
foreach ($dups as $name => $info) {
    fwrite(STDERR, sprintf(
        "  - %-34s sources=[%s]  divergentes=[%s]\n",
        $name,
        implode(', ', $info['sources']),
        implode(', ', $info['divergent_columns'])
    ));
}
fwrite(STDERR, "\nCible : UNE seule source .sql par table (thème 4 — source unique de schéma).\n");
exit(1);
