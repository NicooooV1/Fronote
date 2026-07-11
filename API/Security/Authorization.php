<?php
declare(strict_types=1);

namespace API\Security;

use PDO;

/**
 * Authorization — moteur RBAC/ABAC central.
 *
 * Modèle (cf. spécification rôles) :
 *   Utilisateur → possède un ou plusieurs RÔLES
 *   → chaque rôle accorde des PERMISSIONS
 *   → chaque permission est limitée par un PÉRIMÈTRE (scope)
 *   → chaque action sensible est JOURNALISÉE.
 *
 * Rétro-compatibilité : le "type de compte" (eleve/parent/professeur/vie_scolaire/
 * administrateur/super_admin/technicien) reste l'identité de session et sert de RÔLE
 * DE BASE. Des rôles supplémentaires (cpe, psychologue, infirmerie, direction,
 * professeur_principal, …) sont attribués via la table user_roles, éventuellement
 * scopés (établissement, classes, élèves assignés) et limités dans le temps.
 *
 * Les permissions par rôle proviennent de RoleCatalog (source de vérité en code),
 * synchronisées en base (rbac_permissions) par RoleSync. can() retombe sur le
 * catalogue si la base est indisponible.
 */
final class Authorization
{
    private PDO $pdo;
    private ?array $user;          // ['id'=>int,'type'=>string,'etablissement_id'=>int,...]
    private ?array $roles = null;  // liste de ['role'=>string,'scope_type'=>string,'scope'=>array,'etab'=>?int]
    private ?ScopeResolver $scopeResolver = null;

    public function __construct(PDO $pdo, ?array $user = null)
    {
        $this->pdo  = $pdo;
        $this->user = $user;
    }

    public function setUser(?array $user): void
    {
        $this->user  = $user;
        $this->roles = null;
    }

    /** super_admin = accès total (god mode infrastructure). */
    public function isSuperAdmin(): bool
    {
        return ($this->user['type'] ?? null) === 'super_admin';
    }

    /**
     * Rôles effectifs de l'utilisateur : rôle de base (type de compte) + rôles attribués
     * (user_roles) actifs à l'instant T. Chaque entrée porte son périmètre.
     */
    public function roles(): array
    {
        if ($this->roles !== null) {
            return $this->roles;
        }
        $roles = [];
        $type = $this->user['type'] ?? null;
        if ($type === null) {
            return $this->roles = [];
        }

        // 1) Rôle de base = type de compte, périmètre naturel.
        $roles[] = [
            'role'       => $type,
            'scope_type' => $this->baseScopeFor($type),
            'scope'      => [],
            'etab'       => isset($this->user['etablissement_id']) ? (int) $this->user['etablissement_id'] : null,
        ];

        // 2) Rôles attribués (multi-rôles scopés, temporisés).
        try {
            $stmt = $this->pdo->prepare(
                "SELECT role_key, etablissement_id, scope_type, scope_json
                   FROM user_roles
                  WHERE user_type = ? AND user_id = ?
                    AND (valid_from IS NULL OR valid_from <= NOW())
                    AND (valid_until IS NULL OR valid_until >= NOW())"
            );
            $stmt->execute([$type, (int) ($this->user['id'] ?? 0)]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $roles[] = [
                    'role'       => $r['role_key'],
                    'scope_type' => $r['scope_type'] ?: 'establishment',
                    'scope'      => $r['scope_json'] ? (json_decode($r['scope_json'], true) ?: []) : [],
                    'etab'       => $r['etablissement_id'] !== null ? (int) $r['etablissement_id'] : null,
                ];
            }
        } catch (\PDOException $e) {
            // table absente → seul le rôle de base s'applique (rétro-compat)
        }

        // 3) Rôles dérivés des sous-fonctions de compte (vie_scolaire : est_CPE / est_infirmerie).
        //    Le vrai personnel CPE/infirmerie obtient ainsi les rôles catalogue correspondants
        //    (et donc l'accès disciplinaire/médical) sans attribution manuelle dans user_roles.
        if ($type === 'vie_scolaire') {
            try {
                $vs = $this->pdo->prepare("SELECT est_CPE, est_infirmerie FROM vie_scolaire WHERE id = ? LIMIT 1");
                $vs->execute([(int) ($this->user['id'] ?? 0)]);
                if ($flags = $vs->fetch(PDO::FETCH_ASSOC)) {
                    $own = isset($this->user['etablissement_id']) ? (int) $this->user['etablissement_id'] : null;
                    if (($flags['est_CPE'] ?? 'non') === 'oui') {
                        $roles[] = ['role' => 'cpe', 'scope_type' => 'establishment', 'scope' => [], 'etab' => $own];
                    }
                    if (($flags['est_infirmerie'] ?? 'non') === 'oui') {
                        $roles[] = ['role' => 'infirmerie', 'scope_type' => 'establishment', 'scope' => [], 'etab' => $own];
                    }
                }
            } catch (\PDOException $e) {
                // colonnes/table absentes → ignorer
            }
        }

        return $this->roles = $roles;
    }

