<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use API\Services\RelationshipService;

/**
 * RelationshipService — gestion des relations account_relationships.
 * (add() utilise ON DUPLICATE KEY UPDATE / MySQL : on teste le garde-fou de type,
 * qui lève avant l'INSERT, ainsi que la lecture et le soft-delete, portables.)
 */
final class RelationshipServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Extension pdo_sqlite non disponible.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE account_relationships (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_type TEXT, source_id INTEGER, target_type TEXT, target_id INTEGER,
                relationship_type TEXT, etablissement_id INTEGER,
                starts_at TEXT, expires_at TEXT, is_active INTEGER DEFAULT 1,
                created_by_type TEXT, created_by_id INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE user_role_audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                actor_type TEXT, actor_id INTEGER, target_type TEXT, target_id INTEGER,
                action TEXT, role_key TEXT, old_value TEXT, new_value TEXT,
                ip_address TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }

    private function svc(): RelationshipService
    {
        return new RelationshipService($this->pdo);
    }

    private function seed(string $st, int $sid, string $tt, int $tid, string $type, int $active = 1, int $etab = 1): int
    {
        $this->pdo->prepare(
            'INSERT INTO account_relationships (source_type, source_id, target_type, target_id, relationship_type, is_active, etablissement_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$st, $sid, $tt, $tid, $type, $active, $etab]);
        return (int) $this->pdo->lastInsertId();
    }

    public function testAddRejectsUnknownType(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/relation inconnu/i');
        $this->svc()->add(['type' => 'administrateur', 'id' => 1], 'professeur', 5, 'enseigne_a', 'classe', 8);
    }

    public function testListForReturnsActiveSources(): void
    {
        $this->seed('parent', 10, 'eleve', 100, 'parent_of');
        $this->seed('parent', 10, 'eleve', 101, 'financial_responsible_of');
        $this->seed('parent', 99, 'eleve', 200, 'parent_of'); // autre parent

        $rels = $this->svc()->listFor('parent', 10);
        $this->assertCount(2, $rels);
        $targets = array_map(static fn($r) => (int) $r['target_id'], $rels);
        sort($targets);
        $this->assertSame([100, 101], $targets);
    }

    public function testListTargetsReverseLookup(): void
    {
        // Qui suit l'élève 100 ?
        $this->seed('parent', 10, 'eleve', 100, 'parent_of');
        $this->seed('vie_scolaire', 7, 'eleve', 100, 'aesh_of');

        $who = $this->svc()->listTargets('eleve', 100);
        $this->assertCount(2, $who);
    }

    public function testRemoveSoftDeletesAndAudits(): void
    {
        $id = $this->seed('vie_scolaire', 7, 'eleve', 100, 'aesh_of'); // etablissement_id = 1
        $this->assertCount(1, $this->svc()->listFor('vie_scolaire', 7));

        // Acteur administrateur du MÊME établissement que la relation → autorisé.
        $ok = $this->svc()->remove(['type' => 'administrateur', 'id' => 1, 'etablissement_id' => 1], $id);
        $this->assertTrue($ok);
        $this->assertCount(0, $this->svc()->listFor('vie_scolaire', 7), 'La relation désactivée ne doit plus apparaître.');

        $active = (int) $this->pdo->query("SELECT is_active FROM account_relationships WHERE id = {$id}")->fetchColumn();
        $this->assertSame(0, $active);

        $audited = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM user_role_audit_logs WHERE action = 'relationship_removed'"
        )->fetchColumn();
        $this->assertSame(1, $audited);
    }

    public function testRemoveDeniesCrossTenant(): void
    {
        // Relation de l'établissement 1 ; un administrateur de l'établissement 2 ne doit
        // PAS pouvoir la désactiver (IDOR write cross-tenant corrigé).
        $id = $this->seed('vie_scolaire', 7, 'eleve', 100, 'aesh_of', 1, 1);

        $ok = $this->svc()->remove(['type' => 'administrateur', 'id' => 2, 'etablissement_id' => 2], $id);
        $this->assertFalse($ok, 'Un admin d\'un autre établissement ne peut pas retirer la relation.');

        $active = (int) $this->pdo->query("SELECT is_active FROM account_relationships WHERE id = {$id}")->fetchColumn();
        $this->assertSame(1, $active, 'La relation reste active après une tentative cross-tenant.');
    }
}
