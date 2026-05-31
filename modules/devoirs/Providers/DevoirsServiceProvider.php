<?php
declare(strict_types=1);

namespace Modules\Devoirs\Providers;

use API\Core\ServiceProvider;

class DevoirsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('devoirs', function ($app) {
            return new \Modules\Devoirs\Services\DevoirService($app->make('db')->getConnection());
        });
    }

    public function boot(): void
    {
        $hooks = $this->app->make('hooks');
        $audit = new \API\Events\Listeners\AuditListener();

        $hooks->register(\Modules\Devoirs\Events\DevoirCreated::class, [$audit, 'handle']);
        $hooks->register(\Modules\Devoirs\Events\DevoirUpdated::class, [$audit, 'handle']);
        $hooks->register(\Modules\Devoirs\Events\DevoirDeleted::class, [$audit, 'handle']);
    }
}