    /** Liste plate des clés de rôles effectifs. */
    public function roleKeys(): array
    {
        return array_values(array_unique(array_map(fn($r) => $r['role'], $this->roles())));
    }

    public function hasRole(string $roleKey): bool
    {
        return in_array($roleKey, $this->roleKeys(), true);
    }

    /**
     * Cœur : l'utilisateur peut-il $permission dans le contexte $ctx ?
     * $ctx fournit le périmètre de l'action : etablissement_id, class_id, subject_id,
     * student_id, owner_id/owner_type (propriétaire de la ressource).
     */
    public function can(string $permission, array $ctx = []): bool
    {
        if ($this->user === null) {
            return false;
        }
        if ($this->isSuperAdmin()) {
            $this->maybeAudit($permission, $ctx, true);
            return true;
        }

        $granted = false;
        foreach ($this->roles() as $r) {
            if (!$this->roleGrants($r['role'], $permission)) {
                continue;
            }
            if ($this->scopeAllows($r, $permission, $ctx)) {
                $granted = true;
                break;
            }
        }
        $this->maybeAudit($permission, $ctx, $granted);
        return $granted;
    }

    public function authorize(string $permission, array $ctx = []): void
    {
        if (!$this->can($permission, $ctx)) {
            $this->deny($permission);
        }
    }

    public function canAny(array $permissions, array $ctx = []): bool
    {
        foreach ($permissions as $p) {
            if ($this->can($p, $ctx)) return true;
        }
        return false;
    }

    /**
     * Variante ergonomique : « l'utilisateur peut-il $permission SUR cette ressource ? »
     * Construit le contexte de périmètre à partir du type/id de la ressource — c'est la
     * forme à privilégier dans les modules (anti-IDOR) : canOn('notes.view','student',$id).
     * $extra permet d'ajouter des clés de contexte (ex. owner_type, etablissement_id).
     */
    public function canOn(string $permission, string $resourceType, int $resourceId, array $extra = []): bool
    {
        return $this->can($permission, self::ctxForResource($resourceType, $resourceId) + $extra);
    }

    public function authorizeOn(string $permission, string $resourceType, int $resourceId, array $extra = []): void
    {
        if (!$this->canOn($permission, $resourceType, $resourceId, $extra)) {
            $this->deny($permission);
        }
    }

    /** Traduit (type de ressource, id) → clés de contexte attendues par scopeAllows(). */
    private static function ctxForResource(string $type, int $id): array
    {
        switch ($type) {
            case 'student':
            case 'eleve':            return ['student_id' => $id];
            case 'class':
            case 'classe':           return ['class_id' => $id];
            case 'establishment':
            case 'etablissement':    return ['etablissement_id' => $id];
            case 'subject':
            case 'matiere':          return ['subject_id' => $id];
            case 'self':
            case 'owner':            return ['owner_id' => $id];
            default:                 return ['resource_type' => $type, 'resource_id' => $id];
        }
    }

    /** Résolveur de périmètre (relations parent/AESH/prof…), synchronisé sur l'utilisateur courant. */
    private function scope(): ScopeResolver
    {
        if ($this->scopeResolver === null) {
            $this->scopeResolver = new ScopeResolver($this->pdo, $this->user ?? []);
        } else {
            $this->scopeResolver->setUser($this->user ?? []);
        }
        return $this->scopeResolver;
    }

    // ───────────────────────── interne ─────────────────────────

    /** Un rôle accorde-t-il une permission ? La matrice ÉDITABLE (rbac_permissions) prime
     *  sur le catalogue : c'est ce qui rend les permissions réellement gérables côté admin. */
    private function roleGrants(string $role, string $permission): bool
    {
        // Forme canonique du rôle (alias tenant/variantes → clé canonique du catalogue).
        $canon = RoleCatalog::canonical($role);
        // 1) Override explicite en base (matrice éditée par l'admin) : granted=1 → autorisé,
        //    granted=0 → refusé. Cherché sur le rôle ORIGINAL ET sa forme canonique — une surcharge
        //    posée sur une clé aliasée reste ainsi honorée. Le refus (MIN=0) l'emporte sur l'octroi.
        try {
            $stmt = $this->pdo->prepare(
                "SELECT MIN(granted) FROM rbac_permissions WHERE role IN (?, ?) AND permission = ?"
            );
            $stmt->execute([$role, $canon, $permission]);
            $g = $stmt->fetchColumn();
            if ($g !== false && $g !== null) {
                return (int) $g === 1;
            }
        } catch (\PDOException $e) {
            // table absente → on retombe sur le catalogue
        }
        // 2) Défaut : catalogue en code (RoleCatalog, résolu canonique).
        if (class_exists(RoleCatalog::class)) {
            $grants = RoleCatalog::grantsFor($canon);
            if (WildcardGrants::granted($grants, $permission)) return true;
            // Compat legacy : hasPermission('notes') interroge 'notes.manage'. Un rôle
            // "gère" un domaine s'il détient une action d'écriture du domaine (le wildcard
            // 'domaine.*' est déjà couvert par WildcardGrants). Permet aux rôles catalogue
            // (et attribués) de satisfaire canManageX() sans dépendre du repli RBAC.
            if (str_ends_with($permission, '.manage')) {
                $domain = explode('.', $permission)[0];
                foreach (['create', 'edit', 'delete', 'validate', 'publish'] as $act) {
                    if (in_array($domain . '.' . $act, $grants, true)) return true;
                }
            }
        }
        return false;
    }

