<?php
declare(strict_types=1);

/**
 * Serveur de distribution Fronote (point d'entrée HTTP unique).
 *
 * Endpoints (action via ?action= ou chemin) :
 *   POST /redeem            {key}                 → valide+consomme la clé, renvoie install_token + infos socle
 *   GET  /download          ?token=&artifact=     → stream l'archive (base | module-<x>) autorisée
 *   POST /module            {token, module}       → autorise un module premium (renvoie sa signature)
 *   GET  /health                                  → statut
 *   GET  /pubkey                                  → clé publique de signature (hex)
 *   POST /admin/unban       {ip}   (X-Admin-Token) → débannit (bot Discord / CLI distante)
 *   POST /admin/ban         {ip}   (X-Admin-Token)
 *   GET  /admin/status      ?ip=   (X-Admin-Token)
 *
 * Sécurité : bannissement IP après N échecs de clé, jeton d'admin en temps constant,
 * jamais de secret en réponse, archives signées Ed25519 (vérifiées côté client).
 */

require __DIR__ . '/lib/loader.php';

// Format de réponse : 'json' (défaut) ou 'sh' (KEY=VALUE, pour le bootstrapper bash).
$GLOBALS['RESPONSE_FORMAT'] = (($_GET['format'] ?? $_POST['format'] ?? '') === 'sh') ? 'sh' : 'json';

// ── utilitaires réponse ──────────────────────────────────────────────────────
/** Aplatit un tableau imbriqué en KEY_SUBKEY (listes → CSV) pour la sortie shell. */
function flatten_sh(array $p, string $prefix = ''): array
{
    $out = [];
    foreach ($p as $k => $v) {
        $key = strtoupper($prefix . preg_replace('/[^A-Za-z0-9]+/', '_', (string) $k));
        if (is_array($v)) {
            $isList = array_keys($v) === range(0, count($v) - 1);
            if ($isList) {
                $out[$key] = implode(',', array_map('strval', $v));
            } else {
                $out += flatten_sh($v, $key . '_');
            }
        } else {
            $out[$key] = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
        }
    }
    return $out;
}

