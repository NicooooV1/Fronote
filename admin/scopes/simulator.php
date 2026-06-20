<?php
/**
 * Simulateur de permissions — « qu'est-ce que X peut faire, et sur quoi ? »
 *
 * Évalue, pour un utilisateur arbitraire, le moteur d'autorisation RÉEL
 * (Authorization + ScopeResolver) : rôles effectifs, octroi de la permission par
 * rôle, décision de périmètre (avec/sans ressource), et relations (enfants/élèves
 * suivis/classes enseignées). Lecture seule. Réservé administration / super-admin.
 */
require_once __DIR__ . '/../../API/core.php';
require_once __DIR__ . '/../includes/admin_functions.php';

requireAuth();
tenantGate('tenant.users.view', ['administrateur', 'super_admin']);

use API\Security\Authorization;
use API\Security\ScopeResolver;
use API\Security\RoleCatalog;

$pdo = getPDO();

$accountTypes = ['eleve', 'parent', 'professeur', 'vie_scolaire', 'administrateur', 'super_admin', 'technicien'];
$resourceTypes = ['', 'student', 'class', 'establishment', 'subject'];
$allPerms = RoleCatalog::permissions();
ksort($allPerms);

$uType   = $_GET['ut'] ?? '';
$uId     = (int) ($_GET['uid'] ?? 0);
$perm    = (string) ($_GET['perm'] ?? '');
$resType = (string) ($_GET['rtype'] ?? '');
$resId   = (int) ($_GET['rid'] ?? 0);

/** Reconstruit le tableau utilisateur (id/type/etablissement_id) depuis sa table de compte. */
function simLookupUser(PDO $pdo, string $type, int $id): ?array
{
    $map = [
        'eleve' => 'eleves', 'parent' => 'parents', 'professeur' => 'professeurs',
        'vie_scolaire' => 'vie_scolaire', 'administrateur' => 'administrateurs',
        'super_admin' => 'super_admins', 'technicien' => 'technicien_access',
    ];
    if (!isset($map[$type]) || $id <= 0) return null;
    try {
        $st = $pdo->prepare("SELECT * FROM `{$map[$type]}` WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return [
            'id'   => $id,
            'type' => $type,
            'etablissement_id' => isset($row['etablissement_id']) ? (int) $row['etablissement_id'] : null,
        ];
    } catch (\Throwable $e) { return null; }
}

/** Le rôle accorde-t-il la permission (catalogue + surcharge DB) ? — pour expliquer le « pourquoi ». */
function simRoleGrants(PDO $pdo, string $role, string $permission): bool
{
    try {
        $st = $pdo->prepare("SELECT granted FROM rbac_permissions WHERE role = ? AND permission = ? LIMIT 1");
        $st->execute([$role, $permission]);
        $g = $st->fetchColumn();
        if ($g !== false) return (int) $g === 1;
    } catch (\Throwable $e) { /* fallback catalogue */ }
    $grants = RoleCatalog::grantsFor($role);
    if (in_array('*', $grants, true) || in_array($permission, $grants, true)) return true;
    return in_array(explode('.', $permission)[0] . '.*', $grants, true);
}

$user = ($uType && $uId > 0) ? simLookupUser($pdo, $uType, $uId) : null;
$ran  = ($user !== null && $perm !== '');

