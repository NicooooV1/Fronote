<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Vérifie la whitelist d'extensions de DocumentService (M16 Documents).
 *
 * Régression ciblée : les extensions exécutables (php/phtml/phar) NE DOIVENT
 * JAMAIS figurer dans ALLOWED_EXT, sinon upload RCE possible.
 * DocumentService n'est pas dans le namespace PSR-4 → require direct.
 */
final class UploadValidationTest extends TestCase
{
    /** @return array<string,string> [extension => is_in_whitelist] map */
    private function allowedExtensions(): array
    {
        $file = __DIR__ . '/../../modules/documents/includes/DocumentService.php';
        if (!is_file($file)) {
            $this->markTestSkipped('DocumentService introuvable : ' . $file);
        }
        require_once $file;

        if (!class_exists('DocumentService')) {
            $this->markTestSkipped('Classe DocumentService non chargée.');
        }

        $ref = new ReflectionClass('DocumentService');
        if (!$ref->hasConstant('ALLOWED_EXT')) {
            $this->markTestSkipped('Constante ALLOWED_EXT absente.');
        }

        $value = $ref->getConstant('ALLOWED_EXT');
        $this->assertIsArray($value, 'ALLOWED_EXT doit être un tableau.');
        return $value;
    }

    /**
     * @dataProvider dangerousExtensions
     */
    public function testDangerousExtensionsAreNotWhitelisted(string $ext): void
    {
        $allowed = $this->allowedExtensions();
        $this->assertNotContains(
            $ext,
            $allowed,
            "L'extension exécutable '$ext' NE DOIT PAS être autorisée."
        );
    }

    /**
     * @dataProvider safeExtensions
     */
    public function testSafeExtensionsAreWhitelisted(string $ext): void
    {
        $allowed = $this->allowedExtensions();
        $this->assertContains(
            $ext,
            $allowed,
            "L'extension '$ext' devrait être autorisée."
        );
    }

    public static function dangerousExtensions(): array
    {
        return [
            'php'   => ['php'],
            'phtml' => ['phtml'],
            'phar'  => ['phar'],
        ];
    }

    public static function safeExtensions(): array
    {
        return [
            'pdf' => ['pdf'],
            'jpg' => ['jpg'],
        ];
    }
}
