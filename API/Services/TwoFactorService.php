<?php
declare(strict_types=1);

namespace API\Services;

use PDO;

/**
 * Service d'authentification à deux facteurs (TOTP — RFC 6238)
 * 
 * Implémentation intégrée sans dépendance externe.
 * Compatible avec Google Authenticator, Authy, Microsoft Authenticator, etc.
 */
class TwoFactorService
{
    private PDO $pdo;

    /** Base32 alphabet */
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** TOTP period in seconds */
    private const PERIOD = 30;

    /** Number of digits in the OTP code */
    private const DIGITS = 6;

    /** Time steps to check before/after current (tolerance window) */
    private const WINDOW = 1;

    /** App name shown in authenticator apps */
    private const ISSUER = 'FRONOTE';

    /** Number of single-use recovery codes generated per setup */
    private const BACKUP_CODE_COUNT = 10;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Chiffre le secret TOTP at-rest (AES-256-GCM). Rétro-compatible : ne re-chiffre pas. */
    private function encSecret(?string $v): ?string
    {
        if ($v === null || $v === '' || !\API\Core\Encryption::available()) return $v;
        try { return (new \API\Core\Encryption())->encryptIfPlain($v); }
        catch (\Throwable $e) { return $v; }
    }

    /** Déchiffre un secret TOTP (laisse passer les secrets encore en clair). */
    private function decSecret(?string $v): ?string
    {
        if ($v === null || $v === '' || !\API\Core\Encryption::available()) return $v;
        try { return (new \API\Core\Encryption())->decrypt($v); }
        catch (\Throwable $e) { return $v; }
    }

    // ─── Secret management ───────────────────────────────────────

    /**
     * Generate a new base32-encoded secret (160-bit = 32 chars).
     */
    public function generateSecret(): string
    {
        $bytes = random_bytes(20); // 160 bits
        return $this->encodeBase32($bytes);
    }

