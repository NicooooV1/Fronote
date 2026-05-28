<?php
/**
 * Synchronise les manifestes module.json (modules_config, widgets, permissions,
 * settings_schema) puis exécute les migrations SQL en attente de chaque module
 * (table module_migrations — idempotent, ne réexécute jamais une migration).
 *
 * Usage CLI : php scripts/migrate.php
 * Appelé automatiquement par scripts/update.php après chaque déploiement.
 *
 * Codes de sortie : 0 = succès, 1 = au moins une erreur (déploiement non bloqué).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/API/bootstrap.php';

/** @var \API\Services\ModuleSDK $sdk */
$sdk = app('module_sdk');

$sync     = $sdk->syncAll();
$executed = 0;
$skipped  = 0;
$errors   = $sync['errors'] ?? [];

foreach (array_keys($sdk->discover()) as $key) {
    $r = $sdk->migrate($key, 'cli');
    $executed += count($r['executed']);
    $skipped  += count($r['skipped']);
    $errors    = array_merge($errors, $r['errors']);
}

fwrite(STDOUT, sprintf(
    "Modules synchronisés: %d | migrations exécutées: %d | déjà à jour: %d | erreurs: %d\n",
    $sync['synced'] ?? 0,
    $executed,
    $skipped,
    count($errors)
));
foreach ($errors as $e) {
    fwrite(STDERR, '  - ' . $e . "\n");
}

exit(empty($errors) ? 0 : 1);
