<?php
declare(strict_types=1);

namespace API\Tenant;

use PDO;

/**
 * TenantRoleAssignmentService — attribution/révocation de rôles AU NIVEAU DE
 * L'APPARTENANCE (tenant_membership_roles) + valeurs de périmètre normalisées
 * (tenant_membership_role_scope_values). Garde-fous : anti-escalade, compatibilité
 * type de compte ↔ rôle, justification des rôles sensibles, journalisation
 * (tenant_audit_logs). SQL portable (check-then-insert, testable SQLite).
 */
final class TenantRoleAssignmentService
{
    /** Rôles habilités à attribuer (côté établissement). */
    private const MANAGERS = ['directeur', 'chef_etablissement', 'direction', 'direction_adjointe', 'responsable_permissions'];

    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function assignableRoles(array $actorRoleKeys): array
    {
        if (array_intersect(self::MANAGERS, $actorRoleKeys)) {
            return array_keys(TenantRoleCatalog::roles());
        }
        return [];
    }

    /**
     * Attribue un rôle à une appartenance.
     * $actor = ['membership_id'=>int, 'role_keys'=>string[]] (acteur établissement).
     * $opts : scope_type, scope (['class'=>[ids],'student'=>[ids],'subject'=>[ids]]),
     *         reason, starts_at, expires_at.
     * @throws \RuntimeException sur garde-fou (droit, compatibilité, justification, périmètre).
     */
    public function assign(array $actor, int $membershipId, string $roleKey, array $opts = []): int
    {
        if (!TenantRoleCatalog::exists($roleKey)) {
            throw new \RuntimeException("Rôle établissement inconnu : « {$roleKey} ».");
        }
        $actorRoleKeys = $actor['role_keys'] ?? [];
        if (!in_array($roleKey, $this->assignableRoles($actorRoleKeys), true)) {
            throw new \RuntimeException("Vous n'avez pas le droit d'attribuer le rôle « {$roleKey} ».");
        }
        // Anti-escalade : pas d'auto-attribution d'un rôle non détenu.
        if ((int) ($actor['membership_id'] ?? 0) === $membershipId && !in_array($roleKey, $actorRoleKeys, true)) {
            throw new \RuntimeException('Auto-attribution interdite.');
        }

        $membership = $this->membership($membershipId);
        if ($membership === null) {
            throw new \RuntimeException('Appartenance introuvable.');
        }
        // Compatibilité type de compte ↔ rôle.
        $accType = $this->accountType((int) $membership['tenant_account_id']);
        if ($accType !== null && !isset(TenantRoleCatalog::rolesForAccountType($accType)[$roleKey])) {
            throw new \RuntimeException("Le rôle « {$roleKey} » est incompatible avec un compte « {$accType} ».");
        }
        // Rôle sensible → justification obligatoire.
        $reason = isset($opts['reason']) ? trim((string) $opts['reason']) : '';
        if (TenantRoleCatalog::isSensitiveRole($roleKey) && $reason === '') {
            throw new \RuntimeException("Le rôle sensible « {$roleKey} » exige une justification.");
        }

        $scopeType = (string) ($opts['scope_type'] ?? TenantRoleCatalog::defaultScope($roleKey));
        $cleanScope = $this->validateScope(is_array($opts['scope'] ?? null) ? $opts['scope'] : []);
        $roleId = $this->tenantRoleId($roleKey);

        // check-then-insert (portable) sur l'unicité (membership_id, tenant_role_id).
        $sel = $this->pdo->prepare("SELECT id FROM tenant_membership_roles WHERE membership_id = ? AND tenant_role_id = ? LIMIT 1");
        $sel->execute([$membershipId, $roleId]);
        $mrId = $sel->fetchColumn();
        if ($mrId === false) {
            $this->pdo->prepare(
                "INSERT INTO tenant_membership_roles
                    (membership_id, tenant_role_id, scope_type, starts_at, expires_at, reason,
                     assigned_by_membership_id, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)"
            )->execute([
                $membershipId, $roleId, $scopeType, $opts['starts_at'] ?? null, $opts['expires_at'] ?? null,
                $reason ?: null, (int) ($actor['membership_id'] ?? 0) ?: null,
            ]);
            $mrId = (int) $this->pdo->lastInsertId();
        } else {
            $mrId = (int) $mrId;
            $this->pdo->prepare(
                "UPDATE tenant_membership_roles SET scope_type = ?, starts_at = ?, expires_at = ?, reason = ?, is_active = 1, revoked_at = NULL WHERE id = ?"
            )->execute([$scopeType, $opts['starts_at'] ?? null, $opts['expires_at'] ?? null, $reason ?: null, $mrId]);
            // remplacement des valeurs de périmètre
            $this->pdo->prepare("DELETE FROM tenant_membership_role_scope_values WHERE membership_role_id = ?")->execute([$mrId]);
        }

        foreach ($cleanScope as $stype => $ids) {
            foreach ($ids as $sid) {
                $this->pdo->prepare(
                    "INSERT INTO tenant_membership_role_scope_values (membership_role_id, scope_type, scope_id) VALUES (?, ?, ?)"
                )->execute([$mrId, $stype, $sid]);
            }
        }

        $this->audit((int) $membership['establishment_id'], $actor, 'role_assigned', (int) $membership['tenant_account_id'], [
            'role' => $roleKey, 'scope_type' => $scopeType, 'scope' => $cleanScope, 'reason' => $reason ?: null,
        ]);
        return $mrId;
    }

