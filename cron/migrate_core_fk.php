<?php
/**
 * Migration one-shot : complétude des clés étrangères de cloisonnement
 * (etablissement_id → etablissements.id) sur les tables cœur qui en manquent.
 *
 * SÛR : pour chaque table candidate, on n'ajoute la FK QUE s'il n'existe aucune ligne
 * orpheline (etablissement_id sans établissement correspondant) — sinon l'ALTER échouerait
 * et casserait la table. Idempotent (ignore les tables ayant déjà une FK sur la colonne).
 *
 * Usage : php cron/migrate_core_fk.php   (CLI uniquement)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("CLI only\n"); }

require_once __DIR__ . '/../API/bootstrap.php';

$pdo = getPDO();
$db  = $pdo->query('SELECT DATABASE()')->fetchColumn();

// Tables avec colonne etablissement_id de type entier (candidates FK).
$cand = $pdo->prepare("
    SELECT TABLE_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'etablissement_id' AND DATA_TYPE IN ('int','bigint','smallint')
");
$cand->execute([$db]);
$tables = $cand->fetchAll(PDO::FETCH_COLUMN);

// Tables ayant déjà une FK sur etablissement_id.
$withFk = $pdo->prepare("
    SELECT DISTINCT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'etablissement_id' AND REFERENCED_TABLE_NAME IS NOT NULL
");
$withFk->execute([$db]);
$hasFk = array_flip($withFk->fetchAll(PDO::FETCH_COLUMN));

$added = 0; $skippedOrphans = 0;
foreach ($tables as $t) {
    if ($t === 'etablissements' || isset($hasFk[$t])) continue;
    try {
        // Lignes orphelines (hors NULL) ?
        $orph = $pdo->query("
            SELECT COUNT(*) FROM `{$t}` t
            LEFT JOIN etablissements e ON t.etablissement_id = e.id
            WHERE t.etablissement_id IS NOT NULL AND e.id IS NULL
        ")->fetchColumn();
        if ((int) $orph > 0) {
            echo "[fk] {$t} : {$orph} ligne(s) orpheline(s) → FK NON ajoutée (à nettoyer d'abord)\n";
            $skippedOrphans++;
            continue;
        }
        $fk = 'fk_etab_' . substr($t, 0, 50);
        $pdo->exec("ALTER TABLE `{$t}` ADD CONSTRAINT `{$fk}` FOREIGN KEY (`etablissement_id`) REFERENCES `etablissements`(`id`) ON DELETE CASCADE");
        echo "[fk] {$t} : FK etablissement_id ajoutée\n";
        $added++;
    } catch (\Throwable $e) {
        echo "[fk] {$t} : ignoré (" . substr($e->getMessage(), 0, 80) . ")\n";
    }
}
echo "[fk] Terminé. FK ajoutées : {$added} | tables à orphelins ignorées : {$skippedOrphans} | candidates : " . count($tables) . "\n";
