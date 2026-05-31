<?php
namespace API\Providers;

use API\Core\ServiceProvider;

/**
 * @deprecated Les services scolaires sont désormais enregistrés par le ServiceProvider
 * de chaque module via ModuleSDK::bootActiveModuleProviders(). Ce fichier est conservé
 * comme shim de compatibilité pour la durée de la migration (Phase 1-2 CDC refactoring).
 *
 * À supprimer une fois que tous les modules disposent de leur Providers/*.php.
 */
class ScolaireServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Intentionnellement vide — chaque module enregistre ses services
        // dans son propre ServiceProvider (modules/{key}/Providers/).
    }
}
