<?php
declare(strict_types=1);

namespace API\Tenant;

use PDO;

/**
 * TenantAuthorization — moteur d'autorisation du monde ÉTABLISSEMENT, indexé par
 * APPARTENANCE (tenant_membership). Un compte agit toujours dans le contexte d'un
 * établissement précis (sa membership) ; les rôles, leurs permissions et leur
 * périmètre sont résolus à ce niveau.
 *
 *   can(perm)                  → un rôle de l'appartenance accorde la permission ?
 *   canOn(perm, type, id)      → … ET son périmètre couvre la ressource (anti-IDOR) ?
 *
 * Périmètres gérés : global/establishment(s) (toute la structure), self (son propre
 * compte), children (relations parentales), assigned (suivis AESH/psy/…), own_classes/
 * class(es) (classes du périmètre via tenant_membership_role_scope_values ∪ relation
 * teacher_of), students (élèves explicitement portés). Dégrade fail-closed.
 */
final class TenantAuthorization
{
    private const GUARDIAN_RELS = ['parent_of', 'legal_guardian_of', 'financial_responsible_of', 'tutor_of'];
    private const ASSIGN_RELS   = ['aesh_of', 'psychological_follow_of', 'medical_follow_of', 'social_follow_of', 'company_tutor_of', 'tutor_of'];

    private PDO $pdo;
    private int $membershipId;
    private ?array $membership = null;
    private ?array $roles = null;   // [['membership_role_id','role_key','scope_type'], …]

    public function __construct(PDO $pdo, int $membershipId)
    {
        $this->pdo = $pdo;
        $this->membershipId = $membershipId;
    }

    private function membership(): ?array
    {
        if ($this->membership === null) {
            try {
                $st = $this->pdo->prepare("SELECT * FROM tenant_memberships WHERE id = ? LIMIT 1");
                $st->execute([$this->membershipId]);
                $this->membership = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (\PDOException $e) { $this->membership = []; }
        }
        return $this->membership ?: null;
    }

    private function accountId(): int { return (int) ($this->membership()['tenant_account_id'] ?? 0); }

    /** Rôles actifs et temporellement valides de l'appartenance. */
    public function roles(): array
    {
        if ($this->roles !== null) {
            return $this->roles;
        }
        $m = $this->membership();
        if (!$m || ($m['status'] ?? 'active') !== 'active') {
            return $this->roles = [];
        }
        try {
            $st = $this->pdo->prepare(
                "SELECT tmr.id AS membership_role_id, tr.role_key, tmr.scope_type
                   FROM tenant_membership_roles tmr
                   JOIN tenant_roles tr ON tr.id = tmr.tenant_role_id
                  WHERE tmr.membership_id = ? AND tmr.is_active = 1
                    AND (tmr.starts_at  IS NULL OR tmr.starts_at  <= NOW())
                    AND (tmr.expires_at IS NULL OR tmr.expires_at >= NOW())"
            );
            $st->execute([$this->membershipId]);
            $this->roles = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            error_log('[TenantAuthorization] ' . $e->getMessage());
            $this->roles = [];
        }
        return $this->roles;
    }

    public function roleKeys(): array
    {
        return array_values(array_unique(array_map(fn($r) => $r['role_key'], $this->roles())));
    }

    public function hasRole(string $roleKey): bool
    {
        return in_array($roleKey, $this->roleKeys(), true);
    }

    public function can(string $permission): bool
    {
        foreach ($this->roles() as $r) {
            if (TenantRoleCatalog::roleGrants($r['role_key'], $permission)) {
                return true;
            }
        }
        return false;
    }

    public function canOn(string $permission, string $resourceType, int $resourceId): bool
    {
        foreach ($this->roles() as $r) {
            if (!TenantRoleCatalog::roleGrants($r['role_key'], $permission)) {
                continue;
            }
            if ($this->scopeAllows($r, $resourceType, $resourceId)) {
                return true;
            }
        }
        return false;
    }

    public function authorize(string $permission): void
    {
        if (!$this->can($permission)) { $this->deny($permission); }
    }

    public function authorizeOn(string $permission, string $resourceType, int $resourceId): void
    {
        if (!$this->canOn($permission, $resourceType, $resourceId)) { $this->deny($permission); }
    }

    // ───────────────────────── périmètre ─────────────────────────

    private function scopeAllows(array $role, string $type, int $id): bool
    {
        switch ($role['scope_type']) {
            case 'global':
            case 'establishment':
            case 'establishments':
                return true; // dans le contexte de l'établissement courant (résolu en amont)

            case 'self':
                return ($type === 'student' || $type === 'self') && $id === $this->accountId();

            case 'children':
                return $type === 'student' && $this->relationExists(self::GUARDIAN_RELS, $id);

            case 'assigned':
                if ($type !== 'student') return false;
                return $this->scopeValueExists((int) $role['membership_role_id'], 'student', $id)
                    || $this->relationExists(self::ASSIGN_RELS, $id);

            case 'own_classes':
            case 'class':
            case 'classes':
                return $type === 'class'
                    && ($this->scopeValueExists((int) $role['membership_role_id'], 'class', $id)
                        || $this->relationExistsTo(['teacher_of', 'main_teacher_of'], 'classe', $id));

            case 'students':
                return $type === 'student' && $this->scopeValueExists((int) $role['membership_role_id'], 'student', $id);

            case 'subject':
            case 'subjects':
                return $type === 'subject' && $this->scopeValueExists((int) $role['membership_role_id'], 'subject', $id);

            default:
                return false; // périmètre inconnu → fail-closed
        }
    }

    private function scopeValueExists(int $membershipRoleId, string $scopeType, int $scopeId): bool
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT 1 FROM tenant_membership_role_scope_values
                  WHERE membership_role_id = ? AND scope_type = ? AND scope_id = ? LIMIT 1"
            );
            $st->execute([$membershipRoleId, $scopeType, $scopeId]);
            return (bool) $st->fetchColumn();
        } catch (\PDOException $e) { return false; }
    }

    /** Relation du compte courant vers un élève (cible = tenant_account de l'élève). */
    private function relationExists(array $relTypes, int $studentAccountId): bool
    {
        return $this->relationExistsTo($relTypes, 'tenant_account', $studentAccountId);
    }

    private function relationExistsTo(array $relTypes, string $targetType, int $targetId): bool
    {
        $acc = $this->accountId();
        if ($acc <= 0 || $targetId <= 0 || $relTypes === []) return false;
        try {
            $place = implode(',', array_fill(0, count($relTypes), '?'));
            $st = $this->pdo->prepare(
                "SELECT 1 FROM account_relationships
                  WHERE source_type = 'tenant_account' AND source_id = ?
                    AND target_type = ? AND target_id = ?
                    AND relationship_type IN ($place) AND is_active = 1
                    AND (starts_at  IS NULL OR starts_at  <= NOW())
                    AND (expires_at IS NULL OR expires_at >= NOW())
                  LIMIT 1"
            );
            $st->execute(array_merge([$acc, $targetType, $targetId], $relTypes));
            return (bool) $st->fetchColumn();
        } catch (\PDOException $e) { return false; }
    }

    private function deny(string $permission): void
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $xhr    = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if (str_contains($accept, 'application/json') || $xhr === 'xmlhttprequest') {
            http_response_code(403);
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => true, 'code' => 403, 'message' => 'Accès refusé'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $_SESSION['error_message'] = "Accès refusé pour « {$permission} ».";
        if (!headers_sent()) {
            header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/accueil/accueil.php');
        }
        exit;
    }
}
