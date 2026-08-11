<?php
declare(strict_types=1);
/**
 * Administration — Permissions par module et par rôle (VUE EN LECTURE SEULE).
 *
 * La configuration des permissions est désormais GÉRÉE AU NIVEAU DE LA PLATEFORME et
 * n'est plus modifiable par les directions d'établissement. Cette page n'affiche donc
 * plus qu'une matrice module × rôle en consultation : aucun traitement POST, aucune
 * écriture dans module_permissions (ni enregistrement, ni réinitialisation).
 */
require_once __DIR__ . '/../../API/core.php';
require_once __DIR__ . '/../includes/admin_functions.php';

requireAuth();
// Lecture seule : consultation de la matrice de permissions. Même public que la page
// utilisateurs (direction d'établissement) — plus aucune écriture.
tenantGate('tenant.users.manage', ['administrateur', 'super_admin']);

$pdo = getPDO();
$moduleService = app('modules');

// ─── Roles ──────────────────────────────────────────────────────────────────
$roles = [
    'administrateur' => 'Administrateur',
    'professeur'     => 'Professeur',
    'vie_scolaire'   => 'Vie scolaire',
    'eleve'          => 'Eleve',
    'parent'         => 'Parent',
];

// ─── Permissions standard & custom par module ────────────────────────────────
$standardPerms = [
    'can_view'   => ['label' => 'Voir',      'icon' => 'fas fa-eye'],
    'can_create' => ['label' => 'Creer',     'icon' => 'fas fa-plus'],
    'can_edit'   => ['label' => 'Modifier',   'icon' => 'fas fa-pencil-alt'],
    'can_delete' => ['label' => 'Supprimer',  'icon' => 'fas fa-trash-alt'],
    'can_export' => ['label' => 'Exporter',   'icon' => 'fas fa-file-export'],
    'can_import' => ['label' => 'Importer',   'icon' => 'fas fa-file-import'],
];

$customPermsByModule = [
    'messagerie' => [
        'can_send'     => ['label' => 'Envoyer',  'icon' => 'fas fa-paper-plane'],
        'can_moderate' => ['label' => 'Moderer',  'icon' => 'fas fa-gavel'],
        'can_broadcast'=> ['label' => 'Diffuser', 'icon' => 'fas fa-bullhorn'],
    ],
];

// ─── Chargement des donnees (lecture seule) ──────────────────────────────────
$categories = $moduleService->getByCategory();
$categoryLabels = \API\Services\ModuleService::categoryLabels();

// Charger toutes les permissions existantes
$permStmt = $pdo->query("SELECT * FROM module_permissions");
$permRows = $permStmt->fetchAll(PDO::FETCH_ASSOC);

$permissions = [];
foreach ($permRows as $row) {
    $mk = $row['module_key'];
    $rl = $row['role'];
    $permissions[$mk][$rl] = $row;
    if (!empty($row['custom_permissions'])) {
        $permissions[$mk][$rl]['custom'] = json_decode($row['custom_permissions'], true);
    }
}

/**
 * Helper: verifier si une permission est activee
 */
function permChecked(array $permissions, string $moduleKey, string $role, string $perm): bool {
    if (isset($permissions[$moduleKey][$role])) {
        $row = $permissions[$moduleKey][$role];
        // Standard permission columns
        if (isset($row[$perm])) {
            return (bool)$row[$perm];
        }
        // Custom permissions from JSON
        if (isset($row['custom'][$perm])) {
            return (bool)$row['custom'][$perm];
        }
    }
    return false;
}

// ─── Page ────────────────────────────────────────────────────────────────────
$pageTitle = 'Permissions des modules (lecture seule)';
$currentPage = 'modules';
$pageBack = 'admin/modules/index.php';
$extraCss = ['../../assets/css/admin.css'];

include __DIR__ . '/../includes/header.php';
?>

