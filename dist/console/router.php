<?php
declare(strict_types=1);
/**
 * Console de gestion Fronote (privée) — le « poste de pilotage » du hub CT122.
 *
 * Modèle voulu par Nico : la platform accède à tout, RIEN ne remonte vers elle ;
 * elle n'est joignable qu'en IP locale/VPN, derrière un login opérateur. Elle lit la
 * base de distribution (licences, parc, propositions) — aucune dépendance à MariaDB ni
 * au code applicatif (le joyau de signature reste minimal).
 *
 * Servie par public/index.php pour les chemins /console*. Lecture seule pour l'instant
 * (les actions d'émission/révocation passent par les CLIs dist/bin/* et le bot).
 */

require_once dirname(__DIR__) . '/lib/loader.php';

try {
    $cfg = dist_config();
    $store = dist_store($cfg);
} catch (\Throwable $e) {
    http_response_code(500);
    exit('Configuration serveur invalide.');
}

// ── IP réelle du client (via le proxy de confiance Caddy, segment de droite) ──
function console_client_ip(array $cfg): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $trusted = array_map('strval', (array) ($cfg['trusted_proxies'] ?? []));
    if (!empty($cfg['trust_forwarded']) && in_array($remote, $trusted, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']));
        $cand = end($parts);
        if ($cand !== false && filter_var($cand, FILTER_VALIDATE_IP)) { return $cand; }
    }
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
}

function ip_in_ranges(string $ip, array $ranges): bool
{
    $ipl = @inet_pton($ip);
    if ($ipl === false) { return false; }
    foreach ($ranges as $cidr) {
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $netl = @inet_pton($net);
        if ($netl === false || strlen($netl) !== strlen($ipl)) { continue; }
        $bits = $bits === null ? strlen($ipl) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8); $rem = $bits % 8;
        if ($bytes > 0 && substr($ipl, 0, $bytes) !== substr($netl, 0, $bytes)) { continue; }
        if ($rem === 0) { return true; }
        $mask = chr((0xFF << (8 - $rem)) & 0xFF);
        if ((ord($ipl[$bytes]) & ord($mask)) === (ord($netl[$bytes]) & ord($mask))) { return true; }
    }
    return false;
}

$ip = console_client_ip($cfg);
$ranges = (array) ($cfg['console_allow_ranges'] ?? ['127.0.0.1/32']);

// ── Barrière n°1 : IP locale/VPN uniquement (sinon la console n'existe pas) ──
if (!ip_in_ranges($ip, $ranges)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Page introuvable.");
}

// ── Session (cookie sûr, HTTPS via Caddy) ──
session_name('fronote_console');
session_set_cookie_params(['lifetime' => 0, 'path' => '/console', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['csrf'];
}
$h = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// ── Déconnexion ──
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: /console');
    exit;
}

// ── Barrière n°2 : login opérateur ──
$loginError = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['password'])) {
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        $loginError = 'Session expirée, réessayez.';
    } else {
        $hash = (string) ($cfg['console_password_hash'] ?? '');
        // petit anti-bruteforce : 1 tentative / 1.5 s par session
        $last = (float) ($_SESSION['last_try'] ?? 0);
        if (microtime(true) - $last < 1.5) {
            $loginError = 'Trop rapide, patientez une seconde.';
        } elseif ($hash !== '' && password_verify((string) $_POST['password'], $hash)) {
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            header('Location: /console');
            exit;
        } else {
            $_SESSION['last_try'] = microtime(true);
            $loginError = 'Mot de passe incorrect.';
        }
    }
}

$authed = !empty($_SESSION['auth']);

