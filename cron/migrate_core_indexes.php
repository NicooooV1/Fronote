<?php
/**
 * Migration one-shot : complétude des index de cloisonnement (etablissement_id) sur les
 * tables cœur. Additif et idempotent (ne crée l'index que s'il manque). Améliore les
 * requêtes scopées par établissement (multi-tenant) sur toutes les tables.
 *
 * Usage : php cron/migrate_core_indexes.php   (CLI uniquement)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("CLI only\n"); }

require_once __DIR__ . '/../API/bootstrap.php';

$pdo = getPDO();
$db  = $pdo->query('SELECT DATABASE()')->fetchColumn();

// Tables possédant une colonne etablissement_id.
$withCol = $pdo->prepare("
    SELECT DISTINCT TABLE_NAME
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'etablissement_id'
");
$withCol->execute([$db]);
$tables = $withCol->fetchAll(PDO::FETCH_COLUMN);

// Tables ayant déjà un index dont la 1re colonne est etablissement_id.
$indexed = $pdo->prepare("
    SELECT DISTINCT TABLE_NAME
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'etablissement_id' AND SEQ_IN_INDEX = 1
");
$indexed->execute([$db]);
$already = array_flip($indexed->fetchAll(PDO::FETCH_COLUMN));

$added = 0;
foreach ($tables as $t) {
    if (isset($already[$t])) continue;
    try {
        // Nom d'index sûr, borné à 64 caractères.
        $idx = 'idx_etab_' . substr($t, 0, 50);
        $pdo->exec("ALTER TABLE `{$t}` ADD INDEX `{$idx}` (`etablissement_id`)");
        echo "[idx] {$t} : index etablissement_id ajouté\n";
        $added++;
    } catch (\Throwable $e) {
        echo "[idx] {$t} : ignoré (" . $e->getMessage() . ")\n";
    }
}
echo "[idx] Terminé. Index ajoutés : {$added} (sur " . count($tables) . " tables scopées).\n";
