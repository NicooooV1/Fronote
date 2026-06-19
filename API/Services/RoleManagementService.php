<?php
declare(strict_types=1);

namespace API\Services;

use PDO;
use API\Security\RoleCatalog;

/**
 * RoleManagementService — attribution/révocation des rôles applicatifs (table user_roles).
 *
 * Garde-fous (cf. spécification : "aucun rôle ne peut s'auto-élever / s'agrandir") :
 *  - seul un super_admin peut attribuer le rôle super_admin ;
 *  - un acteur ne peut attribuer que des rôles de son ensemble assignable ;
 *  - interdiction de s'auto-attribuer un rôle qu'on ne possède pas déjà (anti-escalade) ;
 *  - chaque attribution/révocation est journalisée.
 */
final class RoleManagementService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Ensemble des rôles qu'un acteur (donné par ses rôles effectifs) peut attribuer. */
    public function assignableRoles(array $actorRoleKeys): array
    {
        $all = array_keys(RoleCatalog::roles());
        if (in_array('super_admin', $actorRoleKeys, true)) {
            return $all; // super_admin attribue tout
        }
        $managers = ['administrateur', 'direction', 'chef_etablissement', 'direction_adjointe', 'responsable_permissions'];
        if (array_intersect($managers, $actorRoleKeys)) {
            // Tout sauf le rôle infrastructure super_admin.
            return array_values(array_filter($all, fn($r) => $r !== 'super_admin'));
        }
        return []; // les autres rôles ne gèrent pas les attributions
    }

    /** Rôles actuellement attribués à un utilisateur (avec libellé du catalogue). */
    public function listUserRoles(string $userType, int $userId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM user_roles WHERE user_type = ? AND user_id = ? ORDER BY role_key"
            );
            $stmt->execute([$userType, $userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
        $cat = RoleCatalog::roles();
        foreach ($rows as &$r) {
            $r['label'] = $cat[$r['role_key']]['label'] ?? $r['role_key'];
        }
        return $rows;
    }

    /**
     * Attribue un rôle. $actor = ['type'=>,'id'=>], $actorRoleKeys = rôles effectifs de l'acteur.
     * @throws \RuntimeException si l'acteur n'a pas le droit (anti-escalade).
     */
    public function assign(array $actor, array $actorRoleKeys, string $userType, int $userId, string $roleKey, array $opts = []): int
    {
        if (!isset(RoleCatalog::roles()[$roleKey])) {
            throw new \RuntimeException("Rôle inconnu : {$roleKey}");
        }
        if (!in_array($roleKey, $this->assignableRoles($actorRoleKeys), true)) {
            throw new \RuntimeException("Vous n'avez pas le droit d'attribuer le rôle « {$roleKey} ».");
        }
        // Compatibilité type de compte ↔ rôle : un rôle d'un tier non autorisé pour ce
        // type de compte est refusé (ex. la vie scolaire ne reçoit pas de rôle d'administration).
        // super_admin n'est pas limité.
        if (!in_array('super_admin', $actorRoleKeys, true)
            && !isset(RoleCatalog::rolesForAccount($userType)[$roleKey])) {
            throw new \RuntimeException("Le rôle « {$roleKey} » n'est pas compatible avec un compte « {$userType} ».");
        }
        // Anti-escalade : on ne s'attribue pas à soi-même un rôle qu'on ne possède pas déjà.
        $isSelf = ($actor['type'] ?? null) === $userType && (int) ($actor['id'] ?? 0) === $userId;
        if ($isSelf && !in_array($roleKey, $actorRoleKeys, true)) {
            throw new \RuntimeException("Auto-attribution d'un rôle non détenu interdite.");
        }

        $etabId   = isset($opts['etablissement_id']) && $opts['etablissement_id'] !== '' ? (int) $opts['etablissement_id'] : null;
        $meta     = RoleCatalog::roles()[$roleKey];
        $catScope = $meta['scope'] ?? 'establishment';

        // Périmètre : valider contre la liste connue ; défaut = périmètre catalogue du rôle.
        $allowedScopes = ['global', 'establishment', 'establishments', 'self', 'children', 'assigned', 'own_classes'];
        $scopeType = $opts['scope_type'] ?? $catScope;
        if (!in_array($scopeType, $allowedScopes, true)) {
            $scopeType = $catScope;
        }
        // Garde-fou anti-élargissement : un rôle SENSIBLE (médical/psy/social/handicap/pai…)
        // ne peut être élargi à 'global' que si le catalogue le prévoit explicitement.
        // Empêche un acteur d'attribuer p.ex. 'psychologue' en périmètre global.
        if ($scopeType === 'global' && $catScope !== 'global' && !empty($meta['sensitive'])) {
            $scopeType = $catScope;
        }

        $scopeJson = !empty($opts['scope']) && is_array($opts['scope']) ? json_encode($opts['scope']) : null;
        $from      = $opts['valid_from'] ?? null;
        $until     = $opts['valid_until'] ?? null;

        $stmt = $this->pdo->prepare(
            "INSERT INTO user_roles (user_type, user_id, role_key, etablissement_id, scope_type, scope_json, valid_from, valid_until, granted_by_type, granted_by_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE scope_type=VALUES(scope_type), scope_json=VALUES(scope_json),
               valid_from=VALUES(valid_from), valid_until=VALUES(valid_until),
               granted_by_type=VALUES(granted_by_type), granted_by_id=VALUES(granted_by_id)"
        );
        $stmt->execute([
            $userType, $userId, $roleKey, $etabId, $scopeType, $scopeJson, $from ?: null, $until ?: null,
            $actor['type'] ?? null, (int) ($actor['id'] ?? 0),
        ]);
        $id = (int) $this->pdo->lastInsertId();

        $this->audit('role.assigned', $userType, $userId, [
            'role' => $roleKey, 'etablissement_id' => $etabId, 'scope_type' => $scopeType,
            'valid_until' => $until, 'by' => ($actor['type'] ?? '') . ':' . ($actor['id'] ?? ''),
        ]);
        return $id;
    }

    /** Révoque une attribution par son id. */
    public function revoke(array $actor, array $actorRoleKeys, int $rowId): bool
    {
        if (empty($this->assignableRoles($actorRoleKeys))) {
            throw new \RuntimeException("Vous n'avez pas le droit de révoquer des rôles.");
        }
        $stmt = $this->pdo->prepare("SELECT * FROM user_roles WHERE id = ?");
        $stmt->execute([$rowId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        if (!in_array($row['role_key'], $this->assignableRoles($actorRoleKeys), true)) {
            throw new \RuntimeException("Rôle hors de votre périmètre d'attribution.");
        }
        $ok = $this->pdo->prepare("DELETE FROM user_roles WHERE id = ?")->execute([$rowId]);
        if ($ok) {
            $this->audit('role.revoked', $row['user_type'], (int) $row['user_id'], [
                'role' => $row['role_key'], 'by' => ($actor['type'] ?? '') . ':' . ($actor['id'] ?? ''),
            ]);
        }
        return $ok;
    }

    private function audit(string $action, string $userType, int $userId, array $details): void
    {
        try {
            $this->pdo->prepare(
                "INSERT INTO audit_log (action, model, model_id, user_id, user_type, ip_address, new_values)
                 VALUES (?, 'user_roles', ?, ?, ?, ?, ?)"
            )->execute([
                $action, $userId,
                (int) ($_SESSION['user_id'] ?? 0), $_SESSION['user_type'] ?? null,
                $_SERVER['REMOTE_ADDR'] ?? '',
                json_encode($details, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\PDOException $e) {
            error_log('[roles] audit failed: ' . $e->getMessage());
        }
    }
}
