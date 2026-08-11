<?php
declare(strict_types=1);
/**
 * Administration — Permissions par RÔLE (VUE EN LECTURE SEULE).
 *
 * La configuration de « ce qu'un rôle peut faire » (matrice rôle → permissions) est
 * désormais GÉRÉE AU NIVEAU DE LA PLATEFORME et n'est plus modifiable par les
 * directions d'établissement. Cette page ne fait donc QU'AFFICHER, à titre de
 * transparence, les permissions effectives de chaque rôle telles que définies par le
 * catalogue central (RoleCatalog). Elle n'écrit plus rien (ni rbac_permissions, ni
 * rbac_grants) : aucun traitement POST, aucune synchronisation.
 *
 * L'ATTRIBUTION d'un rôle à un utilisateur reste, elle, du ressort de la direction :
 * voir users/roles.php.
 */
require_once __DIR__ . '/../../API/core.php';
require_once __DIR__ . '/../includes/admin_functions.php';

requireAuth();
// Page en LECTURE SEULE : consultation des permissions de rôle. On conserve le même
// public que la page utilisateurs (direction d'établissement) — plus aucune écriture.
tenantGate('tenant.users.manage', ['administrateur', 'super_admin']);

use API\Security\RoleCatalog;

$rolesMeta = class_exists(RoleCatalog::class) ? RoleCatalog::roles() : [];
$permsMeta = class_exists(RoleCatalog::class) ? RoleCatalog::permissions() : [];

// Couleurs par tier (badges type Discord). Utilise le helper central du catalogue s'il
// existe (design partagé), sinon repli sur une table locale couvrant tous les tiers.
$tierColorLocal = [
    'plateforme'   => '#7c3aed',
    'direction'    => '#2563eb',
    'administratif'=> '#0891b2',
    'vie_scolaire' => '#16a34a',
    'pedagogique'  => '#ca8a04',
    'enseignant'   => '#ea580c',
    'sante_social' => '#dc2626',
    'eleve_famille'=> '#db2777',
    'famille'      => '#db2777',
    'eleve'        => '#64748b',
    'organisation' => '#0d9488',
    'communication'=> '#4f46e5',
    'documents'    => '#b45309',
    'services'     => '#65a30d',
    'stages'       => '#c026d3',
    'controle'     => '#475569',
    'systeme'      => '#334155',
];
$tierColor = static function (string $tier) use ($tierColorLocal): string {
    if (method_exists(RoleCatalog::class, 'tierColor')) {
        $c = (string) RoleCatalog::tierColor($tier);
        if ($c !== '') return $c;
    }
    return $tierColorLocal[$tier] ?? '#64748b';
};
$roleDescription = static function (string $roleKey, array $meta): string {
    foreach (['roleDescription', 'description', 'descriptionFor'] as $m) {
        if (method_exists(RoleCatalog::class, $m)) {
            $d = (string) RoleCatalog::$m($roleKey);
            if ($d !== '') return $d;
        }
    }
    return (string) ($meta['description'] ?? '');
};

// Rôle sélectionné (GET uniquement — aucune mutation).
$selectedRole = $_GET['role'] ?? 'professeur';
if (!isset($rolesMeta[$selectedRole])) {
    $selectedRole = $rolesMeta ? array_key_first($rolesMeta) : 'professeur';
}
$selMeta  = $rolesMeta[$selectedRole] ?? [];
$selTier  = (string) ($selMeta['tier'] ?? '');
$selColor = $tierColor($selTier);

// Permissions EFFECTIVES du rôle = catalogue (source de vérité, wildcards développés)
// AJUSTÉ par les surcharges GLOBALES posées au niveau PLATEFORME (rbac_grants). L'établissement
// voit ici, en LECTURE SEULE, ce que la plateforme a réellement configuré pour ce rôle
// (accord/refus) — c'est le déploiement de la gouvernance plateforme côté établissement.
$effective = class_exists(RoleCatalog::class) ? RoleCatalog::permissionsFor($selectedRole) : [];
$effSet    = array_fill_keys($effective, true);
try {
    $canon = class_exists(RoleCatalog::class) ? RoleCatalog::canonical($selectedRole) : $selectedRole;
    $st = getPDO()->prepare("SELECT permission, MIN(granted) AS g FROM rbac_grants WHERE role IN (?, ?) GROUP BY permission");
    $st->execute([$selectedRole, $canon]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((int) $row['g'] === 1) { $effSet[$row['permission']] = true; } // accord plateforme
        else { unset($effSet[$row['permission']]); }                       // refus plateforme
    }
} catch (\Throwable $e) { /* table rbac_grants absente → catalogue seul */ }
$isGranted = static function (string $pk) use ($effSet): bool { return isset($effSet[$pk]); };

// Permissions groupées par catégorie (domaine).
$byCat = [];
foreach ($permsMeta as $pk => $meta) { $byCat[$meta['category'] ?? 'autre'][$pk] = $meta; }
ksort($byCat);

// Nombre de permissions accordées (pour le récap).
$grantedCount = 0;
foreach ($permsMeta as $pk => $meta) { if ($isGranted($pk)) $grantedCount++; }

