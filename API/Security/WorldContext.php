<?php
declare(strict_types=1);

namespace API\Security;

use PDO;
use API\Platform\PlatformAuthorization;
use API\Tenant\TenantAuthorization;
use API\Support\SupportSessionGuard;

/**
 * WorldContext — résolution du « monde » courant (plateforme / établissement /
 * support) et point d'entrée unique des autorisations des trois mondes.
 *
 * Sessions séparées (ne jamais mélanger) :
 *   $_SESSION['platform']['account_id']            → monde plateforme
 *   $_SESSION['tenant']['membership_id'] + ['establishment_id'] → monde établissement
 *   $_SESSION['support']['platform_account_id'] + ['establishment_id'] → session support
 *
 * Les helpers globaux platformAuthorize()/tenantAuthorize()/tenantAuthorizeOn()/
 * supportAuthorize() (cf. Bridge) délèguent ici. Remplace les anciens requireRole().
 */
final class WorldContext
{
    private static function pdo(): PDO
    {
        return function_exists('getPDO') ? getPDO() : app('db')->getConnection();
    }

    public static function currentWorld(): ?string
    {
        if (!empty($_SESSION['platform']['account_id'])) return 'platform';
        if (!empty($_SESSION['tenant']['membership_id'])) return 'tenant';
        return null;
    }

    // ───────────────────────── plateforme ─────────────────────────

    public static function platformAccountId(): int
    {
        return (int) ($_SESSION['platform']['account_id'] ?? 0);
    }

    public static function platformAuth(): PlatformAuthorization
    {
        $id = self::platformAccountId();
        return new PlatformAuthorization(self::pdo(), $id > 0 ? ['id' => $id] : null);
    }

    public static function platformCan(string $permission): bool
    {
        return self::platformAuth()->can($permission);
    }

    public static function platformAuthorize(string $permission): void
    {
        self::platformAuth()->authorize($permission);
    }

    // ───────────────────────── établissement ─────────────────────────

    /** Mémo de requête pour le repli legacy (évite une requête par appel). */
    private static ?int $tenantMembershipFallback = null;

    public static function tenantMembershipId(): int
    {
        // Connexion établissement (portail /e/{slug}) : chemin rapide.
        if (!empty($_SESSION['tenant']['membership_id'])) {
            return (int) $_SESSION['tenant']['membership_id'];
        }
        // Connexion LEGACY (/login/) : on résout l'appartenance depuis l'identité héritée
        // (tenant_accounts.legacy_type/legacy_id → appartenance active de l'établissement courant),
        // pour que tenantCan/tenantAuthorize fonctionnent sous les DEUX chemins de connexion.
        if (self::$tenantMembershipFallback !== null) {
            return self::$tenantMembershipFallback;
        }
        return self::$tenantMembershipFallback = self::resolveLegacyMembership();
    }

    private static function resolveLegacyMembership(): int
    {
        $uid   = $_SESSION['user_id'] ?? null;
        $utype = $_SESSION['user_type'] ?? null;
        $estab = (int) ($_SESSION['etablissement_id'] ?? 0);
        if (!$uid || !$utype || $estab <= 0) { return 0; }
        try {
            $st = self::pdo()->prepare(
                "SELECT tm.id FROM tenant_memberships tm
                   JOIN tenant_accounts ta ON ta.id = tm.tenant_account_id
                  WHERE ta.legacy_type = ? AND ta.legacy_id = ? AND ta.status = 'active'
                    AND tm.establishment_id = ? AND tm.status = 'active' LIMIT 1"
            );
            $st->execute([(string) $utype, (int) $uid, $estab]);
            return (int) ($st->fetchColumn() ?: 0);
        } catch (\Throwable $e) {
            error_log('[WorldContext] resolveLegacyMembership: ' . $e->getMessage());
            return 0;
        }
    }

    public static function tenantAuth(): TenantAuthorization
    {
        return new TenantAuthorization(self::pdo(), self::tenantMembershipId());
    }

    public static function tenantCan(string $permission): bool
    {
        return self::tenantMembershipId() > 0 && self::tenantAuth()->can($permission);
    }

    public static function tenantCanOn(string $permission, string $resourceType, int $resourceId): bool
    {
        return self::tenantMembershipId() > 0 && self::tenantAuth()->canOn($permission, $resourceType, $resourceId);
    }

    public static function tenantAuthorize(string $permission): void
    {
        self::tenantAuth()->authorize($permission);
    }

    public static function tenantAuthorizeOn(string $permission, string $resourceType, int $resourceId): void
    {
        self::tenantAuth()->authorizeOn($permission, $resourceType, $resourceId);
    }

    // ───────────────────────── support (session) ─────────────────────────

    public static function supportCan(int $establishmentId, string $requiredLevel, ?string $resourceType = null, ?int $resourceId = null, bool $sensitive = false): bool
    {
        $accId = (int) ($_SESSION['support']['platform_account_id'] ?? $_SESSION['platform']['account_id'] ?? 0);
        if ($accId <= 0) return false;
        return (new SupportSessionGuard(self::pdo()))->can($accId, $establishmentId, $requiredLevel, $resourceType, $resourceId, $sensitive);
    }

    public static function supportAuthorize(int $establishmentId, string $requiredLevel, ?string $resourceType = null, ?int $resourceId = null, bool $sensitive = false): void
    {
        $accId = (int) ($_SESSION['support']['platform_account_id'] ?? $_SESSION['platform']['account_id'] ?? 0);
        (new SupportSessionGuard(self::pdo()))->authorize($accId, $establishmentId, $requiredLevel, $resourceType, $resourceId, $sensitive);
    }
}
