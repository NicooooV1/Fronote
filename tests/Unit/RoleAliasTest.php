<?php
declare(strict_types=1);

namespace Tests\Unit;

use API\Security\RoleCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Verrouille la résolution canonique des rôles (socle unifié) : une clé aliasée (issue du monde
 * tenant ou d'une variante de nommage) obtient EXACTEMENT les grants de son rôle canonique — fin
 * des « comptes de seconde classe » — et un rôle inconnu reste fail-closed.
 */
final class RoleAliasTest extends TestCase
{
    public function testCanonicalResolvesAliases(): void
    {
        $this->assertSame('direction', RoleCatalog::canonical('directeur'));
        $this->assertSame('inspecteur_academie', RoleCatalog::canonical('inspecteur'));
        $this->assertSame('responsable_edt', RoleCatalog::canonical('responsable_emploi_du_temps'));
    }

    public function testCanonicalIsIdentityForKnownRole(): void
    {
        $this->assertSame('direction', RoleCatalog::canonical('direction'));
        $this->assertSame('professeur', RoleCatalog::canonical('professeur'));
    }

    public function testAliasedRoleGetsCanonicalGrants(): void
    {
        $canonical = RoleCatalog::grantsFor('direction');
        $this->assertNotEmpty($canonical, 'le rôle canonique direction doit avoir des grants');
        $this->assertSame($canonical, RoleCatalog::grantsFor('directeur'));
        $this->assertSame(RoleCatalog::grantsFor('responsable_edt'), RoleCatalog::grantsFor('responsable_emploi_du_temps'));
    }

    public function testUnknownRoleFailsClosed(): void
    {
        $this->assertSame([], RoleCatalog::grantsFor('role_inexistant_xyz'));
    }
}
