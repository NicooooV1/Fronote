<?php
declare(strict_types=1);

namespace Modules\Agenda\Providers;

use API\Core\ServiceProvider;

class AgendaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('evenements', function ($app) {
            return new \Modules\Agenda\Services\EvenementService($app->make('db')->getConnection());
        });
    }

    public function boot(): void
    {
        $hooks = $this->app->make('hooks');
        $audit = new \API\Events\Listeners\AuditListener();
        $ws    = new \API\Events\Listeners\WebSocketListener();

        $hooks->register(\Modules\Agenda\Events\EvenementCreated::class, [$audit, 'handle']);
        $hooks->register(\Modules\Agenda\Events\EvenementCreated::class, [$ws,    'handle']);
        $hooks->register(\Modules\Agenda\Events\EvenementUpdated::class, [$audit, 'handle']);
        $hooks->register(\Modules\Agenda\Events\EvenementDeleted::class, [$audit, 'handle']);
    }
}
