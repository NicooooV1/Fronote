<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use API\Platform\PlatformRoleCatalog;
use API\Platform\PlatformRoleSync;
use API\Platform\PlatformAuthorization;
use API\Platform\PlatformAccountService;
use API\Platform\DirectorInvitationService;

/**
 * Couche PLATEFORME (refonte 3-mondes — Phase A) : catalogue de rôles plateforme,
 * moteur d'autorisation, comptes internes, invitations Directeur. SQLite en mémoire.
 */
final class PlatformLayerTest extends TestCase
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
            'CREATE TABLE platform_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, username TEXT, password_hash TEXT,
                first_name TEXT, last_name TEXT, status TEXT DEFAULT "active",
                two_factor_enabled INTEGER DEFAULT 0, two_factor_secret TEXT, last_login_at TEXT,
                locked_until TEXT, failed_login_attempts INTEGER DEFAULT 0, legacy_super_admin_id INTEGER,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE platform_roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT, role_key TEXT, label TEXT,
                is_sensitive INTEGER DEFAULT 0, is_system INTEGER DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE platform_account_roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT, platform_account_id INTEGER, platform_role_id INTEGER,
                scope_type TEXT DEFAULT "global", is_active INTEGER DEFAULT 1,
                assigned_at TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE director_invitations (
                id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, first_name TEXT, last_name TEXT,
                invitation_type TEXT, token_hash TEXT, allowed_establishment_ids TEXT,
                default_tenant_role TEXT DEFAULT "directeur", created_by_platform_account_id INTEGER,
                expires_at TEXT, accepted_at TEXT, revoked_at TEXT, status TEXT DEFAULT "pending",
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );
        (new PlatformRoleSync($this->pdo))->sync();
    }

    private function assignRole(int $accountId, string $roleKey): void
    {
        $rid = (int) $this->pdo->query("SELECT id FROM platform_roles WHERE role_key = " . $this->pdo->quote($roleKey))->fetchColumn();
        $this->pdo->prepare("INSERT INTO platform_account_roles (platform_account_id, platform_role_id, is_active) VALUES (?, ?, 1)")
            ->execute([$accountId, $rid]);
    }

    // ── Catalogue ──

    public function testCatalogGrantsWildcardsAndSensitivity(): void
    {
        $this->assertTrue(PlatformRoleCatalog::roleGrants('super_admin', 'platform.establishments.purge'));
        $this->assertTrue(PlatformRoleCatalog::roleGrants('super_admin', 'anything.at.all'));
        $this->assertTrue(PlatformRoleCatalog::roleGrants('platform_support', 'platform.support.ticket.view'));
        $this->assertFalse(PlatformRoleCatalog::roleGrants('platform_support', 'platform.establishments.purge'));
        $this->assertFalse(PlatformRoleCatalog::roleGrants('platform_support', 'platform.security.manage'));

        $supportPerms = PlatformRoleCatalog::permissionsFor('platform_support');
        $this->assertContains('platform.support.session.start', $supportPerms);
        $this->assertNotContains('platform.security.manage', $supportPerms);

        $this->assertTrue(PlatformRoleCatalog::isSensitiveRole('super_admin'));
        $this->assertTrue(PlatformRoleCatalog::isSensitiveRole('platform_dpo'));
        $this->assertFalse(PlatformRoleCatalog::isSensitiveRole('platform_support'));
    }

    public function testSyncPopulatesAllRoles(): void
    {
        $n = (int) $this->pdo->query("SELECT COUNT(*) FROM platform_roles")->fetchColumn();
        $this->assertSame(count(PlatformRoleCatalog::roles()), $n);
        // idempotent
        (new PlatformRoleSync($this->pdo))->sync();
        $n2 = (int) $this->pdo->query("SELECT COUNT(*) FROM platform_roles")->fetchColumn();
        $this->assertSame($n, $n2);
    }

    // ── Autorisation ──

    public function testAuthorization(): void
    {
        $svc = new PlatformAccountService($this->pdo);
        $sa = $svc->createAccount(['email' => 'root@fronote', 'username' => 'root', 'password' => 'Sup3r!Pass99', 'first_name' => 'Root', 'last_name' => 'Admin']);
        $sup = $svc->createAccount(['email' => 'help@fronote', 'username' => 'help', 'password' => 'Supp0rt!99', 'first_name' => 'Sup', 'last_name' => 'Port']);
        $this->assignRole($sa, 'super_admin');
        $this->assignRole($sup, 'platform_support');

        $authSa = new PlatformAuthorization($this->pdo, ['id' => $sa]);
        $this->assertTrue($authSa->isSuperAdmin());
        $this->assertTrue($authSa->can('platform.establishments.purge'));

        $authSup = new PlatformAuthorization($this->pdo, ['id' => $sup]);
        $this->assertFalse($authSup->isSuperAdmin());
        $this->assertTrue($authSup->can('platform.support.ticket.view'));
        $this->assertFalse($authSup->can('platform.establishments.purge'));

        $this->assertFalse((new PlatformAuthorization($this->pdo, null))->can('platform.dashboard.view'));
    }

    // ── Comptes ──

    public function testAccountServiceCreateFindDisable(): void
    {
        $svc = new PlatformAccountService($this->pdo);
        $id = $svc->createAccount(['email' => 'dpo@fronote', 'username' => 'dpo', 'password' => 'Dp0!Secret99', 'first_name' => 'Dee', 'last_name' => 'Poh']);
        $this->assertGreaterThan(0, $id);
        $this->assertNotNull($svc->findActiveByLogin('dpo'));
        $this->assertNotNull($svc->findActiveByLogin('dpo@fronote'));

        $svc->disableAccount($id);
        $this->assertNull($svc->findActiveByLogin('dpo'), 'Un compte désactivé ne doit plus être trouvé pour le login.');
    }

    public function testCreateAccountRejectsMissingFields(): void
    {
        $this->expectException(\RuntimeException::class);
        (new PlatformAccountService($this->pdo))->createAccount(['username' => 'x', 'password' => 'y']); // email manquant
    }

    // ── Invitations Directeur ──

    public function testDirectorInvitationLifecycle(): void
    {
        $svc = new DirectorInvitationService($this->pdo);
        $now = '2026-06-19 10:00:00';
        $res = $svc->create(1, 'dir@lycee.fr', 'create_establishment', ['ttl_hours' => 48, 'now' => $now]);
        $this->assertGreaterThan(0, $res['id']);
        $this->assertNotEmpty($res['token']);

        // valide tant que non expirée
        $this->assertNotNull($svc->validate($res['token'], '2026-06-20 10:00:00'));
        // expirée au-delà de 48h
        $this->assertNull($svc->validate($res['token'], '2026-06-22 10:00:01'));
        // jeton inconnu
        $this->assertNull($svc->validate('mauvais-jeton', '2026-06-20 10:00:00'));

        // acceptation → plus valide
        $this->assertTrue($svc->markAccepted($res['id']));
        $this->assertNull($svc->validate($res['token'], '2026-06-20 10:00:00'));

        // révocation d'une autre invitation
        $res2 = $svc->create(1, 'dir2@lycee.fr', 'join_establishment', ['now' => $now]);
        $this->assertTrue($svc->revoke($res2['id']));
        $this->assertNull($svc->validate($res2['token'], '2026-06-20 10:00:00'));
    }

    public function testDirectorInvitationRejectsBadType(): void
    {
        $this->expectException(\RuntimeException::class);
        (new DirectorInvitationService($this->pdo))->create(1, 'x@y.fr', 'bogus_type');
    }
}
