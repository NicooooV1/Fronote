<?php
declare(strict_types=1);

namespace Tests\Unit;

use API\Security\WildcardGrants;
use PHPUnit\Framework\TestCase;

/**
 * Verrouille la résolution UNIQUE des wildcards de permissions (thème 2). Ces cas doivent rester
 * identiques aux 4 implémentations d'origine (RoleCatalog/Authorization/Tenant/Platform).
 */
final class WildcardGrantsTest extends TestCase
{
    public function testStarGrantsEverything(): void
    {
        $this->assertTrue(WildcardGrants::granted(['*'], 'notes.view'));
        $this->assertTrue(WildcardGrants::granted(['*'], 'platform.support.tickets.view'));
    }

    public function testExactMatch(): void
    {
        $this->assertTrue(WildcardGrants::granted(['notes.view', 'absences.view'], 'notes.view'));
        $this->assertFalse(WildcardGrants::granted(['notes.view'], 'notes.edit'));
    }

    public function testDomainWildcardTwoLevel(): void
    {
        $this->assertTrue(WildcardGrants::granted(['notes.*'], 'notes.view'));
        $this->assertTrue(WildcardGrants::granted(['notes.*'], 'notes.edit'));
        $this->assertFalse(WildcardGrants::granted(['notes.*'], 'absences.view'));
    }

    public function testMultiLevelPrefixWildcard(): void
    {
        // 'tenant.users.*' n'accorde que sous 'tenant.users.'
        $this->assertTrue(WildcardGrants::granted(['tenant.users.*'], 'tenant.users.edit'));
        $this->assertFalse(WildcardGrants::granted(['tenant.users.*'], 'tenant.roles.edit'));
        // 'tenant.*' accorde tout le sous-arbre 'tenant.'
        $this->assertTrue(WildcardGrants::granted(['tenant.*'], 'tenant.users.edit'));
    }

    public function testUnknownRoleFailsClosed(): void
    {
        $this->assertFalse(WildcardGrants::granted([], 'notes.view'));
    }

    public function testExpandStar(): void
    {
        $universe = ['notes.view', 'notes.edit', 'absences.view'];
        $this->assertEqualsCanonicalizing($universe, WildcardGrants::expand(['*'], $universe));
    }

    public function testExpandDomainWildcard(): void
    {
        $universe = ['notes.view', 'notes.edit', 'absences.view'];
        $this->assertEqualsCanonicalizing(['notes.view', 'notes.edit'], WildcardGrants::expand(['notes.*'], $universe));
    }

    public function testExpandFiltersUnknownExactGrants(): void
    {
        $universe = ['notes.view', 'absences.view'];
        // Un grant exact hors univers n'est pas retourné (sécurité : pas de clé fantôme).
        $this->assertEqualsCanonicalizing(['notes.view'], WildcardGrants::expand(['notes.view', 'ghost.perm'], $universe));
    }
}
