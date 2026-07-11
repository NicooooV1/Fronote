<?php
/**
 * Cron Job — Maintenance quotidienne
 *
 * Tâches (toutes best-effort : un échec n'interrompt jamais les suivantes) :
 *   1. Backup automatique de la base de données (BackupService)
 *   2. Rotation des backups (garder les N derniers — BACKUP_RETENTION)
 *   3. Purge des audit logs expirés (AUDIT_RETENTION_DAYS)
 *   4. Purge de la file d'e-mails traitée (email_log + corps storage/email_queue)
 *   5. Nettoyage du cache expiré (CacheManager::gc)
 *   6. Nettoyage de storage/tmp (fichiers > 24h)
 *   7. Nettoyage des reliquats de quarantaine (storage/quarantine, > 30 j)
 *   8. Rafraîchit le miroir d'identité `accounts` depuis les tables héritées (additif, sans bascule d'auth)
 *
 * Configurer dans crontab :
 *   0 2 * * * php /chemin/vers/fronote/cron/daily_maintenance.php >> /chemin/vers/fronote/API/logs/cron.log 2>&1
 */
declare(strict_types=1);

// Ne pas exécuter depuis le web
if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit('This script must be run from the command line.');
}

require_once dirname(__DIR__) . '/API/bootstrap.php';

$startTime = microtime(true);
$log = function (string $msg): void {
	echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
};

$log('=== Fronote Daily Maintenance ===');

// 1. Full backup (base de données + fichiers uploadés : documents, justificatifs,
//    PDF — données personnelles qui doivent pouvoir être restaurées, pas seulement
//    le schéma). createFullBackup() = createDatabaseBackup() + createUploadsBackup().
$backup = null;
try {
	$backup = app('backup');
	$full = $backup->createFullBackup();
	$dbFile = $full['db'] ?? null;
	$dbSize = ($dbFile && is_file($dbFile)) ? round(filesize($dbFile) / 1048576, 2) : 0;
	$log("Backup DB: created {$dbFile} ({$dbSize} MB)");
	if (!empty($full['uploads']) && is_file($full['uploads'])) {
		$upSize = round(filesize($full['uploads']) / 1048576, 2);
		$log("Backup uploads: created {$full['uploads']} ({$upSize} MB)");
	} else {
		$log('Backup uploads: skipped (no uploads dir or ZipArchive unavailable)');
	}
	// Copie hors-hôte (offsite) : survivre à la perte du serveur. Configurer
	// BACKUP_OFFSITE_DIR (montage distant/rsync/S3-fuse). Best-effort.
	$offsite = getenv('BACKUP_OFFSITE_DIR') ?: '';
	if ($offsite !== '' && is_dir($offsite) && is_writable($offsite)) {
		foreach (array_filter([$dbFile, $full['uploads'] ?? null]) as $bf) {
			if (is_file($bf) && @copy($bf, rtrim($offsite, '/') . '/' . basename($bf))) {
				$log('Backup offsite: copied ' . basename($bf) . ' -> ' . $offsite);
			} else {
				$log('Backup offsite: FAILED to copy ' . basename($bf));
				fronote_alert('Backup offsite échoué', 'Copie hors-hôte impossible: ' . $bf);
			}
		}
	} elseif ($offsite !== '') {
		$log('Backup offsite: BACKUP_OFFSITE_DIR non accessible (' . $offsite . ')');
		fronote_alert('Backup offsite indisponible', 'BACKUP_OFFSITE_DIR inaccessible: ' . $offsite);
	}
} catch (\Throwable $e) {
	$log('Backup error: ' . $e->getMessage());
	// Alerte proactive : un échec de sauvegarde est un incident (perte de données potentielle).
	fronote_alert('ÉCHEC de la sauvegarde quotidienne', $e->getMessage());
}

// 2. Backup rotation
try {
	if ($backup !== null && method_exists($backup, 'cleanup')) {
		$keep = (int) (env('BACKUP_RETENTION', '5') ?: 5);
		$cleaned = $backup->cleanup($keep);
		$log("Backup rotation: removed {$cleaned} old backups (keeping {$keep} per type)");
	}
} catch (\Throwable $e) {
	$log('Backup rotation error: ' . $e->getMessage());
}

// 3. Audit log cleanup (> AUDIT_RETENTION_DAYS)
try {
	$deleted = app('audit')->cleanup();
	$log("Audit: cleaned up {$deleted} expired entries");
} catch (\Throwable $e) {
	$log('Audit cleanup error: ' . $e->getMessage());
}