<style>
/* ─── Permissions page styles ─── */
.perm-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px}
.perm-header h2{margin:0;font-size:1.2em;color:#2d3748;display:flex;align-items:center;gap:8px}
.perm-header-actions{display:flex;gap:10px;flex-wrap:wrap}

.perm-category{margin-bottom:32px}
.perm-category-title{font-size:1.05em;font-weight:700;color:#4a5568;margin-bottom:14px;padding-bottom:8px;border-bottom:2px solid #e2e8f0;display:flex;align-items:center;gap:8px}
.perm-category-title i{color:#667eea}

.perm-table-wrap{overflow-x:auto;border:1px solid #e2e8f0;border-radius:8px;background:#fff;margin-bottom:16px}
.perm-table{width:100%;border-collapse:collapse;font-size:.88em}
.perm-table thead{background:#f7fafc;position:sticky;top:0;z-index:10}
.perm-table thead th{padding:12px 10px;text-align:center;font-weight:600;color:#4a5568;border-bottom:2px solid #e2e8f0;white-space:nowrap}
.perm-table thead th:first-child{text-align:left;min-width:180px;padding-left:16px}
.perm-table thead th.role-col{min-width:120px}
.perm-table tbody tr{border-bottom:1px solid #edf2f7;transition:background .15s}
.perm-table tbody tr:hover{background:#f8faff}
.perm-table tbody tr:last-child{border-bottom:none}
.perm-table tbody td{padding:10px;text-align:center;vertical-align:top}
.perm-table tbody td:first-child{text-align:left;padding-left:16px;font-weight:500;color:#2d3748}

.module-cell{display:flex;align-items:center;gap:10px}
.module-cell-icon{width:32px;height:32px;border-radius:6px;background:#f0f4ff;display:flex;align-items:center;justify-content:center;font-size:.9em;color:#667eea;flex-shrink:0}
.module-cell-info{display:flex;flex-direction:column}
.module-cell-name{font-weight:600;font-size:.92em;color:#2d3748}
.module-cell-key{font-size:.75em;color:#a0aec0;font-family:monospace}

.perm-checks{display:flex;flex-direction:column;gap:3px;align-items:flex-start}
.perm-check{display:flex;align-items:center;gap:5px;font-size:.82em;color:#4a5568;padding:2px 4px;border-radius:4px;white-space:nowrap}
.perm-check input[type="checkbox"]{margin:0;accent-color:#667eea;width:14px;height:14px}
.perm-check i{font-size:.75em;color:#a0aec0;width:14px;text-align:center}
.perm-check.custom-perm{color:#667eea;font-weight:500}
.perm-check.custom-perm i{color:#667eea}
.perm-check.is-off{opacity:.4}

.perm-divider{border-top:1px dashed #e2e8f0;margin:3px 0;width:100%}

.role-header{display:flex;flex-direction:column;align-items:center;gap:2px}
.role-header-name{font-weight:700;font-size:.9em}
.role-header-badge{font-size:.7em;padding:1px 8px;border-radius:8px;font-weight:500}
.role-badge-administrateur{background:#ebf4ff;color:#3182ce}
.role-badge-professeur{background:#fefcbf;color:#975a16}
.role-badge-vie_scolaire{background:#e9d8fd;color:#6b46c1}
.role-badge-eleve{background:#c6f6d5;color:#276749}
.role-badge-parent{background:#fed7d7;color:#9b2c2c}

.btn-back{padding:10px 24px;background:#edf2f7;color:#4a5568;border:none;border-radius:6px;font-weight:600;cursor:pointer;text-decoration:none;font-size:.92em;display:inline-flex;align-items:center;gap:8px}
.btn-back:hover{background:#e2e8f0}

@media(max-width:900px){
    .perm-table{font-size:.8em}
    .perm-table thead th,.perm-table tbody td{padding:8px 6px}
    .module-cell-icon{display:none}
    .perm-header{flex-direction:column;align-items:flex-start}
}
</style>

<div class="alert alert-info" style="display:flex;gap:10px;align-items:flex-start;margin-bottom:16px">
    <i class="fas fa-info-circle" style="margin-top:2px"></i>
    <div>
        <strong>Les permissions des rôles sont gérées au niveau de la plateforme.</strong><br>
        Cette matrice est affichée en lecture seule, à titre de transparence.
        Pour consulter les permissions effectives par rôle, voir
        « <a href="role_permissions.php">Permissions par rôle</a> ».
    </div>
</div>

<div class="perm-header">
    <h2><i class="fas fa-shield-alt" style="color:#667eea"></i> Matrice des permissions <span style="font-size:.7em;font-weight:500;color:#a0aec0">(lecture seule)</span></h2>
    <div class="perm-header-actions">
        <a href="role_permissions.php" class="btn-back"><i class="fas fa-user-lock"></i> Permissions par rôle</a>
        <a href="index.php" class="btn-back"><i class="fas fa-arrow-left"></i> Modules</a>
    </div>
</div>

<?php foreach ($categories as $catKey => $modules): ?>
<div class="perm-category">
    <div class="perm-category-title">
        <i class="fas fa-folder"></i>
        <?= htmlspecialchars($categoryLabels[$catKey] ?? ucfirst($catKey)) ?>
        <span style="font-size:.78em;font-weight:400;color:#a0aec0">(<?= count($modules) ?> modules)</span>
    </div>

    <div class="perm-table-wrap">
        <table class="perm-table">
            <thead>
                <tr>
                    <th>Module</th>
                    <?php foreach ($roles as $roleKey => $roleLabel): ?>
                    <th class="role-col">
                        <div class="role-header">
                            <span class="role-header-name"><?= htmlspecialchars($roleLabel) ?></span>
                            <span class="role-header-badge role-badge-<?= $roleKey ?>"><?= $roleKey ?></span>
                        </div>
                    </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($modules as $mod):
                    $mk = $mod['module_key'];
                    $hasCustom = isset($customPermsByModule[$mk]);
                ?>
                <tr>
                    <td>
                        <div class="module-cell">
                            <div class="module-cell-icon">
                                <i class="<?= htmlspecialchars($mod['icon']) ?>"></i>
                            </div>
                            <div class="module-cell-info">
                                <span class="module-cell-name"><?= htmlspecialchars($mod['label']) ?></span>
                                <span class="module-cell-key"><?= htmlspecialchars($mk) ?></span>
                            </div>
                        </div>
                    </td>
                    <?php foreach ($roles as $roleKey => $roleLabel): ?>
                    <td>
                        <div class="perm-checks">
                            <?php foreach ($standardPerms as $permKey => $permMeta): $on = permChecked($permissions, $mk, $roleKey, $permKey); ?>
                            <span class="perm-check<?= $on ? '' : ' is-off' ?>">
                                <input type="checkbox" <?= $on ? 'checked' : '' ?> disabled>
                                <i class="<?= $permMeta['icon'] ?>"></i>
                                <?= $permMeta['label'] ?>
                            </span>
                            <?php endforeach; ?>

                            <?php if ($hasCustom): ?>
                            <div class="perm-divider"></div>
                            <?php foreach ($customPermsByModule[$mk] as $cpKey => $cpMeta): $on = permChecked($permissions, $mk, $roleKey, $cpKey); ?>
                            <span class="perm-check custom-perm<?= $on ? '' : ' is-off' ?>">
                                <input type="checkbox" <?= $on ? 'checked' : '' ?> disabled>
                                <i class="<?= $cpMeta['icon'] ?>"></i>
                                <?= $cpMeta['label'] ?>
                            </span>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<div style="margin-top:20px">
    <a href="index.php" class="btn-back"><i class="fas fa-arrow-left"></i> Retour aux modules</a>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
