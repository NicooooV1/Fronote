<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use API\Tenant\TenantOnboardingService;
use API\Tenant\TenantRoleSync;

/**
 * Onboarding Directeur (refonte 3-mondes) : acceptation d'invitation → compte directeur
 * + établissement + appartenance + rôle directeur. SQLite en mémoire + shim NOW().
 */
final class OnboardingTest extends TestCase
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
        $this->pdo->exec('CREATE TABLE etablissements (id INTEGER PRIMARY KEY AUTOINCREMENT, nom TEXT, code TEXT, slug TEXT, type TEXT, ville TEXT, status TEXT DEFAULT "active", created_from_invite_id INTEGER, created_by_tenant_account_id INTEGER, actif INTEGER DEFAULT 1)');
        $this->pdo->exec('CREATE TABLE tenant_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, username TEXT, password_hash TEXT, first_name TEXT, last_name TEXT, display_name TEXT, account_type TEXT, status TEXT DEFAULT "pending", must_change_password INTEGER DEFAULT 1, legacy_type TEXT, legacy_id INTEGER, created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->pdo->exec('CREATE TABLE tenant_memberships (id INTEGER PRIMARY KEY AUTOINCREMENT, establishment_id INTEGER, tenant_account_id INTEGER, status TEXT DEFAULT "active", joined_at TEXT DEFAULT CURRENT_TIMESTAMP, revoked_at TEXT)');
        $this->pdo->exec('CREATE TABLE tenant_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, role_key TEXT, label TEXT, category TEXT, default_scope_type TEXT, is_sensitive INTEGER DEFAULT 0, is_system INTEGER DEFAULT 1, is_assignable INTEGER DEFAULT 1)');
        $this->pdo->exec('CREATE TABLE tenant_membership_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, membership_id INTEGER, tenant_role_id INTEGER, scope_type TEXT, scope_id INTEGER, starts_at TEXT, expires_at TEXT, reason TEXT, assigned_by_membership_id INTEGER, is_active INTEGER DEFAULT 1, assigned_at TEXT DEFAULT CURRENT_TIMESTAMP, revoked_at TEXT)');
        $this->pdo->exec('CREATE TABLE director_invitations (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, first_name TEXT, last_name TEXT, invitation_type TEXT, token_hash TEXT, allowed_establishment_ids TEXT, default_tenant_role TEXT DEFAULT "directeur", created_by_platform_account_id INTEGER, expires_at TEXT, accepted_at TEXT, revoked_at TEXT, status TEXT DEFAULT "pending", created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        (new TenantRoleSync($this->pdo))->sync();
    }

    private function invitation(string $type, ?array $allowed = null): array
    {
        $this->pdo->prepare("INSERT INTO director_invitations (email, invitation_type, token_hash, allowed_establishment_ids, default_tenant_role, created_by_platform_account_id, expires_at, status) VALUES (?, ?, 'h', ?, 'directeur', 1, '2099-01-01 00:00:00', 'pending')")
            ->execute(['dir@lycee.fr', $type, $allowed !== null ? json_encode($allowed) : null]);
        $id = (int) $this->pdo->lastInsertId();
        return $this->pdo->query("SELECT * FROM director_invitations WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
    }

    public function testAcceptCreateEstablishment(): void
    {
        $inv = $this->invitation('create_establishment');
        $res = (new TenantOnboardingService($this->pdo))->acceptInvitation(
            $inv,
            ['first_name' => 'Marie', 'last_name' => 'Dupont', 'password' => 'Directeur!2026X'],
            ['name' => 'Lycée Jean Moulin', 'type' => 'lycee', 'city' => 'Paris']
        );
        $this->assertGreaterThan(0, $res['account_id']);
        $this->assertNotSame('', $res['slug']);

        $acc = $this->pdo->query("SELECT * FROM tenant_accounts WHERE id = {$res['account_id']}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('director', $acc['account_type']);

        $etab = $this->pdo->query("SELECT * FROM etablissements WHERE nom = 'Lycée Jean Moulin'")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($etab);
        $this->assertSame('lycee', $etab['type']);

        $mr = (int) $this->pdo->query("SELECT COUNT(*) FROM tenant_membership_roles tmr JOIN tenant_roles tr ON tr.id=tmr.tenant_role_id JOIN tenant_memberships m ON m.id=tmr.membership_id WHERE tr.role_key='directeur' AND m.tenant_account_id={$res['account_id']}")->fetchColumn();
        $this->assertSame(1, $mr, 'Rôle directeur attribué sur le nouvel établissement.');

        $this->assertSame('accepted', $this->pdo->query("SELECT status FROM director_invitations WHERE id={$inv['id']}")->fetchColumn());
    }

    public function testAcceptJoinExistingEstablishments(): void
    {
        $this->pdo->exec("INSERT INTO etablissements (id, nom, code, slug, type, status) VALUES (7, 'Collège VH', 'cvh', 'cvh', 'college', 'active')");
        $inv = $this->invitation('manage_multiple_establishments', [7]);
        $res = (new TenantOnboardingService($this->pdo))->acceptInvitation(
            $inv, ['first_name' => 'Paul', 'last_name' => 'Martin', 'password' => 'Directeur!2026X']
        );
        $this->assertSame([7], $res['establishments']);
        $n = (int) $this->pdo->query("SELECT COUNT(*) FROM tenant_memberships WHERE tenant_account_id={$res['account_id']} AND establishment_id=7")->fetchColumn();
        $this->assertSame(1, $n);
        // Pas de nouvel établissement créé (toujours 1).
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM etablissements")->fetchColumn());
    }
}
