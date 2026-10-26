<?php
declare(strict_types=1);
/**
 * Fronote PLATFORM — noyau : configuration, base, identité de l'opérateur, CSRF, audit,
 * client de l'API de distribution (CT122).
 *
 * Sécurité de l'interface : Caddy n'ouvre la console qu'au réseau local / VPN et la place
 * derrière Authelia (2FA). L'identité vient des en-têtes Remote-User / Remote-Groups, que
 * l'on n'accepte QUE si la requête arrive du proxy de confiance (le pare-feu de CT129 ne
 * laisse d'ailleurs entrer que lui).
 */

const PF_ROOT = __DIR__ . '/..';

function pf_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = getenv('FRONOTE_PLATFORM_CONFIG') ?: '/etc/fronote-platform/config.php';
        $cfg = is_file($file) ? (require $file) : [];
        if (!is_array($cfg) || empty($cfg['db']['dsn'])) {
            http_response_code(500);
            exit('Configuration de la plateforme absente (' . $file . ').');
        }
    }
    return $cfg;
}

function pf_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = pf_config()['db'];
        $pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function pf_client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($remote === (pf_config()['trusted_proxy'] ?? '') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $first = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) { return $first; }
    }
    return $remote;
}

/** Opérateur connecté (SSO) ou null. */
function pf_operator(): ?array
{
    $cfg = pf_config();
    if (($_SERVER['REMOTE_ADDR'] ?? '') !== ($cfg['trusted_proxy'] ?? '')) {
        return null; // en-têtes d'identité ignorés s'ils ne viennent pas de Caddy
    }
    $user = trim((string) ($_SERVER['HTTP_REMOTE_USER'] ?? ''));
    $groups = array_filter(array_map('trim', explode(',', (string) ($_SERVER['HTTP_REMOTE_GROUPS'] ?? ''))));
    if ($user === '' || !array_intersect($groups, (array) ($cfg['operator_groups'] ?? ['admins']))) {
        return null;
    }
    return [
        'user'  => $user,
        'name'  => trim((string) ($_SERVER['HTTP_REMOTE_NAME'] ?? '')) ?: $user,
        'email' => trim((string) ($_SERVER['HTTP_REMOTE_EMAIL'] ?? '')),
    ];
}

function pf_require_operator(): array
{
    $op = pf_operator();
    if ($op === null) {
        http_response_code(403);
        exit('Accès réservé aux opérateurs de la plateforme.');
    }
    return $op;
}

// ── Session (messages flash + CSRF) ──────────────────────────────────────────
function pf_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    session_name('fronote_platform');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}

function pf_csrf(): string
{
    pf_session();
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
    return $_SESSION['csrf'];
}

function pf_csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(pf_csrf()) . '">'; }

function pf_check_csrf(): void
{
    pf_session();
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Jeton de formulaire expiré : rechargez la page.');
    }
}

function pf_flash(?string $msg = null, string $kind = 'ok'): ?array
{
    pf_session();
    if ($msg !== null) { $_SESSION['flash'] = [$kind, $msg]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function pf_redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function pf_audit(string $actor, string $action, ?string $target = null, array $detail = []): void
{
    try {
        pf_db()->prepare('INSERT INTO audit_log (actor, action, target, detail, ip) VALUES (?, ?, ?, ?, ?)')
            ->execute([$actor, $action, $target, $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null, pf_client_ip()]);
    } catch (\Throwable $e) {
        error_log('[platform] audit: ' . $e->getMessage());
    }
}

// ── Client de l'API de distribution (CT122) ──────────────────────────────────
final class Dist
{
    /** @return array{0:int,1:array} [code HTTP, corps JSON] — code 0 si injoignable. */
    public static function call(string $method, string $path, ?array $body = null, int $timeout = 8, bool $form = false): array
    {
        $cfg = pf_config();
        $ch = curl_init(rtrim((string) $cfg['dist_url'], '/') . $path);
        $headers = ['X-Admin-Token: ' . $cfg['dist_admin_token'], 'Accept: application/json'];
        if ($body !== null && !$form) { $headers[] = 'Content-Type: application/json'; }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_POSTFIELDS     => $body === null ? null : ($form ? http_build_query($body) : json_encode($body)),
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        return [$raw === false ? 0 : $code, is_array($json) ? $json : []];
    }

    /** Lecture avec cache court (pages de la console). */
    public static function get(string $path, string $key, int $ttl = 60): array
    {
        pf_session();
        $c = $_SESSION['dist_cache'][$key] ?? null;
        if ($c && $c['t'] > time() - $ttl) { return $c['v']; }
        [$code, $json] = self::call('GET', $path);
        $v = ['ok' => $code === 200, 'code' => $code, 'data' => $json];
        if ($code === 200) { $_SESSION['dist_cache'][$key] = ['t' => time(), 'v' => $v]; }
        return $v;
    }

    public static function forget(): void { pf_session(); unset($_SESSION['dist_cache']); }

    public static function explain(int $code, array $json): string
    {
        return match (true) {
            $code === 0 => "Serveur de distribution injoignable.",
            $code === 401 => "Jeton admin refusé par le serveur de distribution (vérifier dist_admin_token).",
            $code === 403 => "Le serveur de distribution refuse cette origine pour cette action.",
            $code === 404 && (($json['status'] ?? '') === 'unknown_action') =>
                "Cette fonction n'existe pas encore sur le serveur de distribution : publier la dernière version (fronote-release.sh).",
            default => 'Erreur du serveur de distribution (' . $code . ($json['status'] ?? '' ? ' — ' . $json['status'] : '') . ').',
        };
    }
}
