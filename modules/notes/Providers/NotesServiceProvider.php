<?php
declare(strict_types=1);

namespace Modules\Notes\Providers;

use API\Core\ServiceProvider;

class NotesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('notes', function ($app) {
            return new \Modules\Notes\Services\NoteService($app->make('db')->getConnection());
        });
    }

    public function boot(): void
    {
        $hooks = $this->app->make('hooks');
        $audit = new \API\Events\Listeners\AuditListener();
        $ws    = new \API\Events\Listeners\WebSocketListener();

        $hooks->register(\Modules\Notes\Events\NoteCreated::class, [$audit, 'handle']);
        $hooks->register(\Modules\Notes\Events\NoteCreated::class, [$ws,    'handle']);
        $hooks->register(\Modules\Notes\Events\NoteUpdated::class, [$audit, 'handle']);
        $hooks->register(\Modules\Notes\Events\NoteDeleted::class, [$audit, 'handle']);
    }
}