/* ─────────────────────────── Rendu ─────────────────────────── */
function layout_head(string $title): void
{
    global $h;
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex,nofollow">';
    echo '<title>' . $h($title) . ' — Console Fronote</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Spectral:wght@500;600&family=Public+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap">';
    echo '<style>'
       . ':root{--encre:#16243f;--encre2:#243553;--papier:#f6f2e9;--craie:#fff;--ardoise:#586173;--ardoise2:#7b8494;--ocre:#b9772a;--ocre-v:#efe2c8;--vert:#2f7d4f;--rouge:#be3a2f;--amb:#bd6a12;--hair:#e4ddcc;--sunk:#efeadd;'
       . '--serif:"Spectral",Georgia,serif;--sans:"Public Sans",system-ui,sans-serif;--mono:"IBM Plex Mono",ui-monospace,monospace}'
       . '*{box-sizing:border-box}body{margin:0;background:var(--papier);color:var(--encre);font-family:var(--sans);font-size:15px;line-height:1.55}'
       . 'a{color:var(--ocre);text-decoration:none}a:hover{color:var(--encre)}'
       . ':focus-visible{outline:2.5px solid var(--ocre);outline-offset:2px}'
       . '.shell{display:grid;grid-template-columns:230px 1fr;min-height:100vh}@media(max-width:720px){.shell{grid-template-columns:1fr}}'
       . '.side{background:var(--encre);color:var(--papier);padding:22px 16px;display:flex;flex-direction:column;gap:4px}'
       . '.brand{display:flex;align-items:center;gap:10px;font-family:var(--serif);font-weight:600;font-size:1.2rem;margin-bottom:20px;padding:0 8px}'
       . '.brand svg{width:26px;height:26px}'
       . '.nav a{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:#c3ccdb;font-weight:500;font-size:.95rem}'
       . '.nav a:hover{background:#20304c;color:#fff}.nav a.on{background:var(--ocre);color:#fff}'
       . '.side-foot{margin-top:auto;padding:8px;font-family:var(--mono);font-size:11px;color:#7b8699}'
       . '.side-foot a{color:#c3ccdb}'
       . '.main{padding:30px 34px}@media(max-width:720px){.main{padding:22px 18px}}'
       . '.top{display:flex;align-items:baseline;justify-content:space-between;gap:16px;margin-bottom:22px;flex-wrap:wrap}'
       . 'h1{font-family:var(--serif);font-weight:600;font-size:1.75rem;letter-spacing:-.01em;margin:0}'
       . '.eyebrow{font-family:var(--mono);font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--ocre)}'
       . '.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:26px}@media(max-width:720px){.kpis{grid-template-columns:repeat(2,1fr)}}'
       . '.kpi{background:var(--craie);border:1px solid var(--hair);border-radius:12px;padding:16px 18px}'
       . '.kpi .n{font-family:var(--mono);font-size:2rem;font-weight:600;line-height:1;font-variant-numeric:tabular-nums}'
       . '.kpi .k{font-size:.8rem;color:var(--ardoise);margin-top:8px}'
       . '.card{background:var(--craie);border:1px solid var(--hair);border-radius:13px;padding:4px 6px;overflow:hidden}'
       . '.card h2{font-family:var(--serif);font-size:1.15rem;margin:14px 16px 6px}'
       . '.tw{overflow-x:auto}table{border-collapse:collapse;width:100%;font-size:.9rem;min-width:520px}'
       . 'th,td{text-align:left;padding:10px 16px;border-bottom:1px solid var(--hair);vertical-align:top}'
       . 'th{font-family:var(--mono);font-size:10.5px;letter-spacing:.05em;text-transform:uppercase;color:var(--ardoise2);font-weight:500}'
       . 'tr:last-child td{border-bottom:none}td.mono{font-family:var(--mono);font-variant-numeric:tabular-nums}'
       . '.pill{display:inline-block;font-family:var(--mono);font-size:10.5px;padding:2px 8px;border-radius:20px}'
       . '.pill.ok{background:#e2f0e7;color:var(--vert)}.pill.warn{background:#f9edd9;color:var(--amb)}.pill.crit{background:#fbe9e6;color:var(--rouge)}.pill.mut{background:var(--sunk);color:var(--ardoise)}'
       . '.empty{padding:26px 16px;color:var(--ardoise);font-size:.95rem}'
       . '.dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px}'
       . '</style></head><body>';
}
function brand_svg(): string
{
    return '<svg viewBox="0 0 32 32" fill="none"><rect x="2.5" y="2.5" width="27" height="27" rx="6" fill="#d8a24b"/><path d="M11 9.5h11M11 15.5h8M11 21.5h5" stroke="#16243f" stroke-width="2.4" stroke-linecap="round"/></svg>';
}

