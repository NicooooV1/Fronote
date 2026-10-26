<?php
declare(strict_types=1);

namespace API\Services\Privacy;

use PDO;

/**
 * Consentement aux cookies (recommandations CNIL) :
 *  - refuser aussi simplement qu'accepter (boutons de même poids) ;
 *  - aucun traceur non essentiel avant un choix explicite ;
 *  - choix redemandé au plus tard tous les 6 mois, ou si la politique change (POLICY_VERSION) ;
 *  - retrait possible à tout moment (lien « Gérer mes cookies ») ;
 *  - preuve du consentement conservée (table cookie_consents : version, date, choix,
 *    empreinte d'IP tronquée et salée — jamais l'adresse IP elle-même).
 *
 * Catégories : « essentiels » (toujours actifs, exemptés de consentement) et
 * « mesure d'audience » (statistiques d'usage internes, hébergées par l'établissement).
 */
final class ConsentService
{
    public const POLICY_VERSION = '2026-10';
    public const COOKIE = 'fronote_consent';
    public const MAX_AGE_DAYS = 182;

    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /** @return array{id:string,analytics:bool,at:int,v:string}|null */
    public static function current(): ?array
    {
        $raw = $_COOKIE[self::COOKIE] ?? '';
        if ($raw === '' || strlen($raw) > 300) { return null; }
        $j = json_decode(base64_decode(strtr($raw, '-_', '+/'), true) ?: '', true);
        if (!is_array($j) || !isset($j['id'], $j['v'], $j['t']) || !preg_match('/^[a-f0-9]{32}$/', (string) $j['id'])) { return null; }
        return ['id' => (string) $j['id'], 'analytics' => !empty($j['a']), 'at' => (int) $j['t'], 'v' => (string) $j['v']];
    }

    /** Faut-il (re)demander le choix ? */
    public static function needsPrompt(): bool
    {
        $c = self::current();
        return $c === null || $c['v'] !== self::POLICY_VERSION || $c['at'] < time() - self::MAX_AGE_DAYS * 86400;
    }

    public static function allows(string $category): bool
    {
        if ($category === 'essentiels') { return true; }
        if (self::needsPrompt()) { return false; }
        $c = self::current();
        return $category === 'audience' && $c !== null && $c['analytics'];
    }

    /** Enregistre un choix (cookie + preuve). $action : accept_all | reject_all | custom | withdraw */
    public function record(string $action, bool $analytics): array
    {
        $action = in_array($action, ['accept_all', 'reject_all', 'custom', 'withdraw'], true) ? $action : 'custom';
        if ($action === 'accept_all') { $analytics = true; }
        if ($action === 'reject_all' || $action === 'withdraw') { $analytics = false; }
        $prev = self::current();
        $id = $prev['id'] ?? bin2hex(random_bytes(16));
        $now = time();
        $value = rtrim(strtr(base64_encode((string) json_encode(['id' => $id, 'a' => $analytics ? 1 : 0, 't' => $now, 'v' => self::POLICY_VERSION])), '+/', '-_'), '=');
        if (!headers_sent()) {
            setcookie(self::COOKIE, $value, [
                'expires' => $now + self::MAX_AGE_DAYS * 86400, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[self::COOKIE] = $value;
        if ($this->pdo) {
            try {
                $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
                $salt = (string) (getenv('APP_KEY') ?: getenv('JWT_SECRET') ?: 'fronote') . date('Y-m');
                $this->pdo->prepare('INSERT INTO cookie_consents (etablissement_id, consent_id, user_id, user_type, preferences, analytics, policy_version, action, ip_hash, user_agent)
                                     VALUES (?,?,?,?,0,?,?,?,?,?)')->execute([
                    class_exists('\API\Core\EstablishmentContext') ? (int) \API\Core\EstablishmentContext::id() : null,
                    $id, function_exists('getUserId') && isLoggedIn() ? (int) getUserId() : null, function_exists('getUserRole') && isLoggedIn() ? (string) getUserRole() : null,
                    $analytics ? 1 : 0, self::POLICY_VERSION, $action, $ip !== '' ? substr(hash_hmac('sha256', $ip, $salt), 0, 16) : null,
                    mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160),
                ]);
            } catch (\Throwable $e) { error_log('[consent] preuve non enregistrée : ' . $e->getMessage()); }
        }
        return ['analytics' => $analytics, 'version' => self::POLICY_VERSION, 'at' => $now];
    }

    /** Statistiques de consentement (dernier choix de chaque visiteur sur la période). */
    public function stats(int $days = 30): array
    {
        if (!$this->pdo) { return ['total' => 0, 'accept' => 0, 'refuse' => 0, 'rate' => null]; }
        $st = $this->pdo->prepare('SELECT c.analytics FROM cookie_consents c
             JOIN (SELECT consent_id, MAX(id) mid FROM cookie_consents WHERE etablissement_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY consent_id) l ON l.mid = c.id');
        $st->execute([(int) \API\Core\EstablishmentContext::id(), $days]);
        $rows = $st->fetchAll(PDO::FETCH_COLUMN);
        $acc = count(array_filter($rows, fn($a) => (int) $a === 1));
        $tot = count($rows);
        return ['total' => $tot, 'accept' => $acc, 'refuse' => $tot - $acc, 'rate' => $tot ? round(100 * $acc / $tot) : null];
    }

    /** Purge des preuves de plus de 25 mois. */
    public function purge(): int
    {
        if (!$this->pdo) { return 0; }
        return (int) $this->pdo->exec('DELETE FROM cookie_consents WHERE created_at < DATE_SUB(NOW(), INTERVAL 25 MONTH)');
    }
}