function json_out(int $code, array $payload): never
{
    http_response_code($code);
    header('X-Content-Type-Options: nosniff');
    if (($GLOBALS['RESPONSE_FORMAT'] ?? 'json') === 'sh') {
        header('Content-Type: text/plain; charset=utf-8');
        foreach (flatten_sh($payload) as $k => $v) {
            // Valeurs sûres (hex/csv/url/enum) ; on neutralise tout retour à la ligne par prudence.
            echo $k . '=' . str_replace(["\n", "\r"], '', $v) . "\n";
        }
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function client_ip(array $cfg): string
{
    // Par défaut : l'IP TCP réelle (non spoofable). XFF honoré seulement si explicitement
    // configuré (derrière un reverse-proxy de confiance).
    if (!empty($cfg['trust_forwarded']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function require_admin(array $cfg): void
{
    $sent = (string) ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ($_POST['admin_token'] ?? ''));
    if ($sent === '' || !hash_equals((string) $cfg['admin_token'], $sent)) {
        json_out(401, ['status' => 'unauthorized']);
    }
}

// ── bootstrap ────────────────────────────────────────────────────────────────
try {
    $cfg = dist_config();
    $store = dist_store($cfg);
} catch (\Throwable $e) {
    json_out(500, ['status' => 'server_error', 'message' => 'Configuration serveur invalide.']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');
if ($action === '') {
    // Dérive l'action du chemin (…/dist/server.php/redeem ou réécriture …/redeem).
    $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
    $action = (string) (array_slice(explode('/', $path), -1)[0] ?? '');
    if (str_contains($path, 'admin/')) {
        $action = 'admin_' . $action;
    }
}
$ip = client_ip($cfg);
$payloadDir = rtrim((string) $cfg['data_dir'], '/') . '/payloads';

// ── routes ───────────────────────────────────────────────────────────────────
switch ($action) {
    case 'health':
        json_out(200, ['status' => 'ok', 'service' => 'fronote-dist']);

    // no break (json_out exits)
    case 'pubkey':
        try {
            json_out(200, ['public_key' => dist_signer($cfg)->publicKeyHex()]);
        } catch (\Throwable $e) {
            json_out(500, ['status' => 'server_error']);
        }

    case 'redeem': {
        if ($method !== 'POST') { json_out(405, ['status' => 'method_not_allowed']); }
        $key = trim((string) ($_POST['key'] ?? ''));
        $res = $store->redeem($key, $ip);
        switch ($res['status']) {
            case 'ok':
                $baseSig = @file_get_contents($payloadDir . '/base.tar.gz.sig');
                $baseSha = is_file($payloadDir . '/base.tar.gz') ? hash_file('sha256', $payloadDir . '/base.tar.gz') : '';
                json_out(200, [
                    'status'         => 'ok',
                    'install_token'  => $res['install_token'],
                    'modules'        => $res['modules'],
                    'premium_modules' => $res['premium_modules'],
                    'base' => [
                        'artifact' => 'base',
                        'sig'      => $baseSig !== false ? trim((string) $baseSig) : '',
                        'sha256'   => $baseSha,
                        'url'      => rtrim((string) $cfg['dist_base_url'], '/') . '/download?artifact=base&token=' . $res['install_token'],
                    ],
                ]);
            case 'not_whitelisted':
                json_out(403, ['status' => 'not_whitelisted', 'message' => (string) ($cfg['not_whitelisted_message'] ?? 'Accès refusé : votre IP n\'est pas autorisée. Contactez le support.')]);
            case 'exhausted':
                json_out(409, ['status' => 'exhausted', 'message' => 'Cette clé a déjà été utilisée.']);
            case 'revoked':
                json_out(410, ['status' => 'revoked', 'message' => 'Licence révoquée.']);
            case 'expired':
                json_out(410, ['status' => 'expired', 'message' => 'Licence expirée.']);
            case 'invalid':
            default:
                json_out(401, ['status' => 'invalid', 'message' => 'Clé invalide.']);
        }
    }

    case 'module': {
        if ($method !== 'POST') { json_out(405, ['status' => 'method_not_allowed']); }
        $token = trim((string) ($_POST['token'] ?? ''));
        $module = trim((string) ($_POST['module'] ?? ''));
        $res = $store->authorizeModule($token, $module, $ip);
        if ($res['status'] !== 'ok') {
            $codes = ['banned' => 403, 'invalid_token' => 401, 'not_entitled' => 402, 'revoked' => 410, 'expired' => 410];
            json_out($codes[$res['status']] ?? 400, ['status' => $res['status']]);
        }
        $file = $payloadDir . '/module-' . $module . '.tar.gz';
        if (!is_file($file)) { json_out(404, ['status' => 'not_built', 'message' => 'Module non disponible au téléchargement.']); }
        json_out(200, [
            'status'  => 'ok',
            'module'  => $module,
            'sig'     => trim((string) @file_get_contents($file . '.sig')),
            'sha256'  => hash_file('sha256', $file),
            'url'     => rtrim((string) $cfg['dist_base_url'], '/') . '/download?artifact=module-' . $module . '&token=' . $token,
        ]);
    }

    case 'download': {
        if ($method !== 'GET') { json_out(405, ['status' => 'method_not_allowed']); }
        if (!$store->isIpAllowed($ip)) { json_out(403, ['status' => 'not_whitelisted']); }
        $token = trim((string) ($_GET['token'] ?? ''));
        $artifact = (string) ($_GET['artifact'] ?? '');
        if (!preg_match('/^(base|module-[a-z][a-z0-9_]*)$/', $artifact)) {
            json_out(400, ['status' => 'bad_artifact']);
        }
        // Autorisation : 'base' → jeton d'installation valide ; 'module-x' → droit sur x.
        if ($artifact === 'base') {
            // Un jeton valide suffit pour re-télécharger le socle.
            $ok = $store->authorizeModule($token, ($cfg['simple_modules'][0] ?? 'notes'), $ip);
            if (($ok['status'] ?? '') !== 'ok') { json_out(401, ['status' => 'invalid_token']); }
        } else {
            $mod = substr($artifact, strlen('module-'));
            $ok = $store->authorizeModule($token, $mod, $ip);
            if (($ok['status'] ?? '') !== 'ok') { json_out(402, ['status' => $ok['status'] ?? 'denied']); }
        }
        $file = $payloadDir . '/' . $artifact . '.tar.gz';
        if (!is_file($file)) { json_out(404, ['status' => 'not_built']); }
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    // ── API d'admin (appelée par le bot Discord du distributeur) ─────────────
    case 'admin_allow': {   // whitelister une IP
        if ($method !== 'POST') { json_out(405, ['status' => 'method_not_allowed']); }
        require_admin($cfg);
        $target = trim((string) ($_POST['ip'] ?? ''));
        if ($target === '' || !filter_var($target, FILTER_VALIDATE_IP)) { json_out(400, ['status' => 'bad_ip']); }
        $store->allowIp($target, (string) ($_POST['note'] ?? ''));
        json_out(200, ['status' => 'ok', 'allowed' => $target]);
    }

    case 'admin_disallow': {   // retirer une IP de la whitelist
        if ($method !== 'POST') { json_out(405, ['status' => 'method_not_allowed']); }
        require_admin($cfg);
        $target = trim((string) ($_POST['ip'] ?? ''));
        if ($target === '' || !filter_var($target, FILTER_VALIDATE_IP)) { json_out(400, ['status' => 'bad_ip']); }
        json_out(200, ['status' => 'ok', 'removed' => $store->disallowIp($target)]);
    }

    case 'admin_list': {   // lister la whitelist, ou le statut d'une IP
        require_admin($cfg);
        $target = trim((string) ($_GET['ip'] ?? ''));
        if ($target !== '') { json_out(200, ['status' => 'ok', 'ip' => $store->ipStatus($target)]); }
        json_out(200, ['status' => 'ok', 'allowlist' => $store->listAllowed()]);
    }

    default:
        json_out(404, ['status' => 'unknown_action']);
}
