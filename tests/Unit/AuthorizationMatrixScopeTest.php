<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use API\Security\Authorization;
use API\Core\EstablishmentContext;

/**
 * Harness d'autorisation (SQLite) exerçant Authorization::can() via la matrice éditable.
 *
 * GOUVERNANCE PLATEFORME (2026-08) : la matrice rôle→permission est désormais une table
 * GLOBALE `rbac_grants` (éditée au niveau plateforme), et NON plus une matrice par établissement.
 * Le « quoi » (rôle→permission) est central à l'instance ; le « qui » (assignation user_roles +
 * portée) reste régionalisé par la couche scopeAllows(). Ce test verrouille donc DEUX choses :
 *   (a) matrice GLOBALE : un grant/deny rbac_grants s'applique à tous les établissements ;
 *   (b) finding #23 : scopeAllows() régionalise toujours la PORTÉE (un rôle scopé établissement A
 *       n'agit pas dans une requête résolue sur l'établissement B).
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
        // Matrice GLOBALE (pas d'etablissement_id) — miroir de rbac_grants en production.
        $this->pdo->exec("CREATE TABLE rbac_grants (
            role TEXT NOT NULL, permission TEXT NOT NULL, granted INTEGER NOT NULL DEFAULT 1,
            PRIMARY KEY (role, permission)
        )");
        $this->pdo->exec("CREATE TABLE user_roles (
            id INTEGER PRIMARY KEY, user_type TEXT, user_id INTEGER, role_key TEXT,
            etablissement_id INTEGER, scope_type TEXT, scope_json TEXT,
            valid_from TEXT, valid_until TEXT
        )");
        // Le rôle professeur reçoit une permission SYNTHÉTIQUE (absente du catalogue) via la
        // matrice GLOBALE — elle s'applique donc à tout l'instance.
        $this->pdo->exec("INSERT INTO rbac_grants (role, permission, granted)
                          VALUES ('professeur', 'zzz.harness_only', 1)");
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

    // ── Matrice GLOBALE (gouvernance plateforme) ──

    public function testGlobalGrantApplies(): void
    {
        // Grant global rbac_grants → autorisé (établissement de l'utilisateur indifférent).
        $this->assertTrue($this->authFor(1)->can('zzz.harness_only'));
    }

    public function testGlobalGrantAppliesAcrossEstablishments(): void
    {
        // La matrice n'est PLUS régionalisée : le même grant s'applique à un professeur d'un
        // AUTRE établissement (le cloisonnement des DONNÉES reste assuré par scopeAllows/les repos).
        $this->assertTrue($this->authFor(2)->can('zzz.harness_only'));
    }

    public function testGlobalDenyWins(): void
    {
        // Un REFUS explicite (granted=0) l'emporte sur le catalogue, globalement.
        $this->pdo->exec("INSERT INTO rbac_grants (role, permission, granted)
                          VALUES ('professeur', 'zzz.harness_two', 0)");
        $this->assertFalse($this->authFor(1)->can('zzz.harness_two'));
        $this->assertFalse($this->authFor(2)->can('zzz.harness_two'));
    }

    // ── Finding #23 : scopeAllows() régionalise toujours la PORTÉE de la requête ──

    public function testScopeMatchesResolvedRequestEstablishment(): void
    {
        // Requête résolue sur l'établissement 1, rôle scopé établissement 1 → autorisé.
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
        // Contexte d'établissement NON posé → comportement permissif conservé (le cloisonnement
        // reste appliqué par la couche données).
        EstablishmentContext::reset();
        $this->assertTrue($this->authFor(1)->can('zzz.harness_only'));
    }
}
