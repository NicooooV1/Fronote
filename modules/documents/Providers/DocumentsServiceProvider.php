<?php
declare(strict_types=1);

namespace Modules\Documents\Providers;

use API\Core\ServiceProvider;

class DocumentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('signature', fn($app) => new \API\Services\SignatureService($app->make('db')->getConnection()));
    }

    public function boot(): void {}
}
