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
            isset($opts['etablissement_id']) && $opts['etablissement_id'] !== '' ? (int) $opts['etablissement_id'] : null,
            $opts['starts_at'] ?? null,
            $opts['expires_at'] ?? null,
            $actor['type'] ?? null, (int) ($actor['id'] ?? 0),
        ]);
        $id = (int) $this->pdo->lastInsertId();

        $this->audit($actor, 'relationship_added', $targetType, $targetId, [
            'relationship_type' => $relType, 'source_type' => $sourceType, 'source_id' => $sourceId,
            'etablissement_id' => $opts['etablissement_id'] ?? null, 'expires_at' => $opts['expires_at'] ?? null,
        ]);
        return $id;
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
    public function listFor(string $sourceType, int $sourceId): array
    {
        return $this->fetch(
            "SELECT * FROM account_relationships
              WHERE source_type = ? AND source_id = ? AND is_active = 1
              ORDER BY relationship_type, target_id",
            [$sourceType, $sourceId]
        );
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