    /**
     * Build the otpauth:// URI for QR code generation.
     * format: otpauth://totp/ISSUER:email?secret=SECRET&issuer=ISSUER&digits=6&period=30
     */
    public function getOtpauthUri(string $secret, string $accountName): string
    {
        $issuer = self::ISSUER;
        $label = rawurlencode($issuer . ':' . $accountName);
        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&digits=%d&period=%d',
            $label,
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD
        );
    }

    // (getQrCodeUrl() supprimé : envoyait le secret TOTP à chart.googleapis.com — fuite de secret,
    //  API tierce fermée depuis 2019. L'UI affiche la clé + otpauth:// + QR rendu côté client.)

    // ─── TOTP verification ───────────────────────────────────────

    /**
     * Verify a 6-digit code against a secret.
     * Checks current time step ± WINDOW steps for tolerance.
     */
    public function verifyCode(string $secret, string $code): bool
    {
        return $this->matchStep($secret, $code) >= 0;
    }

    /** Retourne le pas de temps (compteur TOTP) qui valide le code, ou -1 si aucun. */
    private function matchStep(string $secret, string $code): int
    {
        if (strlen($code) !== self::DIGITS || !ctype_digit($code)) {
            return -1;
        }

        $secretBytes = $this->decodeBase32($secret);
        $now = (int) floor(time() / self::PERIOD);

        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $step = $now + $i;
            $expected = $this->generateTOTP($secretBytes, $step);
            if (hash_equals($expected, $code)) {
                return $step;
            }
        }

        return -1;
    }

    private bool $replayTableReady = false;
    /** Crée si besoin le magasin anti-rejeu (dernier pas de temps consommé par utilisateur). */
    private function ensureReplayTable(): void
    {
        if ($this->replayTableReady) return;
        // Définition portable (MySQL et SQLite) : pas de suffixe ENGINE/CHARSET.
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS two_factor_last_step (
            user_id INT NOT NULL, user_type VARCHAR(32) NOT NULL, last_step BIGINT NOT NULL,
            PRIMARY KEY (user_id, user_type)
        )");
        $this->replayTableReady = true;
    }

    /**
     * Generate a TOTP code for a specific time counter.
     */
    private function generateTOTP(string $secretBytes, int $counter): string
    {
        // Counter as 8-byte big-endian
        $counterBytes = pack('N*', 0) . pack('N*', $counter);

        // HMAC-SHA1
        $hash = hash_hmac('sha1', $counterBytes, $secretBytes, true);

        // Dynamic truncation
        $offset = ord($hash[19]) & 0x0F;
        $code = (
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
            ((ord($hash[$offset + 3]) & 0xFF))
        ) % pow(10, self::DIGITS);

        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    // ─── Database integration ────────────────────────────────────

    /**
     * Check if 2FA is enabled for a user.
     */
    public function isEnabled(int $userId, string $userType): bool
    {
        $table = self::getTable($userType);
        if (!$table) return false;

        try {
            $stmt = $this->pdo->prepare("SELECT two_factor_enabled FROM {$table} WHERE id = ?");
            $stmt->execute([$userId]);
            return (bool) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            // Column may not exist yet (pre-migration)
            return false;
        }
    }

    /**
     * Get the stored secret for a user.
     */
    public function getSecret(int $userId, string $userType): ?string
    {
        $table = self::getTable($userType);
        if (!$table) return null;

        try {
            $stmt = $this->pdo->prepare("SELECT two_factor_secret FROM {$table} WHERE id = ?");
            $stmt->execute([$userId]);
            $raw = $stmt->fetchColumn();
            return $raw ? $this->decSecret($raw) : null;
        } catch (\PDOException $e) {
            return null;
        }
    }

    /**
     * Enable 2FA for a user: stores the secret and sets enabled = 1.
     * Requires a valid code to confirm setup.
     */
    public function enable(int $userId, string $userType, string $secret, string $code): bool
    {
        if (!$this->verifyCode($secret, $code)) {
            return false;
        }

        $table = self::getTable($userType);
        if (!$table) return false;

        try {
            $stmt = $this->pdo->prepare("UPDATE {$table} SET two_factor_enabled = 1, two_factor_secret = ? WHERE id = ?");
            return $stmt->execute([$this->encSecret($secret), $userId]);
        } catch (\PDOException $e) {
            error_log("TwoFactorService::enable error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Disable 2FA for a user.
     */
    public function disable(int $userId, string $userType): bool
    {
        $table = self::getTable($userType);
        if (!$table) return false;

        try {
            $stmt = $this->pdo->prepare("UPDATE {$table} SET two_factor_enabled = 0, two_factor_secret = NULL WHERE id = ?");
            $ok = $stmt->execute([$userId]);
            // Purge les codes de secours associés
            $del = $this->pdo->prepare("DELETE FROM user_backup_codes WHERE user_id = ? AND user_type = ?");
            $del->execute([$userId, $userType]);
            return $ok;
        } catch (\PDOException $e) {
            error_log("TwoFactorService::disable error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate a 2FA code during login.
     */
    public function validateLogin(int $userId, string $userType, string $code): bool
    {
        $secret = $this->getSecret($userId, $userType);
        if (!$secret) return false;

        $step = $this->matchStep($secret, $code);
        if ($step < 0) return false;

        // Anti-rejeu (RFC 6238 §5.2) : un pas de temps ne peut être consommé qu'UNE fois.
        // Refuser tout code dont le pas est <= au dernier pas validé pour ce compte
        // (empêche le rejeu d'un code intercepté dans la fenêtre ±1).
        try {
            $this->ensureReplayTable();
            $stmt = $this->pdo->prepare("SELECT last_step FROM two_factor_last_step WHERE user_id = ? AND user_type = ?");
            $stmt->execute([$userId, $userType]);
            $last = $stmt->fetchColumn();
            if ($last !== false && (int) $last >= $step) {
                return false; // rejeu détecté
            }
            // Upsert PORTABLE (MySQL + SQLite) : le SELECT ci-dessus indique déjà s'il faut
            // insérer ou mettre à jour (évite ON DUPLICATE KEY, spécifique MySQL).
            if ($last === false) {
                $this->pdo->prepare("INSERT INTO two_factor_last_step (user_id, user_type, last_step) VALUES (?, ?, ?)")
                          ->execute([$userId, $userType, $step]);
            } else {
                $this->pdo->prepare("UPDATE two_factor_last_step SET last_step = ? WHERE user_id = ? AND user_type = ?")
                          ->execute([$step, $userId, $userType]);
            }
        } catch (\Throwable $e) {
            // FAIL-CLOSED : si le store anti-rejeu est indisponible, on ne peut pas garantir qu'un code
            // n'est pas rejoué → on REFUSE le login 2FA (sécurité avant disponibilité) et on alerte.
            error_log('[2FA anti-rejeu] CRITICAL store indisponible, login refusé : ' . $e->getMessage());
            try { \API\Core\Alerting::notify('2FA anti-rejeu indisponible (login refusé)', $e->getMessage()); } catch (\Throwable $ignore) {}
            return false;
        }
        return true;
    }

    // ─── Codes de secours (recovery) ─────────────────────────────

    /**
     * Génère un nouveau jeu de codes de secours, remplaçant les anciens.
     * Retourne les codes en clair (à afficher UNE seule fois) ; seuls les
     * hachages SHA-256 sont stockés.
     *
     * @return string[] codes en clair, format "XXXXX-XXXXX"
     */
    public function generateBackupCodes(int $userId, string $userType, int $count = self::BACKUP_CODE_COUNT): array
    {
        $codes = [];
        $hashes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = bin2hex(random_bytes(5)); // 10 caractères hex, haute entropie
            $codes[] = strtoupper(substr($raw, 0, 5) . '-' . substr($raw, 5, 5));
            $hashes[] = $this->backupHash($this->normalizeBackupCode($raw));
        }

        try {
            $this->pdo->beginTransaction();
            $del = $this->pdo->prepare("DELETE FROM user_backup_codes WHERE user_id = ? AND user_type = ?");
            $del->execute([$userId, $userType]);
            $ins = $this->pdo->prepare("INSERT INTO user_backup_codes (user_id, user_type, code_hash) VALUES (?, ?, ?)");
            foreach ($hashes as $h) {
                $ins->execute([$userId, $userType, $h]);
            }
            $this->pdo->commit();
        } catch (\PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("TwoFactorService::generateBackupCodes error: " . $e->getMessage());
            return [];
        }

        return $codes;
    }

    /**
     * Vérifie et consomme un code de secours à usage unique.
     */
    public function verifyBackupCode(int $userId, string $userType, string $code): bool
    {
        $norm = $this->normalizeBackupCode($code);
        if ($norm === '') return false;

        // Vérifie le HMAC peppéré (nouveau) ET le SHA-256 legacy (codes générés avant le
        // pepper) pour ne pas invalider les codes de secours existants.
        $hmac   = $this->backupHash($norm);
        $legacy = hash('sha256', $norm);
        try {
            // Consommation atomique : le filtre used_at IS NULL dans l'UPDATE garantit
            // l'usage unique même sous requêtes concurrentes (pas de race SELECT→UPDATE).
            $stmt = $this->pdo->prepare(
                "UPDATE user_backup_codes SET used_at = NOW()
                 WHERE user_id = ? AND user_type = ? AND code_hash IN (?, ?) AND used_at IS NULL"
            );
            $stmt->execute([$userId, $userType, $hmac, $legacy]);
            return $stmt->rowCount() === 1;
        } catch (\PDOException $e) {
            error_log("TwoFactorService::verifyBackupCode error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Nombre de codes de secours encore utilisables.
     */
    public function countBackupCodes(int $userId, string $userType): int
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM user_backup_codes WHERE user_id = ? AND user_type = ? AND used_at IS NULL"
            );
            $stmt->execute([$userId, $userType]);
            return (int) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            return 0;
        }
    }

    /** Hachage des codes de secours : HMAC-SHA256 poivré par APP_KEY (anti brute-force
     *  hors-ligne en cas de fuite DB). Dégrade en SHA-256 nu si aucune clé (comme le
     *  reste du chiffrement) pour ne pas casser sur un déploiement non configuré. */
    private function backupHash(string $norm): string
    {
        $pepper = getenv('APP_KEY') ?: (getenv('JWT_SECRET') ?: '');
        return $pepper !== '' ? hash_hmac('sha256', $norm, $pepper) : hash('sha256', $norm);
    }

    private function normalizeBackupCode(string $code): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $code) ?? '');
    }

    // ─── Base32 encoding/decoding ────────────────────────────────

    private function encodeBase32(string $data): string
    {
        $binary = '';
        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $binary .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        $result = '';
        for ($i = 0, $len = strlen($binary); $i < $len; $i += 5) {
            $chunk = substr($binary, $i, 5);
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0');
            }
            $result .= self::BASE32_CHARS[bindec($chunk)];
        }

        return $result;
    }

    private function decodeBase32(string $base32): string
    {
        $base32 = strtoupper(rtrim($base32, '='));
        $binary = '';

        for ($i = 0, $len = strlen($base32); $i < $len; $i++) {
            $pos = strpos(self::BASE32_CHARS, $base32[$i]);
            if ($pos === false) continue;
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $result = '';
        for ($i = 0, $len = strlen($binary) - (strlen($binary) % 8); $i < $len; $i += 8) {
            $result .= chr(bindec(substr($binary, $i, 8)));
        }

        return $result;
    }

    // ─── Helpers ─────────────────────────────────────────────────

    private static function getTable(string $userType): ?string
    {
        return match ($userType) {
            'administrateur' => 'administrateurs',
            'professeur'     => 'professeurs',
            'eleve'          => 'eleves',
            'parent'         => 'parents',
            'vie_scolaire'   => 'vie_scolaire',
            default          => null,
        };
    }
}
