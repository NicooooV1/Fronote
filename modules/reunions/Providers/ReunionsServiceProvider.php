<?php
declare(strict_types=1);

namespace Modules\Reunions\Providers;

use API\Core\ServiceProvider;

class ReunionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('visio', fn($app) => new \API\Services\VideoConferenceService());
    }

    public function boot(): void {}
}
