<?php
declare(strict_types=1);

namespace API\Platform;

use PDO;

/**
 * PlatformAuthorization — moteur d'autorisation du monde PLATEFORME.
 *
 * Modèle : un platform_account porte des rôles (platform_account_roles) ; chaque
 * rôle accorde des permissions (PlatformRoleCatalog). super_admin = accès total.
 * Aucune notion de périmètre établissement ici : la plateforme est globale par
 * nature (l'accès au CONTENU d'un établissement passe par le pont Support).
 */
final class PlatformAuthorization
{
    private PDO $pdo;
    private ?array $account;        // ['id'=>int, ...]
    private ?array $roleKeys = null;

    public function __construct(PDO $pdo, ?array $account = null)
    {
        $this->pdo = $pdo;
        $this->account = $account;
    }

    public function setAccount(?array $account): void
    {
        $this->account = $account;
        $this->roleKeys = null;
    }

    /** Clés de rôles plateforme actifs du compte. */
    public function roleKeys(): array
    {
        if ($this->roleKeys !== null) {
            return $this->roleKeys;
        }
        $id = (int) ($this->account['id'] ?? 0);
        if ($id <= 0) {
            return $this->roleKeys = [];
        }
        try {
            $stmt = $this->pdo->prepare(
                "SELECT pr.role_key
                   FROM platform_account_roles par
                   JOIN platform_roles pr ON pr.id = par.platform_role_id
                  WHERE par.platform_account_id = ? AND par.is_active = 1"
            );
            $stmt->execute([$id]);
            $this->roleKeys = array_values(array_unique($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
        } catch (\PDOException $e) {
            error_log('[PlatformAuthorization] ' . $e->getMessage());
            $this->roleKeys = [];
        }
        return $this->roleKeys;
    }

    public function hasRole(string $roleKey): bool
    {
        return in_array($roleKey, $this->roleKeys(), true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /** L'utilisateur plateforme a-t-il cette permission ? */
    public function can(string $permission): bool
    {
        if ($this->account === null) {
            return false;
        }
        foreach ($this->roleKeys() as $role) {
            if (PlatformRoleCatalog::roleGrants($role, $permission)) {
                return true;
            }
        }
        return false;
    }

    public function authorize(string $permission): void
    {
        if (!$this->can($permission)) {
            $this->deny($permission);
        }
    }

    private function deny(string $permission): void
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $xhr    = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if (str_contains($accept, 'application/json') || $xhr === 'xmlhttprequest') {
            http_response_code(403);
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => true, 'code' => 403, 'message' => 'Accès refusé (plateforme)'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $_SESSION['error_message'] = "Accès plateforme refusé pour « {$permission} ».";
        if (!headers_sent()) {
            header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/platform/login');
        }
        exit;
    }
}
