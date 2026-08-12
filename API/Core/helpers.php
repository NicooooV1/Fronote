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

if (!function_exists('asset_url')) {
    /**
     * URL versionnée (?v=mtime) d'un asset LOCAL, ancrée à la racine de l'app.
     * $path est relatif à la racine du dépôt (ex. 'assets/lib/chartjs/chart.umd.min.js').
     * Respecte BASE_URL (déploiement en sous-chemin) ; le ?v=mtime assure le cache-busting
     * malgré l'Expires 1-an du .htaccess. Point unique de versionnage réutilisable hors
     * du closure local de shared_header.php.
     */
    function asset_url(string $path): string
    {
        $path = ltrim($path, '/');
        $base = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : '';
        $abs = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $v = @filemtime($abs) ?: null;
        return $base . '/' . $path . ($v ? '?v=' . $v : '');
    }
}

if (!function_exists('asset_bust')) {
    /**
     * Ajoute ?v=<mtime> à une URL d'asset LOCAL déjà construite (avec son rootPrefix), pour le
     * cache-busting du JS des modules chargé via $extraJs / <script src>. Les URL externes
     * (http(s)://, //) ou déjà versionnées sont renvoyées inchangées. Le fichier est localisé
     * sur le disque à partir de la 1re occurrence d'un dossier d'asset connu (assets|modules),
     * quel que soit le préfixe de l'URL.
     */
    function asset_bust(string $href): string
    {
        if ($href === '' || preg_match('#^(?:[a-z]+:)?//#i', $href)) {
            return $href;                       // URL externe : inchangée
        }
        if (strpos($href, 'v=') !== false && preg_match('/[?&]v=/', $href)) {
            return $href;                       // déjà versionnée
        }
        $urlPath = parse_url($href, PHP_URL_PATH);
        if (!is_string($urlPath) || !preg_match('#(?:assets|modules)/.+$#', $urlPath, $m)) {
            return $href;
        }
        $abs = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $m[0]);
        $mtime = @filemtime($abs);
        if (!$mtime) {
            return $href;
        }
        return $href . (strpos($href, '?') === false ? '?v=' : '&v=') . $mtime;
    }
}

if (!function_exists('csv_safe')) {
    /**
     * Neutralise l'injection de formule CSV (OWASP « CSV Injection ») : préfixe d'une apostrophe
     * toute valeur commençant par = + - @ (ou tab/CR), sinon interprétée comme formule par
     * Excel/LibreOffice à l'ouverture. Helper unique réutilisé par tous les exporteurs.
     */
    function csv_safe($value): string
    {
        $value = (string) $value;
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }
}

if (!function_exists('js_json')) {
    /**
     * Encode une valeur pour injection SÛRE dans un bloc <script> : applique les flags qui
     * neutralisent </script>, ', ", & — défense en profondeur si la CSP venait à régresser.
     */
    function js_json($value): string
    {
        return (string) json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
        );
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

if (!function_exists('session_has_identity')) {
    /** Une identité authentifiée est-elle en session, quel que soit le monde (établissement/plateforme/tenant) ? */
    function session_has_identity(): bool
    {
        return !empty($_SESSION['user_id'])
            || !empty($_SESSION['platform']['account_id'])
            || !empty($_SESSION['tenant']['account_id']);
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

