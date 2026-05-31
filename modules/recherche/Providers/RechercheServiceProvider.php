<?php
declare(strict_types=1);

namespace Modules\Recherche\Providers;

use API\Core\ServiceProvider;

class RechercheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('global_search', fn($app) => new \API\Services\GlobalSearchService($app->make('db')->getConnection()));
    }

    public function boot(): void {}
}
