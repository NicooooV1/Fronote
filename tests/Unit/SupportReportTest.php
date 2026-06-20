<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use API\Support\SupportReportService;
use API\Support\SupportSessionService;

/**
 * Rapport de fin d'intervention Support (refonte 3-mondes) : consolidation session +
 * journal + restrictions + durée + synthèse. SQLite en mémoire.
 */
final class SupportReportTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) { $this->markTestSkipped('pdo_sqlite absent'); }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE support_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, access_request_id INTEGER, ticket_id INTEGER, establishment_id INTEGER, platform_account_id INTEGER, access_level TEXT, active_scope_payload TEXT, status TEXT, started_at TEXT, expires_at TEXT, ended_at TEXT, ended_by_type TEXT, ended_by_platform_account_id INTEGER, ended_by_membership_id INTEGER, end_reason TEXT, intervention_summary TEXT, ip_address TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->pdo->exec('CREATE TABLE support_tickets (id INTEGER PRIMARY KEY AUTOINCREMENT, establishment_id INTEGER, title TEXT, status TEXT)');
        $this->pdo->exec('CREATE TABLE support_access_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, establishment_id INTEGER, access_level TEXT, status TEXT)');
        $this->pdo->exec('CREATE TABLE support_session_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, support_session_id INTEGER, ticket_id INTEGER, establishment_id INTEGER, platform_account_id INTEGER, action TEXT, target_type TEXT, target_id INTEGER, permission_used TEXT, access_level TEXT, sensitive INTEGER DEFAULT 0, new_value TEXT, ip_address TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->pdo->exec('CREATE TABLE support_session_restrictions (id INTEGER PRIMARY KEY AUTOINCREMENT, support_session_id INTEGER, restriction_key TEXT, restriction_value TEXT)');
    }

    public function testBuildConsolidatesSessionTrailAndDuration(): void
    {
        $this->pdo->exec("INSERT INTO support_tickets (id, establishment_id, title, status) VALUES (3,1,'Souci notes','resolved')");
        $this->pdo->exec("INSERT INTO support_access_requests (id, establishment_id, access_level, status) VALUES (2,1,'data_assistance','approved')");
        $this->pdo->exec("INSERT INTO support_sessions (id, access_request_id, ticket_id, establishment_id, platform_account_id, access_level, status, started_at, expires_at, ended_at, ended_by_type, end_reason, intervention_summary)
                          VALUES (5,2,3,1,10,'data_assistance','ended','2026-06-19 10:00:00','2026-06-19 12:00:00','2026-06-19 10:45:00','platform','Clôture support','Correction du rattachement effectuée.')");
        $svc = new SupportSessionService($this->pdo);
        $svc->audit(5, 'session_started', []);
        $svc->audit(5, 'data_view', ['target_type' => 'student', 'target_id' => 100]);
        $svc->audit(5, 'data_view', ['target_type' => 'student', 'target_id' => 101, 'sensitive' => true]);
        $this->pdo->exec("INSERT INTO support_session_restrictions (support_session_id, restriction_key, restriction_value) VALUES (5,'hide_medical_data','true')");

        $report = (new SupportReportService($this->pdo))->build(5);
        $this->assertNotNull($report);
        $this->assertSame('Souci notes', $report['ticket']['title']);
        $this->assertSame('data_assistance', $report['request']['access_level']);
        $this->assertSame(45, $report['duration_minutes'], '10:00 → 10:45 = 45 min');
        $this->assertCount(3, $report['trail']);
        $this->assertSame(2, $report['action_counts']['data_view']);
        $this->assertSame(1, $report['sensitive_actions']);
        $this->assertCount(1, $report['restrictions']);
        $this->assertStringContainsString('rattachement', (string) $report['summary']);
    }

    public function testBuildReturnsNullForUnknownSession(): void
    {
        $this->assertNull((new SupportReportService($this->pdo))->build(999));
    }
}
