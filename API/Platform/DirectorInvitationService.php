<?php
declare(strict_types=1);

namespace API\Platform;

use PDO;

/**
 * DirectorInvitationService — invitations Directeur émises depuis /platform.
 *
 * Le SuperAdmin n'ouvre pas un compte Directeur directement : il émet une
 * invitation (3 types : create_establishment, join_establishment,
 * manage_multiple_establishments). Le Directeur, via le lien, crée son
 * tenant_account (jamais un platform_account) puis crée/rejoint son/ses
 * établissement(s). Le jeton n'est stocké que sous forme de hash.
 */
final class DirectorInvitationService
{
    public const TYPES = ['create_establishment', 'join_establishment', 'manage_multiple_establishments'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    private static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Crée une invitation. $opts : first_name, last_name, allowed_establishment_ids (array),
     * default_tenant_role, ttl_hours (def 72), now (Y-m-d H:i:s pour testabilité).
     * @return array{id:int, token:string} le token EN CLAIR (à transmettre, non re-stocké).
     * @throws \RuntimeException type invalide.
     */
    public function create(int $actorPlatformAccountId, string $email, string $type, array $opts = []): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \RuntimeException("Type d'invitation inconnu : « {$type} ».");
        }
        $email = trim($email);
        if ($email === '') {
            throw new \RuntimeException('Email obligatoire.');
        }
        $token = bin2hex(random_bytes(32));
        $ttl   = (int) ($opts['ttl_hours'] ?? 72);
        $now   = $opts['now'] ?? date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', strtotime($now) + $ttl * 3600);
        $allowed = isset($opts['allowed_establishment_ids']) && is_array($opts['allowed_establishment_ids'])
            ? json_encode(array_values(array_map('intval', $opts['allowed_establishment_ids'])))
            : null;

        $stmt = $this->pdo->prepare(
            "INSERT INTO director_invitations
                (email, first_name, last_name, invitation_type, token_hash, allowed_establishment_ids,
                 default_tenant_role, created_by_platform_account_id, expires_at, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
        );
        $stmt->execute([
            $email, $opts['first_name'] ?? null, $opts['last_name'] ?? null, $type,
            self::hashToken($token), $allowed,
            $opts['default_tenant_role'] ?? 'directeur', $actorPlatformAccountId, $expires,
        ]);
        return ['id' => (int) $this->pdo->lastInsertId(), 'token' => $token];
    }

    /**
     * Valide un jeton d'invitation. Retourne la ligne si pending ET non expirée, sinon null.
     * Ne modifie rien (consultation) — l'acceptation effective passe par markAccepted().
     */
    public function validate(string $token, ?string $now = null): ?array
    {
        $now = $now ?? date('Y-m-d H:i:s');
        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM director_invitations
                  WHERE token_hash = ? AND status = 'pending' AND expires_at >= ? LIMIT 1"
            );
            $stmt->execute([self::hashToken($token), $now]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\PDOException $e) {
            error_log('[DirectorInvitationService] ' . $e->getMessage());
            return null;
        }
    }

    /** Marque une invitation comme acceptée (après création du tenant_account Directeur). */
    public function markAccepted(int $id, ?string $now = null): bool
    {
        $now = $now ?? date('Y-m-d H:i:s');
        return $this->pdo->prepare(
            "UPDATE director_invitations SET status = 'accepted', accepted_at = ?
              WHERE id = ? AND status = 'pending'"
        )->execute([$now, $id]);
    }

    public function revoke(int $id, ?string $now = null): bool
    {
        $now = $now ?? date('Y-m-d H:i:s');
        return $this->pdo->prepare(
            "UPDATE director_invitations SET status = 'revoked', revoked_at = ?
              WHERE id = ? AND status = 'pending'"
        )->execute([$now, $id]);
    }

    /** Marque comme expirées les invitations pending dépassées (à appeler périodiquement). */
    public function expireStale(?string $now = null): int
    {
        $now = $now ?? date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "UPDATE director_invitations SET status = 'expired'
              WHERE status = 'pending' AND expires_at < ?"
        );
        $stmt->execute([$now]);
        return $stmt->rowCount();
    }

    public function listPending(): array
    {
        try {
            return $this->pdo->query(
                "SELECT id, email, invitation_type, status, expires_at, created_at
                   FROM director_invitations WHERE status = 'pending' ORDER BY created_at DESC"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            return [];
        }
    }
}
