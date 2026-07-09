<?php
/**
 * Administration — Permissions par RÔLE (RBAC/ABAC).
 *
 * Édite la matrice rôle → permissions (table rbac_permissions), que le moteur
 * Authorization::can() lit EN PRIORITÉ (override du catalogue RoleCatalog).
 * Donc cocher/décocher ici change réellement ce que chaque rôle peut faire.
 *
 * Le périmètre (sa classe / ses enfants / ses élèves assignés) est appliqué par
 * Authorization selon le scope du rôle ; ici on gère le QUOI (permissions), pas le
 * périmètre. L'attribution d'un rôle à un utilisateur se fait dans users/roles.php.
 */
require_once __DIR__ . '/../../API/core.php';
require_once __DIR__ . '/../includes/admin_functions.php';

requireAuth();
tenantGate('tenant.users.view', ['administrateur', 'super_admin']);

use API\Security\RoleCatalog;
use API\Security\RoleSync;

$pdo = getPDO();

// Catalogue (source des rôles + permissions + défauts).
$rolesMeta = class_exists(RoleCatalog::class) ? RoleCatalog::roles() : [];
$permsMeta = class_exists(RoleCatalog::class) ? RoleCatalog::permissions() : [];

// Auto-synchronisation catalogue -> base au premier accès (une fois), pour que la
// matrice et la liste des rôles existent en base sans dépendre du bouton « Mettre à jour ».
$syncNote = '';
try {
    $hasRoles = (int) $pdo->query("SELECT COUNT(*) FROM rbac_roles")->fetchColumn();
    if ($hasRoles === 0 && class_exists(RoleSync::class)) {
        $r = (new RoleSync($pdo))->sync();
        $syncNote = "Catalogue synchronisé : {$r['roles']} rôle(s), {$r['grants']} permission(s).";
    }
} catch (\Throwable $e) {
    $syncNote = "Synchronisation impossible (tables RBAC absentes ?) : " . $e->getMessage();
}

$message = '';
$messageType = 'success';
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Rôle sélectionné.
$selectedRole = $_POST['role'] ?? $_GET['role'] ?? 'professeur';
if (!isset($rolesMeta[$selectedRole])) {
    $selectedRole = $rolesMeta ? array_key_first($rolesMeta) : 'professeur';
}

// Sauvegarde des permissions du rôle sélectionné.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
    $role = $_POST['role'] ?? '';
    if (!isset($rolesMeta[$role])) {
        $message = 'Rôle inconnu.'; $messageType = 'danger';
    } elseif ($role === 'super_admin') {
        $message = 'Le super-administrateur a un accès total non modifiable.'; $messageType = 'danger';
    } else {
        $checked = $_POST['perm'] ?? [];
        try {
            $up = $pdo->prepare(
                "INSERT INTO rbac_permissions (role, permission, granted) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE granted = VALUES(granted), updated_at = NOW()"
            );
            foreach (array_keys($permsMeta) as $pk) {
                $up->execute([$role, $pk, isset($checked[$pk]) ? 1 : 0]);
            }
            try { app('audit')->logAuth('rbac.permissions.update', $role, true, ['count' => count($permsMeta)]); } catch (\Throwable $e) {}
            $message = 'Permissions enregistrées pour « ' . ($rolesMeta[$role]['label'] ?? $role) . ' ».';
            $selectedRole = $role;
        } catch (\Throwable $e) {
            $message = 'Erreur lors de l\'enregistrement : ' . $e->getMessage(); $messageType = 'danger';
        }
    }
}

// État courant des grants du rôle sélectionné (DB override sinon défaut catalogue).
$dbGrants = [];
try {
    $st = $pdo->prepare("SELECT permission, granted FROM rbac_permissions WHERE role = ?");
    $st->execute([$selectedRole]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $dbGrants[$r['permission']] = (int) $r['granted']; }
} catch (\Throwable $e) { /* table absente → défauts catalogue */ }

$catGrants  = class_exists(RoleCatalog::class) ? RoleCatalog::grantsFor($selectedRole) : [];
$isWildcard = in_array('*', $catGrants, true);
$isGranted = function (string $pk) use ($dbGrants, $catGrants, $isWildcard): bool {
    if (array_key_exists($pk, $dbGrants)) return $dbGrants[$pk] === 1;
    if ($isWildcard) return true;
    if (in_array($pk, $catGrants, true)) return true;
    return in_array(explode('.', $pk)[0] . '.*', $catGrants, true);
};

