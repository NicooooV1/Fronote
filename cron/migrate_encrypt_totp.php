<?php
/**
 * Migration one-shot : chiffrement at-rest des secrets TOTP (2FA) déjà activés en clair
 * avant l'ajout du chiffrement. Idempotent (encryptIfPlain saute les valeurs déjà chiffrées).
 *
 * Usage : php cron/migrate_encrypt_totp.php   (CLI uniquement, nécessite APP_KEY/JWT_SECRET)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("CLI only\n"); }

require_once __DIR__ . '/../API/bootstrap.php';

use API\Core\Encryption;

if (!Encryption::available()) {
    fwrite(STDERR, "[migrate-totp] Encryption indisponible (APP_KEY/JWT_SECRET manquant) — abandon.\n");
    exit(1);
}

$pdo = getPDO();
$enc = new Encryption();

// Tables portant un secret TOTP (schéma multi-rôles).
$tables = ['administrateurs', 'professeurs', 'eleves', 'parents', 'vie_scolaire'];
$total = 0;
foreach ($tables as $table) {
    try {
        // Ne traiter que les comptes avec 2FA active et un secret non vide.
        $rows = $pdo->query("SELECT id, two_factor_secret FROM `{$table}` WHERE two_factor_secret IS NOT NULL AND two_factor_secret <> ''")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        echo "[migrate-totp] {$table} : ignorée (" . $e->getMessage() . ")\n";
        continue;
    }
    $changed = 0;
    foreach ($rows as $row) {
        $v = (string) $row['two_factor_secret'];
        if ($enc->isEncrypted($v)) continue; // déjà chiffré
        $encVal = $enc->encryptIfPlain($v);
        $stmt = $pdo->prepare("UPDATE `{$table}` SET two_factor_secret = ? WHERE id = ?");
        $stmt->execute([$encVal, $row['id']]);
        $changed++; $total++;
    }
    echo sprintf("[migrate-totp] %-16s : %d secret(s) chiffré(s)\n", $table, $changed);
}
echo "[migrate-totp] Terminé. Total : {$total}\n";
