<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use API\Services\AccountService;

/**
 * AccountService — préparation des comptes unifiés (table accounts).
 * CRUD de base + garde-fous + flag FEATURE_ACCOUNTS. (SQLite en mémoire.)
 */
final class AccountServiceTest extends TestCase
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
            'CREATE TABLE accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_type TEXT, username TEXT, email TEXT, password_hash TEXT,
                first_name TEXT, last_name TEXT, display_name TEXT, phone TEXT,
                status TEXT DEFAULT "pending", etablissement_id INTEGER,
                legacy_type TEXT, legacy_id INTEGER,
                must_change_password INTEGER DEFAULT 1, two_factor_enabled INTEGER DEFAULT 0,
                last_login_at TEXT, locked_until TEXT, failed_login_attempts INTEGER DEFAULT 0,
                created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT, deleted_at TEXT
            )'
        );
    }

    private function svc(): AccountService
    {
        return new AccountService($this->pdo);
    }

    public function testCreateAndFindByLegacy(): void
    {
        $id = $this->svc()->createAccount([
            'account_type' => 'student', 'username' => 'lucas.durand',
            'email' => 'lucas@example.fr', 'first_name' => 'Lucas', 'last_name' => 'Durand',
            'etablissement_id' => 1, 'status' => 'active',
            'legacy_type' => 'eleve', 'legacy_id' => 100,
        ]);
        $this->assertGreaterThan(0, $id);

        $found = $this->svc()->findByLegacy('eleve', 100);
        $this->assertNotNull($found);
        $this->assertSame('student', $found['account_type']);
        $this->assertSame('lucas.durand', $found['username']);
        $this->assertNull($this->svc()->findByLegacy('eleve', 999));
    }

    public function testInvalidTypeRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->svc()->createAccount(['account_type' => 'wizard', 'username' => 'x']);
    }

    public function testUsernameRequired(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->svc()->createAccount(['account_type' => 'personnel', 'username' => '   ']);
    }

    public function testStatusTransitions(): void
    {
        $svc = $this->svc();
        $id = $svc->createAccount(['account_type' => 'personnel', 'username' => 'p.martin', 'status' => 'active']);

        $svc->disableAccount($id);
        $this->assertSame('inactive', $this->accountStatus($id));

        $svc->lockAccount($id, '2030-01-01 00:00:00');
        $this->assertSame('locked', $this->accountStatus($id));
        $lockedUntil = $this->pdo->query("SELECT locked_until FROM accounts WHERE id = {$id}")->fetchColumn();
        $this->assertSame('2030-01-01 00:00:00', $lockedUntil);

        $svc->archiveAccount($id);
        $this->assertSame('archived', $this->accountStatus($id));
    }

    public function testUpdateRejectsBadStatusButAppliesOthers(): void
    {
        $svc = $this->svc();
        $id = $svc->createAccount(['account_type' => 'family', 'username' => 'c.dupont']);
        $svc->updateAccount($id, ['email' => 'new@example.fr', 'status' => 'bogus']);
        $this->assertSame('new@example.fr', $this->pdo->query("SELECT email FROM accounts WHERE id = {$id}")->fetchColumn());
        $this->assertNotSame('bogus', $this->accountStatus($id), 'Un statut invalide ne doit pas être appliqué.');
    }

    public function testIsEnabledReadsFlag(): void
    {
        $prev = getenv('FEATURE_ACCOUNTS');
        putenv('FEATURE_ACCOUNTS=true');
        $this->assertTrue(AccountService::isEnabled());
        putenv('FEATURE_ACCOUNTS=false');
        $this->assertFalse(AccountService::isEnabled());
        // restaure
        if ($prev === false) { putenv('FEATURE_ACCOUNTS'); } else { putenv('FEATURE_ACCOUNTS=' . $prev); }
    }

    private function accountStatus(int $id): string
    {
        return (string) $this->pdo->query("SELECT status FROM accounts WHERE id = {$id}")->fetchColumn();
    }
}
