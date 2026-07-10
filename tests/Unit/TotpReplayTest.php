<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use API\Services\TwoFactorService;
use PDO;

/**
 * Anti-rejeu TOTP (RFC 6238 §5.2) : un même code (pas de temps) ne doit être accepté
 * qu'UNE seule fois. Comble le manque relevé par l'audit (code valide rejouable dans la
 * fenêtre ±1). Test auto-porté sur SQLite en mémoire.
 */
final class TotpReplayTest extends TestCase
{
    private PDO $pdo;
    private TwoFactorService $svc;
    private string $secret = 'JBSWY3DPEHPK3PXP'; // secret base32 de test

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Table de compte minimale (getSecret lit two_factor_secret dans la table du rôle).
        $this->pdo->exec("CREATE TABLE eleves (id INTEGER PRIMARY KEY, two_factor_enabled INT DEFAULT 1, two_factor_secret TEXT)");
        $this->pdo->exec("INSERT INTO eleves (id, two_factor_enabled, two_factor_secret) VALUES (1, 1, '{$this->secret}')");
        $this->svc = new TwoFactorService($this->pdo);
    }

    /** Génère un code TOTP valide pour le pas de temps courant (via l'API interne). */
    private function currentCode(): string
    {
        $ref = new \ReflectionClass($this->svc);
        $gen = $ref->getMethod('generateTOTP'); $gen->setAccessible(true);
        $dec = $ref->getMethod('decodeBase32'); $dec->setAccessible(true);
        $step = intdiv(time(), 30);
        return $gen->invoke($this->svc, $dec->invoke($this->svc, $this->secret), $step);
    }

    public function testCodeValideAccepteUneSeuleFois(): void
    {
        $code = $this->currentCode();
        $this->assertTrue($this->svc->validateLogin(1, 'eleve', $code), '1er usage du code doit réussir.');
        $this->assertFalse($this->svc->validateLogin(1, 'eleve', $code), 'Rejeu du même code doit être refusé (anti-rejeu).');
    }

    public function testCodeInvalideRefuse(): void
    {
        $this->assertFalse($this->svc->validateLogin(1, 'eleve', '000000'), 'Un code arbitraire doit être refusé.');
    }
}
