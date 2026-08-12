<?php
declare(strict_types=1);

namespace API\Tenant;

use PDO;

/**
 * TenantContext — résolution du contexte ÉTABLISSEMENT (slug → établissement) et
 * primitives de connexion tenant (compte → appartenance). Sépare strictement le
 * monde établissement du monde plateforme.
 */
final class TenantContext
{
    /** Établissement par slug (ou code), sauf supprimé/purgé. */
    public static function establishmentBySlug(PDO $pdo, string $slug): ?array
    {
        try {
            $st = $pdo->prepare(
                "SELECT * FROM etablissements
                  WHERE (slug = ? OR code = ?) AND status NOT IN ('deleted','purged') LIMIT 1"
            );
            $st->execute([$slug, $slug]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\PDOException $e) {
            error_log('[TenantContext] ' . $e->getMessage());
            return null;
        }
    }

    /** Compte établissement ACTIF par identifiant ou email (pour le login). */
    public static function findAccountByLogin(PDO $pdo, string $login): ?array
    {
        try {
            $st = $pdo->prepare(
                "SELECT * FROM tenant_accounts
                  WHERE (username = ? OR email = ?) AND status = 'active' LIMIT 1"
            );
            $st->execute([$login, $login]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\PDOException $e) {
            error_log('[TenantContext] ' . $e->getMessage());
            return null;
        }
    }

    /** Appartenance ACTIVE d'un compte à un établissement. */
    public static function membershipFor(PDO $pdo, int $establishmentId, int $accountId): ?array
    {
        try {
            $st = $pdo->prepare(
                "SELECT * FROM tenant_memberships
                  WHERE establishment_id = ? AND tenant_account_id = ? AND status = 'active' LIMIT 1"
            );
            $st->execute([$establishmentId, $accountId]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\PDOException $e) { return null; }
    }

}
