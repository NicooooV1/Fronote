<?php
declare(strict_types=1);

namespace API\Tenant;

use PDO;

/**
 * TenantRoleSync — réconcilie le catalogue de rôles établissement (code) vers la
 * table tenant_roles (upsert idempotent, SQL portable). Ne contient aucun rôle plateforme.
 */
require_once __DIR__ . '/../Services/AbstractTenantService.php';

final class TenantRoleSync extends \API\Services\AbstractTenantService
{

    /** @return array{synced:int,errors:string[]} */
    public function sync(): array
    {
        $synced = 0;
        $errors = [];
        foreach (TenantRoleCatalog::roles() as $key => $meta) {
            try {
                $sel = $this->pdo->prepare("SELECT id FROM tenant_roles WHERE role_key = ? LIMIT 1");
                $sel->execute([$key]);
                $id = $sel->fetchColumn();
                if ($id === false) {
                    $this->pdo->prepare(
                        "INSERT INTO tenant_roles (role_key, label, category, default_scope_type, is_sensitive, is_system)
                         VALUES (?, ?, ?, ?, ?, 1)"
                    )->execute([$key, $meta['label'], $meta['category'], $meta['scope'], $meta['sensitive'] ? 1 : 0]);
                } else {
                    $this->pdo->prepare(
                        "UPDATE tenant_roles SET label = ?, category = ?, default_scope_type = ?, is_sensitive = ? WHERE id = ?"
                    )->execute([$meta['label'], $meta['category'], $meta['scope'], $meta['sensitive'] ? 1 : 0, (int) $id]);
                }
                $synced++;
            } catch (\Throwable $e) {
                $errors[] = "{$key} : " . $e->getMessage();
            }
        }
        return ['synced' => $synced, 'errors' => $errors];
    }
}
