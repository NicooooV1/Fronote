<?php
declare(strict_types=1);

namespace Dist;

use PDO;
use RuntimeException;

/**
 * Store de distribution (SQLite, isolé). Modèle ALLOWLIST : seules les IP explicitement
 * autorisées (whitelist gérée via le bot Discord existant du distributeur) peuvent
 * échanger une clé et télécharger. Une IP non listée est simplement refusée — pas de
 * comptage d'échecs ni de bannissement à gérer.
 *
 * Gère aussi :
 *   - les licences (clé hachée + poivre, droits, usage unique atomique, révocation, expiration)
 *   - les jetons d'installation (autorisent les téléchargements de modules ultérieurs)
 *   - le journal des activations / téléchargements
 *
 * Aucune dépendance à la base applicative multi-tenant.
 */
final class DistStore
{
    private PDO $db;
    /** @var array<string,mixed> */
    private array $cfg;

    /** @param array<string,mixed> $config */
    public function __construct(string $dbPath, array $config)
    {
        $this->cfg = $config;
        $dir = dirname($dbPath);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Impossible de créer le répertoire de données : {$dir}");
        }
        $this->db = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->db->exec('PRAGMA foreign_keys=ON');
        @chmod($dbPath, 0640);
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS licenses (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                key_hash       TEXT NOT NULL UNIQUE,
                key_prefix     TEXT NOT NULL,               -- 6 premiers car. (repérage sans révéler la clé)
                tier           TEXT NOT NULL DEFAULT 'base',
                modules_json   TEXT NOT NULL DEFAULT '[]',  -- modules PREMIUM autorisés (au-delà des simples)
                max_activations INTEGER NOT NULL DEFAULT 1,
                used_count     INTEGER NOT NULL DEFAULT 0,
                revoked        INTEGER NOT NULL DEFAULT 0,
                note           TEXT,
                expires_at     TEXT,
                created_at     TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE TABLE IF NOT EXISTS activations (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                license_id     INTEGER NOT NULL REFERENCES licenses(id) ON DELETE CASCADE,
                install_token_hash TEXT NOT NULL UNIQUE,
                ip             TEXT NOT NULL,
                activated_at   TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE TABLE IF NOT EXISTS ip_allowlist (
                ip             TEXT PRIMARY KEY,
                note           TEXT,
                added_at       TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE TABLE IF NOT EXISTS download_log (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                kind           TEXT NOT NULL,               -- 'base' | 'module'
                ref            TEXT,                        -- nom du module le cas échéant
                license_id     INTEGER,
                ip             TEXT,
                at             TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE TABLE IF NOT EXISTS refused_log (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                ip             TEXT NOT NULL,
                kind           TEXT NOT NULL,               -- 'redeem' | 'download' | 'module'
                at             TEXT NOT NULL DEFAULT (datetime('now'))
            );
        SQL);
    }

    // ─── Allowlist d'IP ──────────────────────────────────────────────────────

    public function isIpAllowed(string $ip): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM ip_allowlist WHERE ip = :ip LIMIT 1');
        $stmt->execute([':ip' => $ip]);
        return (bool) $stmt->fetchColumn();
    }

    public function allowIp(string $ip, string $note = ''): bool
    {
        $stmt = $this->db->prepare(
            "INSERT INTO ip_allowlist (ip, note) VALUES (:ip, :n)
             ON CONFLICT(ip) DO UPDATE SET note = :n"
        );
        $stmt->execute([':ip' => $ip, ':n' => $note !== '' ? $note : null]);
        return true;
    }

    /** Retire une IP de la whitelist. Retourne true si une entrée existait. */
    public function disallowIp(string $ip): bool
    {
        $stmt = $this->db->prepare('DELETE FROM ip_allowlist WHERE ip = :ip');
        $stmt->execute([':ip' => $ip]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<int,array<string,mixed>> */
    public function listAllowed(int $limit = 200): array
    {
        return $this->db->query('SELECT ip, note, added_at FROM ip_allowlist ORDER BY added_at DESC LIMIT ' . max(1, $limit))->fetchAll();
    }

    /** @return array<string,mixed> */
    public function ipStatus(string $ip): array
    {
        $stmt = $this->db->prepare('SELECT note, added_at FROM ip_allowlist WHERE ip = :ip');
        $stmt->execute([':ip' => $ip]);
        $row = $stmt->fetch();
        return ['ip' => $ip, 'allowed' => (bool) $row, 'note' => $row['note'] ?? null, 'added_at' => $row['added_at'] ?? null];
    }

    // ─── Journal des refus (IP non whitelistées) ─────────────────────────────
    // Consommé par le bot Discord du distributeur (GET /admin/refused) pour proposer
    // d'autoriser l'IP d'un client qui a tenté d'installer sans être encore listé.
    // On ne journalise QUE l'IP et le type de requête — jamais la clé soumise.

    public function recordRefused(string $ip, string $kind): void
    {
        // Ne journalise qu'une IP VALIDE : client_ip() peut, en dernier recours, renvoyer
        // « 0.0.0.0 », et on ne veut jamais stocker une chaîne arbitraire (le bot l'affiche
        // dans Discord et la propose à /dist autoriser).
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return;
        }
        $this->db->prepare('INSERT INTO refused_log (ip, kind) VALUES (:ip, :k)')
            ->execute([':ip' => $ip, ':k' => $kind]);
        // Borne la table (une IP hostile ne doit pas faire gonfler la base sans fin).
        $this->db->exec(
            'DELETE FROM refused_log WHERE id <= (SELECT COALESCE(MAX(id),0) FROM refused_log) - 5000'
        );
    }

    /**
     * Refus depuis un curseur (id > sinceId), ordre chronologique — le bot mémorise le
     * dernier id vu et ne relit que le delta.
     *
     * ⚠️ sinceId <= 0 = « donne-moi les plus RÉCENTS » (commande /dist refus) : on prend
     * la QUEUE du journal (ORDER BY id DESC LIMIT n) puis on ré-inverse pour rendre l'ordre
     * chronologique. Un simple « WHERE id > 0 ORDER BY id ASC LIMIT 1000 » renvoyait au
     * contraire les 1000 plus ANCIENS et masquait tous les refus récents dès que la table
     * dépasse la limite (constat revue 2026-08-12).
     *
     * @return array<int,array<string,mixed>>
     */
    public function listRefused(int $sinceId = 0, int $limit = 200): array
    {
        $n = max(1, min(1000, $limit));
        if ($sinceId > 0) {
            $stmt = $this->db->prepare(
                'SELECT id, ip, kind, at FROM refused_log WHERE id > :s ORDER BY id ASC LIMIT ' . $n
            );
            $stmt->execute([':s' => $sinceId]);
            return $stmt->fetchAll();
        }
        $rows = $this->db->query(
            'SELECT id, ip, kind, at FROM refused_log ORDER BY id DESC LIMIT ' . $n
        )->fetchAll();
        return array_reverse($rows);   // rendu chronologique (id croissant)
    }

    // ─── Clés ────────────────────────────────────────────────────────────────

    /** Hachage de la clé de licence (sha256 poivré). Le poivre n'est jamais en base. */
    public function hashKey(string $key): string
    {
        return hash('sha256', (string) $this->cfg['key_pepper'] . '|' . $key);
    }

    /** Génère une clé opaque de 48 caractères base62 (~285 bits d'entropie). */
    public static function generateKey(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $key = '';
        for ($i = 0; $i < 48; $i++) {
            $key .= $alphabet[random_int(0, 61)];
        }
        return $key;
    }

    /**
     * Émet une licence : enregistre le HACHAGE (jamais la clé en clair) et retourne la clé
     * une seule fois à l'appelant.
     *
     * @param array{tier?:string,modules?:string[],max_activations?:int,expires_at?:?string,note?:?string} $opts
     * @return array{key:string,id:int,prefix:string}
     */
    public function issueLicense(array $opts = []): array
    {
        $key = self::generateKey();
        $stmt = $this->db->prepare(
            'INSERT INTO licenses (key_hash, key_prefix, tier, modules_json, max_activations, expires_at, note)
             VALUES (:h, :p, :t, :m, :ma, :e, :n)'
        );
        $stmt->execute([
            ':h'  => $this->hashKey($key),
            ':p'  => substr($key, 0, 6),
            ':t'  => $opts['tier'] ?? 'base',
            ':m'  => json_encode(array_values($opts['modules'] ?? []), JSON_UNESCAPED_UNICODE),
            ':ma' => max(1, (int) ($opts['max_activations'] ?? 1)),
            ':e'  => $opts['expires_at'] ?? null,
            ':n'  => $opts['note'] ?? null,
        ]);
        return ['key' => $key, 'id' => (int) $this->db->lastInsertId(), 'prefix' => substr($key, 0, 6)];
    }

    public function revokeLicenseByKey(string $key): bool
    {
        $stmt = $this->db->prepare('UPDATE licenses SET revoked = 1 WHERE key_hash = :h');
        $stmt->execute([':h' => $this->hashKey($key)]);
        return $stmt->rowCount() > 0;
    }

    public function revokeLicenseByPrefix(string $prefix): int
    {
        $stmt = $this->db->prepare('UPDATE licenses SET revoked = 1 WHERE key_prefix = :p AND revoked = 0');
        $stmt->execute([':p' => $prefix]);
        return $stmt->rowCount();
    }

    // ─── Redemption ──────────────────────────────────────────────────────────

    /**
     * Valide et consomme une clé (usage unique atomique). L'IP doit être whitelistée.
     * Statuts : 'ok' | 'not_whitelisted' | 'invalid' | 'revoked' | 'expired' | 'exhausted'.
     *
     * @return array<string,mixed>
     */
    public function redeem(string $key, string $ip): array
    {
        if (!$this->isIpAllowed($ip)) {
            $this->recordRefused($ip, 'redeem');
            return ['status' => 'not_whitelisted'];
        }
        if (!preg_match('/^[A-Za-z0-9]{48}$/', $key)) {
            return ['status' => 'invalid'];
        }

        $hash = $this->hashKey($key);
        $this->db->beginTransaction();
        try {
            $lic = $this->db->prepare('SELECT * FROM licenses WHERE key_hash = :h LIMIT 1');
            $lic->execute([':h' => $hash]);
            $row = $lic->fetch();

            if (!$row) {
                $this->db->commit();
                return ['status' => 'invalid'];
            }
            if ((int) $row['revoked'] === 1) {
                $this->db->commit();
                return ['status' => 'revoked'];
            }
            if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
                $this->db->commit();
                return ['status' => 'expired'];
            }

            // Consommation ATOMIQUE : n'incrémente que si des activations restent disponibles.
            $upd = $this->db->prepare(
                'UPDATE licenses SET used_count = used_count + 1
                 WHERE id = :id AND used_count < max_activations AND revoked = 0'
            );
            $upd->execute([':id' => $row['id']]);
            if ($upd->rowCount() === 0) {
                $this->db->commit();
                return ['status' => 'exhausted'];
            }

            $installToken = bin2hex(random_bytes(32));
            $this->db->prepare(
                'INSERT INTO activations (license_id, install_token_hash, ip) VALUES (:l, :t, :ip)'
            )->execute([':l' => $row['id'], ':t' => hash('sha256', $installToken), ':ip' => $ip]);

            $this->db->prepare('INSERT INTO download_log (kind, license_id, ip) VALUES (:k, :l, :ip)')
                ->execute([':k' => 'base', ':l' => $row['id'], ':ip' => $ip]);

            $this->db->commit();

            $premium = json_decode((string) $row['modules_json'], true) ?: [];
            $modules = array_values(array_unique(array_merge(
                (array) ($this->cfg['simple_modules'] ?? []),
                is_array($premium) ? $premium : []
            )));

            return [
                'status'          => 'ok',
                'install_token'   => $installToken,
                'license'         => ['id' => (int) $row['id'], 'tier' => $row['tier'], 'prefix' => $row['key_prefix']],
                'simple_modules'  => array_values((array) ($this->cfg['simple_modules'] ?? [])),
                'premium_modules' => is_array($premium) ? $premium : [],
                'modules'         => $modules,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Autorise un téléchargement de module via le jeton d'installation (post-install).
     * Le module doit faire partie des modules simples OU des modules premium de la licence.
     * L'IP doit rester whitelistée.
     *
     * @return array{status:string, license_id?:int}
     */
    public function authorizeModule(string $installToken, string $module, string $ip): array
    {
        if (!$this->isIpAllowed($ip)) {
            $this->recordRefused($ip, 'module');
            return ['status' => 'not_whitelisted'];
        }
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $module)) {
            return ['status' => 'invalid_module'];
        }
        $act = $this->db->prepare(
            'SELECT a.license_id, l.modules_json, l.revoked, l.expires_at
             FROM activations a JOIN licenses l ON l.id = a.license_id
             WHERE a.install_token_hash = :t LIMIT 1'
        );
        $act->execute([':t' => hash('sha256', $installToken)]);
        $row = $act->fetch();
        if (!$row) {
            return ['status' => 'invalid_token'];
        }
        if ((int) $row['revoked'] === 1) {
            return ['status' => 'revoked'];
        }
        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
            return ['status' => 'expired'];
        }
        $premium = json_decode((string) $row['modules_json'], true) ?: [];
        $allowed = array_merge((array) ($this->cfg['simple_modules'] ?? []), is_array($premium) ? $premium : []);
        if (!in_array($module, $allowed, true)) {
            return ['status' => 'not_entitled'];
        }
        $this->db->prepare('INSERT INTO download_log (kind, ref, license_id, ip) VALUES (:k, :r, :l, :ip)')
            ->execute([':k' => 'module', ':r' => $module, ':l' => (int) $row['license_id'], ':ip' => $ip]);
        return ['status' => 'ok', 'license_id' => (int) $row['license_id']];
    }

    public function pdo(): PDO
    {
        return $this->db;
    }
}
