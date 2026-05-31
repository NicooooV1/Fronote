<?php
declare(strict_types=1);

namespace Modules\Notifications\Providers;

use API\Core\ServiceProvider;

class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $pdo = fn($app) => $app->make('db')->getConnection();

        $this->app->singleton('sms',         fn($app) => new \API\Services\SmsService($pdo($app)));
        $this->app->singleton('email_queue', fn($app) => new \API\Services\EmailQueueService($pdo($app)));
        $this->app->singleton('webpush',     fn($app) => new \API\Services\WebPushService($pdo($app)));
        $this->app->singleton('email',       fn($app) => new \API\Services\EmailService($pdo($app)));
    }

    public function boot(): void {}
}
