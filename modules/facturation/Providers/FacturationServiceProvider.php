<?php
declare(strict_types=1);

namespace Modules\Facturation\Providers;

use API\Core\ServiceProvider;

class FacturationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('payment', fn($app) => new \API\Services\PaymentService($app->make('db')->getConnection()));
    }

    public function boot(): void {}
}
