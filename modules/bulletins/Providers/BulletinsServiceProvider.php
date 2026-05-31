<?php
declare(strict_types=1);

namespace Modules\Bulletins\Providers;

use API\Core\ServiceProvider;

class BulletinsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('bulletin_pdf', function ($app) {
            return new \API\Services\BulletinPdfService(
                $app->make('db')->getConnection(),
                defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4)
            );
        });
    }

    public function boot(): void {}
}
