<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use API\Platform\PlatformEstablishmentService;

/**
 * Gestion d'infrastructure plateforme : transitions de statut d'établissement
 * (suspend/archive/activate/delete) + PURGE définitive bornée aux données 3-mondes.
 * SQLite en mémoire.
 */
final class PlatformInfraTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Extension pdo_sqlite non disponible.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $x = fn(string $sql) => $this->pdo->exec($sql);
        $x('CREATE TABLE etablissements (id INTEGER PRIMARY KEY AUTOINCREMENT, nom TEXT, slug TEXT, code TEXT, type TEXT, ville TEXT, status TEXT DEFAULT "active", actif INTEGER DEFAULT 1)');
        $x('CREATE TABLE tenant_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, account_type TEXT, status TEXT)');
        $x('CREATE TABLE tenant_account_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_account_id INTEGER)');
        $x('CREATE TABLE tenant_memberships (id INTEGER PRIMARY KEY AUTOINCREMENT, establishment_id INTEGER, tenant_account_id INTEGER, status TEXT DEFAULT "active")');
        $x('CREATE TABLE tenant_membership_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, membership_id INTEGER, tenant_role_id INTEGER, scope_type TEXT, is_active INTEGER DEFAULT 1)');
        $x('CREATE TABLE tenant_membership_role_scope_values (id INTEGER PRIMARY KEY AUTOINCREMENT, membership_role_id INTEGER, scope_type TEXT, scope_id INTEGER)');
        $x('CREATE TABLE account_relationships (id INTEGER PRIMARY KEY AUTOINCREMENT, source_type TEXT, source_id INTEGER, target_type TEXT, target_id INTEGER, relationship_type TEXT, is_active INTEGER DEFAULT 1)');
        $x('CREATE TABLE support_tickets (id INTEGER PRIMARY KEY AUTOINCREMENT, establishment_id INTEGER, title TEXT, status TEXT)');
        $x('CREATE TABLE support_bridge_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER, message TEXT)');
        $x('CREATE TABLE support_access_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, establishment_id INTEGER, ticket_id INTEGER, status TEXT)');
        $x('CREATE TABLE support_access_request_approvals (id INTEGER PRIMARY KEY AUTOINCREMENT, access_request_id INTEGER, decision TEXT)');
        $x('CREATE TABLE support_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, establishment_id INTEGER, ticket_id INTEGER, status TEXT)');
        $x('CREATE TABLE support_session_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, support_session_id INTEGER, establishment_id INTEGER, action TEXT)');
        $x('CREATE TABLE support_session_restrictions (id INTEGER PRIMARY KEY AUTOINCREMENT, support_session_id INTEGER, restriction_key TEXT)');
        $x('CREATE TABLE platform_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, platform_account_id INTEGER, establishment_id INTEGER, action TEXT, target_type TEXT, target_id INTEGER, new_value TEXT, ip_address TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $x("INSERT INTO etablissements (id, nom, slug, code, type, status) VALUES (1,'À purger','purge','purge','college','active'), (2,'À garder','keep','keep','lycee','active')");
    }

    public function testStatusTransitionsAreAuditedAndBlockPurged(): void
    {
        $svc = new PlatformEstablishmentService($this->pdo);
        $this->assertTrue($svc->suspend(2, 9));
        $this->assertSame('suspended', $svc->get(2)['status']);
        $this->assertTrue($svc->activate(2, 9));
        $this->assertSame('active', $svc->get(2)['status']);
        $this->assertTrue($svc->archive(2, 9));
        $this->assertSame('archived', $svc->get(2)['status']);
        $this->assertGreaterThanOrEqual(3, (int) $this->pdo->query("SELECT COUNT(*) FROM platform_audit_logs")->fetchColumn());
    }

    public function testPurgeWipesTenantDataAndSparesMultiEstablishmentAccounts(): void
    {
        // Compte A : uniquement dans l'établissement 1 (deviendra orphelin) + rôle + scope + relation.
        $this->pdo->exec("INSERT INTO tenant_accounts (id, username, account_type, status) VALUES (10,'a','student','active'), (11,'b','staff','active')");
        $this->pdo->exec("INSERT INTO tenant_account_profiles (tenant_account_id) VALUES (10)");
        $this->pdo->exec("INSERT INTO tenant_memberships (id, establishment_id, tenant_account_id) VALUES (100,1,10), (101,1,11), (102,2,11)");
        $this->pdo->exec("INSERT INTO tenant_membership_roles (id, membership_id, tenant_role_id) VALUES (200,100,1)");
        $this->pdo->exec("INSERT INTO tenant_membership_role_scope_values (membership_role_id, scope_type, scope_id) VALUES (200,'class',5)");
        $this->pdo->exec("INSERT INTO account_relationships (source_type, source_id, target_type, target_id, relationship_type) VALUES ('tenant_account',10,'tenant_account',99,'parent_of')");
        $this->pdo->exec("INSERT INTO support_tickets (id, establishment_id, title, status) VALUES (300,1,'t','submitted')");
        $this->pdo->exec("INSERT INTO support_bridge_messages (ticket_id, message) VALUES (300,'m')");
        $this->pdo->exec("INSERT INTO support_sessions (id, establishment_id, ticket_id, status) VALUES (400,1,300,'active')");
        $this->pdo->exec("INSERT INTO support_session_audit_logs (support_session_id, establishment_id, action) VALUES (400,1,'x')");
        $this->pdo->exec("INSERT INTO support_session_restrictions (support_session_id, restriction_key) VALUES (400,'hide')");

        $r = (new PlatformEstablishmentService($this->pdo))->purge(1, 9);

        $this->assertSame('purged', $this->pdo->query("SELECT status FROM etablissements WHERE id=1")->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM tenant_memberships WHERE establishment_id=1")->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM support_tickets WHERE establishment_id=1")->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM support_sessions WHERE establishment_id=1")->fetchColumn());
        // Compte A orphelin → supprimé (+ profil + relation) ; compte B conservé (membership en 2).
        $this->assertNull($this->pdo->query("SELECT id FROM tenant_accounts WHERE id=10")->fetch() ?: null);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM tenant_account_profiles WHERE tenant_account_id=10")->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM account_relationships WHERE source_id=10")->fetchColumn());
        $this->assertNotFalse($this->pdo->query("SELECT id FROM tenant_accounts WHERE id=11")->fetch());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM tenant_memberships WHERE tenant_account_id=11")->fetchColumn());
        $this->assertSame(1, $r['purged_accounts']);
    }
}
