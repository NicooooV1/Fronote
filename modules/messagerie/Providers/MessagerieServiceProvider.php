<?php
declare(strict_types=1);

namespace Modules\Messagerie\Providers;

use API\Core\ServiceProvider;

class MessagerieServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $hooks = $this->app->make('hooks');
        $audit = new \API\Events\Listeners\AuditListener();
        $ws    = new \API\Events\Listeners\WebSocketListener();

        $hooks->register(\Modules\Messagerie\Events\MessageSent::class, [$audit, 'handle']);
        $hooks->register(\Modules\Messagerie\Events\MessageSent::class, [$ws,    'handle']);
    }
}
