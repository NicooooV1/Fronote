<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use API\Support\SupportImpersonation;
use API\Support\SupportSessionService;

/**
 * Autorisation d'impersonation Support (refonte 3-mondes) : exige session active de
 * niveau >= impersonation ET compte cible dans le périmètre (fail-closed). SQLite.
 */
final class SupportImpersonationTest extends TestCase
{
    private PDO $pdo;
    private const SUPPORT = 10;
    private const ESTAB = 1;
    private const NOW = '2026-06-19 10:00:00';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) { $this->markTestSkipped('pdo_sqlite absent'); }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE support_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, access_request_id INTEGER, ticket_id INTEGER, establishment_id INTEGER, platform_account_id INTEGER, access_level TEXT, active_scope_payload TEXT, status TEXT, started_at TEXT, expires_at TEXT, ended_at TEXT, end_reason TEXT, ip_address TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->pdo->exec('CREATE TABLE support_session_restrictions (id INTEGER PRIMARY KEY AUTOINCREMENT, support_session_id INTEGER, restriction_key TEXT, restriction_value TEXT)');
        $this->pdo->exec('CREATE TABLE support_session_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, support_session_id INTEGER, ticket_id INTEGER, establishment_id INTEGER, platform_account_id INTEGER, action TEXT, target_type TEXT, target_id INTEGER, permission_used TEXT, access_level TEXT, sensitive INTEGER DEFAULT 0, new_value TEXT, ip_address TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    }

    private function session(string $level, string $payload, string $status = 'active', string $expires = '2026-06-19 12:00:00'): void
    {
        $this->pdo->prepare("INSERT INTO support_sessions (id, access_request_id, ticket_id, establishment_id, platform_account_id, access_level, active_scope_payload, status, started_at, expires_at) VALUES (1,1,1,?,?,?,?,?,?,?)")
            ->execute([self::ESTAB, self::SUPPORT, $level, $payload, $status, self::NOW, $expires]);
    }

    public function testAllowsImpersonationInScope(): void
    {
        $this->session('impersonation', json_encode(['account' => [55]]));
        $svc = new SupportSessionService($this->pdo);
        $r = SupportImpersonation::canImpersonate($svc, self::SUPPORT, self::ESTAB, 55, self::NOW);
        $this->assertTrue($r['ok']);
    }

    public function testRefusesTargetOutOfScope(): void
    {
        $this->session('impersonation', json_encode(['account' => [55]]));
        $r = SupportImpersonation::canImpersonate(new SupportSessionService($this->pdo), self::SUPPORT, self::ESTAB, 999, self::NOW);
        $this->assertFalse($r['ok']);
    }

    public function testRefusesInsufficientLevel(): void
    {
        $this->session('account_assistance', json_encode(['account' => [55]]));
        $r = SupportImpersonation::canImpersonate(new SupportSessionService($this->pdo), self::SUPPORT, self::ESTAB, 55, self::NOW);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Niveau', $r['reason']);
    }

    public function testRefusesWhenNoActiveSession(): void
    {
        $this->session('impersonation', json_encode(['account' => [55]]), 'ended');
        $r = SupportImpersonation::canImpersonate(new SupportSessionService($this->pdo), self::SUPPORT, self::ESTAB, 55, self::NOW);
        $this->assertFalse($r['ok']);
    }

    public function testRefusesWhenSessionExpired(): void
    {
        $this->session('impersonation', json_encode(['account' => [55]]), 'active', '2026-06-19 09:00:00');
        $r = SupportImpersonation::canImpersonate(new SupportSessionService($this->pdo), self::SUPPORT, self::ESTAB, 55, self::NOW);
        $this->assertFalse($r['ok']);
    }
}
