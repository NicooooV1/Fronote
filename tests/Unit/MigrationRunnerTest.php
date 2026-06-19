<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use API\Services\MigrationRunner;

/**
 * Vérifie le système de migrations versionnées : application ordonnée + idempotente,
 * journal schema_migrations, et rollback (down) de la dernière fournée.
 */
final class MigrationRunnerTest extends TestCase
{
    private PDO $pdo;
    private string $base;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Extension pdo_sqlite non disponible.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->base = sys_get_temp_dir() . '/migtest_' . uniqid('', true);
        mkdir($this->base . '/database/migrations', 0777, true);
        file_put_contents(
            $this->base . '/database/migrations/0001_create_widget.php',
            "<?php\nreturn new class {\n"
            . "    public function up(\\PDO \$pdo): void { \$pdo->exec('CREATE TABLE widget (id INTEGER PRIMARY KEY, name TEXT)'); }\n"
            . "    public function down(\\PDO \$pdo): void { \$pdo->exec('DROP TABLE IF EXISTS widget'); }\n"
            . "};\n"
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->base . '/database/migrations/0001_create_widget.php');
        @rmdir($this->base . '/database/migrations');
        @rmdir($this->base . '/database');
        @rmdir($this->base);
    }

    private function tableExists(string $name): bool
    {
        $s = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?");
        $s->execute([$name]);
        return (bool) $s->fetchColumn();
    }

    public function testMigrateAppliesTracksAndIsIdempotent(): void
    {
        $r = new MigrationRunner($this->pdo, $this->base);

        $this->assertSame(['0001_create_widget'], $r->status()['pending']);

        $res = $r->migrate();
        $this->assertSame(['0001_create_widget'], $res['applied']);
        $this->assertSame([], $res['errors']);
        $this->assertTrue($this->tableExists('widget'), 'La migration doit créer la table.');
        $this->assertTrue($this->tableExists('schema_migrations'), 'Le journal doit exister.');

        // Idempotent : un second migrate() ne réapplique rien.
        $this->assertSame([], $r->migrate()['applied']);
        $this->assertSame([], $r->status()['pending']);
        $this->assertSame(['0001_create_widget'], $r->status()['applied']);
    }

    public function testRollbackRevertsLastBatch(): void
    {
        $r = new MigrationRunner($this->pdo, $this->base);
        $r->migrate();

        $rb = $r->rollback();
        $this->assertSame(['0001_create_widget'], $rb['reverted']);
        $this->assertFalse($this->tableExists('widget'), 'Le down() doit supprimer la table.');
        $this->assertSame(['0001_create_widget'], $r->status()['pending'], 'La migration redevient en attente.');
    }
}
