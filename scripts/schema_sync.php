<?php
/**
 * Synchronisation déclarative du schéma — interface CLI.
 *
 *   php scripts/schema_sync.php
 *
 * Applique SchemaSyncService : lit pronote.sql + les install.sql des modules et
 * crée les tables MANQUANTES / ajoute les colonnes manquantes (ADD-ONLY, idempotent,
 * ne supprime ni ne modifie jamais l'existant). Même mécanisme que la mise à jour
 * « un bouton » (UpdateService), exposé seul pour appliquer un nouveau schéma sans
 * toucher au code. Voir API/Services/SchemaSyncService.php.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../API/bootstrap.php';

use API\Services\SchemaSyncService;

$svc = new SchemaSyncService(getPDO(), dirname(__DIR__));
$r   = $svc->sync();

echo 'Tables créées (' . count($r['created'] ?? []) . ') : ' . (implode(', ', $r['created'] ?? []) ?: '-') . "\n";
$alteredFmt = array_map(static fn($a) => is_array($a) ? implode('.', array_map('strval', $a)) : (string) $a, $r['altered'] ?? []);
echo 'Colonnes ajoutées (' . count($alteredFmt) . ') : ' . (implode(', ', $alteredFmt) ?: '-') . "\n";
if (!empty($r['errors'])) {
    fwrite(STDERR, 'Erreurs : ' . implode(' ; ', $r['errors']) . "\n");
    exit(1);
}
echo "OK\n";
