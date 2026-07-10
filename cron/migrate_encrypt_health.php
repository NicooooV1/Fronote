<?php
/**
 * Migration one-shot : chiffrement at-rest des champs médicaux libres existants (RGPD Art. 9).
 *
 * Chiffre les lignes déjà en base qui étaient en CLAIR avant l'activation du chiffrement
 * (fiches_sante / passages_infirmerie / infirmerie_traitements). Idempotent : encryptIfPlain()
 * saute toute valeur déjà chiffrée → rejouable sans risque de double-chiffrement.
 *
 * Usage :  php cron/migrate_encrypt_health.php
 * (CLI uniquement ; nécessite APP_KEY/JWT_SECRET pour que Encryption soit disponible.)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI only\n");
}

require_once __DIR__ . '/../API/bootstrap.php';

use API\Core\Encryption;

if (!Encryption::available()) {
    fwrite(STDERR, "[migrate] Encryption indisponible (APP_KEY/JWT_SECRET manquant) — abandon.\n");
    exit(1);
}

$pdo = getPDO();
$enc = new Encryption();

/** Chiffre en place les champs libres d'une table, ligne par ligne (idempotent). */
$migrateTable = function (string $table, array $fields) use ($pdo, $enc): array {
    $cols = implode(', ', $fields);
    $rows = $pdo->query("SELECT id, {$cols} FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);
    $rowsChanged = 0; $valsEncrypted = 0;
    foreach ($rows as $row) {
        $set = []; $params = [];
        foreach ($fields as $f) {
            $v = $row[$f] ?? null;
            if ($v === null || $v === '') continue;
            if ($enc->isEncrypted($v)) continue; // déjà chiffré → skip (idempotence)
            $set[] = "{$f} = ?";
            $params[] = $enc->encryptIfPlain($v);
            $valsEncrypted++;
        }
        if (!$set) continue;
        $params[] = $row['id'];
        $stmt = $pdo->prepare("UPDATE {$table} SET " . implode(', ', $set) . " WHERE id = ?");
        $stmt->execute($params);
        $rowsChanged++;
    }
    return [$rowsChanged, $valsEncrypted, count($rows)];
};

$plan = [
    'fiches_sante'          => ['allergies', 'traitements', 'contact_urgence', 'pai', 'pai_details', 'observations', 'pathologies', 'antecedents'],
    'passages_infirmerie'   => ['symptomes', 'soins_prodigues', 'observations'],
    'infirmerie_traitements'=> ['medicament', 'posologie'],
];

$totalVals = 0;
foreach ($plan as $table => $fields) {
    try {
        [$rowsChanged, $vals, $seen] = $migrateTable($table, $fields);
        $totalVals += $vals;
        echo sprintf("[migrate] %-24s : %d lignes vues, %d lignes modifiées, %d valeurs chiffrées\n",
            $table, $seen, $rowsChanged, $vals);
    } catch (\Throwable $e) {
        fwrite(STDERR, "[migrate] {$table} : ERREUR " . $e->getMessage() . "\n");
    }
}
echo "[migrate] Terminé. Total valeurs chiffrées : {$totalVals}\n";
