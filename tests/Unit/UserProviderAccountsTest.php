<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use API\Auth\UserProvider;

/**
 * Bascule des comptes unifiés (FEATURE_ACCOUNTS) côté résolution de login.
 *
 * Garantit la sécurité de l'activation :
 *  - compte ACTIF dans `accounts` → login résolu via accounts vers l'identité héritée
 *    (le mot de passe reste vérifié sur la table héritée → pas de hash périmé) ;
 *  - compte INACTIF → connexion bloquée (pas de repli) ;
 *  - aucun compte → repli sur le scan hérité (utilisateur non encore reflété, pas de lock-out) ;
 *  - flag OFF → `accounts` ignoré, comportement hérité inchangé.
 */
final class UserProviderAccountsTest extends TestCase
{
    private PDO $pdo;
    private UserProvider $provider;
    /** @var string|false */
    private $prevFlag;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Extension pdo_sqlite non disponible.');
        }
        $this->prevFlag = getenv('FEATURE_ACCOUNTS');

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec(
            'CREATE TABLE administrateurs (
                id INTEGER PRIMARY KEY, nom TEXT, prenom TEXT, mail TEXT, mot_de_passe TEXT,
                identifiant TEXT, etablissement_id INTEGER, actif INTEGER DEFAULT 1, locked_until TEXT
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT, account_type TEXT, username TEXT, email TEXT,
                status TEXT DEFAULT "active", legacy_type TEXT, legacy_id INTEGER
            )'
        );
        // Administrateur hérité #5, identifiant "admin", hash connu.
        $this->pdo->exec(
            "INSERT INTO administrateurs (id, nom, prenom, mail, mot_de_passe, identifiant, etablissement_id, actif)
             VALUES (5, 'Boss', 'Ada', 'ada@ex.fr', 'HASH5', 'admin', 1, 1)"
        );

        $this->provider = new UserProvider($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->prevFlag === false) { putenv('FEATURE_ACCOUNTS'); }
        else { putenv('FEATURE_ACCOUNTS=' . $this->prevFlag); }
    }

    private function linkAccount(string $status): void
    {
        $this->pdo->exec(
            "INSERT INTO accounts (account_type, username, email, status, legacy_type, legacy_id)
             VALUES ('personnel', 'admin', 'ada@ex.fr', '{$status}', 'administrateur', 5)"
        );
    }

    public function testActiveAccountResolvesToLegacyIdentity(): void
    {
        $this->linkAccount('active');
        putenv('FEATURE_ACCOUNTS=true');

        $c = $this->provider->findByLoginAllTypes('admin');
        $this->assertCount(1, $c);
        $this->assertSame('administrateur', $c[0]['type']);
        $this->assertSame(5, (int) $c[0]['id']);
        $this->assertSame('HASH5', $c[0]['mot_de_passe'], 'Le mot de passe vient de la table héritée.');
    }

    public function testInactiveAccountBlocksLoginNoFallback(): void
    {
        $this->linkAccount('inactive');
        putenv('FEATURE_ACCOUNTS=true');

        $c = $this->provider->findByLoginAllTypes('admin');
        $this->assertSame([], $c, 'Compte désactivé → aucune candidature, pas de repli hérité.');
    }

    public function testNoAccountFallsBackToLegacy(): void
    {
        // Pas de ligne accounts pour ce login.
        putenv('FEATURE_ACCOUNTS=true');

        $c = $this->provider->findByLoginAllTypes('admin');
        $this->assertCount(1, $c, 'Utilisateur non reflété dans accounts → repli hérité.');
        $this->assertSame('administrateur', $c[0]['type']);
    }

    public function testFlagOffIgnoresAccounts(): void
    {
        $this->linkAccount('inactive'); // serait bloquant si accounts était consulté
        putenv('FEATURE_ACCOUNTS=false');

        $c = $this->provider->findByLoginAllTypes('admin');
        $this->assertCount(1, $c, 'Flag off → accounts ignoré, scan hérité.');
        $this->assertSame('administrateur', $c[0]['type']);
    }
}
