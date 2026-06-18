<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use API\Auth\UserProvider;

/**
 * Sous-classe de test exposant la méthode protégée accountUsable().
 */
final class TestableUserProvider extends UserProvider
{
    public function accountUsablePublic(array $user): bool
    {
        return $this->accountUsable($user);
    }
}

/**
 * Vérifie UserProvider::accountUsable().
 *
 * Régression ciblée : un compte désactivé (actif=0) ou verrouillé
 * (locked_until futur) NE DOIT PAS être considéré comme utilisable.
 */
final class AccountUsableTest extends TestCase
{
    private TestableUserProvider $provider;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Extension pdo_sqlite non disponible.');
        }
        // accountUsable() ne touche pas la base, mais le constructeur exige un PDO.
        $pdo = new PDO('sqlite::memory:');
        $this->provider = new TestableUserProvider($pdo);
    }

    public function testInactiveAccountIsNotUsable(): void
    {
        $this->assertFalse(
            $this->provider->accountUsablePublic(['actif' => 0]),
            'Un compte avec actif=0 ne doit pas être utilisable.'
        );
    }

    public function testFutureLockMakesAccountNotUsable(): void
    {
        $future = date('Y-m-d H:i:s', time() + 3600);
        $this->assertFalse(
            $this->provider->accountUsablePublic(['actif' => 1, 'locked_until' => $future]),
            'Un compte verrouillé (locked_until futur) ne doit pas être utilisable.'
        );
    }

    public function testActiveUnlockedAccountIsUsable(): void
    {
        $this->assertTrue(
            $this->provider->accountUsablePublic(['actif' => 1]),
            'Un compte actif et non verrouillé doit être utilisable.'
        );
    }
}
