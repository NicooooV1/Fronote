<?php
declare(strict_types=1);

namespace API\Providers;

use API\Core\ServiceProvider;

/**
 * Enregistre les listeners sur les événements CORE uniquement.
 *
 * Les événements domaine-métier (NoteCreated, AbsenceCreated…) sont enregistrés
 * dans le ServiceProvider de chaque module via HookManager. Plus aucun événement
 * core à ce jour (les anciens UserCreated/UserPasswordChanged n'étaient jamais
 * dispatchés et ont été supprimés) — la map reste le point d'accroche.
 */
class EventServiceProvider extends ServiceProvider
{
    private const LISTEN = [];

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
