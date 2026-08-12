<?php
declare(strict_types=1);

namespace API\Services;

use PDO;

/**
 * RelationshipService — gestion des relations métier entre comptes
 * (table account_relationships) : parent ↔ élève, prof ↔ classe, AESH/psy/médical/
 * social ↔ élève, tuteur entreprise ↔ élève, …
 *
 * C'est la voie d'écriture du modèle de relations unifié consommé en lecture par
 * ScopeResolver (et donc par Authorization pour les scopes children/assigned/own_classes).
 * Chaque mutation est journalisée dans user_role_audit_logs.
 */
final class RelationshipService
{
    /** Types de relation reconnus (cf. cahier des charges §5.8). */
    public const TYPES = [
        'parent_of', 'legal_guardian_of', 'financial_responsible_of',
        'aesh_of', 'teacher_of', 'main_teacher_of', 'tutor_of', 'company_tutor_of',
        'medical_follow_of', 'psychological_follow_of', 'social_follow_of',
    ];

    /** Type de compte/ressource → table (+ colonne etablissement_id) servant à valider
     *  l'appartenance à l'établissement courant. Liste blanche : tout type absent est
     *  refusé (fail-closed) et le nom de table n'est jamais issu de l'entrée client. */
    private const TYPE_TABLES = [
        'eleve'          => 'eleves',
        'parent'         => 'parents',
        'professeur'     => 'professeurs',
        'vie_scolaire'   => 'vie_scolaire',
        'administrateur' => 'administrateurs',
        'classe'         => 'classes',
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Crée (ou réactive) une relation. Idempotent via la clé unique uk_rel.
     * $opts : etablissement_id, starts_at, expires_at.
     * @throws \RuntimeException si le type de relation est inconnu.
     */
    public function add(array $actor, string $sourceType, int $sourceId, string $relType, string $targetType, int $targetId, array $opts = []): int
    {
        if (!in_array($relType, self::TYPES, true)) {
            throw new \RuntimeException("Type de relation inconnu : « {$relType} ».");
        }
        if ($sourceId <= 0 || $targetId <= 0) {
            throw new \RuntimeException('Source et cible doivent être des identifiants valides.');
        }

        // ── Cloisonnement multi-établissement (faille : source/cible/etablissement_id
        //    entièrement pilotés par le client). L'établissement est TOUJOURS déduit du
        //    contexte courant ; toute valeur cliente ($opts['etablissement_id']) est ignorée.
        //    Une relation accorde un PÉRIMÈTRE : sans ce garde-fou, on pourrait poser une
        //    relation dans/vers n'importe quel établissement. Fail-closed sur toute incohérence.
        $etab = 0;
        try { $etab = (int) \API\Core\EstablishmentContext::id(); } catch (\Throwable $e) { $etab = 0; }
        if ($etab <= 0) {
            throw new \RuntimeException("Contexte d'établissement non résolu : création de relation refusée.");
        }
        // L'acteur doit être habilité à écrire les relations de CET établissement.
        if (!$this->actorMayManage($actor, $etab)) {
            throw new \RuntimeException("Vous n'êtes pas autorisé à créer cette relation.");
        }
        // La source ET la cible doivent EXISTER dans cet établissement (anti-injection de
        // périmètre inter-établissement).
        if (!$this->accountInEtab($sourceType, $sourceId, $etab)) {
            throw new \RuntimeException('Compte source introuvable dans cet établissement.');
        }
        if (!$this->accountInEtab($targetType, $targetId, $etab)) {
            throw new \RuntimeException('Compte cible introuvable dans cet établissement.');
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO account_relationships
                (source_type, source_id, target_type, target_id, relationship_type,
                 etablissement_id, starts_at, expires_at, is_active, created_by_type, created_by_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE
                id               = LAST_INSERT_ID(id),
                etablissement_id = VALUES(etablissement_id),
                starts_at        = VALUES(starts_at),
                expires_at       = VALUES(expires_at),
                is_active        = 1"
        );
        $stmt->execute([
            $sourceType, $sourceId, $targetType, $targetId, $relType,
            $etab,
            $opts['starts_at'] ?? null,
            $opts['expires_at'] ?? null,
            $actor['type'] ?? null, (int) ($actor['id'] ?? 0),
        ]);
        $id = (int) $this->pdo->lastInsertId();

        $this->audit($actor, 'relationship_added', $targetType, $targetId, [
            'relationship_type' => $relType, 'source_type' => $sourceType, 'source_id' => $sourceId,
            'etablissement_id' => $etab, 'expires_at' => $opts['expires_at'] ?? null,
        ]);
        return $id;
    }

    /**
     * L'acteur est-il habilité à créer une relation dans l'établissement $etab ?
     * super_admin : périmètre global. administrateur : uniquement DANS son établissement
     * (appartenance vérifiée via la session, à défaut en base). Tout autre type : refus.
     */
    private function actorMayManage(array $actor, int $etab): bool
    {
        $type = (string) ($actor['type'] ?? '');
        if ($type === 'super_admin') {
            return true;
        }
        if ($type !== 'administrateur') {
            return false; // seuls administrateur/super_admin gèrent les relations
        }
        $actorEtab = isset($actor['etablissement_id']) ? (int) $actor['etablissement_id'] : 0;
        if ($actorEtab > 0) {
            return $actorEtab === $etab;
        }
        // Établissement non porté par la session → on confirme l'appartenance en base.
        return $this->accountInEtab('administrateur', (int) ($actor['id'] ?? 0), $etab);
    }

    /** Le compte/ressource ($type,$id) existe-t-il dans l'établissement $etab ? Fail-closed. */
    private function accountInEtab(string $type, int $id, int $etab): bool
    {
        $table = self::TYPE_TABLES[$type] ?? null;
        if ($table === null || $id <= 0 || $etab <= 0) {
            return false; // type non reconnu → refus
        }
        try {
            // $table provient d'une liste blanche (jamais de l'entrée client) : interpolation sûre.
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM `{$table}` WHERE id = ? AND etablissement_id = ? LIMIT 1"
            );
            $stmt->execute([$id, $etab]);
            return (bool) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            error_log('[relationships] accountInEtab: ' . $e->getMessage());
            return false; // toute erreur ⇒ refus
        }
    }

    /** Désactive une relation (soft-delete : is_active=0) par son id. */
    public function remove(array $actor, int $id): bool
    {
        $stmt = $this->pdo->prepare("SELECT * FROM account_relationships WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        // Cloisonnement multi-établissement (symétrique à add()) : l'acteur doit gérer
        // l'établissement de la relation. Sans ce contrôle, un admin d'un établissement
        // pouvait désactiver n'importe quelle relation d'un autre tenant (IDOR write).
        if (!$this->actorMayManage($actor, (int) $row['etablissement_id'])) {
            return false;
        }
        $ok = $this->pdo->prepare("UPDATE account_relationships SET is_active = 0 WHERE id = ?")->execute([$id]);
        if ($ok) {
            $this->audit($actor, 'relationship_removed', $row['target_type'], (int) $row['target_id'], [
                'relationship_type' => $row['relationship_type'],
                'source_type' => $row['source_type'], 'source_id' => (int) $row['source_id'],
            ]);
        }
        return (bool) $ok;
    }

    /** Relations actives DONT un compte est la source (ses élèves/classes suivis). */
    public function listFor(string $sourceType, int $sourceId, ?int $etabId = null): array
    {
        // Cloisonnement tenant optionnel : la page admin passe l'établissement courant,
        // sinon un admin énumérait les relations (et le rattachement établissement) de
        // comptes d'autres tenants par simple saisie d'un id (fuite lecture cross-tenant).
        $sql = "SELECT * FROM account_relationships
                 WHERE source_type = ? AND source_id = ? AND is_active = 1";
        $params = [$sourceType, $sourceId];
        if ($etabId !== null) {
            $sql .= " AND etablissement_id = ?";
            $params[] = $etabId;
        }
        $sql .= " ORDER BY relationship_type, target_id";
        return $this->fetch($sql, $params);
    }

    /** Relations actives DONT un compte/ressource est la cible (qui suit cet élève ?). */
    public function listTargets(string $targetType, int $targetId): array
    {
        return $this->fetch(
            "SELECT * FROM account_relationships
              WHERE target_type = ? AND target_id = ? AND is_active = 1
              ORDER BY relationship_type, source_id",
            [$targetType, $targetId]
        );
    }

    private function fetch(string $sql, array $args): array
    {
        try {
            $s = $this->pdo->prepare($sql);
            $s->execute($args);
            return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            return [];
        }
    }

    private function audit(array $actor, string $action, string $targetType, int $targetId, array $newValue): void
    {
        try {
            $this->pdo->prepare(
                "INSERT INTO user_role_audit_logs
                    (actor_type, actor_id, target_type, target_id, action, new_value, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $actor['type'] ?? null, (int) ($actor['id'] ?? 0),
                $targetType, $targetId, $action,
                json_encode($newValue, JSON_UNESCAPED_UNICODE),
                $_SERVER['REMOTE_ADDR'] ?? '',
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ]);
        } catch (\PDOException $e) {
            error_log('[relationships] audit failed: ' . $e->getMessage());
        }
    }
}
