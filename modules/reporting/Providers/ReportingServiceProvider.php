<?php
declare(strict_types=1);

namespace Modules\Reporting\Providers;

use API\Core\ServiceProvider;

class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $pdo = fn($app) => $app->make('db')->getConnection();

        $this->app->singleton('analytics', fn($app) => new \API\Services\AnalyticsService($pdo($app)));
        $this->app->singleton('cross_analytics', fn($app) => new \API\Services\CrossModuleAnalyticsService($pdo($app)));
        $this->app->singleton('metrics', fn($app) => new \API\Services\MetricsService($pdo($app)));
    }

    public function boot(): void {}
}