// Rôles groupés par tier pour le sélecteur.
$rolesByTier = [];
foreach ($rolesMeta as $rk => $rm) { $rolesByTier[$rm['tier'] ?? 'autre'][$rk] = $rm; }

$pageTitle = 'Permissions par rôle (lecture seule)';
include __DIR__ . '/../includes/header.php';
?>
<div class="admin-container">
    <div class="page-header">
        <h1><i class="fas fa-user-lock"></i> Permissions par rôle</h1>
        <div>
            <a href="../users/roles.php" class="btn btn-secondary"><i class="fas fa-user-tag"></i> Attribuer des rôles</a>
            <a href="permissions.php" class="btn btn-secondary"><i class="fas fa-table"></i> Permissions par module (lecture seule)</a>
        </div>
    </div>

    <div class="alert alert-info" style="display:flex;gap:10px;align-items:flex-start">
        <i class="fas fa-info-circle" style="margin-top:2px"></i>
        <div>
            <strong>Les permissions des rôles sont gérées au niveau de la plateforme.</strong><br>
            Cette page présente, à titre de transparence, ce que chaque rôle peut faire.
            L'<em>attribution</em> d'un rôle à un utilisateur reste possible depuis
            « <a href="../users/roles.php">Attribuer des rôles</a> ».
        </div>
    </div>

    <form method="get" style="margin-bottom:16px;display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
        <div class="form-group">
            <label>Rôle</label>
            <select name="role" class="form-control" data-fr-change="submitOwn">
                <?php foreach ($rolesByTier as $tier => $rlist): ?>
                    <optgroup label="<?= htmlspecialchars(ucfirst(str_replace('_', ' ', $tier))) ?>">
                        <?php foreach ($rlist as $rk => $rm): ?>
                            <option value="<?= htmlspecialchars($rk) ?>" <?= $rk === $selectedRole ? 'selected' : '' ?>>
                                <?= htmlspecialchars($rm['label'] ?? $rk) ?><?= !empty($rm['sensitive']) ? ' ⚠️' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <noscript><button class="btn btn-primary" type="submit">Afficher</button></noscript>
    </form>

    <?php if ($selMeta): ?>
    <div class="card" style="margin-bottom:16px">
        <div class="card-body" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
            <span style="display:inline-flex;align-items:center;gap:8px;padding:4px 12px;border-radius:999px;background:<?= htmlspecialchars($selColor) ?>;color:#fff;font-weight:600;font-size:.9rem">
                <i class="fas fa-user-shield"></i> <?= htmlspecialchars($selMeta['label'] ?? $selectedRole) ?>
            </span>
            <span style="color:var(--text-muted,#64748b);font-size:.85rem">
                <i class="fas fa-layer-group"></i> <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $selTier))) ?>
                &nbsp;·&nbsp; <i class="fas fa-street-view"></i> périmètre : <?= htmlspecialchars($selMeta['scope'] ?? '—') ?>
                <?php if (!empty($selMeta['sensitive'])): ?>&nbsp;·&nbsp; <span style="color:#b91c1c"><i class="fas fa-triangle-exclamation"></i> rôle sensible</span><?php endif; ?>
            </span>
            <span style="margin-left:auto;color:var(--text-muted,#64748b);font-size:.85rem">
                <?php if (in_array('*', class_exists(RoleCatalog::class) ? RoleCatalog::grantsFor($selectedRole) : [], true)): ?>
                    <strong>Accès total</strong> (toutes les permissions)
                <?php else: ?>
                    <strong><?= (int) $grantedCount ?></strong> permission(s) accordée(s)
                <?php endif; ?>
            </span>
            <?php $desc = $roleDescription($selectedRole, $selMeta); if ($desc !== ''): ?>
                <div style="flex-basis:100%;color:var(--text-muted,#64748b);font-size:.85rem"><?= htmlspecialchars($desc) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($selectedRole === 'super_admin'): ?>
        <div class="alert alert-warning"><strong>Super-administrateur</strong> : accès total à toute la plateforme.</div>
    <?php endif; ?>

    <?php foreach ($byCat as $cat => $perms): ?>
    <div class="card" style="margin-bottom:12px">
        <div class="card-header">
            <h3 style="margin:0;text-transform:capitalize"><?= htmlspecialchars($cat) ?></h3>
        </div>
        <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:6px">
            <?php foreach ($perms as $pk => $meta): $g = $isGranted($pk); ?>
                <div style="display:flex;gap:8px;align-items:center;<?= $g ? '' : 'opacity:.45' ?>">
                    <?php if ($g): ?>
                        <i class="fas fa-check-circle" style="color:#16a34a" aria-hidden="true"></i>
                    <?php else: ?>
                        <i class="far fa-circle" style="color:#94a3b8" aria-hidden="true"></i>
                    <?php endif; ?>
                    <span title="<?= htmlspecialchars($pk) ?>" style="<?= !empty($meta['sensitive']) ? 'color:#b91c1c' : '' ?>">
                        <?= htmlspecialchars($meta['label'] ?? $pk) ?><?= !empty($meta['sensitive']) ? ' 🔒' : '' ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (!$byCat): ?>
        <div class="alert alert-warning">Aucune permission au catalogue (RoleCatalog introuvable ?).</div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
