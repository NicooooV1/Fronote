<?php
declare(strict_types=1);
/**
 * Fonctions helper globales
 */

// UI Component Library
require_once __DIR__ . '/../UI/Components.php';

if (!function_exists('e')) {
    /**
     * Échappe une valeur pour affichage HTML sécurisé
     */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('app')) {
    /**
     * Retourne l'instance de l'application (via singleton, sans global)
     */
    function app($abstract = null)
    {
        $app = \API\Core\Application::getInstance();
        
        if (is_null($abstract)) {
            return $app;
        }
        
        return $app->make($abstract);
    }
}

if (!function_exists('config')) {
    /**
     * Récupère une valeur de configuration
     */
    function config($key, $default = null)
    {
        try {
            $config = app('config');
            
            // Support de la notation pointée: 'database.host'
            $keys = explode('.', $key);
            $value = $config;
            
            foreach ($keys as $k) {
                if (!isset($value[$k])) {
                    return $default;
                }
                $value = $value[$k];
            }
            
            return $value;
        } catch (\Exception $e) {
            return $default;
        }
    }
}

if (!function_exists('env')) {
    /**
     * Récupère une variable d'environnement
     */
    function env($key, $default = null)
    {
        $value = getenv($key);
        
        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        }
        
        // Convertir les booléens
        if (is_string($value)) {
            switch (strtolower($value)) {
                case 'true':
                case '(true)':
                    return true;
                case 'false':
                case '(false)':
                    return false;
                case 'null':
                case '(null)':
                    return null;
                case 'empty':
                case '(empty)':
                    return '';
            }
        }
        
        return $value;
    }
}
if (!function_exists('csp_nonce')) {
    /**
     * Nonce CSP de la requête courante (pour les attributs nonce="" des <script>/<style>).
     * Retourne '' si le bootstrap n'a pas encore défini CSP_NONCE.
     */
    function csp_nonce(): string
    {
        return defined('CSP_NONCE') ? CSP_NONCE : '';
    }
}

if (!function_exists('request_wants_json')) {
    /**
     * L'appelant attend-il une réponse JSON plutôt qu'une page HTML ? Unifie les ~9
     * implémentations locales : requête XHR, Content-Type JSON, ou Accept demandant
     * explicitement du JSON sans HTML. Défaut sûr = HTML (page).
     */
    function request_wants_json(): bool
    {
        if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
            return true;
        }
        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
            return true;
        }
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return stripos($accept, 'application/json') !== false && stripos($accept, 'text/html') === false;
    }
}

if (!function_exists('app_is_debug')) {
    /** Mode debug applicatif (config('app.debug') si dispo, sinon APP_DEBUG / APP_ENV dev). */
    function app_is_debug(): bool
    {
        try {
            $d = config('app.debug', null);
            if ($d !== null) {
                return (bool) $d;
            }
        } catch (\Throwable $e) { /* config indisponible tôt dans le bootstrap */ }
        return in_array(strtolower((string) (getenv('APP_DEBUG') ?: '')), ['1', 'true', 'on'], true)
            || in_array(strtolower((string) (getenv('APP_ENV') ?: '')), ['dev', 'development', 'local'], true);
    }
}

if (!function_exists('json_error')) {
    /**
     * Réponse d'erreur JSON normalisée {ok:false, error, code}. Le détail n'est exposé qu'en
     * debug (message générique en prod pour les 5xx) — contrat unique pour endpoints & gardes.
     */
    function json_error(int $status, string $message, array $extra = []): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        $public = ($status >= 500 && !app_is_debug()) ? 'Erreur interne.' : $message;
        echo json_encode(array_merge(['ok' => false, 'error' => $public, 'code' => $status], $extra));
    }
}

if (!function_exists('json_ok')) {
    /** Réponse JSON de succès normalisée {ok:true, ...data}. */
    function json_ok(array $data = [], int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(array_merge(['ok' => true], $data));
    }
}

if (!function_exists('deny_access')) {
    /**
     * Refus d'accès unifié : 401/403 en JSON si l'appelant attend du JSON, sinon redirection HTML
     * de connexion. Remplace les die()/exit() qui renvoyaient un HTTP 200 texte-brut pour un refus.
     */
    function deny_access(bool $needAuth = false, string $message = ''): void
    {
        $status = $needAuth ? 401 : 403;
        $msg = $message !== '' ? $message : ($needAuth ? 'Authentification requise.' : 'Accès refusé.');
        if (request_wants_json()) {
            json_error($status, $msg);
            exit;
        }
        if (!headers_sent()) {
            http_response_code($status);
            if ($needAuth) {
                $base = defined('BASE_URL') ? BASE_URL : '';
                header('Location: ' . $base . '/login/index.php');
                exit;
            }
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $msg;
        exit;
    }
}

if (!function_exists('app_log_error')) {
    /**
     * Journalisation d'erreur applicative via le Logger structuré (singleton 'log') si disponible,
     * sinon repli error_log(). Point d'entrée à préférer aux error_log() bruts dispersés.
     */
    function app_log_error(string $message, array $context = []): void
    {
        try {
            if (function_exists('app')) {
                $log = app('log');
                if (is_object($log) && method_exists($log, 'error')) {
                    $log->error($message, $context);
                    return;
                }
            }
        } catch (\Throwable $e) { /* repli ci-dessous */ }
        error_log('[Fronote] ' . $message . ($context ? ' ' . json_encode($context) : ''));
    }
}
