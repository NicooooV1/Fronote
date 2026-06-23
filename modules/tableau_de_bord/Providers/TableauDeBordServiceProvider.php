<?php
declare(strict_types=1);

namespace Modules\TableauDeBord\Providers;

use API\Core\ServiceProvider;

class TableauDeBordServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $pdo = fn($app) => $app->make('db')->getConnection();
        $this->app->singleton('admin_dashboard', fn($app) => new \Modules\TableauDeBord\Services\AdminDashboardService($pdo($app)));
        $this->app->singleton('classes',         fn($app) => new \Modules\TableauDeBord\Services\ClasseService($pdo($app)));
    }

    public function boot(): void
    {
        $hooks = $this->app->make('hooks');
        $audit = new \API\Events\Listeners\AuditListener();

        foreach ([
            \Modules\TableauDeBord\Events\ClasseCreated::class,
            \Modules\TableauDeBord\Events\ClasseUpdated::class,
            \Modules\TableauDeBord\Events\ClasseDeleted::class,
        ] as $event) {
            $hooks->register($event, [$audit, 'handle']);
        }
    }
}
