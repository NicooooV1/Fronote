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
    private string $scratchDb = '';

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
        if ($this->scratchDb !== '' && $this->pdo) {
            try { $this->pdo->exec('DROP DATABASE IF EXISTS `' . $this->scratchDb . '`'); } catch (\Throwable $e) {}
        }
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

    /**
     * Round-trip RÉEL : sauvegarde → restauration dans une base scratch → les données
     * correspondent. Prouve que restoreDatabase() aboutit vraiment (pas seulement que
     * l'artefact est un SQL bien formé). Skippe sans privilège CREATE DATABASE.
     */
    public function testRestoreRoundTripIntoScratchDatabase(): void
    {
        // Nombre d'établissements dans la base source (référence de comparaison).
        $expected = (int) $this->pdo->query('SELECT COUNT(*) FROM etablissements')->fetchColumn();

        $svc  = new \API\Services\BackupService($this->pdo, $this->tmpBase);
        $file = $svc->createDatabaseBackup();

        // Base scratch isolée.
        $this->scratchDb = 'pronote_rt_' . getmypid();
        try {
            $this->pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $this->scratchDb . '` CHARACTER SET utf8mb4');
        } catch (\Throwable $e) {
            $this->markTestSkipped('Privilège CREATE DATABASE absent — round-trip ignoré.');
        }

        $scratchPdo = new \PDO(
            'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT')
                . ';dbname=' . $this->scratchDb . ';charset=utf8mb4',
            getenv('DB_USER') ?: '', getenv('DB_PASS') ?: '',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        // Restauration du dump dans la base scratch (vide au départ).
        $restore = new \API\Services\BackupService($scratchPdo, $this->tmpBase);
        $this->assertTrue($restore->restoreDatabase($file), 'La restauration doit aboutir.');

        // Les données restaurées doivent correspondre à la source.
        $got = (int) $scratchPdo->query('SELECT COUNT(*) FROM etablissements')->fetchColumn();
        $this->assertSame($expected, $got, 'Le nombre d\'établissements restauré doit correspondre à la source.');
        $this->assertNotFalse(
            $scratchPdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = '{$this->scratchDb}' AND table_name = 'eleves'")->fetchColumn(),
            'La table eleves doit exister après restauration.'
        );
    }
}
