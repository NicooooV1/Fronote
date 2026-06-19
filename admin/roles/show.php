<?php
/**
 * Détail d'un rôle du catalogue : périmètre, permissions accordées (catalogue +
 * surcharges DB rbac_permissions), et titulaires. Lecture seule.
 */
require_once __DIR__ . '/../../API/core.php';
require_once __DIR__ . '/../includes/admin_functions.php';

requireAuth();
requireRole('administrateur', 'super_admin');

use API\Security\RoleCatalog;

$pdo     = getPDO();
$roleKey = (string) ($_GET['role'] ?? '');
$catalog = RoleCatalog::roles();
$meta    = $catalog[$roleKey] ?? null;

if ($meta === null) {
    $pageTitle = 'Rôle introuvable';
    include __DIR__ . '/../includes/header.php';
    echo '<div class="admin-container"><div class="alert alert-danger">Rôle inconnu : ' . htmlspecialchars($roleKey) . '</div>'
       . '<a class="btn btn-secondary" href="index.php">← Catalogue</a></div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$grants   = RoleCatalog::grantsFor($roleKey);                  // brut (peut contenir des wildcards)
$expanded = RoleCatalog::permissionsFor($roleKey);            // étendu (wildcards résolus)
$allPerms = RoleCatalog::permissions();
$hasStar  = in_array('*', $grants, true);

// Surcharges en base (matrice éditable) pour ce rôle.
$overrides = [];
try {
    $st = $pdo->prepare("SELECT permission, granted FROM rbac_permissions WHERE role = ?");
    $st->execute([$roleKey]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) { $overrides[$o['permission']] = (int) $o['granted']; }
} catch (\Throwable $e) { /* table absente */ }

// Titulaires (attributions).
$holders = [];
try {
    $st = $pdo->prepare("SELECT user_type, user_id, etablissement_id, scope_type, valid_until FROM user_roles WHERE role_key = ? ORDER BY user_type, user_id");
    $st->execute([$roleKey]);
    $holders = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { /* table absente */ }

$pageTitle = 'Rôle : ' . ($meta['label'] ?? $roleKey);
include __DIR__ . '/../includes/header.php';
?>
<div class="admin-container">
    <div class="page-header">
        <h1><i class="fas fa-id-badge"></i> <?= htmlspecialchars($meta['label'] ?? $roleKey) ?></h1>
        <a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Catalogue</a>
    </div>

    <div class="card">
        <div class="card-body">
            <p><strong>Clé :</strong> <code><?= htmlspecialchars($roleKey) ?></code>
               &nbsp;·&nbsp; <strong>Tier :</strong> <?= htmlspecialchars($meta['tier'] ?? '—') ?>
               &nbsp;·&nbsp; <strong>Périmètre par défaut :</strong> <?= htmlspecialchars($meta['scope'] ?? '—') ?>
               <?php if (!empty($meta['sensitive'])): ?>&nbsp;·&nbsp; <span style="color:#dc2626;font-weight:600">● rôle sensible (justification requise)</span><?php endif; ?>
            </p>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="card-header"><h2>Permissions <small><?= $hasStar ? '(accès total *)' : '(' . count($expanded) . ')' ?></small></h2></div>
        <div class="card-body" style="overflow-x:auto">
            <?php if ($hasStar): ?>
                <p>Ce rôle détient le wildcard <code>*</code> : <strong>toutes</strong> les permissions.</p>
            <?php else: ?>
                <table class="table">
                    <thead><tr><th>Permission</th><th>Domaine</th><th>Sensible</th><th>Source</th></tr></thead>
                    <tbody>
                    <?php foreach ($expanded as $p):
                        $pm = $allPerms[$p] ?? [];
                        $src = isset($overrides[$p]) ? ($overrides[$p] === 1 ? 'surcharge DB (accordée)' : 'surcharge DB (refusée)') : 'catalogue';
                        if (isset($overrides[$p]) && $overrides[$p] === 0) continue; // refusée en base
                    ?>
                        <tr>
                            <td><code><?= htmlspecialchars($p) ?></code></td>
                            <td><?= htmlspecialchars(explode('.', $p)[0]) ?></td>
                            <td><?= RoleCatalog::isSensitive($p) ? '<span style="color:#dc2626">●</span>' : '' ?></td>
                            <td><?= htmlspecialchars($src) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="card-header"><h2>Titulaires <small>(<?= count($holders) ?> attribution<?= count($holders) > 1 ? 's' : '' ?>)</small></h2></div>
        <div class="card-body" style="overflow-x:auto">
            <table class="table">
                <thead><tr><th>Compte</th><th>Établissement</th><th>Périmètre</th><th>Expire</th></tr></thead>
                <tbody>
                <?php if (!$holders): ?><tr><td colspan="4"><em>Aucune attribution explicite.</em></td></tr><?php endif; ?>
                <?php foreach ($holders as $h): ?>
                    <tr>
                        <td><?= htmlspecialchars($h['user_type']) ?> #<?= (int) $h['user_id'] ?></td>
                        <td><?= $h['etablissement_id'] !== null ? (int) $h['etablissement_id'] : '<em>tous</em>' ?></td>
                        <td><?= htmlspecialchars($h['scope_type'] ?? '') ?></td>
                        <td><?= $h['valid_until'] ? htmlspecialchars($h['valid_until']) : '<em>permanent</em>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
