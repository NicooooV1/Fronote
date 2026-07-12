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

        // Anti-IDOR cross-établissement : la CIBLE doit appartenir à l'établissement de l'acteur
        // (sauf super_admin). Résolu côté serveur, jamais depuis les paramètres de requête.
        if (!in_array('super_admin', $actorRoleKeys, true)
            && !$this->targetInActorEstablishment($userType, $userId)) {
            throw new \RuntimeException("Utilisateur cible hors de votre établissement.");
        }

        // Securite (anti-escalade inter-etablissement) : seul super_admin peut viser un
        // etablissement_id arbitraire ; un acteur normal est borne a SON etablissement courant,
        // quelle que soit la valeur fournie en opts.
        if (in_array('super_admin', $actorRoleKeys, true)) {
            $etabId = isset($opts['etablissement_id']) && $opts['etablissement_id'] !== '' ? (int) $opts['etablissement_id'] : null;
        } else {
            try { $etabId = \API\Core\EstablishmentContext::id(); } catch (\Throwable $e) { $etabId = null; }
        }
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

        // Rôle SENSIBLE : justification obligatoire (cf. §8.2 — confirmation forte + audit).
        $reason = isset($opts['reason']) ? trim((string) $opts['reason']) : '';
        if (!empty($meta['sensitive']) && $reason === '') {
            throw new \RuntimeException("Le rôle sensible « {$roleKey} » exige une justification.");
        }

        // Validation stricte du périmètre fin (scope_json) : seules des listes d'IDs
        // entiers sous des clés connues sont acceptées (refus silencieux des typos).
        $cleanScope = $this->validateScope(is_array($opts['scope'] ?? null) ? $opts['scope'] : []);
        $scopeJson  = $cleanScope ? json_encode($cleanScope) : null;
        $from       = $opts['valid_from'] ?? null;
        $until      = $opts['valid_until'] ?? null;

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
        $this->roleAudit($actor, 'role_assigned', $userType, $userId, $roleKey, null, [
            'scope_type' => $scopeType, 'scope' => $cleanScope, 'etablissement_id' => $etabId,
            'valid_from' => $from ?: null, 'valid_until' => $until ?: null, 'reason' => $reason ?: null,
        ]);
        return $id;
    }

    /**
     * La cible (user_type, user_id) appartient-elle à l'établissement courant de l'acteur ?
     * Anti-IDOR cross-établissement. Fail-closed (type inconnu / erreur → refus).
     */
    private function targetInActorEstablishment(string $userType, int $userId): bool
    {
        static $map = [
            'eleve' => 'eleves', 'parent' => 'parents', 'professeur' => 'professeurs',
            'vie_scolaire' => 'vie_scolaire', 'administrateur' => 'administrateurs',
        ];
        $table = $map[$userType] ?? null;
        if ($table === null || $userId <= 0) {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare("SELECT 1 FROM `$table` WHERE id = ? AND etablissement_id = ? LIMIT 1");
            $stmt->execute([$userId, \API\Core\EstablishmentContext::id()]);
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
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
        // Anti-IDOR : ne pas révoquer une attribution d'un utilisateur hors de son établissement.
        if (!in_array('super_admin', $actorRoleKeys, true)
            && !$this->targetInActorEstablishment((string) $row['user_type'], (int) $row['user_id'])) {
            throw new \RuntimeException("Attribution hors de votre établissement.");
        }
        $ok = $this->pdo->prepare("DELETE FROM user_roles WHERE id = ?")->execute([$rowId]);
        if ($ok) {
            $this->audit('role.revoked', $row['user_type'], (int) $row['user_id'], [
                'role' => $row['role_key'], 'by' => ($actor['type'] ?? '') . ':' . ($actor['id'] ?? ''),
            ]);
            $this->roleAudit($actor, 'role_revoked', $row['user_type'], (int) $row['user_id'], $row['role_key'], [
                'scope_type' => $row['scope_type'] ?? null, 'etablissement_id' => $row['etablissement_id'] ?? null,
            ], null);
        }
        return $ok;
    }

    /**
     * Purge les rôles attribués EXPIRÉS (valid_until dépassé) — comptes temporaires,
     * vacataires, invités, techniciens. À déclencher périodiquement (scripts/purge_expired_roles.php
     * en cron) pour garantir l'expiration automatique exigée par le cahier des charges (§10.5).
     * @return int nombre d'attributions purgées.
     */
    public function purgeExpired(): int
    {
        try {
            $sel = $this->pdo->query(
                "SELECT id, user_type, user_id, role_key FROM user_roles
                  WHERE valid_until IS NOT NULL AND valid_until < NOW()"
            );
            $rows = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            return 0;
        }
        if ($rows === []) {
            return 0;
        }
        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        $place = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->prepare("DELETE FROM user_roles WHERE id IN ($place)")->execute($ids);
        foreach ($rows as $r) {
            $this->roleAudit(['type' => 'system', 'id' => 0], 'role_revoked',
                $r['user_type'], (int) $r['user_id'], $r['role_key'], ['cause' => 'expired'], null);
        }
        return count($rows);
    }

    /**
     * Valide un périmètre fin : uniquement des listes d'IDs entiers (>0) sous des clés
     * connues. Toute clé inconnue ou valeur non-liste est rejetée (anti scope_json malformé).
     * @return array périmètre nettoyé (clés présentes seulement si non vides).
     */
    private function validateScope(array $scope): array
    {
        $allowed = ['class_ids', 'classes', 'student_ids', 'students', 'etablissement_ids', 'subject_ids', 'group_ids'];
        $clean = [];
        foreach ($scope as $key => $values) {
            if (!in_array($key, $allowed, true)) {
                throw new \RuntimeException("Clé de périmètre inconnue : « {$key} ».");
            }
            if (!is_array($values)) {
                throw new \RuntimeException("Le périmètre « {$key} » doit être une liste d'identifiants.");
            }
            $ints = array_values(array_filter(array_map('intval', $values), static fn($n) => $n > 0));
            if ($ints !== []) {
                $clean[$key] = array_values(array_unique($ints));
            }
        }
        return $clean;
    }

    /** Journal dédié des changements de rôles/relations/accès sensibles (table user_role_audit_logs). */
    private function roleAudit(array $actor, string $action, string $targetType, int $targetId, ?string $roleKey, ?array $oldValue, ?array $newValue): void
    {
        try {
            $this->pdo->prepare(
                "INSERT INTO user_role_audit_logs
                    (actor_type, actor_id, target_type, target_id, action, role_key, old_value, new_value, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $actor['type'] ?? null, (int) ($actor['id'] ?? 0),
                $targetType, $targetId, $action, $roleKey,
                $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
                $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
                $_SERVER['REMOTE_ADDR'] ?? '',
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ]);
        } catch (\PDOException $e) {
            error_log('[roles] user_role_audit_logs failed: ' . $e->getMessage());
        }
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
