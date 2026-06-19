<?php
/**
 * Backfill des comptes unifiés (table `accounts`) depuis les tables héritées.
 *
 *   php scripts/backfill_accounts.php          # nécessite FEATURE_ACCOUNTS=true
 *   php scripts/backfill_accounts.php --force   # force même si le flag est faux
 *
 * OPÉRATION INERTE pour l'authentification : elle ne fait que MIROITER les comptes
 * existants dans `accounts` (idempotent via la clé unique legacy_type+legacy_id).
 * Ne touche NI au login NI aux tables héritées. À jouer avant un futur basculement.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../API/bootstrap.php';

use API\Services\AccountService;

$force = in_array('--force', $argv, true);
if (!AccountService::isEnabled() && !$force) {
    fwrite(STDERR, "FEATURE_ACCOUNTS désactivé. Relancez avec --force pour forcer le miroir.\n");
    exit(2);
}

$pdo = getPDO();
$svc = new AccountService($pdo);

// table héritée => [account_type, profile_type]
$map = [
    'super_admins'      => ['platform',  'system'],
    'administrateurs'   => ['personnel', 'personnel'],
    'professeurs'       => ['personnel', 'personnel'],
    'vie_scolaire'      => ['personnel', 'personnel'],
    'eleves'            => ['student',   'student'],
    'parents'           => ['family',    'family'],
    'technicien_access' => ['temporary', 'system'],
];

$created = 0; $skipped = 0; $errors = 0;
foreach ($map as $table => [$accountType, $profileType]) {
    try {
        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        fwrite(STDERR, "  (table {$table} absente, ignorée)\n");
        continue;
    }
    foreach ($rows as $r) {
        $legacyId = (int) ($r['id'] ?? 0);
        if ($legacyId <= 0) { continue; }
        $legacyType = rtrim($table, 's');                       // eleves→eleve, parents→parent…
        $legacyType = $table === 'technicien_access' ? 'technicien' : ($table === 'vie_scolaire' ? 'vie_scolaire' : $legacyType);
        $legacyType = $table === 'super_admins' ? 'super_admin' : $legacyType;
        if ($svc->findByLegacy($legacyType, $legacyId)) { $skipped++; continue; }
        try {
            $svc->createAccount([
                'account_type'     => $accountType,
                'username'         => $r['identifiant'] ?? ('legacy_' . $legacyType . '_' . $legacyId),
                'email'            => $r['mail'] ?? ($r['email'] ?? null),
                'first_name'       => $r['prenom'] ?? null,
                'last_name'        => $r['nom'] ?? null,
                'etablissement_id' => $r['etablissement_id'] ?? null,
                'status'           => (isset($r['actif']) && (int) $r['actif'] === 0) ? 'inactive' : 'active',
                'legacy_type'      => $legacyType,
                'legacy_id'        => $legacyId,
                'must_change_password' => 0,
            ]);
            $created++;
        } catch (\Throwable $e) {
            $errors++;
            error_log("[backfill_accounts] {$legacyType}#{$legacyId} : " . $e->getMessage());
        }
    }
}

echo "Comptes créés : {$created} · déjà présents : {$skipped} · erreurs : {$errors}\n";
