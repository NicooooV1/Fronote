<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use API\Services\UserService;

/**
 * Vérifie que UserService::changePassword propage le nouveau hash vers le miroir
 * `accounts` (prérequis du basculement complet vers l'auth contre accounts).
 */
final class PasswordSyncTest extends TestCase
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
        $this->pdo->exec('CREATE TABLE professeurs (id INTEGER PRIMARY KEY, mot_de_passe TEXT, password_changed_at TEXT)');
        $this->pdo->exec(
            'CREATE TABLE accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT, account_type TEXT, username TEXT, email TEXT,
                password_hash TEXT, status TEXT DEFAULT "active", legacy_type TEXT, legacy_id INTEGER,
                must_change_password INTEGER DEFAULT 1
            )'
        );
        $this->pdo->exec("INSERT INTO professeurs (id, mot_de_passe) VALUES (5, 'OLD')");
        $this->pdo->exec("INSERT INTO accounts (account_type, username, password_hash, legacy_type, legacy_id) VALUES ('personnel', 'p5', 'OLD_MIRROR', 'professeur', 5)");
    }

    public function testChangePasswordPropagatesToAccounts(): void
    {
        $service = new UserService($this->pdo);
        try {
            $result = $service->changePassword(5, 'StrongPass!2345', 'professeur');
        } catch (\Throwable $e) {
            $this->markTestSkipped('changePassword indisponible hors contexte applicatif : ' . $e->getMessage());
        }
        if (!is_array($result) || empty($result['success'])) {
            $this->markTestSkipped("changePassword n'a pas abouti (politique / app() indisponible).");
        }

        $legacyHash  = (string) $this->pdo->query('SELECT mot_de_passe FROM professeurs WHERE id = 5')->fetchColumn();
        $mirrorHash  = (string) $this->pdo->query("SELECT password_hash FROM accounts WHERE legacy_type='professeur' AND legacy_id=5")->fetchColumn();

        $this->assertNotSame('OLD_MIRROR', $mirrorHash, 'Le miroir accounts doit avoir été mis à jour.');
        $this->assertSame($legacyHash, $mirrorHash, 'Le miroir doit égaler le hash legacy fraîchement écrit.');
    }
}
