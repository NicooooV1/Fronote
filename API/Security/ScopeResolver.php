<?php
declare(strict_types=1);

namespace API\Security;

use PDO;

/**
 * ScopeResolver — résout le PÉRIMÈTRE effectif d'un utilisateur.
 *
 * Deux usages :
 *  1) Vérifier une RELATION sujet → ressource (parent_of, aesh_of, teacher_of,
 *     medical_follow_of, …) — utilisé par Authorization pour les scopes
 *     children / assigned / own_classes.
 *  2) Lister les ressources accessibles (élèves, classes, établissements) — utilisé
 *     par les requêtes scopées des modules et par le simulateur de permissions.
 *
 * Source unifiée : la table `account_relationships` (modèle cible du cahier des
 * charges §5.8), avec REPLI sur les liens hérités (parent_eleve, professeur_classes)
 * tant que la migration des relations n'est pas terminée → aucune régression.
 * Toute requête dégrade proprement (catch) si une table est absente : sur SQLite
 * de test sans account_relationships, on retombe sur les liens hérités.
 */
final class ScopeResolver
{
    /** Relations « responsable légal/financier » d'un élève. */
    public const GUARDIAN_RELS = ['parent_of', 'legal_guardian_of', 'financial_responsible_of', 'tutor_of'];
    /** Relations de suivi/assignation d'un élève (AESH, psy, médical, social, tuteur entreprise). */
    public const ASSIGN_RELS   = ['aesh_of', 'psychological_follow_of', 'medical_follow_of', 'social_follow_of', 'tutor_of', 'company_tutor_of'];
    /** Relations d'enseignement vers une classe. */
    public const TEACH_RELS    = ['teacher_of', 'main_teacher_of'];

    private PDO $pdo;
    private array $user;

    public function __construct(PDO $pdo, array $user = [])
    {
        $this->pdo  = $pdo;
        $this->user = $user;
    }

    public function setUser(array $user): void
    {
        $this->user = $user;
    }

    private function uid(): int      { return (int) ($this->user['id'] ?? 0); }
    private function utype(): string { return (string) ($this->user['type'] ?? ''); }

    // ───────────────────────── relations (vérification) ─────────────────────────

    /** L'utilisateur courant entretient-il une relation $relType avec ($targetType,$targetId) ? */
    public function relates(string $relType, string $targetType, int $targetId): bool
    {
        return $this->relationExists($this->utype(), $this->uid(), [$relType], $targetType, $targetId);
    }

    /** L'utilisateur courant est-il responsable (parent / tuteur légal / …) de l'élève $studentId ? */
    public function isGuardianOf(int $studentId): bool
    {
        $state = $this->relationState(self::GUARDIAN_RELS, 'eleve', $studentId);
        if ($state === 'active') {
            return true;
        }
        // Une relation existe mais est désactivée/expirée → décision AUTORITAIRE (révoquée) :
        // surtout PAS de repli hérité, sinon la révocation serait contournée par parent_eleve.
        if ($state === 'inactive') {
            return false;
        }
        // Aucune relation enregistrée → repli sur le lien hérité (parent non encore reflété).
        if ($this->utype() === 'parent') {
            return $this->legacyExists(
                'SELECT 1 FROM parent_eleve WHERE id_parent = ? AND id_eleve = ? LIMIT 1',
                [$this->uid(), $studentId]
            );
        }
        return false;
    }

    /** L'élève $studentId fait-il partie des élèves ASSIGNÉS à l'utilisateur (AESH/psy/médical/…) ? */
    public function isAssignedToStudent(int $studentId): bool
    {
        return $this->relationExists($this->utype(), $this->uid(), self::ASSIGN_RELS, 'eleve', $studentId);
    }

