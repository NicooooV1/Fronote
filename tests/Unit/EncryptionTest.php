<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use API\Core\Encryption;

/**
 * Chiffrement at-rest AES-256-GCM des données sensibles (RGPD Art. 9 — santé).
 * Prouve le round-trip, la détection de format, l'idempotence et la détection
 * d'altération (tag GCM). Comble le manque de test de chiffrement relevé par l'audit.
 */
final class EncryptionTest extends TestCase
{
    private Encryption $enc;

    protected function setUp(): void
    {
        // Clé de test explicite : le test ne dépend pas d'APP_KEY d'environnement.
        $this->enc = new Encryption(str_repeat('k', 48));
    }

    public function testRoundTrip(): void
    {
        $plain = 'Allergies : arachides, pollen — traitement Ventoline';
        $cipher = $this->enc->encrypt($plain);
        $this->assertNotSame($plain, $cipher, 'Le chiffré ne doit pas égaler le clair.');
        $this->assertSame($plain, $this->enc->decrypt($cipher), 'Le déchiffré doit restituer le clair.');
    }

    public function testDetectsEncryptedFormat(): void
    {
        $cipher = $this->enc->encrypt('secret');
        $this->assertTrue($this->enc->isEncrypted($cipher));
        $this->assertFalse($this->enc->isEncrypted('texte en clair'));
        $this->assertFalse($this->enc->isEncrypted(''));
    }

    public function testEncryptIfPlainIsIdempotent(): void
    {
        $once = $this->enc->encryptIfPlain('donnée santé');
        $twice = $this->enc->encryptIfPlain($once);
        $this->assertSame($once, $twice, 'Une valeur déjà chiffrée ne doit pas être re-chiffrée.');
        $this->assertSame('donnée santé', $this->enc->decrypt($twice));
    }

    public function testNonceIsRandomPerCall(): void
    {
        $this->assertNotSame(
            $this->enc->encrypt('même donnée'),
            $this->enc->encrypt('même donnée'),
            'Deux chiffrements de la même valeur doivent différer (nonce aléatoire).'
        );
    }

    public function testTamperingIsRejected(): void
    {
        $cipher = $this->enc->encrypt('intègre');
        // Altère le dernier caractère du ciphertext → le tag GCM doit faire échouer.
        $tampered = substr($cipher, 0, -1) . ($cipher[-1] === 'A' ? 'B' : 'A');
        $this->expectException(\RuntimeException::class);
        $this->enc->decrypt($tampered);
    }
}
