<?php
/**
 * Gestion des relations entre comptes (account_relationships) : parent ↔ élève,
 * prof ↔ classe, AESH/psy/médical/social ↔ élève, tuteur entreprise ↔ élève.
 * Affiche les relations d'un compte (et la vue inverse), et permet d'en ajouter /
 * désactiver. Réservé administration / super-admin.
 */
require_once __DIR__ . '/../../API/core.php';
require_once __DIR__ . '/../includes/admin_functions.php';

requireAuth();
// Bascule 3-mondes : permission établissement prioritaire, repli legacy le temps de la transition.
tenantGate('tenant.users.manage', ['administrateur', 'super_admin']);

use API\Services\RelationshipService;

$pdo   = getPDO();
$svc   = new RelationshipService($pdo);
$actor = getCurrentUser();

$message = '';
$error   = '';

$srcType = $_GET['st'] ?? ($_POST['st'] ?? '');
$srcId   = (int) ($_GET['sid'] ?? ($_POST['sid'] ?? 0));

// CSRF : on s'appuie sur le validateur canonique du framework (rotation de jeton,
// usage unique, expiration) — cf. docs/security.md, plutôt qu'un schéma maison.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRFToken()) {
    try {
        if (($_POST['action'] ?? '') === 'add') {
            $svc->add(
                $actor,
                (string) $_POST['src_type'], (int) $_POST['src_id'],
                (string) $_POST['rel_type'],
                (string) $_POST['tgt_type'], (int) $_POST['tgt_id'],
                ['etablissement_id' => $_POST['etablissement_id'] ?? null, 'expires_at' => $_POST['expires_at'] ?? null]
            );
            $message = 'Relation ajoutée.';
        } elseif (($_POST['action'] ?? '') === 'remove') {
            $svc->remove($actor, (int) ($_POST['rel_id'] ?? 0));
            $message = 'Relation désactivée.';
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$relations = ($srcType && $srcId > 0) ? $svc->listFor($srcType, $srcId) : [];
$accountTypes = ['eleve', 'parent', 'professeur', 'vie_scolaire', 'administrateur'];
$targetTypes  = ['eleve', 'classe'];

$pageTitle = 'Relations entre comptes';
include __DIR__ . '/../includes/header.php';
?>
<div class="admin-container">
    <div class="page-header">
        <h1><i class="fas fa-project-diagram"></i> Relations entre comptes</h1>
        <a href="simulator.php" class="btn btn-secondary"><i class="fas fa-vial"></i> Simulateur</a>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
                <div class="form-group">
                    <label>Type de compte (source)</label>
                    <select name="st" class="form-control">
                        <?php foreach ($accountTypes as $t): ?>
                            <option value="<?= $t ?>" <?= $srcType === $t ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>ID</label>
                    <input type="number" name="sid" class="form-control" min="1" value="<?= $srcId ?: '' ?>">
                </div>
                <button class="btn btn-primary" type="submit">Charger</button>
            </form>
        </div>
    </div>

    <?php if ($srcType && $srcId > 0): ?>
    <div class="card" style="margin-top:16px">
        <div class="card-header"><h2>Relations de <?= htmlspecialchars($srcType) ?> #<?= $srcId ?></h2></div>
        <div class="card-body" style="overflow-x:auto">
            <table class="table">
                <thead><tr><th>Type de relation</th><th>Cible</th><th>Établissement</th><th>Expire</th><th></th></tr></thead>
                <tbody>
                <?php if (!$relations): ?><tr><td colspan="5"><em>Aucune relation active.</em></td></tr><?php endif; ?>
                <?php foreach ($relations as $r): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($r['relationship_type']) ?></code></td>
                        <td><?= htmlspecialchars($r['target_type']) ?> #<?= (int) $r['target_id'] ?></td>
                        <td><?= $r['etablissement_id'] !== null ? (int) $r['etablissement_id'] : '<em>—</em>' ?></td>
                        <td><?= !empty($r['expires_at']) ? htmlspecialchars($r['expires_at']) : '<em>permanent</em>' ?></td>
                        <td>
                            <form method="post" data-fr-confirm="Désactiver cette relation ?">
                                <?= csrfField() ?>
                                <input type="hidden" name="st" value="<?= htmlspecialchars($srcType) ?>">
                                <input type="hidden" name="sid" value="<?= $srcId ?>">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="rel_id" value="<?= (int) $r['id'] ?>">
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
        <div class="card-header"><h2>Ajouter une relation</h2></div>
        <div class="card-body">
            <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
                <?= csrfField() ?>
                <input type="hidden" name="st" value="<?= htmlspecialchars($srcType) ?>">
                <input type="hidden" name="sid" value="<?= $srcId ?>">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="src_type" value="<?= htmlspecialchars($srcType) ?>">
                <input type="hidden" name="src_id" value="<?= $srcId ?>">
                <div class="form-group">
                    <label>Relation</label>
                    <select name="rel_type" class="form-control" required>
                        <?php foreach (RelationshipService::TYPES as $rt): ?>
                            <option value="<?= $rt ?>"><?= $rt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Cible</label>
                    <select name="tgt_type" class="form-control">
                        <?php foreach ($targetTypes as $t): ?><option value="<?= $t ?>"><?= $t ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>ID cible</label>
                    <input type="number" name="tgt_id" class="form-control" min="1" required>
                </div>
                <div class="form-group">
                    <label>Établissement (optionnel)</label>
                    <input type="number" name="etablissement_id" class="form-control" min="1">
                </div>
                <div class="form-group">
                    <label>Expire le (optionnel)</label>
                    <input type="datetime-local" name="expires_at" class="form-control">
                </div>
                <button class="btn btn-primary" type="submit"><i class="fas fa-plus"></i> Ajouter</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
