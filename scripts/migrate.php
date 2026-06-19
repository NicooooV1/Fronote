<?php
/**
 * Migrations versionnées — interface CLI.
 *
 *   php scripts/migrate.php            # status (par défaut)
 *   php scripts/migrate.php migrate    # applique les migrations en attente
 *   php scripts/migrate.php rollback   # annule la dernière fournée (batch)
 *
 * Les migrations vivent dans database/migrations/<timestamp>_<nom>.php et exposent
 * up(PDO) / down(PDO). Voir API/Services/MigrationRunner.php.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../API/bootstrap.php';

use API\Services\MigrationRunner;

$cmd    = $argv[1] ?? 'status';
$runner = new MigrationRunner(getPDO(), dirname(__DIR__));

switch ($cmd) {
    case 'migrate':
        $r = $runner->migrate();
        echo 'Appliquées : ' . (implode(', ', $r['applied']) ?: '(aucune)') . "\n";
        if (!empty($r['errors'])) { fwrite(STDERR, 'Erreurs : ' . implode(' ; ', $r['errors']) . "\n"); exit(1); }
        break;

    case 'rollback':
        $r = $runner->rollback();
        echo 'Annulées : ' . (implode(', ', $r['reverted']) ?: '(aucune)') . "\n";
        if (!empty($r['errors'])) { fwrite(STDERR, 'Erreurs : ' . implode(' ; ', $r['errors']) . "\n"); exit(1); }
        break;

    case 'status':
    default:
        $s = $runner->status();
        echo 'Appliquées (' . count($s['applied']) . ') : ' . (implode(', ', $s['applied']) ?: '-') . "\n";
        echo 'En attente (' . count($s['pending']) . ') : ' . (implode(', ', $s['pending']) ?: '-') . "\n";
        break;
}
