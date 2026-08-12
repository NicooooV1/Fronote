<?php
declare(strict_types=1);

namespace Modules\Messagerie\Providers;

use API\Core\ServiceProvider;

class MessagerieServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // L'événement MessageSent a été retiré (jamais dispatché) : la messagerie
        // notifie le WebSocket directement et journalise via son propre service.
        // Aucun hook à enregistrer ici.
    }
}
