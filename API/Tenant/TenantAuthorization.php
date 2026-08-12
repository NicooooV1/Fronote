<?php
declare(strict_types=1);

namespace API\Tenant;

use PDO;

/**
 * TenantAuthorization — moteur d'autorisation du monde ÉTABLISSEMENT, indexé par
 * APPARTENANCE (tenant_membership). Un compte agit toujours dans le contexte d'un
 * établissement précis (sa membership) ; les rôles et leurs permissions sont
 * résolus à ce niveau : can(perm) → un rôle de l'appartenance accorde la permission ?
 * (Le contrôle par ressource/périmètre est porté par le moteur unique
 * API\Security\Authorization::canOn du côté applicatif.) Dégrade fail-closed.
 */
final class TenantAuthorization
{
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

    public function can(string $permission): bool
    {
        foreach ($this->roles() as $r) {
            if (TenantRoleCatalog::roleGrants($r['role_key'], $permission)) {
                return true;
            }
        }
        return false;
    }

    public function authorize(string $permission): void
    {
        if (!$this->can($permission)) { $this->deny($permission); }
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