// ── Écran de connexion ──
if (!$authed) {
    layout_head('Connexion');
    echo '<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px">';
    echo '<form method="post" action="/console" style="background:var(--craie);border:1px solid var(--hair);border-radius:16px;padding:32px 30px;width:100%;max-width:380px;box-shadow:0 10px 40px rgba(22,36,63,.1)">';
    echo '<div style="display:flex;align-items:center;gap:11px;font-family:var(--serif);font-weight:600;font-size:1.4rem;margin-bottom:6px"><span style="width:30px;height:30px;display:inline-block">' . brand_svg() . '</span>Console Fronote</div>';
    echo '<p style="color:var(--ardoise);font-size:.9rem;margin:0 0 20px">Espace de gestion — accès réservé.</p>';
    if ($loginError) { echo '<p style="color:var(--rouge);font-size:.88rem;margin:0 0 14px">' . $h($loginError) . '</p>'; }
    echo '<input type="hidden" name="csrf" value="' . $h(csrf_token()) . '">';
    echo '<label style="font-size:.82rem;font-weight:600;color:var(--ardoise)">Mot de passe</label>';
    echo '<input type="password" name="password" autofocus autocomplete="current-password" style="width:100%;margin:7px 0 18px;padding:12px 14px;border:1.5px solid var(--hair);border-radius:9px;font-family:var(--sans);font-size:1rem">';
    echo '<button type="submit" style="width:100%;padding:12px;background:var(--encre);color:var(--papier);border:none;border-radius:9px;font-family:var(--sans);font-weight:600;font-size:1rem;cursor:pointer">Se connecter</button>';
    echo '</form></div></body></html>';
    exit;
}

// ── Application (authentifié) ──
$page = preg_replace('/[^a-z]/', '', (string) ($_GET['p'] ?? 'dashboard'));
$pages = ['dashboard' => 'Tableau de bord', 'licences' => 'Licences', 'parc' => 'Parc', 'propositions' => 'Propositions'];
if (!isset($pages[$page])) { $page = 'dashboard'; }

$fmt_age = static function ($sec) {
    if ($sec === null) { return '—'; }
    $s = (int) $sec;
    if ($s < 3600) { return intdiv($s, 60) . ' min'; }
    if ($s < 86400) { return intdiv($s, 3600) . ' h'; }
    return intdiv($s, 86400) . ' j';
};

layout_head($pages[$page]);
echo '<div class="shell"><aside class="side"><div class="brand"><span>' . brand_svg() . '</span>Fronote</div><nav class="nav">';
$icons = ['dashboard' => 'M4 13h7V4H4v9Zm9 7h7V4h-7v16ZM4 20h7v-5H4v5Z', 'licences' => 'M4 7h16M4 12h16M4 17h10', 'parc' => 'M4 5h16v6H4zM4 15h16v4H4z', 'propositions' => 'M4 5h16v11H8l-4 3z'];
foreach ($pages as $k => $label) {
    $on = $k === $page ? ' on' : '';
    echo '<a class="' . $on . '" href="/console?p=' . $k . '"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="' . $icons[$k] . '"/></svg>' . $h($label) . '</a>';
}
echo '</nav><div class="side-foot">accès depuis<br>' . $h($ip) . '<br><a href="/console?logout=1">Se déconnecter →</a></div></aside><main class="main">';

// ── Contenu par page ──
try {
    $licenses = $store->listLicenses(500);
    $instances = $store->listInstances(500);
    $proposals = $store->listProposals(0, 200);
} catch (\Throwable $e) {
    $licenses = $instances = $proposals = [];
}

echo '<div class="top"><div><div class="eyebrow">Hub de distribution</div><h1>' . $h($pages[$page]) . '</h1></div></div>';

