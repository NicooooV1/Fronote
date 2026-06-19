<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use API\Services\RoleManagementService;

/**
 * Garde-fous d'attribution de rôles (Phase 3 — sécurité) :
 *   - périmètre fin (scope_json) validé : clés connues + listes d'entiers ;
 *   - rôle SENSIBLE → justification obligatoire ;
 *   - purge des rôles expirés + journalisation dédiée (user_role_audit_logs).
 *
 * Le chemin d'écriture nominal (assign INSERT) utilise ON DUPLICATE KEY UPDATE
 * (MySQL) : on teste donc les garde-fous, qui lèvent AVANT l'INSERT, et la purge,
 * dont le SQL est portable. (SQLite en mémoire + shim NOW().)
 */
final class RoleManagementGuardTest extends TestCase
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
        $this->pdo->exec(
            'CREATE TABLE user_roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_type TEXT, user_id INTEGER, role_key TEXT, etablissement_id INTEGER,
                scope_type TEXT, scope_json TEXT, valid_from TEXT, valid_until TEXT
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE user_role_audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                actor_type TEXT, actor_id INTEGER, target_type TEXT, target_id INTEGER,
                action TEXT, role_key TEXT, old_value TEXT, new_value TEXT,
                ip_address TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }

    private function svc(): RoleManagementService
    {
        return new RoleManagementService($this->pdo);
    }

    public function testUnknownScopeKeyRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/périmètre inconnue/i');
        // super_admin → rôle assignable + compatibilité contournée ; professeur_principal
        // n'est pas sensible → on atteint la validation du périmètre, qui rejette la clé.
        $this->svc()->assign(
            ['type' => 'super_admin', 'id' => 1], ['super_admin'],
            'professeur', 5, 'professeur_principal',
            ['scope' => ['bogus_ids' => [1, 2]]]
        );
    }

    public function testNonListScopeRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->svc()->assign(
            ['type' => 'super_admin', 'id' => 1], ['super_admin'],
            'professeur', 5, 'professeur_principal',
            ['scope' => ['class_ids' => 42]] // doit être une liste
        );
    }

    public function testSensitiveRoleRequiresJustification(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/justification/i');
        // aesh est sensible → sans 'reason', refus.
        $this->svc()->assign(
            ['type' => 'super_admin', 'id' => 1], ['super_admin'],
            'eleve', 100, 'aesh', ['scope' => ['student_ids' => [100]]]
        );
    }

    public function testPurgeExpiredRemovesAndAudits(): void
    {
        $this->pdo->exec("INSERT INTO user_roles (user_type, user_id, role_key, valid_until) VALUES ('vie_scolaire', 7, 'aesh', '2000-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO user_roles (user_type, user_id, role_key, valid_until) VALUES ('professeur', 5, 'professeur_principal', NULL)");
        $this->pdo->exec("INSERT INTO user_roles (user_type, user_id, role_key, valid_until) VALUES ('technicien', 9, 'technicien', '2999-01-01 00:00:00')");

        $purged = $this->svc()->purgeExpired();
        $this->assertSame(1, $purged, 'Seul le rôle expiré doit être purgé.');

        $remaining = (int) $this->pdo->query('SELECT COUNT(*) FROM user_roles')->fetchColumn();
        $this->assertSame(2, $remaining, 'Le permanent et le futur doivent rester.');

        $audited = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM user_role_audit_logs WHERE action = 'role_revoked'"
        )->fetchColumn();
        $this->assertSame(1, $audited, 'La purge doit être journalisée.');
    }
}
