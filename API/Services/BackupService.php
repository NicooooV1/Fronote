<?php
declare(strict_types=1);

namespace API\Services;

/**
 * BackupService — Sauvegarde et restauration de la base de données et des uploads
 *
 * Usage :
 *   $backup = new BackupService($pdo, BASE_PATH);
 *   $file = $backup->createDatabaseBackup();
 *   $backup->restore($file);
 *   $backup->cleanup(5); // Garder les 5 derniers
 */
class BackupService
{
	protected \PDO $pdo;
	protected string $basePath;
	protected string $backupPath;

	public function __construct(\PDO $pdo, string $basePath)
	{
		$this->pdo = $pdo;
		$this->basePath = $basePath;
		$this->backupPath = $basePath . '/storage/backups';
		$this->ensureDirectory($this->backupPath);
	}

	/**
	 * Crée un dump SQL complet de la base de données
	 *
	 * @return string Chemin absolu du fichier de backup
	 */
	public function createDatabaseBackup(): string
	{
		$timestamp = date('Y-m-d_H-i-s');
		$filename = "backup_db_{$timestamp}.sql";
		$filepath = $this->backupPath . '/' . $filename;

		$tables = $this->getTables();
		$sql = "-- Fronote Database Backup\n";
		$sql .= "-- Date: " . date('Y-m-d H:i:s') . "\n";
		$sql .= "-- Tables: " . count($tables) . "\n\n";
		// Mode SQL permissif pour la durée du dump (comme mysqldump) : une restauration
		// est une reproduction fidèle de la source, pas une revalidation. Sans cela, une
		// valeur héritée non stricte (ex. enum='' saisie sous un ancien mode SQL) casse
		// l'INSERT si la base cible est en mode strict. On restaure le mode à la fin.
		$sql .= "SET @OLD_SQL_MODE = @@SQL_MODE;\n";
		$sql .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
		$sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

		foreach ($tables as $table) {
			// Structure
			$stmt = $this->pdo->query("SHOW CREATE TABLE `{$table}`");
			$row = $stmt->fetch(\PDO::FETCH_ASSOC);
			$sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
			$sql .= ($row['Create Table'] ?? '') . ";\n\n";

			// Data — cursor-based to avoid loading full tables into RAM
			$stmt = $this->pdo->query("SELECT * FROM `{$table}`");
			$stmt->setFetchMode(\PDO::FETCH_ASSOC);
			$batch = [];
			$colList = null;

			$flushBatch = function (array $rows) use (&$sql, $table, &$colList): void {
				$values = [];
				foreach ($rows as $row) {
					$escaped = array_map(function ($v) {
						if ($v === null) return 'NULL';
						return $this->pdo->quote((string) $v);
					}, array_values($row));
					$values[] = '(' . implode(', ', $escaped) . ')';
				}
				$sql .= "INSERT INTO `{$table}` ({$colList}) VALUES\n";
				$sql .= implode(",\n", $values) . ";\n\n";
			};

			while ($row = $stmt->fetch()) {
				if ($colList === null) {
					$colList = '`' . implode('`, `', array_keys($row)) . '`';
				}
				$batch[] = $row;
				if (count($batch) >= 500) {
					$flushBatch($batch);
					$batch = [];
				}
			}
			if (!empty($batch)) {
				$flushBatch($batch);
			}
		}

		// Vues — exportées APRÈS les tables de base dont elles dépendent. On retire
		// la clause DEFINER (non portable entre serveurs/users) et on force
		// CREATE OR REPLACE pour une restauration idempotente.
		foreach ($this->getViews() as $view) {
			$row = $this->pdo->query("SHOW CREATE VIEW `{$view}`")->fetch(\PDO::FETCH_ASSOC);
			$createView = $row['Create View'] ?? '';
			if ($createView === '') {
				continue;
			}
			$createView = preg_replace('/\sDEFINER=`[^`]*`@`[^`]*`/i', '', $createView);
			$createView = preg_replace('/^CREATE /i', 'CREATE OR REPLACE ', $createView, 1);
			$sql .= "DROP VIEW IF EXISTS `{$view}`;\n";
			$sql .= $createView . ";\n\n";
		}

		$sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
		$sql .= "SET SQL_MODE = @OLD_SQL_MODE;\n";

		// Compression gzip si disponible.
		$payload = $sql;
		if (function_exists('gzencode')) {
			$payload = gzencode($sql, 9);
			$filepath .= '.gz';
		}

		// Chiffrement at-rest (RGPD) : les dumps contiennent des données personnelles
		// de mineurs + hash de mots de passe. Chiffrés si une clé applicative existe,
		// sauf désactivation explicite via BACKUP_ENCRYPT=false.
		$encrypt = \API\Core\Encryption::available()
			&& !in_array(strtolower((string) getenv('BACKUP_ENCRYPT')), ['0', 'false', 'no', 'off'], true);
		if ($encrypt) {
			$payload = (new \API\Core\Encryption())->encrypt($payload);
			$filepath .= '.enc';
		} else {
			error_log('BackupService: sauvegarde NON chiffrée (aucune clé applicative ou BACKUP_ENCRYPT désactivé) — données personnelles en clair.');
		}

		file_put_contents($filepath, $payload);

		return $filepath;
	}

