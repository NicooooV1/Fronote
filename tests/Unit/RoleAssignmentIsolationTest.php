<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use ReflectionClass;
use API\Core\EstablishmentContext;
use API\Services\RoleManagementService;

/**
 * Banc d'isolation multi-tenant (harness authz, SQLite) : verrouille l'anti-IDOR
 * cross-établissement de l'attribution/révocation de rôle (finding #9). La cible d'une
 * attribution doit appartenir à l'établissement de l'acteur ; fail-closed sinon.
 */
final class RoleAssignmentIsolationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Extension pdo_sqlite requise.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['eleves', 'parents', 'professeurs', 'vie_scolaire', 'administrateurs'] as $t) {
            $this->pdo->exec("CREATE TABLE `$t` (id INTEGER PRIMARY KEY, etablissement_id INTEGER NOT NULL)");
        }
        // Établissement 1 : prof #10, élève #30. Établissement 2 : prof #20, élève #40.
        $this->pdo->exec("INSERT INTO professeurs (id, etablissement_id) VALUES (10, 1), (20, 2)");
        $this->pdo->exec("INSERT INTO eleves (id, etablissement_id) VALUES (30, 1), (40, 2)");

        EstablishmentContext::reset();
        EstablishmentContext::set(1); // l'acteur agit dans l'établissement 1
    }

    protected function tearDown(): void
    {
        EstablishmentContext::reset();
    }

    private function inActorEtab(string $type, int $id): bool
    {
        $rc  = new ReflectionClass(RoleManagementService::class);
        $svc = new RoleManagementService($this->pdo);
        $m   = $rc->getMethod('targetInActorEstablishment');
        $m->setAccessible(true);
        return (bool) $m->invoke($svc, $type, $id);
    }

    public function testTargetInSameEstablishmentAccepted(): void
    {
        $this->assertTrue($this->inActorEtab('professeur', 10));
        $this->assertTrue($this->inActorEtab('eleve', 30));
    }

    public function testTargetInOtherEstablishmentRejected(): void
    {
        $this->assertFalse($this->inActorEtab('professeur', 20), 'IDOR cross-établissement doit être refusé');
        $this->assertFalse($this->inActorEtab('eleve', 40), 'IDOR cross-établissement doit être refusé');
    }

    public function testUnknownTypeAndInvalidIdFailClosed(): void
    {
        $this->assertFalse($this->inActorEtab('inconnu', 10), 'type inconnu → fail-closed');
        $this->assertFalse($this->inActorEtab('professeur', 0), 'id invalide → fail-closed');
        $this->assertFalse($this->inActorEtab('professeur', 999), 'cible inexistante → refus');
    }
}
