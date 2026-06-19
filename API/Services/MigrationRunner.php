<?php
declare(strict_types=1);

namespace API\Services;

use PDO;

/**
 * MigrationRunner — système de migrations versionnées (up/down + journal schema_migrations).
 *
 * Complète SchemaSyncService (déclaratif, purement additif : CREATE TABLE / ADD COLUMN)
 * en gérant les transformations qu'il ne sait PAS faire : renommage de colonne,
 * changement de type, ajout d'index / clé étrangère, migration de données, suppression
 * contrôlée. Chaque fichier `database/migrations/<timestamp>_<nom>.php` retourne un objet
 * exposant `up(PDO): void` et (idéalement) `down(PDO): void`. L'ordre d'exécution est
 * l'ordre lexicographique du nom de fichier (préfixe horodaté).
 *
 * MySQL exécute le DDL hors transaction (auto-commit implicite) : on n'enveloppe donc
 * pas une migration dans une transaction. Une migration N'EST enregistrée qu'après le
 * succès de son up() ; au premier échec, on s'arrête (cohérence) et l'appelant décide
 * (UpdateService déclenche alors un rollback complet base + code).
 */
class MigrationRunner
{
    private PDO $pdo;
    private string $dir;

    public function __construct(PDO $pdo, string $basePath)
    {
        $this->pdo = $pdo;
        $this->dir = rtrim($basePath, '/\\') . '/database/migrations';
    }

    /** Applique toutes les migrations en attente. @return array{applied:string[],errors:string[],batch:int} */
    public function migrate(): array
    {
        $this->ensureLedger();
        $applied = $this->appliedSet();
        $batch   = $this->nextBatch();
        $done = [];
        $errors = [];
        foreach ($this->files() as $name => $path) {
            if (isset($applied[$name])) {
                continue;
            }
            try {
                $mig = require $path;
                if (!is_object($mig) || !method_exists($mig, 'up')) {
                    throw new \RuntimeException("migration invalide (up() manquant) : {$name}");
                }
                $mig->up($this->pdo);
                $this->record($name, $batch);
                $done[] = $name;
            } catch (\Throwable $e) {
                $errors[] = "{$name} : " . $e->getMessage();
                break; // arrêt au premier échec
            }
        }
        return ['applied' => $done, 'errors' => $errors, 'batch' => $batch];
    }

    /** Annule la dernière fournée (batch) appliquée. @return array{reverted:string[],errors:string[],batch:int} */
    public function rollback(): array
    {
        $this->ensureLedger();
        $last = (int) $this->pdo->query("SELECT COALESCE(MAX(batch), 0) FROM schema_migrations")->fetchColumn();
        if ($last === 0) {
            return ['reverted' => [], 'errors' => [], 'batch' => 0];
        }
        $stmt = $this->pdo->prepare("SELECT migration FROM schema_migrations WHERE batch = ? ORDER BY migration DESC");
        $stmt->execute([$last]);
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $files = $this->files();
        $reverted = [];
        $errors = [];
        foreach ($names as $name) {
            try {
                if (isset($files[$name])) {
                    $mig = require $files[$name];
                    if (is_object($mig) && method_exists($mig, 'down')) {
                        $mig->down($this->pdo);
                    }
                }
                $del = $this->pdo->prepare("DELETE FROM schema_migrations WHERE migration = ?");
                $del->execute([$name]);
                $reverted[] = $name;
            } catch (\Throwable $e) {
                $errors[] = "{$name} : " . $e->getMessage();
                break;
            }
        }
        return ['reverted' => $reverted, 'errors' => $errors, 'batch' => $last];
    }

    /** @return array{applied:string[],pending:string[]} */
    public function status(): array
    {
        $this->ensureLedger();
        $applied = $this->appliedSet();
        $pending = [];
        foreach ($this->files() as $name => $_) {
            if (!isset($applied[$name])) {
                $pending[] = $name;
            }
        }
        return ['applied' => array_keys($applied), 'pending' => $pending];
    }

    // ───────────────────────── interne ─────────────────────────

    private function ensureLedger(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS schema_migrations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    migration TEXT NOT NULL UNIQUE,
                    batch INTEGER NOT NULL DEFAULT 1,
                    applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )"
            );
            return;
        }
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS `schema_migrations` (
                `id`         INT AUTO_INCREMENT PRIMARY KEY,
                `migration`  VARCHAR(255) NOT NULL,
                `batch`      INT NOT NULL DEFAULT 1,
                `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_migration` (`migration`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** @return array<string,string> nom => chemin, trié par nom (ordre d'exécution). */
    private function files(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }
        $out = [];
        foreach (glob($this->dir . '/*.php') ?: [] as $path) {
            $out[basename($path, '.php')] = $path;
        }
        ksort($out);
        return $out;
    }

    /** @return array<string,true> */
    private function appliedSet(): array
    {
        $set = [];
        foreach ($this->pdo->query("SELECT migration FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN) as $m) {
            $set[$m] = true;
        }
        return $set;
    }

    private function nextBatch(): int
    {
        return 1 + (int) $this->pdo->query("SELECT COALESCE(MAX(batch), 0) FROM schema_migrations")->fetchColumn();
    }

    private function record(string $name, int $batch): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO schema_migrations (migration, batch) VALUES (?, ?)");
        $stmt->execute([$name, $batch]);
    }
}
