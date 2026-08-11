<?php
declare(strict_types=1);

namespace API\Security;

/**
 * TwoFactorTrust — « confiance 2FA » à durée limitée, par APPAREIL (cookie signé).
 *
 * Exigence : pour tout rôle à responsabilité, le 2e facteur est requis à CHAQUE nouvelle
 * connexion, MAIS on ne le redemande pas si cet appareil a déjà validé le 2FA pour ce compte
 * il y a moins d'UNE HEURE. Le jeton est un cookie HMAC-signé (clé = APP_KEY) contenant
 * user_id:user_type:expiration ; non falsifiable et lié au couple compte+appareil.
 */
final class TwoFactorTrust
{
    private const COOKIE = 'fronote_2fa_trust';
    private const TTL    = 3600; // 1 heure

    private static function key(): string
    {
        $k = getenv('APP_KEY') ?: (getenv('JWT_SECRET') ?: '');
        // Repli : ne jamais utiliser une clé vide (sinon signatures triviales).
        return $k !== '' ? $k : hash('sha256', __DIR__);
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, self::key());
    }

    /** Pose la confiance pour (user, appareil) pendant TTL secondes. */
    public static function grant(int $userId, string $userType): void
    {
        $exp     = time() + self::TTL;
        $payload = $userId . ':' . $userType . ':' . $exp;
        $value   = base64_encode($payload) . '.' . self::sign($payload);
        $secure  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                   || ($_SERVER['SERVER_PORT'] ?? '') === '443';
        setcookie(self::COOKIE, $value, [
            'expires'  => $exp,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $value; // visible dans la même requête
    }

    /** Vrai si cet appareil a validé le 2FA pour ce compte il y a moins d'UNE HEURE. */
    public static function isTrusted(int $userId, string $userType): bool
    {
        $raw = $_COOKIE[self::COOKIE] ?? '';
        if ($raw === '' || strpos($raw, '.') === false) {
            return false;
        }
        [$b64, $mac] = explode('.', $raw, 2);
        $payload = base64_decode($b64, true);
        if ($payload === false || !hash_equals(self::sign($payload), $mac)) {
            return false; // signature invalide → falsification
        }
        $parts = explode(':', $payload);
        if (count($parts) !== 3) {
            return false;
        }
        [$uid, $type, $exp] = $parts;
        return (int) $uid === $userId
            && $type === $userType
            && (int) $exp > time();
    }

    /** Révoque la confiance (déconnexion). */
    public static function clear(): void
    {
        setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
        unset($_COOKIE[self::COOKIE]);
    }
}