    /** L'utilisateur courant enseigne-t-il à la classe (id numérique ou nom) ? */
    public function teachesClass($class): bool
    {
        $classId = $this->classId($class);
        if ($classId !== null) {
            $state = $this->relationState(self::TEACH_RELS, 'classe', $classId);
            if ($state === 'active') {
                return true;
            }
            // Relation désactivée/expirée → autoritaire (pas de repli, sinon révocation contournée).
            if ($state === 'inactive') {
                return false;
            }
        }
        // Aucune relation enregistrée → repli sur professeur_classes (lien hérité par NOM).
        if ($this->utype() === 'professeur') {
            $nom = $this->className($class);
            if ($nom !== null) {
                return $this->legacyExists(
                    'SELECT 1 FROM professeur_classes WHERE id_professeur = ? AND nom_classe = ? LIMIT 1',
                    [$this->uid(), $nom]
                );
            }
        }
        return false;
    }

    // ───────────────────────── ressources accessibles (listes) ─────────────────────────

    /** IDs des élèves dont l'utilisateur est responsable (account_relationships ∪ parent_eleve). */
    public function guardianStudentIds(): array
    {
        $ids = $this->relationTargetIds(self::GUARDIAN_RELS, 'eleve');
        if ($this->utype() === 'parent') {
            $ids = array_merge($ids, $this->legacyColumn(
                'SELECT id_eleve FROM parent_eleve WHERE id_parent = ?', [$this->uid()]
            ));
        }
        return $this->uniqInts($ids);
    }

    /** IDs des élèves assignés à l'utilisateur (suivis AESH/psy/médical/social/tuteur). */
    public function assignedStudentIds(): array
    {
        return $this->uniqInts($this->relationTargetIds(self::ASSIGN_RELS, 'eleve'));
    }

    /** IDs des classes enseignées par l'utilisateur (account_relationships ∪ professeur_classes). */
    public function taughtClassIds(): array
    {
        $ids = $this->relationTargetIds(self::TEACH_RELS, 'classe');
        if ($this->utype() === 'professeur') {
            $ids = array_merge($ids, $this->legacyColumn(
                'SELECT c.id FROM professeur_classes pc JOIN classes c ON c.nom = pc.nom_classe
                  WHERE pc.id_professeur = ?',
                [$this->uid()]
            ));
        }
        return $this->uniqInts($ids);
    }

    // ───────────────────────── interne ─────────────────────────

    private function relationExists(string $st, int $sid, array $relTypes, string $tt, int $tid): bool
    {
        if ($sid <= 0 || $tid <= 0 || $st === '' || $relTypes === []) {
            return false;
        }
        try {
            $place = implode(',', array_fill(0, count($relTypes), '?'));
            $stmt  = $this->pdo->prepare(
                "SELECT 1 FROM account_relationships
                  WHERE source_type = ? AND source_id = ? AND target_type = ? AND target_id = ?
                    AND relationship_type IN ($place) AND is_active = 1
                    AND (starts_at  IS NULL OR starts_at  <= NOW())
                    AND (expires_at IS NULL OR expires_at >= NOW())
                  LIMIT 1"
            );
            $stmt->execute(array_merge([$st, $sid, $tt, $tid], $relTypes));
            return (bool) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            $this->logIfReal($e);
            return false; // table absente → l'appelant gère le repli hérité
        }
    }

