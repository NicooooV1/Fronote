<?php
/** Portail PLATEFORME — vue système / infrastructure (supervision globale). */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$base = defined('BASE_URL') ? BASE_URL : '';
platformAuthorize('platform.system.view');
$pdo = getPDO();
$h = fn($s) => htmlspecialchars((string) $s);

$count = function (string $sql) use ($pdo): int {
    try { return (int) $pdo->query($sql)->fetchColumn(); } catch (\Throwable $e) { return -1; }
};

$byStatus = [];
try { foreach ($pdo->query("SELECT status, COUNT(*) c FROM etablissements GROUP BY status") as $r) { $byStatus[$r['status']] = (int) $r['c']; } } catch (\Throwable $e) {}

$metrics = [
    'Établissements'            => $count("SELECT COUNT(*) FROM etablissements"),
    'Comptes établissement'     => $count("SELECT COUNT(*) FROM tenant_accounts"),
    'Appartenances actives'     => $count("SELECT COUNT(*) FROM tenant_memberships WHERE status='active'"),
    'Comptes plateforme'        => $count("SELECT COUNT(*) FROM platform_accounts"),
    'Sessions support actives'  => $count("SELECT COUNT(*) FROM support_sessions WHERE status='active'"),
    'Tickets support ouverts'   => $count("SELECT COUNT(*) FROM support_tickets WHERE status NOT IN ('resolved','closed','cancelled')"),
    'Invitations en attente'    => $count("SELECT COUNT(*) FROM director_invitations WHERE status='pending'"),
];

$dbName = '';
try { $dbName = (string) $pdo->query("SELECT DATABASE()")->fetchColumn(); } catch (\Throwable $e) {}
$tableCount = $count("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");

$version = '?';
$vf = __DIR__ . '/../version.json';
if (is_file($vf)) { $v = json_decode((string) file_get_contents($vf), true); $version = $v['version'] ?? ($v['app_version'] ?? '?'); }
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Plateforme — Système</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; }
        header { background: #1e293b; padding: 14px 24px; } header a { color: #93c5fd; text-decoration: none; }
        main { max-width: 860px; margin: 24px auto; padding: 0 16px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px,1fr)); gap: 12px; }
        .tile { background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 16px; }
        .tile .n { font-size: 1.6rem; font-weight: 700; } .tile .l { color: #94a3b8; font-size: .82rem; }
        .muted { color: #94a3b8; font-size: .85rem; } .badge { background: #273449; border-radius: 6px; padding: 2px 8px; font-size: .75rem; margin-right: 4px; }
    </style>
</head>
<body>
    <header><a href="<?= $base ?>/platform/dashboard.php">← Tableau de bord</a></header>
    <main>
        <h1>Système &amp; infrastructure</h1>
        <p class="muted">Base <code><?= $h($dbName) ?></code> · <?= $tableCount ?> tables · Fronote v<?= $h($version) ?></p>
        <div class="grid">
            <?php foreach ($metrics as $label => $val): ?>
                <div class="tile"><div class="n"><?= $val < 0 ? '—' : $val ?></div><div class="l"><?= $h($label) ?></div></div>
            <?php endforeach; ?>
        </div>
        <h2>Établissements par statut</h2>
        <p><?php foreach ($byStatus as $s => $c): ?><span class="badge"><?= $h($s) ?> : <?= (int) $c ?></span><?php endforeach; ?></p>
    </main>
</body>
</html>
