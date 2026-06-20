<?php
/** Portail ÉTABLISSEMENT — tableau de bord utilisateur. */
require __DIR__ . '/_bootstrap.php';
tenantRequireAuth($establishment, $base, $slug);

use API\Tenant\TenantAuthorization;

$membershipId = (int) $_SESSION['tenant']['membership_id'];
$auth  = new TenantAuthorization(getPDO(), $membershipId);
$roles = $auth->roleKeys();
$isAdminLike = $auth->can('tenant.users.view') || $auth->can('tenant.dashboard.view') && (bool) array_intersect(['directeur', 'direction', 'administration', 'chef_etablissement'], $roles);
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($establishment['nom']) ?></title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f8fafc; color: #1e293b; margin: 0; }
        header { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
        header a { color: #2563eb; text-decoration: none; }
        main { max-width: 900px; margin: 24px auto; padding: 0 16px; }
        .roles span { background: #eef2ff; color: #3730a3; border-radius: 6px; padding: 2px 8px; font-size: .75rem; margin-right: 4px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px,1fr)); gap: 12px; margin-top: 16px; }
        .tile { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; }
        .tile a { color: #1e293b; text-decoration: none; font-weight: 600; }
        .muted { color: #64748b; font-size: .85rem; }
    </style>
</head>
<body>
    <header>
        <div><strong><?= htmlspecialchars($establishment['nom']) ?></strong></div>
        <div><span class="muted"><?= htmlspecialchars($_SESSION['tenant']['username']) ?></span>
            <?php if ($isAdminLike): ?> &nbsp; <a href="<?= $base ?>/tenant/admin.php?e=<?= urlencode($slug) ?>">Administration</a><?php endif; ?>
            &nbsp; <a href="<?= $base ?>/tenant/logout.php?e=<?= urlencode($slug) ?>">Déconnexion</a>
        </div>
    </header>
    <main>
        <h1>Bonjour</h1>
        <p class="roles">Rôles : <?php foreach ($roles as $r): ?><span><?= htmlspecialchars($r) ?></span><?php endforeach; ?></p>
        <div class="grid">
            <?php
            // N'affiche un module que si la permission de consultation est détenue.
            $tiles = [
                ['students.view', 'Élèves',   '/accueil/accueil.php'],
                ['grades.view',   'Notes',    '/modules/notes/notes.php'],
                ['homework.view', 'Devoirs',  '/modules/cahierdetextes/cahierdetextes.php'],
                ['attendance.view','Absences','/modules/absences/absences.php'],
                ['documents.view','Documents','/modules/documents/documents.php'],
            ];
            foreach ($tiles as [$perm, $label, $href]): if (!$auth->can($perm)) continue; ?>
                <div class="tile"><a href="<?= $base . $href ?>"><?= htmlspecialchars($label) ?></a></div>
            <?php endforeach; ?>
        </div>
        <p class="muted" style="margin-top:24px">Portail établissement (modèle tenant_accounts). Le câblage des modules existants sur la session établissement est en cours.</p>
    </main>
</body>
</html>