// Permissions groupées par catégorie (domaine).
$byCat = [];
foreach ($permsMeta as $pk => $meta) { $byCat[$meta['category'] ?? 'autre'][$pk] = $meta; }
ksort($byCat);

$pageTitle = 'Permissions par rôle';
include __DIR__ . '/../includes/header.php';
?>
<div class="admin-container">
    <div class="page-header">
        <h1><i class="fas fa-user-lock"></i> Permissions par rôle</h1>
        <div>
            <a href="../users/roles.php" class="btn btn-secondary"><i class="fas fa-user-tag"></i> Attribuer des rôles</a>
            <a href="permissions.php" class="btn btn-secondary"><i class="fas fa-table"></i> Permissions par module (legacy)</a>
        </div>
    </div>

    <?php if ($syncNote): ?><div class="alert alert-info"><?= htmlspecialchars($syncNote) ?></div><?php endif; ?>
    <?php if ($message): ?><div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <form method="get" style="margin-bottom:16px;display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
        <div class="form-group">
            <label>Rôle</label>
            <select name="role" class="form-control" data-fr-change="submitOwn">
                <?php foreach ($rolesMeta as $rk => $rm): ?>
                    <option value="<?= htmlspecialchars($rk) ?>" <?= $rk === $selectedRole ? 'selected' : '' ?>>
                        <?= htmlspecialchars($rm['label'] ?? $rk) ?> — <?= htmlspecialchars($rm['tier'] ?? '') ?><?= !empty($rm['sensitive']) ? ' ⚠️' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <noscript><button class="btn btn-primary" type="submit">Charger</button></noscript>
    </form>

    <?php if ($selectedRole === 'super_admin'): ?>
        <div class="alert alert-warning"><strong>Super-administrateur</strong> : accès total à toute la plateforme (non modifiable).</div>
    <?php else: ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="role" value="<?= htmlspecialchars($selectedRole) ?>">

        <div style="margin-bottom:12px;display:flex;gap:8px">
            <button type="button" class="btn btn-sm btn-outline" data-fr-click="frRP1">Tout cocher</button>
            <button type="button" class="btn btn-sm btn-outline" data-fr-click="frRP2">Tout décocher</button>
        </div>

        <?php foreach ($byCat as $cat => $perms): ?>
        <div class="card" style="margin-bottom:12px">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
                <h3 style="margin:0;text-transform:capitalize"><?= htmlspecialchars($cat) ?></h3>
                <label style="font-size:.85rem;font-weight:normal">
                    <input type="checkbox" data-fr-change="frRP3"> tout
                </label>
            </div>
            <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:6px">
                <?php foreach ($perms as $pk => $meta): ?>
                    <label style="display:flex;gap:8px;align-items:center;<?= !empty($meta['sensitive']) ? 'color:#b91c1c' : '' ?>">
                        <input type="checkbox" class="perm-cb" name="perm[<?= htmlspecialchars($pk) ?>]" <?= $isGranted($pk) ? 'checked' : '' ?>>
                        <span title="<?= htmlspecialchars($pk) ?>"><?= htmlspecialchars($meta['label'] ?? $pk) ?><?= !empty($meta['sensitive']) ? ' 🔒' : '' ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if (!$byCat): ?>
            <div class="alert alert-warning">Aucune permission au catalogue (RoleCatalog introuvable ?).</div>
        <?php endif; ?>

        <div class="form-actions" style="position:sticky;bottom:0;background:var(--surface,#fff);padding:12px 0">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer les permissions de ce rôle</button>
        </div>
    </form>
    <?php endif; ?>
</div>
<script nonce="<?= csp_nonce() ?>">
window.frRP1 = function () { document.querySelectorAll('.perm-cb').forEach(c=>c.checked=true) };
window.frRP2 = function () { document.querySelectorAll('.perm-cb').forEach(c=>c.checked=false) };
window.frRP3 = function () { this.closest('.card').querySelectorAll('.perm-cb').forEach(c=>c.checked=this.checked) };
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
