<?php
/**
 * Catalogue des rôles (RBAC) — vue LECTURE SEULE, groupée par tier.
 * Source de vérité = RoleCatalog (code). Réservé administration / super-admin.
 */
require_once __DIR__ . '/../../API/core.php';
require_once __DIR__ . '/../includes/admin_functions.php';

requireAuth();
// Bascule 3-mondes : permission établissement prioritaire, repli legacy le temps de la transition.
tenantGate('tenant.roles.view', ['administrateur', 'super_admin']);

use API\Security\RoleCatalog;

$pdo     = getPDO();
$byTier  = RoleCatalog::rolesByTier();
$catalog = RoleCatalog::roles();

// Nombre de titulaires (rôles ATTRIBUÉS via user_roles ; le rôle de base = type de
// compte n'est pas compté ici).
$holders = [];
try {
    foreach ($pdo->query("SELECT role_key, COUNT(*) c FROM user_roles GROUP BY role_key") as $row) {
        $holders[$row['role_key']] = (int) $row['c'];
    }
} catch (\Throwable $e) { /* table absente : 0 partout */ }

$pageTitle = 'Catalogue des rôles';
include __DIR__ . '/../includes/header.php';
?>
<div class="admin-container">
    <div class="page-header">
        <h1><i class="fas fa-id-badge"></i> Catalogue des rôles</h1>
        <div>
            <a href="../scopes/simulator.php" class="btn btn-secondary"><i class="fas fa-vial"></i> Simulateur</a>
            <a href="../users/index.php" class="btn btn-secondary"><i class="fas fa-users"></i> Utilisateurs</a>
        </div>
    </div>
    <p class="text-muted">
        Un <strong>rôle</strong> = une fonction. Il accorde des <strong>permissions</strong> et porte un
        <strong>périmètre</strong> par défaut. Le « type de compte » sert de rôle de base ; les rôles ci-dessous
        s'attribuent en plus, scopés et éventuellement temporaires (voir
        <a href="../users/roles.php">Attribution des rôles</a>).
    </p>

    <?php foreach ($byTier as $tier => $roles): ?>
    <div class="card" style="margin-top:16px">
        <div class="card-header"><h2><?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string) $tier))) ?> <small>(<?= count($roles) ?>)</small></h2></div>
        <div class="card-body" style="overflow-x:auto">
            <table class="table">
                <thead><tr><th>Rôle</th><th>Clé</th><th>Périmètre</th><th>Sensible</th><th>Permissions</th><th>Attribué à</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($roles as $rk):
                    $meta  = $catalog[$rk] ?? [];
                    $grants = RoleCatalog::grantsFor($rk);
                    $permCount = in_array('*', $grants, true) ? 'toutes' : count(RoleCatalog::permissionsFor($rk));
                ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($meta['label'] ?? $rk) ?></strong></td>
                        <td><code><?= htmlspecialchars($rk) ?></code></td>
                        <td><?= htmlspecialchars($meta['scope'] ?? '—') ?></td>
                        <td><?php if (!empty($meta['sensitive'])): ?><span style="color:#dc2626;font-weight:600">● sensible</span><?php endif; ?></td>
                        <td><?= $permCount ?></td>
                        <td><?= (int) ($holders[$rk] ?? 0) ?></td>
                        <td><a class="btn btn-sm btn-secondary" href="show.php?role=<?= urlencode($rk) ?>">Détail</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
