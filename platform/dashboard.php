<?php
/**
 * Portail PLATEFORME — tableau de bord. Protégé par le garde plateforme.
 */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$base = defined('BASE_URL') ? BASE_URL : '';

// Garde plateforme : refuse (→ /platform/login) si pas de compte plateforme valide.
platformAuthorize('platform.dashboard.view');

$auth     = \API\Security\WorldContext::platformAuth();
$roles    = $auth->roleKeys();
$username = $_SESSION['platform']['username'] ?? '';

// Comptage des établissements (supervision).
$nbEtab = 0;
try { $nbEtab = (int) getPDO()->query("SELECT COUNT(*) FROM etablissements")->fetchColumn(); }
catch (\Throwable $e) { error_log('[platform dashboard] ' . $e->getMessage()); }

/** Item de menu affiché seulement si la permission est détenue. */
$menu = [
    ['platform.establishments.view',   'Établissements',        '/platform/establishments.php'],
    ['platform.director_invites.create','Invitations Directeur', '/platform/director-invitations.php'],
    ['platform.support.ticket.view',   'Support',               '/platform/support/tickets.php'],
    ['platform.audit.view',            'Audit global',          '/platform/audit.php'],
    ['platform.security.view',         'Sécurité',              '/platform/security.php'],
    ['platform.backups.view',          'Sauvegardes',           '/platform/backups.php'],
    ['platform.system.view',           'Système',               '/platform/system.php'],
];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Plateforme Fronote — Tableau de bord</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; }
        header { background: #1e293b; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #334155; }
        header .b { font-weight: 700; } header a { color: #93c5fd; text-decoration: none; }
        main { max-width: 920px; margin: 24px auto; padding: 0 16px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px,1fr)); gap: 14px; margin-top: 16px; }
        .tile { background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 18px; }
        .tile a { color: #e2e8f0; text-decoration: none; font-weight: 600; }
        .muted { color: #94a3b8; font-size: .85rem; }
        .roles span { background: #273449; border-radius: 6px; padding: 2px 8px; font-size: .75rem; margin-right: 4px; }
    </style>
</head>
<body>
    <header>
        <div class="b">⬢ Plateforme Fronote</div>
        <div><span class="muted"><?= htmlspecialchars($username) ?></span> &nbsp; <a href="<?= $base ?>/platform/logout.php">Déconnexion</a></div>
    </header>
    <main>
        <h1>Tableau de bord plateforme</h1>
        <p class="muted">Établissements supervisés : <strong><?= $nbEtab ?></strong></p>
        <p class="roles">Rôles : <?php foreach ($roles as $r): ?><span><?= htmlspecialchars($r) ?></span><?php endforeach; ?></p>
        <div class="grid">
            <?php foreach ($menu as [$perm, $label, $href]): if (!$auth->can($perm)) continue; ?>
                <div class="tile"><a href="<?= $base . $href ?>"><?= htmlspecialchars($label) ?></a></div>
            <?php endforeach; ?>
        </div>
        <p class="muted" style="margin-top:24px">Les pages détaillées (établissements, invitations, support, audit…) sont en cours de mise en place — la couche d'autorisation et de données est opérationnelle.</p>
    </main>
</body>
</html>
