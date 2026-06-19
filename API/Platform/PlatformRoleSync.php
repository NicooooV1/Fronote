<?php
declare(strict_types=1);

namespace API\Platform;

use PDO;

/**
 * PlatformRoleSync — réconcilie le catalogue de rôles plateforme (code) vers la
 * table platform_roles (upsert idempotent, SQL portable). Équivalent plateforme
 * de RoleSync. À jouer après une migration de schéma.
 */
final class PlatformRoleSync
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return array{synced:int,errors:string[]} */
    public function sync(): array
    {
        $synced = 0;
        $errors = [];
        foreach (PlatformRoleCatalog::roles() as $key => $meta) {
            try {
                $sel = $this->pdo->prepare("SELECT id FROM platform_roles WHERE role_key = ? LIMIT 1");
                $sel->execute([$key]);
                $id = $sel->fetchColumn();
                if ($id === false) {
                    $this->pdo->prepare(
                        "INSERT INTO platform_roles (role_key, label, is_sensitive, is_system) VALUES (?, ?, ?, 1)"
                    )->execute([$key, $meta['label'], !empty($meta['sensitive']) ? 1 : 0]);
                } else {
                    $this->pdo->prepare(
                        "UPDATE platform_roles SET label = ?, is_sensitive = ? WHERE id = ?"
                    )->execute([$meta['label'], !empty($meta['sensitive']) ? 1 : 0, (int) $id]);
                }
                $synced++;
            } catch (\Throwable $e) {
                $errors[] = "{$key} : " . $e->getMessage();
            }
        }
        return ['synced' => $synced, 'errors' => $errors];
    }
}
