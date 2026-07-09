<?php
/**
 * Attribution des rôles applicatifs (RBAC) — assigne/révoque des rôles scopés et
 * temporisés (table user_roles) à un utilisateur. Réservé administration / direction /
 * super-admin. Le contrôle d'accès central (AccessControl) impose déjà admin/ ; on
 * double ici par requireRole + garde-fous anti-escalade dans RoleManagementService.
 */
require_once __DIR__ . '/../../API/core.php';
require_once __DIR__ . '/../includes/admin_functions.php';

requireAuth();
tenantGate('tenant.users.view', ['administrateur', 'super_admin']);

use API\Services\RoleManagementService;
use API\Security\RoleCatalog;

$pdo   = getPDO();
$svc   = new RoleManagementService($pdo);
$actor = getCurrentUser();
$actorRoles = app('authz')->roleKeys();

$message = '';
$error   = '';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Cible courante (utilisateur à qui on gère les rôles).
$targetType = $_GET['ut'] ?? ($_POST['ut'] ?? '');
$targetId   = (int) ($_GET['uid'] ?? ($_POST['uid'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'assign' && $targetType && $targetId > 0) {
            // Périmètre fin optionnel (scope_json), p.ex. {"class_ids":[2,4]} pour own_classes,
            // {"student_ids":[5,7]} pour assigned, {"etablissement_ids":[1,3]} pour establishments.
            $scopeRaw = trim((string) ($_POST['scope_json'] ?? ''));
            $scopeArr = null;
            if ($scopeRaw !== '') {
                $scopeArr = json_decode($scopeRaw, true);
                if (!is_array($scopeArr)) {
                    throw new \RuntimeException('Périmètre JSON invalide. Exemple : {"class_ids":[2,4]} ou {"student_ids":[5,7]}.');
                }
            }
            $svc->assign($actor, $actorRoles, $targetType, $targetId, $_POST['role_key'] ?? '', [
                'etablissement_id' => $_POST['etablissement_id'] ?? '',
                'scope_type'       => $_POST['scope_type'] ?? null,
                'scope'            => $scopeArr,
                'valid_until'      => $_POST['valid_until'] ?? null,
            ]);
            $message = 'Rôle attribué.';
        } elseif ($action === 'revoke') {
            $svc->revoke($actor, $actorRoles, (int) ($_POST['row_id'] ?? 0));
            $message = 'Rôle révoqué.';
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$assignable = $svc->assignableRoles($actorRoles);
$catalog    = RoleCatalog::roles();
$current    = ($targetType && $targetId > 0) ? $svc->listUserRoles($targetType, $targetId) : [];
$etabs      = $pdo->query("SELECT id, nom FROM etablissements WHERE actif = 1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$scopeTypes = ['establishment', 'establishments', 'global', 'self', 'children', 'assigned', 'own_classes'];
$accountTypes = ['eleve', 'parent', 'professeur', 'vie_scolaire', 'administrateur', 'super_admin', 'technicien'];

$pageTitle = 'Attribution des rôles';
include __DIR__ . '/../includes/header.php';
?>
<div class="admin-container">
    <div class="page-header">
        <h1><i class="fas fa-user-shield"></i> Attribution des rôles</h1>
        <a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Utilisateurs</a>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form method="get" class="form-inline" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
                <div class="form-group">
                    <label>Type de compte</label>
                    <select name="ut" class="form-control">
                        <?php foreach ($accountTypes as $t): ?>
                            <option value="<?= $t ?>" <?= $targetType === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>ID utilisateur</label>
                    <input type="number" name="uid" class="form-control" value="<?= $targetId ?: '' ?>" min="1">
                </div>
                <button class="btn btn-primary" type="submit">Charger</button>
            </form>
        </div>
    </div>

    <?php if ($targetType && $targetId > 0): ?>
    <div class="card" style="margin-top:16px">
        <div class="card-header"><h2>Rôles de <?= htmlspecialchars($targetType) ?> #<?= $targetId ?></h2></div>
        <div class="card-body">
            <table class="table">
                <thead><tr><th>Rôle</th><th>Établissement</th><th>Périmètre</th><th>Expire</th><th></th></tr></thead>
                <tbody>
                <?php if (!$current): ?>
                    <tr><td colspan="5"><em>Aucun rôle attribué (le rôle de base = type de compte s'applique).</em></td></tr>
                <?php endif; ?>
                <?php foreach ($current as $r): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($r['label']) ?></strong> <small>(<?= htmlspecialchars($r['role_key']) ?>)</small></td>
                        <td><?= $r['etablissement_id'] !== null ? (int) $r['etablissement_id'] : '<em>tous</em>' ?></td>
                        <td><?= htmlspecialchars($r['scope_type']) ?></td>
                        <td><?= $r['valid_until'] ? htmlspecialchars($r['valid_until']) : '<em>permanent</em>' ?></td>
                        <td>
                            <form method="post" data-fr-confirm="Révoquer ce rôle ?">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="ut" value="<?= htmlspecialchars($targetType) ?>">
                                <input type="hidden" name="uid" value="<?= $targetId ?>">
                                <input type="hidden" name="action" value="revoke">
                                <input type="hidden" name="row_id" value="<?= (int) $r['id'] ?>">
                                <button class="btn btn-sm btn-danger" type="submit"><i class="fas fa-times"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="card-header"><h2>Attribuer un rôle</h2></div>
        <div class="card-body">
            <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="ut" value="<?= htmlspecialchars($targetType) ?>">
                <input type="hidden" name="uid" value="<?= $targetId ?>">
                <input type="hidden" name="action" value="assign">
                <div class="form-group">
                    <label>Rôle</label>
                    <select name="role_key" class="form-control" required>
                        <?php foreach ($assignable as $rk): ?>
                            <option value="<?= htmlspecialchars($rk) ?>"><?= htmlspecialchars($catalog[$rk]['label'] ?? $rk) ?> (<?= htmlspecialchars($catalog[$rk]['tier'] ?? '') ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Établissement</label>
                    <select name="etablissement_id" class="form-control">
                        <option value="">— tous (super-admin) —</option>
                        <?php foreach ($etabs as $e): ?>
                            <option value="<?= (int) $e['id'] ?>"><?= htmlspecialchars($e['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Périmètre</label>
                    <select name="scope_type" class="form-control">
                        <option value="">— défaut du rôle —</option>
                        <?php foreach ($scopeTypes as $s): ?>
                            <option value="<?= $s ?>"><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Périmètre fin <small>(JSON, optionnel)</small></label>
                    <input type="text" name="scope_json" class="form-control" placeholder='{"class_ids":[2,4]}'>
                    <small class="form-text text-muted">own_classes → class_ids ; assigned → student_ids ; establishments → etablissement_ids</small>
                </div>
                <div class="form-group">
                    <label>Expire le (optionnel)</label>
                    <input type="datetime-local" name="valid_until" class="form-control">
                </div>
                <button class="btn btn-primary" type="submit"><i class="fas fa-plus"></i> Attribuer</button>
            </form>
            <?php if (!$assignable): ?><p class="text-muted">Votre rôle ne permet pas d'attribuer de rôles.</p><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
