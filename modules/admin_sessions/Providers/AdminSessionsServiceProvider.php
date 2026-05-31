<?php
declare(strict_types=1);

namespace Modules\AdminSessions\Providers;

use API\Core\ServiceProvider;

class AdminSessionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('sessions', fn($app) => new \Modules\AdminSessions\Services\SessionManagementService($app->make('db')->getConnection()));
    }

    public function boot(): void {}
}
