<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use API\Tenant\TenantRoleCatalog;
use API\Tenant\TenantRoleSync;
use API\Tenant\TenantAuthorization;
use API\Tenant\TenantAccountService;
use API\Tenant\TenantMembershipService;

/**
 * Couche ÉTABLISSEMENT (refonte 3-mondes — Phase B) : catalogue tenant, autorisation
 * par appartenance (permission + périmètre), services comptes/appartenances/rôles.
 * SQLite en mémoire + shim NOW().
 */
final class TenantLayerTest extends TestCase
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
        $this->pdo->exec('CREATE TABLE tenant_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, username TEXT, password_hash TEXT, first_name TEXT, last_name TEXT, display_name TEXT, account_type TEXT, status TEXT DEFAULT "pending", must_change_password INTEGER DEFAULT 1, locked_until TEXT, legacy_type TEXT, legacy_id INTEGER, created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->pdo->exec('CREATE TABLE tenant_memberships (id INTEGER PRIMARY KEY AUTOINCREMENT, establishment_id INTEGER, tenant_account_id INTEGER, status TEXT DEFAULT "active", joined_at TEXT DEFAULT CURRENT_TIMESTAMP, revoked_at TEXT)');
        $this->pdo->exec('CREATE TABLE tenant_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, role_key TEXT, label TEXT, category TEXT, default_scope_type TEXT, is_sensitive INTEGER DEFAULT 0, is_system INTEGER DEFAULT 1, is_assignable INTEGER DEFAULT 1)');
        $this->pdo->exec('CREATE TABLE tenant_membership_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, membership_id INTEGER, tenant_role_id INTEGER, scope_type TEXT, scope_id INTEGER, starts_at TEXT, expires_at TEXT, reason TEXT, assigned_by_membership_id INTEGER, is_active INTEGER DEFAULT 1, assigned_at TEXT DEFAULT CURRENT_TIMESTAMP, revoked_at TEXT)');
        $this->pdo->exec('CREATE TABLE tenant_membership_role_scope_values (id INTEGER PRIMARY KEY AUTOINCREMENT, membership_role_id INTEGER, scope_type TEXT, scope_id INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->pdo->exec('CREATE TABLE account_relationships (id INTEGER PRIMARY KEY AUTOINCREMENT, source_type TEXT, source_id INTEGER, target_type TEXT, target_id INTEGER, relationship_type TEXT, etablissement_id INTEGER, starts_at TEXT, expires_at TEXT, is_active INTEGER DEFAULT 1, created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->pdo->exec('CREATE TABLE tenant_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, establishment_id INTEGER, actor_account_id INTEGER, target_account_id INTEGER, action TEXT, target_type TEXT, target_id INTEGER, old_value TEXT, new_value TEXT, ip_address TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        (new TenantRoleSync($this->pdo))->sync();
    }

    private function mkAccount(string $type): int
    {
        $this->pdo->prepare("INSERT INTO tenant_accounts (username, first_name, last_name, account_type, status) VALUES (?, 'F', 'L', ?, 'active')")
            ->execute(['u' . bin2hex(random_bytes(4)), $type]);
        return (int) $this->pdo->lastInsertId();
    }

    private function mkMembership(int $accId, int $estab = 1): int
    {
        return (new TenantMembershipService($this->pdo))->ensure($estab, $accId);
    }

    private function giveRole(int $membershipId, string $roleKey, string $scopeType): int
    {
        $rid = (int) $this->pdo->query("SELECT id FROM tenant_roles WHERE role_key = " . $this->pdo->quote($roleKey))->fetchColumn();
        $this->pdo->prepare("INSERT INTO tenant_membership_roles (membership_id, tenant_role_id, scope_type, is_active) VALUES (?, ?, ?, 1)")
            ->execute([$membershipId, $rid, $scopeType]);
        return (int) $this->pdo->lastInsertId();
    }

    // ── Catalogue ──
    public function testCatalog(): void
    {
        $this->assertTrue(TenantRoleCatalog::roleGrants('directeur', 'tenant.users.create'));
        $this->assertTrue(TenantRoleCatalog::roleGrants('professeur', 'grades.create'));
        $this->assertFalse(TenantRoleCatalog::roleGrants('eleve', 'grades.create'));
        $this->assertTrue(TenantRoleCatalog::roleGrants('parent', 'grades.view'));
        $this->assertSame(['eleve', 'ancien_eleve'], array_keys(TenantRoleCatalog::rolesForAccountType('student')));
        $this->assertTrue(TenantRoleCatalog::isSensitiveRole('aesh'));
        $this->assertFalse(TenantRoleCatalog::isSensitiveRole('professeur'));
        $this->assertArrayNotHasKey('super_admin', TenantRoleCatalog::roles());
        $this->assertArrayNotHasKey('administrateur', TenantRoleCatalog::roles());
    }

    // ── Autorisation (permission plate — le contrôle par ressource/périmètre est
    //    porté par le moteur unique Authorization::canOn, testé dans AuthorizationScopeTest) ──
    public function testDirectorEstablishmentScope(): void
    {
        $m = $this->mkMembership($this->mkAccount('director'));
        $this->giveRole($m, 'directeur', 'establishment');
        $auth = new TenantAuthorization($this->pdo, $m);
        $this->assertTrue($auth->can('tenant.users.create'));
        $this->assertFalse($auth->can('permission.inexistante'));
    }

    public function testInactiveMembershipDeniesAll(): void
    {
        $m = $this->mkMembership($this->mkAccount('director'));
        $this->giveRole($m, 'directeur', 'establishment');
        $this->pdo->prepare("UPDATE tenant_memberships SET status='inactive' WHERE id=?")->execute([$m]);
        $auth = new TenantAuthorization($this->pdo, $m);
        $this->assertFalse($auth->can('tenant.users.create'));
    }

    public function testMembershipEnsureIdempotent(): void
    {
        $acc = $this->mkAccount('staff');
        $a = $this->mkMembership($acc, 1);
        $b = $this->mkMembership($acc, 1);
        $this->assertSame($a, $b);
    }

    public function testAccountServiceCreateFindByLegacy(): void
    {
        $svc = new TenantAccountService($this->pdo);
        $id = $svc->createAccount(['account_type' => 'student', 'username' => 'lucas.d', 'first_name' => 'Lucas', 'last_name' => 'Durand', 'legacy_type' => 'eleve', 'legacy_id' => 100, 'status' => 'active']);
        $this->assertGreaterThan(0, $id);
        $this->assertNotNull($svc->findByLegacy('eleve', 100));
        $this->assertNull($svc->findByLegacy('eleve', 999));
    }
}
