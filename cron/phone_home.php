<?php
/**
 * Cron — Phone-home (supervision distributeur).
 *
 * Signale QUOTIDIENNEMENT cette instance au serveur de distribution : version, santé
 * (HealthCheckService), âge de la dernière sauvegarde, compteurs agrégés. Le distributeur
 * obtient ainsi une vue du parc (versions, silences-radio, sauvegardes vieillissantes)
 * qui n'existait pas — l'IP seule ne suffisait pas (NAT, IP dynamiques).
 *
 * Authentifié par le JETON D'INSTALLATION (.fronote-license), pas par l'IP. NO-OP complet
 * si .fronote-license est absent (installation manuelle hors distribution).
 *
 * MINIMISATION RGPD : n'envoie QUE des compteurs et états agrégés — jamais un nom
 * d'élève, d'utilisateur, ni aucune donnée personnelle.
 *
 * Cron : quelques minutes après 04:00 (posé par install.sh).
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement.\n");
}

$appRoot = dirname(__DIR__);
$licenseFile = $appRoot . '/.fronote-license';
if (!is_file($licenseFile)) {
    // Installation hors distribution : rien à signaler.
    exit(0);
}

// ── contexte de licence (.fronote-license : lignes KEY=VALUE) ──────────────────
$lic = [];
foreach (file($licenseFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) { continue; }
    [$k, $v] = explode('=', $line, 2);
    $lic[trim($k)] = trim($v);
}
$token = $lic['INSTALL_TOKEN'] ?? '';
$distUrl = rtrim($lic['DIST_URL'] ?? '', '/');
if ($token === '' || $distUrl === '') {
    fwrite(STDERR, "[phone-home] .fronote-license incomplet — abandon.\n");
    exit(1);
}

require_once $appRoot . '/API/bootstrap.php';

$log = static fn(string $m) => print('[' . date('Y-m-d H:i:s') . '] [phone-home] ' . $m . "\n");

// ── collecte (best-effort, chaque bloc protégé) ───────────────────────────────
$fields = ['token' => $token];

// Identité machine
$fields['hostname'] = (string) gethostname();
$fields['os'] = trim(php_uname('s') . ' ' . php_uname('r'));
$fields['php_version'] = PHP_VERSION;
$fields['app_url'] = (string) (getenv('APP_URL') ?: '');

// Version applicative (version.json généré au build)
$vf = $appRoot . '/version.json';
if (is_file($vf)) {
    $v = json_decode((string) file_get_contents($vf), true) ?: [];
    $fields['app_version'] = (string) ($v['version'] ?? '');
    $fields['app_build'] = (string) ($v['build'] ?? '');
}

// Santé des sous-systèmes
try {
    $health = (new \API\Services\HealthCheckService(getPDO(), $appRoot))->runAll();
    $fields['healthy'] = !empty($health['healthy']) ? 1 : 0;
    // On ne transmet que le statut par sonde (agrégé) — pas les messages détaillés.
    $slim = [];
    foreach (($health['checks'] ?? []) as $name => $c) {
        $slim[$name] = $c['status'] ?? 'unknown';
    }
    $fields['checks_json'] = json_encode($slim, JSON_UNESCAPED_SLASHES);
    if (isset($health['checks']['disk']['used_percent'])) {
        $fields['disk_pct'] = (int) round((float) $health['checks']['disk']['used_percent']);
    }
} catch (\Throwable $e) {
    $log('santé indisponible: ' . $e->getMessage());
}

// Compteurs agrégés (jamais de données personnelles)
try {
    $pdo = getPDO();
    $q = static function (PDO $pdo, string $sql): ?int {
        try { $r = $pdo->query($sql)->fetchColumn(); return $r === false ? null : (int) $r; }
        catch (\Throwable $e) { return null; }
    };
    $t = $q($pdo, "SELECT COUNT(*) FROM etablissements WHERE status = 'active'");
    if ($t !== null) { $fields['tenants_count'] = $t; }
    $a = $q($pdo, "SELECT COUNT(*) FROM tenant_accounts");
    if ($a === null) { $a = $q($pdo, "SELECT COUNT(*) FROM utilisateurs"); }
    if ($a !== null) { $fields['accounts_count'] = $a; }
} catch (\Throwable $e) {
    $log('compteurs indisponibles: ' . $e->getMessage());
}

// Dernière sauvegarde : fichier le plus récent de storage/backups/
try {
    $bkDir = $appRoot . '/storage/backups';
    $newest = 0;
    foreach (glob($bkDir . '/*') ?: [] as $f) {
        if (is_file($f)) { $newest = max($newest, (int) filemtime($f)); }
    }
    // UTC (gmdate) : le serveur dist calcule l'âge via julianday('now') en UTC. Envoyer
    // une heure locale (ex. Europe/Paris) donnait un âge de sauvegarde NÉGATIF (2026-08-18).
    if ($newest > 0) { $fields['last_backup_at'] = gmdate('Y-m-d H:i:s', $newest); }
} catch (\Throwable $e) { /* best-effort */ }

// ── envoi ─────────────────────────────────────────────────────────────────────
$url = $distUrl . '/?action=phone-home';
$body = http_build_query($fields);
$respBody = null; $code = 0;

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $respBody = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
} else {
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $body,
        'timeout' => 30,
        'ignore_errors' => true,
    ]]);
    $respBody = @file_get_contents($url, false, $ctx);
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int) $m[1];
    }
}

if ($respBody === false || $respBody === null) {
    $log('serveur de distribution injoignable (' . $distUrl . ')');
    exit(1);
}
$resp = json_decode((string) $respBody, true) ?: [];
if ($code !== 200 || ($resp['status'] ?? '') !== 'ok') {
    $log('rapport refusé (HTTP ' . $code . ', status=' . ($resp['status'] ?? '?') . ')');
    exit(1);
}

// ── statut de licence pour la bannière (enforcement DOUX, jamais bloquant) ─────
try {
    $state = [
        'license_status' => (string) ($resp['license_status'] ?? 'ok'),
        'expires_at'     => $resp['expires_at'] ?? null,
        'latest_version' => (string) ($resp['latest_version'] ?? ''),
        'latest_build'   => (string) ($resp['latest_build'] ?? ''),
        'checked_at'     => date('c'),
    ];
    @file_put_contents(
        $appRoot . '/storage/.license-status.json',
        json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
} catch (\Throwable $e) { /* best-effort */ }

$log('OK — version ' . ($fields['app_version'] ?? '?') . ', santé ' . (($fields['healthy'] ?? 0) ? 'saine' : 'dégradée')
    . ', licence ' . ($resp['license_status'] ?? '?'));
exit(0);
