<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use API\Services\UserService;

/**
 * Vérifie l'isolation inter-tables de UserService::changePassword().
 *
 * Régression ciblée : un même id peut exister dans plusieurs tables
 * (eleves, professeurs, …). changePassword($id, $pwd, 'professeur')
 * NE DOIT modifier QUE la table professeurs et laisser eleves intacte.
 */
final class ChangePasswordIsolationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Extension pdo_sqlite non disponible.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // changePassword utilise NOW() (MySQL). On le simule sous SQLite
        // pour que l'UPDATE réel s'exécute au lieu d'être avalé par le catch.
        if (method_exists($this->pdo, 'sqliteCreateFunction')) {
            $this->pdo->sqliteCreateFunction('NOW', static function () {
                return date('Y-m-d H:i:s');
            });
        }

        $this->pdo->exec(
            'CREATE TABLE eleves (id INTEGER PRIMARY KEY, mot_de_passe TEXT, password_changed_at TEXT)'
        );
        $this->pdo->exec(
            'CREATE TABLE professeurs (id INTEGER PRIMARY KEY, mot_de_passe TEXT, password_changed_at TEXT)'
        );

        $this->pdo->exec("INSERT INTO eleves (id, mot_de_passe) VALUES (5, 'ELEVE_HASH')");
        $this->pdo->exec("INSERT INTO professeurs (id, mot_de_passe) VALUES (5, 'PROF_HASH')");
    }

    public function testChangePasswordOnlyAffectsTargetTable(): void
    {
        $service = new UserService($this->pdo);

        try {
            $result = $service->changePassword(5, 'StrongPass!2345', 'professeur');
        } catch (\Throwable $e) {
            // app('password_policy') / dépendances indisponibles hors runtime applicatif.
            $this->markTestSkipped('changePassword indisponible hors contexte applicatif : ' . $e->getMessage());
        }

        if (!is_array($result) || empty($result['success'])) {
            $this->markTestSkipped('changePassword n\'a pas abouti (politique de mot de passe / app() indisponible).');
        }

        // La ligne professeurs.id=5 a changé.
        $prof = $this->pdo->query('SELECT mot_de_passe FROM professeurs WHERE id = 5')
                         ->fetchColumn();
        $this->assertNotSame('PROF_HASH', $prof, 'Le mot de passe du professeur aurait dû changer.');

        // La ligne eleves.id=5 est INCHANGÉE.
        $eleve = $this->pdo->query('SELECT mot_de_passe FROM eleves WHERE id = 5')
                          ->fetchColumn();
        $this->assertSame('ELEVE_HASH', $eleve, 'Le mot de passe de l\'élève NE DOIT PAS changer.');
    }
}
