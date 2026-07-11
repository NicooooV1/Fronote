<?php
declare(strict_types=1);
namespace API\Providers;

use API\Core\ServiceProvider;

/**
 * Service Provider pour la configuration
 */
class ConfigServiceProvider extends ServiceProvider
{
    protected $requiredEnvVars = [
        'DB_HOST',
        'DB_NAME',
        'DB_USER',
        'DB_PASS',
        'APP_BASE_PATH'
    ];

    public function register()
    {
        $this->app->singleton('config', function($app) {
            return [
                'database' => [
                    'host' => env('DB_HOST'),
                    'database' => env('DB_NAME'),
                    'username' => env('DB_USER'),
                    'password' => env('DB_PASS'),
                    'charset' => env('DB_CHARSET', 'utf8mb4'),
                    'port' => env('DB_PORT', 3306)
                ],
                'app' => [
                    'base_path' => env('APP_BASE_PATH'),
                    'debug' => env('APP_DEBUG', false),
                    'env' => env('APP_ENV', 'production'),
                    'url' => env('APP_URL', 'http://localhost')
                ],
                'auth' => [
                    'login_url' => env('AUTH_LOGIN_URL', '/login/index.php')
                ],
                'session' => [
                    'name' => env('SESSION_NAME', 'pronote_session'),
                    'lifetime' => env('SESSION_LIFETIME', 120)
                ],
                'security' => [
                    'csrf_lifetime' => env('CSRF_LIFETIME', 3600),
                    'csrf_max_tokens' => env('CSRF_MAX_TOKENS', 30),
                    'rate_limit_attempts' => env('RATE_LIMIT_ATTEMPTS', 5),
                    'rate_limit_decay' => env('RATE_LIMIT_DECAY', 1),
                    'session_lifetime' => env('SESSION_LIFETIME', 7200),
                    // Politique de mot de passe (consommée par le binding password_policy)
                    'password_min_length' => env('PASSWORD_MIN_LENGTH', 10),
                    'password_require_upper' => env('PASSWORD_REQUIRE_UPPER', true),
                    'password_require_lower' => env('PASSWORD_REQUIRE_LOWER', true),
                    'password_require_digit' => env('PASSWORD_REQUIRE_DIGIT', true),
                    'password_require_special' => env('PASSWORD_REQUIRE_SPECIAL', true),
                    'password_max_repeating' => env('PASSWORD_MAX_REPEATING', 3)
                ]
            ];
        });
    }

    public function boot()
    {
        // Valider que toutes les variables requises sont définies
        $envLoader = $this->app->make('env.loader');

        try {
            $envLoader->validate($this->requiredEnvVars);
        } catch (\RuntimeException $e) {
            // Si EnvLoader::load() avait déjà échoué (.env absent/illisible),
            // surfacer la cause racine au lieu de l'erreur générique "vars manquantes".
            $loadError = null;
            try { $loadError = $this->app->make('env.load_error'); } catch (\Throwable $e) { error_log('[ConfigServiceProvider.php] ' . $e->getMessage()); }

            if ($loadError instanceof \Throwable) {
                throw new \RuntimeException(
                    "Configuration incomplète : " . $loadError->getMessage()
                    . " — Lancez install.php ou copiez .env.example vers .env et remplissez "
                    . "DB_HOST, DB_NAME, DB_USER, DB_PASS, APP_BASE_PATH.",
                    0,
                    $loadError
                );
            }

            throw new \RuntimeException(
                "Configuration incomplète : " . $e->getMessage()
                . " — Vérifiez que ces clés sont définies (et non vides) dans le .env."
            );
        }
    }
}
