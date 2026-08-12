<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use API\Security\Authorization;
use API\Security\ScopeResolver;

/**
 * Vérifie le résolveur de périmètre unifié (ScopeResolver) et son intégration au
 * moteur d'autorisation :
 *   - canOn() traduit (type de ressource, id) en contexte de périmètre ;
 *   - le scope 'assigned' est satisfait par une relation account_relationships
 *     (AESH/psy/médical) en plus de scope_json ;
 *   - le scope 'children' s'appuie sur account_relationships ∪ parent_eleve ;
 *   - les listes d'IDs accessibles unifient relations et liens hérités.
 *
 * Exécuté sur SQLite en mémoire avec un shim NOW() (comme AuthorizationScopeTest).
 */
final class ScopeResolverTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Extension pdo_sqlite non disponible.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (method_exists($this->pdo, 'sqliteCreateFunction')) {
            $this->pdo->sqliteCreateFunction('NOW', static fn() => date('Y-m-d H:i:s'));
        }

        $this->pdo->exec('CREATE TABLE parent_eleve (id_parent INTEGER, id_eleve INTEGER)');
        $this->pdo->exec('CREATE TABLE professeur_classes (id_professeur INTEGER, nom_classe TEXT)');
        $this->pdo->exec('CREATE TABLE classes (id INTEGER PRIMARY KEY, nom TEXT)');
        $this->pdo->exec(
            'CREATE TABLE user_roles (
                user_type TEXT, user_id INTEGER, role_key TEXT, etablissement_id INTEGER,
                scope_type TEXT, scope_json TEXT, valid_from TEXT, valid_until TEXT
            )'
        );
        $this->pdo->exec('CREATE TABLE rbac_grants (role TEXT, permission TEXT, granted INTEGER)');
        $this->pdo->exec(
            'CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT, action TEXT, model TEXT, model_id INTEGER,
                user_id INTEGER, user_type TEXT, ip_address TEXT, user_agent TEXT, new_values TEXT
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE account_relationships (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_type TEXT, source_id INTEGER, target_type TEXT, target_id INTEGER,
                relationship_type TEXT, etablissement_id INTEGER,
                starts_at TEXT, expires_at TEXT, is_active INTEGER DEFAULT 1
            )'
        );

        // Parent 10 → enfant 100 (lien hérité).
        $this->pdo->exec('INSERT INTO parent_eleve (id_parent, id_eleve) VALUES (10, 100)');
        // Classe 8 = "4A".
        $this->pdo->exec("INSERT INTO classes (id, nom) VALUES (8, '4A')");
    }

    private function authFor(array $user): Authorization
    {
        return new Authorization($this->pdo, $user);
    }

    private function rel(string $st, int $sid, string $tt, int $tid, string $type): void
    {
        $this->pdo->prepare(
            'INSERT INTO account_relationships (source_type, source_id, target_type, target_id, relationship_type, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$st, $sid, $tt, $tid, $type]);
    }

    // ───────────────────────── canOn() ─────────────────────────

    public function testCanOnMapsStudentResourceToScope(): void
    {
        $auth = $this->authFor(['id' => 10, 'type' => 'parent', 'etablissement_id' => 1]);

        $this->assertTrue(
            $auth->canOn('notes.view', 'student', 100),
            "canOn('student') doit autoriser le parent sur SON enfant."
        );
        $this->assertFalse(
            $auth->canOn('notes.view', 'student', 200),
            "canOn('student') doit refuser un élève non lié."
        );
        // alias eleve == student
        $this->assertTrue($auth->canOn('notes.view', 'eleve', 100));
    }

    public function testCanOnMapsEstablishmentResource(): void
    {
        $auth = $this->authFor(['id' => 5, 'type' => 'professeur', 'etablissement_id' => 1]);
        $this->assertTrue($auth->canOn('notes.create', 'establishment', 1));
        $this->assertFalse($auth->canOn('notes.create', 'establishment', 2));
    }

    // ───────────────── relations unifiées (account_relationships) ─────────────────

    public function testAssignedSatisfiedByRelationship(): void
    {
        // Compte vie_scolaire 7 + rôle AESH scope 'assigned' SANS scope_json :
        // l'accès doit venir de la relation account_relationships (aesh_of).
        $this->pdo->exec(
            "INSERT INTO user_roles (user_type, user_id, role_key, etablissement_id, scope_type, scope_json, valid_from, valid_until)
             VALUES ('vie_scolaire', 7, 'aesh', 1, 'assigned', NULL, NULL, NULL)"
        );
        $this->rel('vie_scolaire', 7, 'eleve', 100, 'aesh_of');

        $auth = $this->authFor(['id' => 7, 'type' => 'vie_scolaire', 'etablissement_id' => 1]);
        $this->assertTrue(
            $auth->canOn('handicap.view', 'student', 100),
            "L'AESH doit voir l'élève suivi via account_relationships."
        );
        $this->assertFalse(
            $auth->canOn('handicap.view', 'student', 300),
            "L'AESH NE DOIT PAS voir un élève non relié."
        );
    }

    public function testChildrenStillSatisfiedByLegacyParentEleve(): void
    {
        // Aucune relation account_relationships : le repli parent_eleve doit suffire.
        $auth = $this->authFor(['id' => 10, 'type' => 'parent', 'etablissement_id' => 1]);
        $this->assertTrue($auth->can('notes.view', ['student_id' => 100]));
        $this->assertFalse($auth->can('notes.view', ['student_id' => 200]));
    }

    public function testInactiveRelationshipIgnored(): void
    {
        $this->pdo->exec(
            "INSERT INTO user_roles (user_type, user_id, role_key, etablissement_id, scope_type, scope_json, valid_from, valid_until)
             VALUES ('vie_scolaire', 7, 'aesh', 1, 'assigned', NULL, NULL, NULL)"
        );
        // relation désactivée (is_active=0) → ne doit pas accorder l'accès.
        $this->pdo->prepare(
            'INSERT INTO account_relationships (source_type, source_id, target_type, target_id, relationship_type, is_active)
             VALUES (?, ?, ?, ?, ?, 0)'
        )->execute(['vie_scolaire', 7, 'eleve', 100, 'aesh_of']);

        $auth = $this->authFor(['id' => 7, 'type' => 'vie_scolaire', 'etablissement_id' => 1]);
        $this->assertFalse($auth->canOn('handicap.view', 'student', 100));
    }

    // ───────────────────────── ScopeResolver direct ─────────────────────────

    public function testResolverGuardianUnionLegacyAndRelationships(): void
    {
        // Enfant 100 via parent_eleve ; enfant 101 via account_relationships.
        $this->rel('parent', 10, 'eleve', 101, 'parent_of');
        $resolver = new ScopeResolver($this->pdo, ['id' => 10, 'type' => 'parent', 'etablissement_id' => 1]);

        $this->assertTrue($resolver->isGuardianOf(100));
        $this->assertTrue($resolver->isGuardianOf(101));
        $this->assertFalse($resolver->isGuardianOf(200));

        $ids = $resolver->guardianStudentIds();
        sort($ids);
        $this->assertSame([100, 101], $ids);
    }

    public function testResolverTeachesClassByLegacyAndRelationship(): void
    {
        $this->pdo->exec("INSERT INTO professeur_classes (id_professeur, nom_classe) VALUES (5, '4A')");
        $resolver = new ScopeResolver($this->pdo, ['id' => 5, 'type' => 'professeur', 'etablissement_id' => 1]);

        $this->assertTrue($resolver->teachesClass('4A'), 'Repli professeur_classes par nom.');
        $this->assertTrue($resolver->teachesClass(8), 'Résolution nom→id (classe 8 = 4A).');
        $this->assertFalse($resolver->teachesClass('5B'));

        // Relation explicite vers une autre classe.
        $this->pdo->exec("INSERT INTO classes (id, nom) VALUES (9, '3B')");
        $this->rel('professeur', 5, 'classe', 9, 'teacher_of');
        $this->assertTrue($resolver->teachesClass(9));
    }

    public function testRevokedTeacherRelationshipBlocksDespiteLegacyLink(): void
    {
        // Lien hérité présent (prof 5 ↔ 4A) MAIS relation account_relationships désactivée :
        // la révocation doit faire autorité — pas de re-grant par professeur_classes.
        $this->pdo->exec("INSERT INTO professeur_classes (id_professeur, nom_classe) VALUES (5, '4A')");
        $this->pdo->prepare(
            'INSERT INTO account_relationships (source_type, source_id, target_type, target_id, relationship_type, is_active)
             VALUES (?, ?, ?, ?, ?, 0)'
        )->execute(['professeur', 5, 'classe', 8, 'teacher_of']);

        $resolver = new ScopeResolver($this->pdo, ['id' => 5, 'type' => 'professeur', 'etablissement_id' => 1]);
        $this->assertFalse($resolver->teachesClass(8), 'Relation révoquée → accès retiré malgré le lien hérité (par id).');
        $this->assertFalse($resolver->teachesClass('4A'), 'Idem via le nom de classe.');
    }

    public function testRevokedGuardianRelationshipBlocksDespiteParentEleve(): void
    {
        // parent_eleve (10→100) présent (setUp) MAIS relation parent_of désactivée → accès retiré.
        $this->pdo->prepare(
            'INSERT INTO account_relationships (source_type, source_id, target_type, target_id, relationship_type, is_active)
             VALUES (?, ?, ?, ?, ?, 0)'
        )->execute(['parent', 10, 'eleve', 100, 'parent_of']);

        $resolver = new ScopeResolver($this->pdo, ['id' => 10, 'type' => 'parent', 'etablissement_id' => 1]);
        $this->assertFalse($resolver->isGuardianOf(100), 'Relation parentale révoquée → accès retiré malgré parent_eleve.');
    }

    public function testResolverDegradesWithoutRelationshipTable(): void
    {
        // Sans table account_relationships, le résolveur ne doit PAS fataliser
        // et doit retomber sur les liens hérités.
        $this->pdo->exec('DROP TABLE account_relationships');
        $resolver = new ScopeResolver($this->pdo, ['id' => 10, 'type' => 'parent', 'etablissement_id' => 1]);
        $this->assertTrue($resolver->isGuardianOf(100));
        $this->assertFalse($resolver->isGuardianOf(999));
    }
}
