<?php
declare(strict_types=1);

namespace API\Services;

use PDO;

/**
 * MetricsService — bootstrap + I/O de la table `app_metrics` (série temporelle légère).
 *
 * `app_metrics` existe dans pronote.sql mais restait inutilisée. Ce service en fait
 * le socle d'observabilité :
 *   - le cron (cron/daily_maintenance.php) y écrit un HEARTBEAT + un instantané de
 *     métriques à chaque exécution → un arrêt silencieux du cron/backup devient
 *     détectable (la fraîcheur de `heartbeat.cron` est la preuve de vie) ;
 *   - la page platform/observability.php la relit pour afficher liveness + tendances.
 *
 * ensureTable() est idempotent (CREATE TABLE IF NOT EXISTS) : filet de sécurité pour
 * les installations dont le schéma déclaratif n'aurait pas encore cette table. Toutes
 * les méthodes sont best-effort : elles journalisent et renvoient une valeur neutre
 * plutôt que de lever (l'observabilité ne doit jamais casser une page ou un cron).
 *
 * Convention de clés (namespacées par point) :
 *   heartbeat.cron            preuve de vie du cron (valeur = durée du run en s)
 *   sys.cpu_percent           charge CPU normalisée (%)
 *   sys.mem_percent           mémoire utilisée (%)
 *   sys.swap_percent          swap utilisé (%)      ← critique sur boîtier 2 Go
 *   sys.disk_percent          stockage utilisé (%)
 *   db.connections            connexions MariaDB (Threads_connected)
 *   backup.age_hours          âge de la dernière sauvegarde (h)
 *   fleet.etablissements      nb d'établissements actifs
 *   fleet.accounts_active     nb de comptes actifs (parc)
 */
final class MetricsService
{
    private PDO $pdo;
    private bool $ready = false;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Garantit l'existence de la table (idempotent). Best-effort. */
    public function ensureTable(): bool
    {
        if ($this->ready) {
            return true;
        }
        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS `app_metrics` (\n"
                . "  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,\n"
                . "  `metric_key` VARCHAR(100) NOT NULL,\n"
                . "  `metric_value` DECIMAL(12,2) NOT NULL,\n"
                . "  `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
                . "  INDEX `idx_key_date` (`metric_key`, `recorded_at`)\n"
                . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $this->ready = true;
        } catch (\Throwable $e) {
            error_log('[metrics] ensureTable: ' . $e->getMessage());
        }
        return $this->ready;
    }

    /**
     * Enregistre un point de mesure. La valeur est bornée au domaine DECIMAL(12,2)
     * (0 .. 9 999 999 999.99) pour ne jamais faire échouer l'INSERT.
     */
    public function record(string $key, float $value): bool
    {
        if (!$this->ensureTable()) {
            return false;
        }
        $value = $this->clamp($value);
        try {
            $st = $this->pdo->prepare('INSERT INTO app_metrics (metric_key, metric_value) VALUES (?, ?)');
            $st->execute([$key, $value]);
            return true;
        } catch (\Throwable $e) {
            error_log('[metrics] record ' . $key . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Enregistre plusieurs points en une passe (instantané). Les valeurs non
     * numériques sont ignorées silencieusement.
     *
     * @param array<string,int|float> $pairs
     * @return int nombre de points effectivement écrits
     */
    public function recordMany(array $pairs): int
    {
        if ($pairs === [] || !$this->ensureTable()) {
            return 0;
        }
        $n = 0;
        try {
            $st = $this->pdo->prepare('INSERT INTO app_metrics (metric_key, metric_value) VALUES (?, ?)');
            foreach ($pairs as $key => $value) {
                if (!is_numeric($value)) {
                    continue;
                }
                try {
                    $st->execute([$key, $this->clamp((float) $value)]);
                    $n++;
                } catch (\Throwable $e) {
                    error_log('[metrics] recordMany ' . $key . ': ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            error_log('[metrics] recordMany: ' . $e->getMessage());
        }
        return $n;
    }

    /**
     * Dernier point connu pour une clé.
     * @return array{value:float,recorded_at:string}|null
     */
    public function latest(string $key): ?array
    {
        if (!$this->ensureTable()) {
            return null;
        }
        try {
            $st = $this->pdo->prepare('SELECT metric_value, recorded_at FROM app_metrics WHERE metric_key = ? ORDER BY id DESC LIMIT 1');
            $st->execute([$key]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) {
                return null;
            }
            return ['value' => (float) $r['metric_value'], 'recorded_at' => (string) $r['recorded_at']];
        } catch (\Throwable $e) {
            error_log('[metrics] latest ' . $key . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Dernier point de plusieurs clés en UNE requête (sous-requête MAX(id) par clé).
     * @param string[] $keys
     * @return array<string,array{value:float,recorded_at:string}>
     */
    public function latestMany(array $keys): array
    {
        $keys = array_values(array_unique(array_filter($keys, static fn($k) => $k !== '')));
        if ($keys === [] || !$this->ensureTable()) {
            return [];
        }
        try {
            $ph  = implode(',', array_fill(0, count($keys), '?'));
            $sql = "SELECT m.metric_key AS k, m.metric_value AS v, m.recorded_at AS t\n"
                 . "FROM app_metrics m\n"
                 . "JOIN (SELECT metric_key, MAX(id) AS mid FROM app_metrics WHERE metric_key IN ($ph) GROUP BY metric_key) last\n"
                 . "  ON last.mid = m.id";
            $st = $this->pdo->prepare($sql);
            $st->execute($keys);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(string) $r['k']] = ['value' => (float) $r['v'], 'recorded_at' => (string) $r['t']];
            }
            return $out;
        } catch (\Throwable $e) {
            error_log('[metrics] latestMany: ' . $e->getMessage());
            return [];
        }
    }

    /** Borne une valeur au domaine stockable (DECIMAL(12,2), non signé en pratique). */
    private function clamp(float $value): float
    {
        if (!is_finite($value)) {
            return 0.0;
        }
        if ($value < 0) {
            $value = 0.0;
        }
        if ($value > 9999999999.99) {
            $value = 9999999999.99;
        }
        return round($value, 2);
    }
}