    /** Le périmètre du rôle autorise-t-il l'action sur ce contexte ? */
    private function scopeAllows(array $r, string $permission, array $ctx): bool
    {
        $scopeType = $r['scope_type'];
        switch ($scopeType) {
            case 'global':
                return true;

            case 'establishment':
            case 'establishments':
                if (empty($ctx['etablissement_id'])) {
                    return true; // action non scopée à un établissement précis → autorisée au niveau rôle
                }
                $target = (int) $ctx['etablissement_id'];
                if ($scopeType === 'establishments' && !empty($r['scope']['etablissement_ids'])) {
                    return in_array($target, array_map('intval', $r['scope']['etablissement_ids']), true);
                }
                $own = $r['etab'] ?? (isset($this->user['etablissement_id']) ? (int) $this->user['etablissement_id'] : null);
                return $own === null || $own === $target;

            case 'self':
                if (empty($ctx['owner_id'])) return false;
                return (int) $ctx['owner_id'] === (int) ($this->user['id'] ?? 0)
                    && (($ctx['owner_type'] ?? $this->user['type']) === ($this->user['type'] ?? null));

            case 'children':
                if (empty($ctx['student_id'])) return false;
                // Responsable de l'élève : account_relationships ∪ parent_eleve (repli).
                return $this->scope()->isGuardianOf((int) $ctx['student_id']);

            case 'assigned':
                if (empty($ctx['student_id'])) return false;
                // 1) liste explicite portée par l'attribution (scope_json['student_ids']) ;
                $ids = array_map('intval', $r['scope']['student_ids'] ?? []);
                if (in_array((int) $ctx['student_id'], $ids, true)) return true;
                // 2) relation de suivi unifiée (AESH/psy/médical/social/tuteur entreprise).
                return $this->scope()->isAssignedToStudent((int) $ctx['student_id']);

            case 'own_classes':
                $cls = $ctx['class_id'] ?? ($ctx['class_name'] ?? null);
                if ($cls === null) return false;
                // 1) classes explicitement portées par l'attribution (scope_json['class_ids']) ;
                if (isset($ctx['class_id'])) {
                    $ids = array_map('intval', $r['scope']['class_ids'] ?? []);
                    if (in_array((int) $ctx['class_id'], $ids, true)) return true;
                }
                // 2) classe enseignée : account_relationships ∪ professeur_classes (repli).
                return $this->scope()->teachesClass($cls);

            default:
                return false; // périmètre inconnu → fail-closed
        }
    }

    /** Périmètre naturel d'un type de compte. */
    private function baseScopeFor(string $type): string
    {
        switch ($type) {
            case 'super_admin':   return 'global';
            case 'administrateur':
            case 'technicien':
            case 'vie_scolaire':  return 'establishment';
            case 'professeur':    return 'establishment'; // lecture établissement ; écriture fine via ctx (own_classes) au niveau module
            case 'parent':        return 'children';
            case 'eleve':         return 'self';
            default:              return 'establishment';
        }
    }

    /** Journalise les permissions marquées sensibles (médical, psy, social, purge, export…). */
    private function maybeAudit(string $permission, array $ctx, bool $granted): void
    {
        $sensitive = class_exists(RoleCatalog::class) && RoleCatalog::isSensitive($permission);
        if (!$sensitive) return;
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO audit_log (action, model, model_id, user_id, user_type, ip_address, user_agent, new_values)
                 VALUES ('authz.sensitive', ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $permission,
                isset($ctx['student_id']) ? (int) $ctx['student_id'] : null,
                (int) ($this->user['id'] ?? 0),
                $this->user['type'] ?? null,
                $_SERVER['REMOTE_ADDR'] ?? '',
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                json_encode(['granted' => $granted, 'ctx' => $ctx], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\PDOException $e) {
            error_log('[authz] audit failed: ' . $e->getMessage());
        }
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
        $_SESSION['error_message'] = "Vous n'avez pas les droits pour cette action.";
        if (!headers_sent()) {
            header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/accueil/accueil.php');
        }
        exit;
    }
}
