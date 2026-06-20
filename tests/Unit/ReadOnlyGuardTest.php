<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use API\Core\ReadOnlyGuard;

/**
 * Garde « archivé = lecture seule » (refonte 3-mondes) : logique de décision pure.
 */
final class ReadOnlyGuardTest extends TestCase
{
    public function testBlocksWritesOnArchivedEstablishment(): void
    {
        $this->assertTrue(ReadOnlyGuard::decide('POST', 'archived', true, false, '/modules/notes/save.php'));
        $this->assertTrue(ReadOnlyGuard::decide('DELETE', 'archived', true, false, '/modules/x/del.php'));
    }

    public function testBlocksOtherNonWritableStatuses(): void
    {
        foreach (['suspended', 'deleted', 'purged'] as $st) {
            $this->assertTrue(ReadOnlyGuard::decide('POST', $st, true, false, '/x/y.php'), "statut {$st} bloqué");
        }
    }

    public function testAllowsReadsAndWritableStatuses(): void
    {
        $this->assertFalse(ReadOnlyGuard::decide('GET', 'archived', true, false, '/modules/notes/notes.php'), 'lecture autorisée');
        $this->assertFalse(ReadOnlyGuard::decide('HEAD', 'archived', true, false, '/x.php'));
        $this->assertFalse(ReadOnlyGuard::decide('POST', 'active', true, false, '/modules/notes/save.php'), 'établissement actif inscriptible');
        $this->assertFalse(ReadOnlyGuard::decide('POST', 'onboarding', true, false, '/x.php'), 'onboarding inscriptible');
    }

    public function testPlatformAndAnonymousAreExempt(): void
    {
        $this->assertFalse(ReadOnlyGuard::decide('POST', 'archived', true, true, '/platform/establishments.php'), 'monde plateforme exempté');
        $this->assertFalse(ReadOnlyGuard::decide('POST', 'archived', false, false, '/x.php'), 'pas de session établissement');
    }

    public function testAuthPathsAlwaysAllowed(): void
    {
        $this->assertFalse(ReadOnlyGuard::decide('POST', 'archived', true, false, '/e/test/login'), 'login autorisé');
        $this->assertFalse(ReadOnlyGuard::decide('POST', 'archived', true, false, '/login/index.php'));
        $this->assertFalse(ReadOnlyGuard::decide('GET', 'archived', true, false, '/tenant/logout.php'));
        $this->assertFalse(ReadOnlyGuard::decide('POST', 'archived', true, false, '/tenant/logout.php'), 'logout autorisé');
    }
}
