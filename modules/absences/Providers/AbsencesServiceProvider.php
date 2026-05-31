<?php
declare(strict_types=1);

namespace Modules\Absences\Providers;

use API\Core\ServiceProvider;

class AbsencesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('absences', function ($app) {
            return new \Modules\Absences\Services\AbsenceService($app->make('db')->getConnection());
        });
    }

    public function boot(): void
    {
        $hooks  = $this->app->make('hooks');
        $audit  = new \API\Events\Listeners\AuditListener();
        $ws     = new \API\Events\Listeners\WebSocketListener();
        $notify = new \API\Events\Listeners\NotifyParentAbsenceListener();

        $hooks->register(\Modules\Absences\Events\AbsenceCreated::class,       [$audit,  'handle']);
        $hooks->register(\Modules\Absences\Events\AbsenceCreated::class,       [$ws,     'handle']);
        $hooks->register(\Modules\Absences\Events\AbsenceCreated::class,       [$notify, 'handle']);
        $hooks->register(\Modules\Absences\Events\AbsenceDeleted::class,       [$audit,  'handle']);
        $hooks->register(\Modules\Absences\Events\RetardCreated::class,        [$audit,  'handle']);
        $hooks->register(\Modules\Absences\Events\RetardDeleted::class,        [$audit,  'handle']);
        $hooks->register(\Modules\Absences\Events\JustificatifApproved::class, [$audit,  'handle']);
        $hooks->register(\Modules\Absences\Events\JustificatifRejected::class, [$audit,  'handle']);
    }
}
