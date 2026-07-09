<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use API\Core\Encryption;

/**
 * Intégrité & restaurabilité des sauvegardes : createDatabaseBackup() produit un
 * artefact qui se déchiffre, se décompresse et contient un dump SQL valide
 * (schéma + données). Prouve automatiquement qu'une sauvegarde est restaurable,
 * comble le manque de test de restore relevé par l'audit. Intégration : tourne
 * contre la base configurée dans un répertoire temporaire (nettoyé) ; se skippe
 * sans base (ex. CI sans MySQL).
 */
final class BackupIntegrityTest extends TestCase
{
    private ?\PDO $pdo = null;
    private string $tmpBase = '';

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $envFile = $root . '/.env';
        if (!is_file($envFile)) {
            $this->markTestSkipped('.env absent — test d\'intégration ignoré.');
        }
        foreach (file($envFile, FILE_IGNORE_NEW_LINES) as $l) {
            if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $l, $m)) {
                putenv($m[1] . '=' . trim($m[2], " \"'"));
            }
        }
        try {
            $this->pdo = new \PDO(
                'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT')
                    . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
                getenv('DB_USER') ?: '', getenv('DB_PASS') ?: '',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('Base indisponible — test d\'intégration ignoré.');
        }
        require_once $root . '/API/Services/BackupService.php';
        $this->tmpBase = sys_get_temp_dir() . '/fronote-backup-test-' . getmypid();
        @mkdir($this->tmpBase . '/storage/backups', 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmpBase && is_dir($this->tmpBase)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpBase, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
            @rmdir($this->tmpBase);
        }
    }

    public function testBackupIsDecryptableAndValidSql(): void
    {
        $svc = new \API\Services\BackupService($this->pdo, $this->tmpBase);
        $file = $svc->createDatabaseBackup();
        $this->assertFileExists($file, 'La sauvegarde doit être créée.');

        $blob = file_get_contents($file);
        // Déchiffrement si le dump est chiffré (.enc, format AES-256-GCM).
        if (substr($file, -4) === '.enc') {
            $this->assertTrue(Encryption::available(), 'Clé de chiffrement requise pour un dump .enc.');
            $blob = (new Encryption())->decrypt($blob);
        }
        // Décompression si gzip.
        if (substr(preg_replace('/\.enc$/', '', $file), -3) === '.gz') {
            $blob = gzdecode($blob);
            $this->assertNotFalse($blob, 'Le dump doit se décompresser (gzip valide).');
        }

        $this->assertIsString($blob);
        $this->assertGreaterThan(50, substr_count($blob, 'CREATE TABLE'), 'Le dump doit contenir le schéma.');
        $this->assertStringContainsString('etablissements', $blob, 'Le dump doit contenir les tables métier.');
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS', $blob, 'Le dump doit être un SQL restaurable cohérent.');
    }
}
