<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use API\Security\Authorization;
use API\Core\EstablishmentContext;

/**
 * Harness d'autorisation (SQLite) exerçant Authorization::can() via la matrice éditable
 * rbac_permissions. Verrouille la RÉGIONALISATION de la matrice (finding #2) : une surcharge de
 * permission posée pour l'établissement A ne doit PAS s'appliquer à un utilisateur de
 * l'établissement B (avant le correctif, la matrice était globale à l'instance).
 */
final class AuthorizationMatrixScopeTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Extension pdo_sqlite requise.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Schéma post-régionalisation : rbac_permissions porte etablissement_id.
        $this->pdo->exec("CREATE TABLE rbac_permissions (
            id INTEGER PRIMARY KEY, role TEXT NOT NULL, permission TEXT NOT NULL,
            etablissement_id INTEGER NOT NULL DEFAULT 1, granted INTEGER NOT NULL DEFAULT 1
        )");
        $this->pdo->exec("CREATE TABLE user_roles (
            id INTEGER PRIMARY KEY, user_type TEXT, user_id INTEGER, role_key TEXT,
            etablissement_id INTEGER, scope_type TEXT, scope_json TEXT,
            valid_from TEXT, valid_until TEXT
        )");
        // Surcharge : le rôle professeur reçoit une permission SYNTHÉTIQUE (absente du catalogue),
        // UNIQUEMENT pour l'établissement 1.
        $this->pdo->exec("INSERT INTO rbac_permissions (role, permission, etablissement_id, granted)
                          VALUES ('professeur', 'zzz.harness_only', 1, 1)");
        EstablishmentContext::reset(); // état global propre pour ce test
    }

    protected function tearDown(): void
    {
        EstablishmentContext::reset();
    }

    private function authFor(int $etab): Authorization
    {
        return new Authorization($this->pdo, [
            'id' => 1, 'type' => 'professeur', 'etablissement_id' => $etab,
        ]);
    }

    public function testMatrixGrantAppliesToItsOwnEstablishment(): void
    {
        // Établissement 1 possède la surcharge granted=1 → autorisé.
        $this->assertTrue($this->authFor(1)->can('zzz.harness_only'));
    }

    public function testMatrixGrantDoesNotLeakToOtherEstablishment(): void
    {
        // Établissement 2 n'a PAS la surcharge → retombe sur le catalogue (qui ne l'accorde pas) → refusé.
        // C'est la garantie de régionalisation : la matrice d'un établissement ne fuit pas ailleurs.
        $this->assertFalse($this->authFor(2)->can('zzz.harness_only'));
    }

    public function testMatrixDenyIsScopedToItsEstablishment(): void
    {
        // Surcharge de REFUS (granted=0) pour l'établissement 1 sur une permission synthétique
        // « accordée » par une autre surcharge globale-du-même-établissement.
        $this->pdo->exec("INSERT INTO rbac_permissions (role, permission, etablissement_id, granted)
                          VALUES ('professeur', 'zzz.harness_two', 1, 1)");
        $this->pdo->exec("INSERT INTO rbac_permissions (role, permission, etablissement_id, granted)
                          VALUES ('professeur', 'zzz.harness_two', 2, 0)");
        $this->assertTrue($this->authFor(1)->can('zzz.harness_two'), 'établissement 1 : accordé');
        $this->assertFalse($this->authFor(2)->can('zzz.harness_two'), 'établissement 2 : refusé (deny scopé)');
    }

    // ── Finding #23 : scopeAllows déduit le scope de requête au lieu de fail-open ──

    public function testScopeMatchesResolvedRequestEstablishment(): void
    {
        // Requête résolue sur l'établissement 1, rôle scopé établissement 1 → autorisé (cas légitime).
        EstablishmentContext::set(1);
        $this->assertTrue($this->authFor(1)->can('zzz.harness_only'));
    }

    public function testScopeDeniesWhenRequestEstablishmentDiffersFromRole(): void
    {
        // Scope de requête résolu sur l'établissement 2 mais rôle de l'établissement 1 (sans cible
        // explicite dans le ctx) → REFUS : un rôle scopé établissement A n'agit pas dans le scope B.
        EstablishmentContext::set(2);
        $this->assertFalse($this->authFor(1)->can('zzz.harness_only'));
    }

    public function testScopeStaysPermissiveWhenContextUnresolved(): void
    {
        // Contexte d'établissement NON posé → comportement permissif conservé (pas de régression ;
        // le cloisonnement reste appliqué par la couche données).
        EstablishmentContext::reset();
        $this->assertTrue($this->authFor(1)->can('zzz.harness_only'));
    }
}
