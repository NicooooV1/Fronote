<?php
/**
 * bin/upgrade.php — réconciliation post-mise-à-jour (headless, à lancer en www-data).
 *
 * Appelé par update.sh APRÈS la bascule des fichiers (socle + modules signés). Réplique
 * les étapes non-git de UpdateService::applyUpdate() : schéma déclaratif idempotent,
 * migrations versionnées, re-sync des manifestes de modules, purge du cache. Aucun
 * téléchargement, aucun git : le code neuf est déjà en place.
 *
 * Sortie : code 0 si tout est OK, 1 si le schéma/les migrations ont échoué (update.sh
 * en tiendra compte). Idempotent : peut être relancé sans risque.
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement.\n");
}

require_once dirname(__DIR__) . '/API/bootstrap.php';

use API\Services\SchemaSyncService;
use API\Services\MigrationRunner;

$base = dirname(__DIR__);
$log = static fn(string $m) => print('[' . date('Y-m-d H:i:s') . '] [upgrade] ' . $m . "\n");
$errors = [];

// 1) Schéma SQL déclaratif (crée tables/colonnes manquantes ; idempotent).
try {
    $schema = (new SchemaSyncService(getPDO(), $base))->sync();
    $log(sprintf('Schéma : %d vérifiée(s), %d créée(s), %d modifiée(s)',
        $schema['checked'] ?? 0, count($schema['created'] ?? []), count($schema['altered'] ?? [])));
    foreach (($schema['errors'] ?? []) as $e) { $errors[] = 'schema: ' . $e; }
} catch (\Throwable $e) {
    $errors[] = 'schema: ' . $e->getMessage();
    $log('Schéma : ÉCHEC — ' . $e->getMessage());
}

// 2) Migrations versionnées (transformations hors portée du schéma déclaratif).
try {
    $mig = (new MigrationRunner(getPDO(), $base))->migrate();
    if (!empty($mig['applied'])) { $log('Migrations appliquées : ' . implode(', ', $mig['applied'])); }
    foreach (($mig['errors'] ?? []) as $e) { $errors[] = 'migration: ' . $e; }
} catch (\Throwable $e) {
    $errors[] = 'migration: ' . $e->getMessage();
    $log('Migrations : ÉCHEC — ' . $e->getMessage());
}

if ($errors) {
    // On NE poursuit pas la sync de modules si le schéma est cassé : update.sh restaurera
    // la base depuis la sauvegarde qu'il a prise avant la bascule.
    $log('Réconciliation en erreur : ' . implode(' ; ', $errors));
    exit(1);
}

// 3) Re-synchronise les manifestes de modules (permissions, widgets, routes, nouveaux modules).
try {
    $r = app('module_sdk')->syncAll();
    $log('Modules synchronisés : ' . ($r['synced'] ?? 0));
} catch (\Throwable $e) {
    $log('Sync modules : échec (non bloquant) — ' . $e->getMessage());
}

// 4) Purge du cache applicatif (best-effort).
try {
    $cache = app('cache');
    if (method_exists($cache, 'flush')) { $cache->flush(); $log('Cache vidé.'); }
    elseif (method_exists($cache, 'clear')) { $cache->clear(); $log('Cache vidé.'); }
} catch (\Throwable $e) {
    $log('Purge cache : ignorée — ' . $e->getMessage());
}

$vf = $base . '/version.json';
$ver = is_file($vf) ? (json_decode((string) file_get_contents($vf), true)['version'] ?? '?') : '?';
$log('Réconciliation terminée — version ' . $ver);
exit(0);