    /**
     * État d'une relation pour l'utilisateur courant :
     *  - 'active'   : au moins une ligne active ET temporellement valide ;
     *  - 'inactive' : des lignes existent mais aucune active (révoquée/expirée) → décision
     *                 AUTORITAIRE, l'appelant NE DOIT PAS retomber sur le lien hérité ;
     *  - 'none'     : aucune ligne → repli hérité autorisé.
     * Corrige le contournement de révocation : désactiver une relation suffit à retirer
     * l'accès même si le lien hérité (parent_eleve / professeur_classes) subsiste.
     */
    private function relationState(array $relTypes, string $targetType, int $targetId): string
    {
        if ($this->uid() <= 0 || $this->utype() === '' || $targetId <= 0 || $relTypes === []) {
            return 'none';
        }
        try {
            $place = implode(',', array_fill(0, count($relTypes), '?'));
            $stmt  = $this->pdo->prepare(
                "SELECT (is_active = 1
                         AND (starts_at  IS NULL OR starts_at  <= NOW())
                         AND (expires_at IS NULL OR expires_at >= NOW())) AS usable
                  FROM account_relationships
                  WHERE source_type = ? AND source_id = ? AND target_type = ? AND target_id = ?
                    AND relationship_type IN ($place)"
            );
            $stmt->execute(array_merge([$this->utype(), $this->uid(), $targetType, $targetId], $relTypes));
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!$rows) {
                return 'none';
            }
            foreach ($rows as $usable) {
                if ((int) $usable === 1) {
                    return 'active';
                }
            }
            return 'inactive';
        } catch (\PDOException $e) {
            $this->logIfReal($e);
            return 'none'; // table absente → repli hérité géré par l'appelant
        }
    }

    /** Journalise une PDOException SAUF si elle signale une table absente (dégradation attendue :
     *  SQLite de test / migration non encore jouée). Sinon une vraie panne SQL resterait invisible. */
    private function logIfReal(\PDOException $e): void
    {
        $msg = $e->getMessage();
        if (stripos($msg, 'no such table') !== false
            || stripos($msg, "doesn't exist") !== false
            || stripos($msg, 'Base table or view not found') !== false) {
            return;
        }
        error_log('[ScopeResolver] ' . $msg);
    }

    /** Cibles d'un ensemble de relations pour l'utilisateur courant. */
    private function relationTargetIds(array $relTypes, string $targetType): array
    {
        if ($this->uid() <= 0 || $this->utype() === '' || $relTypes === []) {
            return [];
        }
        try {
            $place = implode(',', array_fill(0, count($relTypes), '?'));
            $stmt  = $this->pdo->prepare(
                "SELECT target_id FROM account_relationships
                  WHERE source_type = ? AND source_id = ? AND target_type = ?
                    AND relationship_type IN ($place) AND is_active = 1
                    AND (starts_at  IS NULL OR starts_at  <= NOW())
                    AND (expires_at IS NULL OR expires_at >= NOW())"
            );
            $stmt->execute(array_merge([$this->utype(), $this->uid(), $targetType], $relTypes));
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (\PDOException $e) {
            $this->logIfReal($e);
            return [];
        }
    }

    private function legacyExists(string $sql, array $args): bool
    {
        try {
            $s = $this->pdo->prepare($sql);
            $s->execute($args);
            return (bool) $s->fetchColumn();
        } catch (\PDOException $e) {
            $this->logIfReal($e);
            return false;
        }
    }

    private function legacyColumn(string $sql, array $args): array
    {
        try {
            $s = $this->pdo->prepare($sql);
            $s->execute($args);
            return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (\PDOException $e) {
            $this->logIfReal($e);
            return [];
        }
    }

    private function classId($class): ?int
    {
        if (is_numeric($class)) {
            return (int) $class;
        }
        try {
            $s = $this->pdo->prepare('SELECT id FROM classes WHERE nom = ? LIMIT 1');
            $s->execute([(string) $class]);
            $v = $s->fetchColumn();
            return $v !== false ? (int) $v : null;
        } catch (\PDOException $e) {
            $this->logIfReal($e);
            return null;
        }
    }

    private function className($class): ?string
    {
        if (!is_numeric($class)) {
            return (string) $class;
        }
        try {
            $s = $this->pdo->prepare('SELECT nom FROM classes WHERE id = ? LIMIT 1');
            $s->execute([(int) $class]);
            $v = $s->fetchColumn();
            return $v !== false ? (string) $v : null;
        } catch (\PDOException $e) {
            $this->logIfReal($e);
            return null;
        }
    }

    private function uniqInts(array $ids): array
    {
        return array_values(array_unique(array_map('intval', $ids)));
    }
}
