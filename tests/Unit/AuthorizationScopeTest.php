<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use API\Security\Authorization;

/**
 * Vérifie le périmètre (scope) du moteur d'autorisation RBAC/ABAC.
 *
 * Couvre les garanties de confidentialité réclamées pour un Pronote-like :
 *   - un parent ne voit QUE ses enfants (scope children) ;
 *   - un élève ne voit QUE lui-même (scope self) ;
 *   - un professeur est limité à son établissement (scope establishment de base) ;
 *   - un rôle attribué scopé "assigned" (AESH) ne voit QUE ses élèves assignés ;
 *   - super_admin = accès total ;
 *   - les accès sensibles (handicap/médical/…) sont journalisés (audit_log).
 *
 * Exécuté sur SQLite en mémoire. NOW() (MySQL) est simulé pour que la requête
 * user_roles (rôles attribués temporisés) s'exécute au lieu d'être avalée par le catch.
 */
final class AuthorizationScopeTest extends TestCase
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

        // Tables minimales touchées par Authorization.
        $this->pdo->exec('CREATE TABLE parent_eleve (id_parent INTEGER, id_eleve INTEGER)');
        $this->pdo->exec('CREATE TABLE professeur_classes (id_professeur INTEGER, nom_classe TEXT)');
        $this->pdo->exec('CREATE TABLE classes (id INTEGER PRIMARY KEY, nom TEXT)');
        $this->pdo->exec(
            'CREATE TABLE user_roles (
                user_type TEXT, user_id INTEGER, role_key TEXT, etablissement_id INTEGER,
                scope_type TEXT, scope_json TEXT, valid_from TEXT, valid_until TEXT
            )'
        );
        // Déviations plateforme : présentes mais vides → can() retombe sur le catalogue (RoleCatalog).
        $this->pdo->exec('CREATE TABLE rbac_grants (role TEXT, permission TEXT, granted INTEGER)');
        $this->pdo->exec(
            'CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT, action TEXT, model TEXT, model_id INTEGER,
                user_id INTEGER, user_type TEXT, ip_address TEXT, user_agent TEXT, new_values TEXT
            )'
        );

        // Parent 10 → enfant 100 (et PAS 200).
        $this->pdo->exec('INSERT INTO parent_eleve (id_parent, id_eleve) VALUES (10, 100)');
    }

    private function authFor(array $user): Authorization
    {
        return new Authorization($this->pdo, $user);
    }

    public function testParentSeesOwnChildOnly(): void
    {
        $auth = $this->authFor(['id' => 10, 'type' => 'parent', 'etablissement_id' => 1]);

        $this->assertTrue(
            $auth->can('notes.view', ['student_id' => 100]),
            'Le parent doit voir les notes de SON enfant.'
        );
        $this->assertFalse(
            $auth->can('notes.view', ['student_id' => 200]),
            "Le parent NE DOIT PAS voir les notes d'un autre élève."
        );
    }

    public function testParentCannotCreateNotes(): void
    {
        $auth = $this->authFor(['id' => 10, 'type' => 'parent', 'etablissement_id' => 1]);
        // Le catalogue n'accorde pas notes.create au parent → refus, quel que soit le scope.
        $this->assertFalse($auth->can('notes.create', ['student_id' => 100]));
    }

    public function testEleveSeesSelfOnly(): void
    {
        $auth = $this->authFor(['id' => 100, 'type' => 'eleve', 'etablissement_id' => 1]);

        $this->assertTrue(
            $auth->can('notes.view', ['owner_id' => 100, 'owner_type' => 'eleve']),
            "L'élève doit voir ses propres notes."
        );
        $this->assertFalse(
            $auth->can('notes.view', ['owner_id' => 999, 'owner_type' => 'eleve']),
            "L'élève NE DOIT PAS voir les notes d'autrui."
        );
    }

    public function testProfesseurLimitedToOwnEstablishment(): void
    {
        $auth = $this->authFor(['id' => 5, 'type' => 'professeur', 'etablissement_id' => 1]);

        $this->assertTrue(
            $auth->can('notes.create', ['etablissement_id' => 1]),
            'Le professeur peut saisir des notes dans SON établissement.'
        );
        $this->assertFalse(
            $auth->can('notes.create', ['etablissement_id' => 2]),
            "Le professeur NE DOIT PAS agir dans un autre établissement."
        );
    }

    public function testSuperAdminHasGodMode(): void
    {
        $auth = $this->authFor(['id' => 1, 'type' => 'super_admin']);

        $this->assertTrue($auth->can('etablissement.purge'), 'super_admin = accès total.');
        $this->assertTrue($auth->can('whatever.unknown'));
    }

    public function testAssignedRoleScopesToAssignedStudentsAndAudits(): void
    {
        // Compte de base vie_scolaire (qui n'accorde PAS handicap.view) + rôle attribué
        // AESH scopé aux élèves assignés [100].
        $this->pdo->exec(
            "INSERT INTO user_roles (user_type, user_id, role_key, etablissement_id, scope_type, scope_json, valid_from, valid_until)
             VALUES ('vie_scolaire', 7, 'aesh', 1, 'assigned', '{\"student_ids\":[100]}', NULL, NULL)"
        );
        $auth = $this->authFor(['id' => 7, 'type' => 'vie_scolaire', 'etablissement_id' => 1]);

        $this->assertTrue(
            $auth->can('handicap.view', ['student_id' => 100]),
            "L'AESH doit voir le dossier handicap de son élève assigné."
        );
        $this->assertFalse(
            $auth->can('handicap.view', ['student_id' => 200]),
            "L'AESH NE DOIT PAS voir un élève non assigné."
        );

        // handicap.* est sensible → chaque évaluation doit être journalisée.
        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'authz.sensitive' AND model = 'handicap.view'"
        )->fetchColumn();
        $this->assertGreaterThanOrEqual(2, $count, 'Les accès sensibles doivent être audités (accordés ET refusés).');
    }

    public function testUnknownPermissionFailsClosed(): void
    {
        $auth = $this->authFor(['id' => 10, 'type' => 'parent', 'etablissement_id' => 1]);
        $this->assertFalse($auth->can('nonexistent.permission', ['student_id' => 100]));
    }

    public function testNoUserDeniesEverything(): void
    {
        $auth = $this->authFor([]);
        $this->assertFalse($auth->can('notes.view', ['student_id' => 100]));
    }
}
