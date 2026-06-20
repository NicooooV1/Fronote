<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use API\Tenant\TenantAccountService;
use API\Tenant\TenantMembershipService;
use API\Tenant\TenantRoleSync;
use API\Tenant\TenantAuthorization;

/**
 * Bascule des contrôles d'accès (refonte 3-mondes) : l'enabler qui résout l'appartenance
 * établissement depuis l'identité LEGACY (tenant_accounts.legacy_type/legacy_id), afin que
 * tenantCan/tenantAuthorize fonctionnent aussi sous la connexion /login/ historique.
 * Vérifie aussi qu'un admin migré (rôle « administration ») détient les permissions pilotes.
 * SQLite en mémoire (même schéma que TenantLayerTest).
 */
final class AuthzBasculeTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) { $this->markTestSkipped('pdo_sqlite absent'); }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (method_exists($this->pdo, 'sqliteCreateFunction')) {
            $this->pdo->sqliteCreateFunction('NOW', static fn() => date('Y-m-d H:i:s'));
        }
        $x = fn(string $s) => $this->pdo->exec($s);
        $x('CREATE TABLE tenant_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, username TEXT, password_hash TEXT, first_name TEXT, last_name TEXT, display_name TEXT, account_type TEXT, status TEXT DEFAULT "pending", must_change_password INTEGER DEFAULT 1, locked_until TEXT, legacy_type TEXT, legacy_id INTEGER, created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $x('CREATE TABLE tenant_memberships (id INTEGER PRIMARY KEY AUTOINCREMENT, establishment_id INTEGER, tenant_account_id INTEGER, status TEXT DEFAULT "active", joined_at TEXT DEFAULT CURRENT_TIMESTAMP, revoked_at TEXT)');
        $x('CREATE TABLE tenant_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, role_key TEXT, label TEXT, category TEXT, default_scope_type TEXT, is_sensitive INTEGER DEFAULT 0, is_system INTEGER DEFAULT 1, is_assignable INTEGER DEFAULT 1)');
        $x('CREATE TABLE tenant_membership_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, membership_id INTEGER, tenant_role_id INTEGER, scope_type TEXT, scope_id INTEGER, starts_at TEXT, expires_at TEXT, reason TEXT, assigned_by_membership_id INTEGER, is_active INTEGER DEFAULT 1, assigned_at TEXT DEFAULT CURRENT_TIMESTAMP, revoked_at TEXT)');
        $x('CREATE TABLE tenant_membership_role_scope_values (id INTEGER PRIMARY KEY AUTOINCREMENT, membership_role_id INTEGER, scope_type TEXT, scope_id INTEGER)');
        $x('CREATE TABLE account_relationships (id INTEGER PRIMARY KEY AUTOINCREMENT, source_type TEXT, source_id INTEGER, target_type TEXT, target_id INTEGER, relationship_type TEXT, is_active INTEGER DEFAULT 1)');
        $x('CREATE TABLE tenant_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, establishment_id INTEGER, actor_membership_id INTEGER, action TEXT, target_type TEXT, target_id INTEGER, new_value TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        (new TenantRoleSync($this->pdo))->sync();
    }

    /** Requête de résolution identique à WorldContext::resolveLegacyMembership. */
    private function resolve(string $legacyType, int $legacyId, int $estab): int
    {
        $st = $this->pdo->prepare(
            "SELECT tm.id FROM tenant_memberships tm
               JOIN tenant_accounts ta ON ta.id = tm.tenant_account_id
              WHERE ta.legacy_type = ? AND ta.legacy_id = ? AND ta.status = 'active'
                AND tm.establishment_id = ? AND tm.status = 'active' LIMIT 1"
        );
        $st->execute([$legacyType, $legacyId, $estab]);
        return (int) ($st->fetchColumn() ?: 0);
    }

    public function testLegacyIdentityResolvesToMembershipWithPilotPermissions(): void
    {
        // Un agent « vie_scolaire » migré, mais doté du rôle établissement « administration ».
        $acc = (new TenantAccountService($this->pdo))->createAccount([
            'account_type' => 'staff', 'username' => 'vs.admin', 'status' => 'active',
            'legacy_type' => 'vie_scolaire', 'legacy_id' => 777,
        ]);
        $m = (new TenantMembershipService($this->pdo))->ensure(1, $acc);
        $rid = (int) $this->pdo->query("SELECT id FROM tenant_roles WHERE role_key='administration'")->fetchColumn();
        $this->assertGreaterThan(0, $rid, 'rôle administration présent (TenantRoleSync)');
        $this->pdo->prepare("INSERT INTO tenant_membership_roles (membership_id, tenant_role_id, scope_type, is_active) VALUES (?, ?, 'establishment', 1)")->execute([$m, $rid]);

        // L'enabler : identité legacy → appartenance.
        $this->assertSame($m, $this->resolve('vie_scolaire', 777, 1), 'identité legacy résolue vers son appartenance');

        // Les permissions pilotes sont bien détenues (gate tenant prioritaire passerait).
        $auth = new TenantAuthorization($this->pdo, $m);
        $this->assertTrue($auth->can('tenant.roles.view'),   'administration → tenant.roles.view');
        $this->assertTrue($auth->can('tenant.users.manage'), 'administration → tenant.users.manage (via tenant.users.*)');
        $this->assertTrue($auth->can('tenant.audit.view'),   'administration → tenant.audit.view');
    }

    public function testSpecialistRolesCannotReachAdmin(): void
    {
        // Les rôles « responsable » opérationnels ne doivent PAS détenir la clé d'entrée
        // de l'espace admin (tenant.users.view), ni la gestion des comptes/rôles.
        foreach (['responsable_cantine', 'responsable_internat', 'responsable_emploi_du_temps', 'responsable_documents'] as $roleKey) {
            $acc = (new TenantAccountService($this->pdo))->createAccount([
                'account_type' => 'staff', 'username' => 'spec_' . $roleKey, 'status' => 'active',
            ]);
            $m = (new TenantMembershipService($this->pdo))->ensure(1, $acc);
            $rid = (int) $this->pdo->query("SELECT id FROM tenant_roles WHERE role_key=" . $this->pdo->quote($roleKey))->fetchColumn();
            $this->pdo->prepare("INSERT INTO tenant_membership_roles (membership_id, tenant_role_id, scope_type, is_active) VALUES (?, ?, 'establishment', 1)")->execute([$m, $rid]);
            $auth = new TenantAuthorization($this->pdo, $m);
            $this->assertFalse($auth->can('tenant.users.view'),   "{$roleKey} ne doit pas entrer dans l'admin");
            $this->assertFalse($auth->can('tenant.users.manage'), "{$roleKey} ne doit pas gérer les comptes");
            $this->assertFalse($auth->can('tenant.roles.view'),   "{$roleKey} ne doit pas voir les rôles");
        }
    }

    public function testUnknownLegacyIdentityResolvesToNothing(): void
    {
        $this->assertSame(0, $this->resolve('professeur', 4242, 1), 'aucune appartenance → fail-closed (gate retombera sur le repli legacy)');
    }

    public function testWrongEstablishmentIsNotResolved(): void
    {
        $acc = (new TenantAccountService($this->pdo))->createAccount([
            'account_type' => 'staff', 'username' => 'vs.other', 'status' => 'active',
            'legacy_type' => 'vie_scolaire', 'legacy_id' => 778,
        ]);
        (new TenantMembershipService($this->pdo))->ensure(1, $acc);
        // Demande pour l'établissement 2 → pas d'appartenance.
        $this->assertSame(0, $this->resolve('vie_scolaire', 778, 2));
    }
}
