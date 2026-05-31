<?php
declare(strict_types=1);

namespace Modules\Appel\Providers;

use API\Core\ServiceProvider;

class AppelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('qr_presence', fn($app) => new \API\Services\QrPresenceService($app->make('db')->getConnection()));
    }

    public function boot(): void {}
}
