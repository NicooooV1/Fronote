<?php
declare(strict_types=1);

namespace API\Providers;

use API\Core\ServiceProvider;
use API\Events\Listeners\AuditListener;

/**
 * Enregistre les listeners sur les événements CORE uniquement.
 *
 * Les événements domaine-métier (NoteCreated, AbsenceCreated…) sont désormais
 * enregistrés dans le ServiceProvider de chaque module via HookManager.
 * Seuls les événements transversaux (UserCreated, UserPasswordChanged) restent ici.
 */
class EventServiceProvider extends ServiceProvider
{
    private const LISTEN = [
        \API\Events\UserCreated::class         => [AuditListener::class],
        \API\Events\UserPasswordChanged::class => [AuditListener::class],
    ];

    public function register(): void {}

    public function boot(): void
    {
        $hooks     = $this->app->make('hooks');
        $instances = [];

        foreach (self::LISTEN as $eventClass => $listenerClasses) {
            foreach ($listenerClasses as $listenerClass) {
                $instances[$listenerClass] ??= new $listenerClass();
                $hooks->register($eventClass, [$instances[$listenerClass], 'handle']);
            }
        }
    }
}