// 4. Email queue : envoi des e-mails en attente puis purge des lignes traitées
try {
	$emailQueue = new \API\Services\EmailQueueService(getPDO());
	// 4a. Traiter la file d'attente (envoi borné pour éviter les runs interminables).
	try {
		$sent = $emailQueue->processQueue(100);
		$log("Email queue: sent {$sent} pending emails");
	} catch (\Throwable $e) {
		$log('Email queue processing error: ' . $e->getMessage());
	}
	// 4b. Purge des corps traités + lignes email_log expirées.
	$purged = $emailQueue->cleanup();
	$log("Email queue: purged {$purged} processed entries");
} catch (\Throwable $e) {
	$log('Email queue cleanup error: ' . $e->getMessage());
}

// 5. Cache GC
try {
	$cache = app('cache');
	if (method_exists($cache, 'gc')) {
		$gcCount = $cache->gc();
		$log("Cache: garbage collected {$gcCount} expired entries");
	}
} catch (\Throwable $e) {
	$log('Cache GC error: ' . $e->getMessage());
}

// 6. Temp file cleanup (storage/tmp, fichiers > 24h)
try {
	$cleaned = fronote_cleanup_dir(BASE_PATH . '/storage/tmp', 86400);
	$log("Temp files: removed {$cleaned} old files from storage/tmp");
} catch (\Throwable $e) {
	$log('Temp cleanup error: ' . $e->getMessage());
}

// 7. Quarantine reliquats (storage/quarantine, entrées > 30 jours)
try {
	$cleaned = fronote_cleanup_dir(BASE_PATH . '/storage/quarantine', 30 * 86400, true);
	$log("Quarantine: removed {$cleaned} stale entries from storage/quarantine");
} catch (\Throwable $e) {
	$log('Quarantine cleanup error: ' . $e->getMessage());
}

// 8. Rafraîchit le miroir d'identité `accounts` depuis les 5 tables d'auth héritées
//    (unification d'identité, pas-à-pas). Additif/idempotent : n'altère PAS l'auth courante.
try {
	$acc = (new \API\Services\AccountService(getPDO()))->syncFromLegacy();
	$log("Accounts mirror: synced {$acc['synced']} accounts" . ($acc['errors'] ? ' (' . count($acc['errors']) . ' errors)' : ''));
} catch (\Throwable $e) {
	$log('Accounts mirror error: ' . $e->getMessage());
}

$duration = round(microtime(true) - $startTime, 2);
$log("=== Maintenance completed in {$duration}s ===");

/**
 * Supprime les fichiers (et optionnellement sous-dossiers) d'un répertoire dont le
 * mtime est antérieur à $maxAge secondes. Best-effort, ne lève jamais.
 *
 * @return int Nombre d'entrées supprimées
 */
function fronote_cleanup_dir(string $dir, int $maxAge, bool $includeDirs = false): int
{
	if (!is_dir($dir)) {
		return 0;
	}
	$cutoff = time() - $maxAge;
	$removed = 0;
	foreach (new \DirectoryIterator($dir) as $f) {
		if ($f->isDot()) {
			continue;
		}
		if ($f->getMTime() >= $cutoff) {
			continue;
		}
		if ($f->isDir()) {
			if (!$includeDirs) {
				continue;
			}
			if (fronote_rrmdir($f->getPathname())) {
				$removed++;
			}
		} elseif (@unlink($f->getPathname())) {
			$removed++;
		}
	}
	return $removed;
}

/**
 * Suppression récursive d'un répertoire. Best-effort.
 */
function fronote_rrmdir(string $dir): bool
{
	if (!is_dir($dir)) {
		return false;
	}
	$items = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
		\RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($items as $item) {
		$item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
	}
	return @rmdir($dir);
}

/**
 * Alerte proactive sur incident (échec backup, disque plein, événement critique).
 * Journalise toujours en CRITICAL ; POSTe en plus vers ALERT_WEBHOOK si configuré
 * (Slack/Teams/webhook générique). Best-effort, ne lève jamais.
 */
function fronote_alert(string $subject, string $body): void
{
	// Délègue à la brique unique d'alerting (log CRITICAL + webhook + anti-flood).
	if (class_exists(\API\Core\Alerting::class)) {
		\API\Core\Alerting::notify($subject, $body);
		return;
	}
	// Repli si l'autoloader n'est pas chargé (ne devrait pas arriver via bootstrap).
	error_log('[fronote][ALERT][CRITICAL] ' . $subject . ' — ' . $body);
}