    public function revoke(array $actor, int $membershipRoleId, string $reason = ''): bool
    {
        $st = $this->pdo->prepare(
            "SELECT tmr.*, tm.establishment_id, tm.tenant_account_id
               FROM tenant_membership_roles tmr JOIN tenant_memberships tm ON tm.id = tmr.membership_id
              WHERE tmr.id = ? LIMIT 1"
        );
        $st->execute([$membershipRoleId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { return false; }
        $ok = $this->pdo->prepare("UPDATE tenant_membership_roles SET is_active = 0, revoked_at = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $membershipRoleId]);
        if ($ok) {
            $this->audit((int) $row['establishment_id'], $actor, 'role_revoked', (int) $row['tenant_account_id'],
                ['membership_role_id' => $membershipRoleId, 'reason' => $reason ?: null]);
        }
        return (bool) $ok;
    }

    public function getEffectiveRoles(int $membershipId): array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT tr.role_key FROM tenant_membership_roles tmr
                   JOIN tenant_roles tr ON tr.id = tmr.tenant_role_id
                  WHERE tmr.membership_id = ? AND tmr.is_active = 1"
            );
            $st->execute([$membershipId]);
            return array_values(array_unique($st->fetchAll(PDO::FETCH_COLUMN) ?: []));
        } catch (\PDOException $e) { return []; }
    }

    // ───────────────────────── interne ─────────────────────────

    private function validateScope(array $scope): array
    {
        $normalize = [
            'class' => 'class', 'classes' => 'class',
            'student' => 'student', 'students' => 'student',
            'subject' => 'subject', 'subjects' => 'subject',
            'group' => 'group', 'groups' => 'group',
        ];
        $clean = [];
        foreach ($scope as $key => $values) {
            if (!isset($normalize[$key])) {
                throw new \RuntimeException("Clé de périmètre inconnue : « {$key} ».");
            }
            $stype = $normalize[$key];
            if (!is_array($values)) {
                throw new \RuntimeException("Le périmètre « {$key} » doit être une liste d'identifiants.");
            }
            $ints = array_values(array_unique(array_filter(array_map('intval', $values), fn($n) => $n > 0)));
            if ($ints !== []) {
                $clean[$stype] = array_merge($clean[$stype] ?? [], $ints);
            }
        }
        return $clean;
    }

    private function membership(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM tenant_memberships WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function accountType(int $accountId): ?string
    {
        try {
            $st = $this->pdo->prepare("SELECT account_type FROM tenant_accounts WHERE id = ? LIMIT 1");
            $st->execute([$accountId]);
            $v = $st->fetchColumn();
            return $v !== false ? (string) $v : null;
        } catch (\PDOException $e) { return null; }
    }

    private function tenantRoleId(string $roleKey): int
    {
        $st = $this->pdo->prepare("SELECT id FROM tenant_roles WHERE role_key = ? LIMIT 1");
        $st->execute([$roleKey]);
        $id = $st->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException("Rôle « {$roleKey} » absent de tenant_roles (lancer TenantRoleSync).");
        }
        return (int) $id;
    }

    private function audit(int $establishmentId, array $actor, string $action, int $targetAccountId, array $newValue): void
    {
        try {
            $this->pdo->prepare(
                "INSERT INTO tenant_audit_logs
                    (establishment_id, actor_account_id, target_account_id, action, new_value, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $establishmentId, $actor['account_id'] ?? null, $targetAccountId, $action,
                json_encode($newValue, JSON_UNESCAPED_UNICODE),
                $_SERVER['REMOTE_ADDR'] ?? '', substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ]);
        } catch (\PDOException $e) {
            error_log('[TenantRoleAssignmentService] audit: ' . $e->getMessage());
        }
    }
}
