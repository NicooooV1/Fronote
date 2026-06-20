<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use API\Support\SupportTicketService;
use API\Support\SupportAccessRequestService;
use API\Support\SupportAccessApprovalService;
use API\Support\SupportSessionService;
use API\Support\SupportSessionGuard;

/**
 * Pont SUPPORT (refonte 3-mondes — Phase C) : ticket → demande d'accès → validation
 * Direction → session temporaire bornée/auditée. Vérifie les invariants de sécurité
 * du §7.14. SQLite en mémoire.
 */
final class SupportBridgeTest extends TestCase
{
    private PDO $pdo;
    private const SUPPORT = 10;   // platform_account_id (Support)
    private const APPROVER = 5;   // tenant_membership_id (Direction)

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Extension pdo_sqlite non disponible.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE support_tickets (id INTEGER PRIMARY KEY AUTOINCREMENT, establishment_id INTEGER, created_by_tenant_account_id INTEGER, created_by_membership_id INTEGER, assigned_platform_account_id INTEGER, title TEXT, category TEXT, priority TEXT DEFAULT "normal", description TEXT, status TEXT DEFAULT "submitted", sensitive_flag INTEGER DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT, resolved_at TEXT, closed_at TEXT)');
        $this->pdo->exec('CREATE TABLE support_bridge_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER, author_type TEXT, tenant_account_id INTEGER, platform_account_id INTEGER, message TEXT, is_internal_note INTEGER DEFAULT 0, visible_to_tenant INTEGER DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->pdo->exec('CREATE TABLE support_access_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER, establishment_id INTEGER, requested_by_platform_account_id INTEGER, reviewed_by_membership_id INTEGER, access_level TEXT, requested_scope_type TEXT, requested_scope_payload TEXT, reason TEXT, detailed_reason TEXT, planned_actions TEXT, requested_duration_minutes INTEGER, approved_duration_minutes INTEGER, requested_expires_at TEXT, approved_expires_at TEXT, status TEXT DEFAULT "draft", refusal_reason TEXT, direction_comment TEXT, requires_sensitive_validation INTEGER DEFAULT 0, sensitive_validation_status TEXT DEFAULT "not_required", created_at TEXT DEFAULT CURRENT_TIMESTAMP, sent_at TEXT, reviewed_at TEXT, revoked_at TEXT)');
        $this->pdo->exec('CREATE TABLE support_access_request_approvals (id INTEGER PRIMARY KEY AUTOINCREMENT, access_request_id INTEGER, approver_membership_id INTEGER, decision TEXT, approved_access_level TEXT, approved_scope_payload TEXT, approved_duration_minutes INTEGER, comment TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->pdo->exec('CREATE TABLE support_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, access_request_id INTEGER, ticket_id INTEGER, establishment_id INTEGER, platform_account_id INTEGER, access_level TEXT, active_scope_payload TEXT, status TEXT DEFAULT "pending_start", started_at TEXT, expires_at TEXT, ended_at TEXT, ended_by_type TEXT, ended_by_platform_account_id INTEGER, ended_by_membership_id INTEGER, end_reason TEXT, ip_address TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->pdo->exec('CREATE TABLE support_session_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, support_session_id INTEGER, ticket_id INTEGER, establishment_id INTEGER, platform_account_id INTEGER, action TEXT, target_type TEXT, target_id INTEGER, permission_used TEXT, access_level TEXT, description TEXT, old_value TEXT, new_value TEXT, sensitive INTEGER DEFAULT 0, ip_address TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->pdo->exec('CREATE TABLE support_session_restrictions (id INTEGER PRIMARY KEY AUTOINCREMENT, support_session_id INTEGER, restriction_key TEXT, restriction_value TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    }

    /** Monte un ticket + demande d'accès account_assistance sur l'élève 100. @return [ticketId, requestId]. */
    private function ticketAndRequest(string $now = '2026-06-19 10:00:00', string $level = 'account_assistance'): array
    {
        $t = new SupportTicketService($this->pdo);
        $ticketId = $t->create(1, 55, null, 'Parent ne voit pas son enfant', 'permissions', 'IDOR rattachement');
        $r = new SupportAccessRequestService($this->pdo);
        $reqId = $r->create(self::SUPPORT, $ticketId, 1, $level, 'student',
            ['student' => [100], 'account' => [55]], 'Vérifier le rattachement', 120, ['now' => $now]);
        return [$ticketId, $reqId];
    }

    public function testNoAccessWithoutApprovedRequest(): void
    {
        [$ticketId, $reqId] = $this->ticketAndRequest();
        $guard = new SupportSessionGuard($this->pdo);
        // Aucune session créée tant que la Direction n'a pas approuvé.
        $this->assertNull($guard->session(self::SUPPORT, 1, '2026-06-19 10:30:00'));
        $this->assertFalse($guard->can(self::SUPPORT, 1, 'read_only', null, null, false, '2026-06-19 10:30:00'));
    }

    public function testApproveStartAndScopedAccess(): void
    {
        $now = '2026-06-19 10:00:00';
        [$ticketId, $reqId] = $this->ticketAndRequest($now);
        $appr = new SupportAccessApprovalService($this->pdo);
        // Direction approuve mais limite à 60 min.
        $sessionId = $appr->approve(self::APPROVER, $reqId, ['approved_duration_minutes' => 60, 'now' => $now]);
        $this->assertGreaterThan(0, $sessionId);
        $this->assertSame('access_approved', (new SupportTicketService($this->pdo))->get($ticketId)['status']);

        $sessions = new SupportSessionService($this->pdo);
        $this->assertTrue($sessions->start($sessionId, self::SUPPORT, $now));

        $guard = new SupportSessionGuard($this->pdo);
        $at = '2026-06-19 10:30:00'; // dans la fenêtre
        $this->assertTrue($guard->can(self::SUPPORT, 1, 'account_assistance', 'student', 100, false, $at), 'Élève dans le périmètre approuvé.');
        $this->assertFalse($guard->can(self::SUPPORT, 1, 'account_assistance', 'student', 200, false, $at), 'Élève hors périmètre.');
        // Niveau insuffisant pour une action data_assistance.
        $this->assertFalse($guard->can(self::SUPPORT, 1, 'data_assistance', null, null, false, $at));
        // Donnée sensible interdite hors niveau sensitive_data_access.
        $this->assertFalse($guard->can(self::SUPPORT, 1, 'account_assistance', 'medical', 100, true, $at));

        // Audit append-only alimenté.
        $n = (int) $this->pdo->query("SELECT COUNT(*) FROM support_session_audit_logs WHERE support_session_id={$sessionId}")->fetchColumn();
        $this->assertGreaterThanOrEqual(2, $n);
    }

    public function testSessionAutoExpires(): void
    {
        $now = '2026-06-19 10:00:00';
        [$ticketId, $reqId] = $this->ticketAndRequest($now);
        $appr = new SupportAccessApprovalService($this->pdo);
        $sessionId = $appr->approve(self::APPROVER, $reqId, ['approved_duration_minutes' => 30, 'now' => $now]);
        (new SupportSessionService($this->pdo))->start($sessionId, self::SUPPORT, $now);
        $guard = new SupportSessionGuard($this->pdo);
        // 31 min plus tard → expirée.
        $this->assertNull($guard->session(self::SUPPORT, 1, '2026-06-19 10:31:00'));
        $this->assertFalse($guard->can(self::SUPPORT, 1, 'read_only', null, null, false, '2026-06-19 10:31:00'));
        $this->assertSame('expired', (new SupportSessionService($this->pdo))->get($sessionId)['status']);
    }

    public function testDirectionCanRevokeAnytime(): void
    {
        $now = '2026-06-19 10:00:00';
        [$ticketId, $reqId] = $this->ticketAndRequest($now);
        $sessions = new SupportSessionService($this->pdo);
        $sessionId = (new SupportAccessApprovalService($this->pdo))->approve(self::APPROVER, $reqId, ['now' => $now]);
        $sessions->start($sessionId, self::SUPPORT, $now);
        $this->assertTrue($sessions->revokeByDirection($sessionId, self::APPROVER, 'Plus nécessaire', '2026-06-19 10:10:00'));
        $guard = new SupportSessionGuard($this->pdo);
        $this->assertFalse($guard->can(self::SUPPORT, 1, 'read_only', null, null, false, '2026-06-19 10:20:00'));
        $this->assertSame('revoked', $sessions->get($sessionId)['status']);
    }

    public function testSecurityStopEndsSession(): void
    {
        $now = '2026-06-19 10:00:00';
        [$ticketId, $reqId] = $this->ticketAndRequest($now);
        $sessions = new SupportSessionService($this->pdo);
        $sessionId = (new SupportAccessApprovalService($this->pdo))->approve(self::APPROVER, $reqId, ['now' => $now]);
        $sessions->start($sessionId, self::SUPPORT, $now);
        $this->assertTrue($sessions->securityStop($sessionId, 1, 'Incident', '2026-06-19 10:05:00'));
        $this->assertSame('security_stopped', $sessions->get($sessionId)['status']);
        // plus d'accès après arrêt de sécurité
        $this->assertFalse((new SupportSessionGuard($this->pdo))->can(self::SUPPORT, 1, 'read_only', null, null, false, '2026-06-19 10:06:00'));
    }

    public function testStartRefusedWithoutApproval(): void
    {
        $now = '2026-06-19 10:00:00';
        [$ticketId, $reqId] = $this->ticketAndRequest($now);
        // Session forgée pending_start mais demande encore en attente (non approuvée).
        $this->pdo->prepare("INSERT INTO support_sessions (access_request_id, ticket_id, establishment_id, platform_account_id, access_level, status, expires_at) VALUES (?, ?, 1, ?, 'diagnostic', 'pending_start', ?)")
            ->execute([$reqId, $ticketId, self::SUPPORT, '2026-06-19 12:00:00']);
        $sid = (int) $this->pdo->lastInsertId();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/approuv/i');
        (new SupportSessionService($this->pdo))->start($sid, self::SUPPORT, $now);
    }

    public function testGlobalScopeRequestRefused(): void
    {
        $r = new SupportAccessRequestService($this->pdo);
        $t = (new SupportTicketService($this->pdo))->create(1, 55, null, 'x', 'other', 'y');
        $this->expectException(\RuntimeException::class);
        $r->create(self::SUPPORT, $t, 1, 'diagnostic', 'global', [], 'fourre-tout', 1440);
    }
}
