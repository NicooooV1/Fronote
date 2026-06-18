<?php
declare(strict_types=1);

namespace API\Security;

use PDO;

/**
 * RoleSync — réconcilie le catalogue de rôles/permissions (code) vers la base.
 *
 * Source de vérité = RoleCatalog (en code). sync() est idempotent : il insère/maj les
 * rôles dans rbac_roles et les grants rôle→permission dans rbac_permissions. À appeler
 * lors de la mise à jour (UpdateService) ou via une commande admin. Sans effet de bord
 * destructif : ne supprime pas les permissions dynamiques ajoutées hors catalogue.
 */
final class RoleSync
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return array{roles:int,grants:int,errors:string[]} */
    public function sync(): array
    {
        $res = ['roles' => 0, 'grants' => 0, 'errors' => []];
        if (!class_exists(RoleCatalog::class)) {
            $res['errors'][] = 'RoleCatalog introuvable';
            return $res;
        }

        try {
            $upRole = $this->pdo->prepare(
                "INSERT INTO rbac_roles (role_key, label, tier, is_system, sensitive, description)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE label=VALUES(label), tier=VALUES(tier),
                   is_system=VALUES(is_system), sensitive=VALUES(sensitive), description=VALUES(description)"
            );
            foreach (RoleCatalog::roles() as $key => $meta) {
                $upRole->execute([
                    $key,
                    $meta['label'] ?? $key,
                    $meta['tier'] ?? 'autre',
                    !empty($meta['is_system']) ? 1 : 0,
                    !empty($meta['sensitive']) ? 1 : 0,
                    $meta['description'] ?? null,
                ]);
                $res['roles']++;
            }
        } catch (\PDOException $e) {
            $res['errors'][] = 'roles: ' . $e->getMessage();
        }

        try {
            $upGrant = $this->pdo->prepare(
                "INSERT INTO rbac_permissions (role, permission, granted)
                 VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE granted = 1, updated_at = NOW()"
            );
            foreach (RoleCatalog::grants() as $role => $perms) {
                foreach ($perms as $perm) {
                    if ($perm === '*') continue; // le wildcard est géré en code (super_admin), pas stocké
                    $upGrant->execute([$role, $perm]);
                    $res['grants']++;
                }
            }
        } catch (\PDOException $e) {
            $res['errors'][] = 'grants: ' . $e->getMessage();
        }

        return $res;
    }
}
