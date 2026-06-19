<?php
declare(strict_types=1);

namespace API\Tenant;

use PDO;

/**
 * TenantMembershipService — appartenances compte ↔ établissement (tenant_memberships).
 * C'est la couche qui fournit le PÉRIMÈTRE établissement et permet le multi-établissement
 * (un même compte peut être Directeur ici et simple Direction ailleurs).
 */
final class TenantMembershipService
{
    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** Crée (ou retrouve) l'appartenance d'un compte à un établissement. Idempotent. */
    public function ensure(int $establishmentId, int $tenantAccountId, string $status = 'active'): int
    {
        $existing = $this->find($establishmentId, $tenantAccountId);
        if ($existing !== null) {
            return (int) $existing['id'];
        }
        $this->pdo->prepare(
            "INSERT INTO tenant_memberships (establishment_id, tenant_account_id, status) VALUES (?, ?, ?)"
        )->execute([$establishmentId, $tenantAccountId, $status]);
        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $establishmentId, int $tenantAccountId): ?array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT * FROM tenant_memberships WHERE establishment_id = ? AND tenant_account_id = ? LIMIT 1"
            );
            $st->execute([$establishmentId, $tenantAccountId]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\PDOException $e) { return null; }
    }

    /** Toutes les appartenances actives d'un compte (multi-établissement). */
    public function forAccount(int $tenantAccountId): array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT * FROM tenant_memberships WHERE tenant_account_id = ? AND status = 'active' ORDER BY establishment_id"
            );
            $st->execute([$tenantAccountId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) { return []; }
    }

    public function revoke(int $membershipId, ?string $now = null): bool
    {
        $now = $now ?? date('Y-m-d H:i:s');
        return $this->pdo->prepare(
            "UPDATE tenant_memberships SET status = 'revoked', revoked_at = ? WHERE id = ?"
        )->execute([$now, $membershipId]);
    }
}
