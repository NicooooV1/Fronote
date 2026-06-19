<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use API\Security\CSRF;

/**
 * Protection CSRF : un token émis est accepté une fois, un token inconnu/vide est
 * refusé. Comble un manque de couverture relevé par l'audit.
 */
final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testGeneratedTokenValidates(): void
    {
        $csrf  = new CSRF(3600, 10);
        $token = $csrf->generate();
        $this->assertNotEmpty($token);
        $this->assertTrue($csrf->validate($token), 'Un token fraîchement émis doit être valide.');
    }

    public function testEmptyTokenIsRejected(): void
    {
        $csrf = new CSRF(3600, 10);
        $csrf->generate();
        $this->assertFalse($csrf->validate(''), 'Un token vide doit être refusé.');
    }

    public function testUnknownTokenIsRejected(): void
    {
        $csrf = new CSRF(3600, 10);
        $csrf->generate();
        $this->assertFalse($csrf->validate('deadbeef' . str_repeat('0', 56)), 'Un token inconnu doit être refusé.');
    }

    public function testExpiredTokenIsRejected(): void
    {
        $csrf  = new CSRF(1, 10); // durée de vie 1 s
        $token = $csrf->generate();
        // Antidater le timestamp stocké au-delà de la durée de vie pour simuler l'expiration.
        $key = (new \ReflectionClass(CSRF::class))->getConstant('SESSION_KEY');
        $_SESSION[$key][$token] = time() - 60;
        $this->assertFalse($csrf->validate($token), 'Un token expiré doit être refusé.');
    }
}
