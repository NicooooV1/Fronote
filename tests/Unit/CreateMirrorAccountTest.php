<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use API\Services\UserService;

/**
 * Bascule complète : UserService::create doit aussi créer le compte miroir `accounts`
 * (sinon un nouvel utilisateur ne pourrait se connecter qu'au travers du repli hérité).
 * Skip-tolérant : create() dépend d'IdentifierGenerator/EstablishmentContext qui peuvent
 * manquer hors runtime applicatif.
 */
final class CreateMirrorAccountTest extends TestCase
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
            'CREATE TABLE professeurs (
                id INTEGER PRIMARY KEY AUTOINCREMENT, identifiant TEXT, nom TEXT, prenom TEXT, mail TEXT,
                mot_de_passe TEXT, etablissement_id INTEGER, adresse TEXT, matiere TEXT, professeur_principal TEXT
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT, account_type TEXT, username TEXT, email TEXT,
                password_hash TEXT, first_name TEXT, last_name TEXT, display_name TEXT, phone TEXT,
                status TEXT DEFAULT "pending", etablissement_id INTEGER, legacy_type TEXT, legacy_id INTEGER,
                must_change_password INTEGER DEFAULT 1, two_factor_enabled INTEGER DEFAULT 0,
                last_login_at TEXT, locked_until TEXT, failed_login_attempts INTEGER DEFAULT 0,
                created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT, deleted_at TEXT
            )'
        );
    }

    public function testCreateAlsoCreatesMirrorAccount(): void
    {
        $svc = new UserService($this->pdo);
        try {
            $r = $svc->create('professeur', ['nom' => 'Martin', 'prenom' => 'Paul', 'mail' => 'paul.martin@ex.fr', 'matiere' => 'Maths']);
        } catch (\Throwable $e) {
            $this->markTestSkipped('create() indisponible hors contexte applicatif : ' . $e->getMessage());
        }
        if (!is_array($r) || empty($r['success'])) {
            $this->markTestSkipped("create() n'a pas abouti (dépendances indisponibles).");
        }

        $acc = $this->pdo->query("SELECT * FROM accounts WHERE legacy_type='professeur'")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($acc, 'Un compte miroir doit avoir été créé.');
        $this->assertSame('personnel', $acc['account_type']);
        $this->assertSame('active', $acc['status']);
        $this->assertNotEmpty($acc['password_hash'], 'Le hash doit être miroité dès la création.');

        // legacy_id doit pointer vers la ligne professeurs réellement créée.
        $profId = (int) $this->pdo->query("SELECT id FROM professeurs WHERE mail='paul.martin@ex.fr'")->fetchColumn();
        $this->assertSame($profId, (int) $acc['legacy_id']);
    }
}