$pageTitle = 'Simulateur de permissions';
include __DIR__ . '/../includes/header.php';
?>
<div class="admin-container">
    <div class="page-header">
        <h1><i class="fas fa-vial"></i> Simulateur de permissions</h1>
        <a href="../roles/index.php" class="btn btn-secondary"><i class="fas fa-id-badge"></i> Catalogue des rôles</a>
    </div>
    <p class="text-muted">Évalue le moteur d'autorisation réel pour un utilisateur donné — sans rien modifier.</p>

    <div class="card">
        <div class="card-body">
            <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
                <div class="form-group">
                    <label>Type de compte</label>
                    <select name="ut" class="form-control">
                        <?php foreach ($accountTypes as $t): ?>
                            <option value="<?= $t ?>" <?= $uType === $t ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>ID utilisateur</label>
                    <input type="number" name="uid" class="form-control" min="1" value="<?= $uId ?: '' ?>">
                </div>
                <div class="form-group">
                    <label>Permission</label>
                    <select name="perm" class="form-control">
                        <option value="">— choisir —</option>
                        <?php foreach ($allPerms as $pk => $pm): ?>
                            <option value="<?= htmlspecialchars($pk) ?>" <?= $perm === $pk ? 'selected' : '' ?>><?= htmlspecialchars($pk) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Ressource (optionnel)</label>
                    <select name="rtype" class="form-control">
                        <?php foreach ($resourceTypes as $rt): ?>
                            <option value="<?= $rt ?>" <?= $resType === $rt ? 'selected' : '' ?>><?= $rt === '' ? '— aucune —' : $rt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>ID ressource</label>
                    <input type="number" name="rid" class="form-control" min="1" value="<?= $resId ?: '' ?>">
                </div>
                <button class="btn btn-primary" type="submit"><i class="fas fa-play"></i> Évaluer</button>
            </form>
        </div>
    </div>

    <?php if ($uType && $uId > 0 && $user === null): ?>
        <div class="alert alert-danger" style="margin-top:16px">Utilisateur <?= htmlspecialchars($uType) ?> #<?= $uId ?> introuvable.</div>
    <?php endif; ?>

    <?php if ($ran):
        $authz = new Authorization($pdo, $user);
        $roles = $authz->roles();
        $globalCan = $authz->can($perm);
        $resourceCan = null;
        if ($resType !== '' && $resId > 0) {
            $resourceCan = $authz->canOn($perm, $resType, $resId);
        }
        $resolver = new ScopeResolver($pdo, $user);
    ?>
    <div class="card" style="margin-top:16px">
        <div class="card-header"><h2>Résultat</h2></div>
        <div class="card-body">
            <p style="font-size:1.1em">
                <strong><?= htmlspecialchars($uType) ?> #<?= $uId ?></strong> · permission <code><?= htmlspecialchars($perm) ?></code>
                <?php if (RoleCatalog::isSensitive($perm)): ?> <span style="color:#dc2626">(sensible)</span><?php endif; ?>
            </p>
            <p style="font-size:1.2em">
                Sans périmètre précis :
                <?php if ($globalCan): ?><span style="color:#16a34a;font-weight:700">✔ AUTORISÉ</span>
                <?php else: ?><span style="color:#dc2626;font-weight:700">✘ REFUSÉ</span><?php endif; ?>
            </p>
            <?php if ($resourceCan !== null): ?>
            <p style="font-size:1.2em">
                Sur <?= htmlspecialchars($resType) ?> #<?= $resId ?> :
                <?php if ($resourceCan): ?><span style="color:#16a34a;font-weight:700">✔ AUTORISÉ</span>
                <?php else: ?><span style="color:#dc2626;font-weight:700">✘ REFUSÉ</span><?php endif; ?>
            </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="card-header"><h2>Rôles effectifs &amp; octroi</h2></div>
        <div class="card-body" style="overflow-x:auto">
            <table class="table">
                <thead><tr><th>Rôle</th><th>Périmètre</th><th>Établissement</th><th>Détail scope</th><th>Accorde <code><?= htmlspecialchars($perm) ?></code> ?</th></tr></thead>
                <tbody>
                <?php foreach ($roles as $r):
                    $grantsThis = $authz->isSuperAdmin() || simRoleGrants($pdo, $r['role'], $perm);
                ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($r['role']) ?></strong></td>
                        <td><?= htmlspecialchars($r['scope_type']) ?></td>
                        <td><?= $r['etab'] !== null ? (int) $r['etab'] : '<em>tous</em>' ?></td>
                        <td><?= $r['scope'] ? '<code>' . htmlspecialchars(json_encode($r['scope'], JSON_UNESCAPED_UNICODE)) . '</code>' : '—' ?></td>
                        <td><?= $grantsThis ? '<span style="color:#16a34a">✔</span>' : '<span style="color:#dc2626">✘</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="card-header"><h2>Périmètre résolu (relations)</h2></div>
        <div class="card-body">
            <ul>
                <li><strong>Enfants / élèves sous responsabilité :</strong>
                    <?= ($ids = $resolver->guardianStudentIds()) ? implode(', ', $ids) : '<em>aucun</em>' ?></li>
                <li><strong>Élèves suivis/assignés (AESH/psy/médical…) :</strong>
                    <?= ($ids = $resolver->assignedStudentIds()) ? implode(', ', $ids) : '<em>aucun</em>' ?></li>
                <li><strong>Classes enseignées :</strong>
                    <?= ($ids = $resolver->taughtClassIds()) ? implode(', ', $ids) : '<em>aucune</em>' ?></li>
            </ul>
            <p class="text-muted">Ces ensembles alimentent les périmètres <code>children</code>, <code>assigned</code> et <code>own_classes</code>.</p>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
