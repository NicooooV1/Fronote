<?php
declare(strict_types=1);

namespace Modules\EmploiDuTemps\Providers;

use API\Core\ServiceProvider;

class EmploiDuTempsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $pdo = fn($app) => $app->make('db')->getConnection();
        $this->app->singleton('matieres', fn($app) => new \Modules\EmploiDuTemps\Services\MatiereService($pdo($app)));
        $this->app->singleton('periodes', fn($app) => new \Modules\EmploiDuTemps\Services\PeriodeService($pdo($app)));
    }

    public function boot(): void
    {
        $hooks = $this->app->make('hooks');
        $audit = new \API\Events\Listeners\AuditListener();

        foreach ([
            \Modules\EmploiDuTemps\Events\MatiereCreated::class,
            \Modules\EmploiDuTemps\Events\MatiereUpdated::class,
            \Modules\EmploiDuTemps\Events\MatiereDeleted::class,
            \Modules\EmploiDuTemps\Events\PeriodeCreated::class,
            \Modules\EmploiDuTemps\Events\PeriodeUpdated::class,
            \Modules\EmploiDuTemps\Events\PeriodeDeleted::class,
        ] as $event) {
            $hooks->register($event, [$audit, 'handle']);
        }
    }
}
