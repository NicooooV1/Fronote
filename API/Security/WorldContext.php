<?php
declare(strict_types=1);

namespace API\Security;

use PDO;
use API\Tenant\TenantAuthorization;

/**
 * WorldContext — point d'entrée des autorisations des mondes plateforme et
 * établissement (opérateurs vs comptes d'établissement).
 *
 * Sessions séparées (ne jamais mélanger) :
 *   $_SESSION['platform']['account_id']            → monde plateforme
 *   $_SESSION['tenant']['membership_id'] + ['establishment_id'] → monde établissement
 *
 * Les helpers globaux platformAuthorize()/tenantAuthorize() (cf. Bridge) délèguent ici.
 */
final class WorldContext
{
    private static function pdo(): PDO
    {
        return function_exists('getPDO') ? getPDO() : app('db')->getConnection();
    }

    // ───────────────────────── plateforme ─────────────────────────

    public static function platformAccountId(): int
    {
        return (int) ($_SESSION['platform']['account_id'] ?? 0);
    }

    // Le « monde plateforme » a été retiré des instances distribuées (aucune platform
    // locale) : platformAuth/platformCan/platformAuthorize et la classe PlatformAuthorization
    // n'existent plus ici. La gestion multi-établissements est centralisée sur le hub de
    // distribution (CT122), jamais dans l'instance.

    // ───────────────────────── établissement ─────────────────────────

    /**
     * Mémo de requête pour le repli legacy (évite une requête par appel). CLÉ COMPOSITE
     * (identité + établissement) : un changement d'établissement, une impersonation ou une
     * ré-authentification dans la même requête ne doit JAMAIS renvoyer un memo périmé.
     * @var array<string,int>
     */
    private static array $tenantMembershipMemo = [];

    public static function tenantMembershipId(): int
    {
        // Connexion établissement (portail /e/{slug}) : chemin rapide.
        if (!empty($_SESSION['tenant']['membership_id'])) {
            return (int) $_SESSION['tenant']['membership_id'];
        }
        // Connexion LEGACY (/login/) : on résout l'appartenance depuis l'identité héritée
        // (tenant_accounts.legacy_type/legacy_id → appartenance active de l'établissement courant),
        // pour que tenantCan/tenantAuthorize fonctionnent sous les DEUX chemins de connexion.
        $key = ($_SESSION['user_type'] ?? '') . ':' . ($_SESSION['user_id'] ?? '') . ':' . ($_SESSION['etablissement_id'] ?? '');
        if (array_key_exists($key, self::$tenantMembershipMemo)) {
            return self::$tenantMembershipMemo[$key];
        }
        return self::$tenantMembershipMemo[$key] = self::resolveLegacyMembership();
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

    public static function tenantAuthorize(string $permission): void
    {
        self::tenantAuth()->authorize($permission);
    }
}