if ($page === 'dashboard') {
    $licActive = count(array_filter($licenses, static fn($l) => empty($l['revoked'])));
    $instSilent = count(array_filter($instances, static fn($i) => ($i['silence_seconds'] ?? 0) > 216000));
    echo '<div class="kpis">';
    echo '<div class="kpi"><div class="n">' . count($licenses) . '</div><div class="k">Licences (' . $licActive . ' actives)</div></div>';
    echo '<div class="kpi"><div class="n">' . count($instances) . '</div><div class="k">Écoles au parc</div></div>';
    echo '<div class="kpi"><div class="n" style="color:' . ($instSilent ? 'var(--rouge)' : 'var(--vert)') . '">' . $instSilent . '</div><div class="k">En silence radio</div></div>';
    echo '<div class="kpi"><div class="n" style="color:var(--ocre)">' . count($proposals) . '</div><div class="k">Propositions reçues</div></div>';
    echo '</div>';
    echo '<div class="card"><h2>Dernières propositions</h2>';
    $recent = array_slice(array_reverse($proposals), 0, 5);
    if (!$recent) { echo '<div class="empty">Aucune proposition pour l\'instant.</div>'; }
    else {
        echo '<div class="tw"><table><thead><tr><th>Établissement</th><th>Contact</th><th>Reçu</th></tr></thead><tbody>';
        foreach ($recent as $p) {
            echo '<tr><td>' . $h($p['etablissement'] ?: '—') . '</td><td>' . $h($p['email'] ?: $p['telephone'] ?: '—') . '</td><td class="mono">' . $h($p['at']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
} elseif ($page === 'licences') {
    echo '<div class="card">';
    if (!$licenses) { echo '<div class="empty">Aucune licence émise. Utilise <code>dist/bin/issue-key.php</code> ou le bot pour en créer.</div>'; }
    else {
        echo '<div class="tw"><table><thead><tr><th>Préfixe</th><th>Tier</th><th>Premium</th><th>Usage</th><th>État</th><th>Note</th></tr></thead><tbody>';
        foreach ($licenses as $l) {
            $prem = trim(implode(', ', json_decode((string) $l['modules_json'], true) ?: [])) ?: '—';
            $state = !empty($l['revoked']) ? '<span class="pill crit">révoquée</span>' : '<span class="pill ok">active</span>';
            echo '<tr><td class="mono">' . $h($l['key_prefix']) . '…</td><td>' . $h($l['tier']) . '</td><td>' . $h($prem) . '</td><td class="mono">' . (int) $l['used_count'] . '/' . (int) $l['max_activations'] . '</td><td>' . $state . '</td><td>' . $h($l['note'] ?: '—') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
} elseif ($page === 'parc') {
    echo '<div class="card">';
    if (!$instances) { echo '<div class="empty">Aucune école ne s\'est encore signalée (phone-home quotidien).</div>'; }
    else {
        echo '<div class="tw"><table><thead><tr><th>École</th><th>Version</th><th>Santé</th><th>Vu</th><th>Sauvegarde</th><th>Disque</th></tr></thead><tbody>';
        foreach ($instances as $i) {
            $sil = $i['silence_seconds'] ?? null;
            $level = $sil !== null && $sil > 216000 ? 'crit' : (empty($i['healthy']) ? 'warn' : 'ok');
            $dot = ['ok' => 'var(--vert)', 'warn' => 'var(--amb)', 'crit' => 'var(--rouge)'][$level];
            echo '<tr><td><span class="dot" style="background:' . $dot . '"></span>' . $h($i['hostname'] ?: ('#' . $i['activation_id'])) . '</td>';
            echo '<td class="mono">' . $h($i['app_version'] ?: '?') . '</td>';
            echo '<td>' . (empty($i['healthy']) ? '<span class="pill warn">dégradée</span>' : '<span class="pill ok">saine</span>') . '</td>';
            echo '<td class="mono">' . $fmt_age($sil) . '</td>';
            echo '<td class="mono">' . $fmt_age($i['backup_age_seconds'] ?? null) . '</td>';
            echo '<td class="mono">' . ($i['disk_pct'] !== null ? (int) $i['disk_pct'] . ' %' : '—') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
} elseif ($page === 'propositions') {
    echo '<div class="card">';
    $rows = array_reverse($proposals);
    if (!$rows) { echo '<div class="empty">Aucune proposition reçue via le site vitrine.</div>'; }
    else {
        echo '<div class="tw"><table><thead><tr><th>Établissement</th><th>Type</th><th>Contact</th><th>Besoin</th><th>Reçu</th></tr></thead><tbody>';
        foreach ($rows as $p) {
            $contact = trim(($p['contact'] ? $p['contact'] . ' · ' : '') . ($p['email'] ?: $p['telephone'] ?: '—'));
            echo '<tr><td>' . $h($p['etablissement'] ?: '—') . '</td><td>' . $h($p['type'] ?: '—') . '</td><td>' . $h($contact) . '</td><td style="max-width:340px">' . $h($p['message'] ?: '—') . '</td><td class="mono">' . $h($p['at']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
}

echo '</main></div></body></html>';