	/**
	 * Crée un backup du dossier uploads
	 *
	 * @return string|null Chemin du fichier ZIP, ou null si zip non disponible
	 */
	public function createUploadsBackup(): ?string
	{
		$uploadsPath = $this->basePath . '/uploads';
		if (!is_dir($uploadsPath)) {
			return null;
		}

		if (!class_exists('ZipArchive')) {
			return null;
		}

		$timestamp = date('Y-m-d_H-i-s');
		$filename = "backup_uploads_{$timestamp}.zip";
		$filepath = $this->backupPath . '/' . $filename;

		$zip = new \ZipArchive();
		if ($zip->open($filepath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			return null;
		}

		$this->addDirectoryToZip($zip, $uploadsPath, 'uploads');
		$zip->close();

		return $filepath;
	}

	/**
	 * Crée un backup complet (DB + uploads)
	 *
	 * @return array{db: string, uploads: ?string}
	 */
	public function createFullBackup(): array
	{
		return [
			'db' => $this->createDatabaseBackup(),
			'uploads' => $this->createUploadsBackup(),
		];
	}

	/**
	 * Restaure un backup SQL
	 *
	 * @param string $filepath Chemin vers le fichier .sql ou .sql.gz
	 * @return bool
	 */
	public function restoreDatabase(string $filepath): bool
	{
		if (!file_exists($filepath)) {
			throw new \RuntimeException("Backup file not found: {$filepath}");
		}

		$raw = file_get_contents($filepath);
		if ($raw === false) {
			throw new \RuntimeException("Failed to read backup file");
		}

		$name = $filepath;

		// Déchiffrement si nécessaire (.enc).
		if (str_ends_with($name, '.enc')) {
			if (!\API\Core\Encryption::available()) {
				throw new \RuntimeException("Backup chiffré mais aucune clé applicative (APP_KEY/JWT_SECRET) disponible pour le déchiffrer.");
			}
			$raw = (new \API\Core\Encryption())->decrypt($raw);
			$name = substr($name, 0, -4); // retire .enc
		}

		// Décompression si gzip.
		if (str_ends_with($name, '.gz')) {
			$sql = gzdecode($raw);
		} else {
			$sql = $raw;
		}

		if ($sql === false) {
			throw new \RuntimeException("Failed to read backup file");
		}

		// Mode SQL permissif pour la restauration : reproduire fidèlement la source,
		// sans revalider en mode strict des valeurs héritées (ex. enum vide saisi sous
		// un ancien mode). Sécurise aussi les dumps antérieurs à l'en-tête SQL_MODE.
		$oldSqlMode = null;
		try {
			$oldSqlMode = $this->pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
			$this->pdo->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
		} catch (\Throwable $e) {
			$oldSqlMode = null;
		}

		try {
			$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

			foreach (\API\Core\SqlSplitter::split($sql) as $stmt) {
				if (str_starts_with($stmt, '--')) continue;
				$this->pdo->exec($stmt);
			}

			return true;
		} finally {
			// Toujours réactiver les contrôles FK, même en cas d'erreur fatale à
			// mi-restauration : la connexion PDO est persistante, sinon FOREIGN_KEY_CHECKS
			// resterait à 0 pour toutes les requêtes suivantes de ce process.
			try { $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (\Throwable $e) {}
			if ($oldSqlMode !== null) {
				try { $this->pdo->exec('SET SESSION sql_mode = ' . $this->pdo->quote((string) $oldSqlMode)); } catch (\Throwable $e) {}
			}
		}
	}

	/**
	 * Liste les backups disponibles
	 *
	 * @return array Liste triée par date (plus récent en premier)
	 */
	public function listBackups(): array
	{
		$files = glob($this->backupPath . '/backup_*');
		if (!$files) return [];

		$backups = [];
		foreach ($files as $file) {
			$basename = basename($file);
			$type = str_contains($basename, '_db_') ? 'database' : 'uploads';
			$backups[] = [
				'filename' => $basename,
				'path' => $file,
				'type' => $type,
				'size_mb' => round(filesize($file) / 1048576, 2),
				'created_at' => date('Y-m-d H:i:s', filemtime($file)),
			];
		}

		usort($backups, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
		return $backups;
	}

	/**
	 * Supprime les backups anciens, garde les N plus récents par type
	 *
	 * @param int $keep Nombre de backups à conserver par type
	 * @return int Nombre de fichiers supprimés
	 */
	public function cleanup(int $keep = 5): int
	{
		$deleted = 0;
		foreach (['db', 'uploads'] as $type) {
			$pattern = $this->backupPath . "/backup_{$type}_*";
			$files = glob($pattern);
			if (!$files) continue;

			// Trier par date (plus récent en premier)
			usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

			// Supprimer les anciens
			foreach (array_slice($files, $keep) as $file) {
				if (unlink($file)) $deleted++;
			}
		}
		return $deleted;
	}

	/**
	 * Supprime un backup spécifique
	 */
	public function deleteBackup(string $filename): bool
	{
		$filepath = $this->backupPath . '/' . basename($filename);
		if (!file_exists($filepath) || !str_starts_with(basename($filename), 'backup_')) {
			return false;
		}
		return unlink($filepath);
	}

	// ─── Private helpers ────────────────────────────────────────────

	private function getTables(): array
	{
		// UNIQUEMENT les tables de base : les vues sont exportées séparément APRÈS
		// (elles dépendent des tables et n'ont pas de données propres). Sinon un
		// SHOW CREATE TABLE sur une vue renvoie une clé « Create View » → CREATE vide
		// à l'export → « table doesn't exist » à la restauration.
		$stmt = $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
		return array_map(static fn($r) => array_values($r)[0], $stmt->fetchAll(\PDO::FETCH_ASSOC));
	}

	private function getViews(): array
	{
		$stmt = $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
		return array_map(static fn($r) => array_values($r)[0], $stmt->fetchAll(\PDO::FETCH_ASSOC));
	}

	private function addDirectoryToZip(\ZipArchive $zip, string $dir, string $prefix): void
	{
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ($iterator as $file) {
			if ($file->isFile()) {
				$relativePath = $prefix . '/' . substr($file->getRealPath(), strlen($dir) + 1);
				$zip->addFile($file->getRealPath(), str_replace('\\', '/', $relativePath));
			}
		}
	}

	private function ensureDirectory(string $path): void
	{
		if (!is_dir($path)) {
			mkdir($path, 0755, true);
		}
	}
}
