<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use API\Security\RoleCatalog;

/**
 * Helpers de résolution des permissions du catalogue (profil, création, modules).
 */
final class RoleCatalogHelpersTest extends TestCase
{
    public function testSuperAdminHasAllPermissions(): void
    {
        $all = array_keys(RoleCatalog::permissions());
        $this->assertSame(
            count($all),
            count(RoleCatalog::permissionsFor('super_admin')),
            'super_admin doit détenir TOUTES les permissions du catalogue.'
        );
    }

    public function testWildcardDomainExpands(): void
    {
        // Un rôle quelconque : ses permissions effectives doivent inclure, pour chaque
        // grant 'domaine.*', au moins une permission de ce domaine.
        $perms = RoleCatalog::permissionsFor('professeur');
        $this->assertNotEmpty($perms);
        foreach ($perms as $p) {
            $this->assertArrayHasKey($p, RoleCatalog::permissions(), "Permission inconnue développée : {$p}");
        }
    }

    public function testEffectivePermissionsIsUnion(): void
    {
        $a = RoleCatalog::permissionsFor('eleve');
        $b = RoleCatalog::permissionsFor('parent');
        $union = RoleCatalog::effectivePermissions(['eleve', 'parent']);
        $this->assertEqualsCanonicalizing(array_values(array_unique(array_merge($a, $b))), $union);
    }

    public function testRolesByTierCoversAllRoles(): void
    {
        $byTier = RoleCatalog::rolesByTier();
        $flat = [];
        foreach ($byTier as $roles) {
            foreach ($roles as $k => $_) $flat[$k] = true;
        }
        $this->assertSame(count(RoleCatalog::roles()), count($flat), 'Chaque rôle doit apparaître dans exactement un tier.');
    }

    public function testVieScolaireCannotReceiveAdminRoles(): void
    {
        $vs = RoleCatalog::rolesForAccount('vie_scolaire');
        $this->assertArrayHasKey('cpe', $vs);
        $this->assertArrayHasKey('infirmerie', $vs);
        $this->assertArrayNotHasKey('direction', $vs, 'La vie scolaire ne doit pas recevoir de rôle de direction.');
        $this->assertArrayNotHasKey('administration', $vs, 'La vie scolaire ne doit pas recevoir de rôle d\'administration.');
    }

    public function testEleveOnlyGetsEleveFamilleTier(): void
    {
        foreach (RoleCatalog::rolesForAccount('eleve') as $key => $meta) {
            $this->assertSame('eleve_famille', $meta['tier'] ?? null, "Le rôle {$key} ne devrait pas être attribuable à un élève.");
        }
    }
}
