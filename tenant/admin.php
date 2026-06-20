<?php
/** Portail ÉTABLISSEMENT — administration locale. Exige une permission de gestion. */
require __DIR__ . '/_bootstrap.php';
tenantRequireAuth($establishment, $base, $slug);

use API\Tenant\TenantAuthorization;

$auth  = new TenantAuthorization(getPDO(), (int) $_SESSION['tenant']['membership_id']);
$roles = $auth->roleKeys();

// Accès admin local : au moins une permission de gestion.
$canAdmin = $auth->can('tenant.users.view') || $auth->can('tenant.roles.view') || $auth->can('tenant.audit.view');
if (!$canAdmin) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>Accès refusé</title>'
       . '<p style="font-family:system-ui;padding:40px">Accès administration refusé. '
       . '<a href="' . $base . '/tenant/dashboard.php?e=' . urlencode($slug) . '">Retour</a></p>';
    exit;
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($establishment['nom']) ?> — Administration</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f8fafc; color: #1e293b; margin: 0; }
        header { background: #1e293b; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
        header a { color: #93c5fd; text-decoration: none; }
        main { max-width: 900px; margin: 24px auto; padding: 0 16px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px,1fr)); gap: 12px; margin-top: 16px; }
        .tile { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; }
        .tile a { color: #1e293b; text-decoration: none; font-weight: 600; }
        .muted { color: #64748b; font-size: .85rem; }
    </style>
</head>
<body>
    <header>
        <div><strong><?= htmlspecialchars($establishment['nom']) ?></strong> — Administration</div>
        <div><a href="<?= $base ?>/tenant/dashboard.php?e=<?= urlencode($slug) ?>">Espace utilisateur</a>
            &nbsp; <a href="<?= $base ?>/tenant/logout.php?e=<?= urlencode($slug) ?>">Déconnexion</a></div>
    </header>
    <main>
        <h1>Administration — <?= htmlspecialchars($establishment['nom']) ?></h1>
        <div class="grid">
            <?php if ($auth->can('tenant.support.ticket.view')): ?>
                <div class="tile"><a href="<?= $base ?>/tenant/support.php?e=<?= urlencode($slug) ?>">Support &amp; demandes d'accès</a></div>
            <?php endif; ?>
            <?php
            $tiles = [
                ['tenant.users.view', 'Utilisateurs',           '/admin/users/index.php'],
                ['tenant.roles.view', 'Catalogue des rôles',    '/admin/roles/index.php'],
                ['tenant.roles.view', 'Simulateur de permissions', '/admin/scopes/simulator.php'],
                ['tenant.roles.view', 'Relations',              '/admin/scopes/relationships.php'],
                ['tenant.audit.view', 'Audit établissement',    '/admin/about.php'],
            ];
            foreach ($tiles as [$perm, $label, $href]): if (!$auth->can($perm)) continue; ?>
                <div class="tile"><a href="<?= $base . $href ?>"><?= htmlspecialchars($label) ?></a></div>
            <?php endforeach; ?>
        </div>
        <p class="muted" style="margin-top:24px">Support : ticket → demande d'accès → session (tables support_* en place ; UI en cours).</p>
    </main>
</body>
</html>
