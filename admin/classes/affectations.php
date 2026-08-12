<?php
declare(strict_types=1);
/**
 * Affectations professeurs ↔ classes — vue matricielle avec checkbox toggle
 */
require_once __DIR__ . '/../../API/core.php';
require_once __DIR__ . '/../includes/admin_functions.php';

requireAuth();
tenantGate('tenant.classes.manage');

$pdo = getPDO();
$admin = getCurrentUser();
$message = '';
// Cloisonnement multi-tenant : toute la matrice profs↔classes est bornée à
// l'établissement courant (professeur_classes n'a pas etablissement_id → on filtre via
// le professeur). Sans ça la page listait/éditait/écrasait les affectations de TOUS
// les établissements (le DELETE global était particulièrement destructif).
$etabId = \API\Core\EstablishmentContext::id();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$pstmt = $pdo->prepare("SELECT id, nom, prenom FROM professeurs WHERE etablissement_id = ? ORDER BY nom, prenom");
$pstmt->execute([$etabId]);
$professeurs = $pstmt->fetchAll(PDO::FETCH_ASSOC);
$cstmt = $pdo->prepare("SELECT id, nom, niveau FROM classes WHERE actif = 1 AND etablissement_id = ? ORDER BY niveau, nom");
$cstmt->execute([$etabId]);
$classes = $cstmt->fetchAll(PDO::FETCH_ASSOC);

// Charger affectations existantes de l'établissement (jointure sur le professeur).
$existing = [];
$stmt = $pdo->prepare("SELECT pc.id_professeur, pc.nom_classe FROM professeur_classes pc JOIN professeurs p ON pc.id_professeur = p.id WHERE p.etablissement_id = ?");
$stmt->execute([$etabId]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $existing[$row['id_professeur'] . '_' . $row['nom_classe']] = true;
}

// POST : sauvegarder la matrice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['csrf_token'] ?? '') === $csrf_token) {
    $action = $_POST['action'] ?? '';

    // Profs valides = ceux de l'établissement courant (déjà chargés, scopés).
    $validProfIds = array_column($professeurs, 'id');
    $validProfIds = array_map('intval', $validProfIds);

    if ($action === 'save_matrix') {
        $assignments = $_POST['assign'] ?? [];
        try {
            $pdo->beginTransaction();
            // Ne supprimer QUE les affectations des profs de l'établissement courant
            // (jamais un DELETE global : il effaçait les affectations de tous les établissements).
            $pdo->prepare("DELETE pc FROM professeur_classes pc JOIN professeurs p ON pc.id_professeur = p.id WHERE p.etablissement_id = ?")->execute([$etabId]);
            // Insérer les nouvelles — uniquement pour des profs de cet établissement.
            $insert = $pdo->prepare("INSERT INTO professeur_classes (id_professeur, nom_classe) VALUES (?, ?)");
            $count = 0;
            foreach ($assignments as $key => $val) {
                // key = profId_className
                $parts = explode('_', $key, 2);
                if (count($parts) === 2 && in_array((int) $parts[0], $validProfIds, true)) {
                    $insert->execute([(int) $parts[0], $parts[1]]);
                    $count++;
                }
            }
            $pdo->commit();
            logAudit('affectations_saved', 'professeur_classes', 0, [], ['total' => $count]);
            $message = "$count affectation(s) enregistrée(s).";
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("save_matrix failed: " . $e->getMessage());
            $message = "Échec de l'enregistrement des affectations (aucune modification appliquée).";
        }
        // Recharger (scopé établissement)
        $existing = [];
        $stmt = $pdo->prepare("SELECT pc.id_professeur, pc.nom_classe FROM professeur_classes pc JOIN professeurs p ON pc.id_professeur = p.id WHERE p.etablissement_id = ?");
        $stmt->execute([$etabId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existing[$row['id_professeur'] . '_' . $row['nom_classe']] = true;
        }
    }

    if ($action === 'toggle_single') {
        $profId = intval($_POST['prof_id'] ?? 0);
        $className = $_POST['class_name'] ?? '';
        // Le prof doit appartenir à l'établissement courant.
        if ($profId > 0 && !empty($className) && in_array($profId, $validProfIds, true)) {
            $key = $profId . '_' . $className;
            if (isset($existing[$key])) {
                $pdo->prepare("DELETE FROM professeur_classes WHERE id_professeur = ? AND nom_classe = ?")->execute([$profId, $className]);
                unset($existing[$key]);
                $message = "Affectation retirée.";
            } else {
                $pdo->prepare("INSERT INTO professeur_classes (id_professeur, nom_classe) VALUES (?, ?)")->execute([$profId, $className]);
                $existing[$key] = true;
                $message = "Affectation ajoutée.";
            }
            logAudit('affectation_toggled', 'professeur_classes', $profId, ['nom_classe' => $className]);
        }
    }
}

$pageTitle = 'Affectations Prof ↔ Classes';
$currentPage = 'affectations';
$extraCss = ['../../assets/css/admin.css'];

ob_start();
?>
<style>
    .aff-container { max-width: 1200px; margin: 0 auto; }
    .matrix-wrapper { overflow-x: auto; background: white; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .matrix-table { border-collapse: collapse; width: 100%; min-width: 800px; }
    .matrix-table th, .matrix-table td { padding: 8px 10px; text-align: center; border: 1px solid #edf2f7; font-size: 13px; }
    .matrix-table thead th { background: #f7fafc; font-weight: 600; color: #4a5568; position: sticky; top: 0; z-index: 1; }
    .matrix-table thead th:first-child { text-align: left; min-width: 180px; position: sticky; left: 0; z-index: 2; background: #edf2f7; }
    .matrix-table tbody td:first-child { text-align: left; font-weight: 500; position: sticky; left: 0; background: white; z-index: 1; border-right: 2px solid #e2e8f0; }
    .matrix-table tbody tr:hover { background: #f0f7ff; }
    .matrix-table tbody tr:hover td:first-child { background: #f0f7ff; }
    .cell-check { width: 18px; height: 18px; cursor: pointer; accent-color: #0f4c81; }
    .legend { display: flex; gap: 15px; margin-bottom: 15px; font-size: 13px; color: #555; align-items: center; }
    .legend span { display: flex; align-items: center; gap: 4px; }
    .count-badge { background: #dbeafe; color: #1e40af; padding: 1px 6px; border-radius: 10px; font-size: 11px; font-weight: 600; margin-left: 4px; }
    .actions-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
</style>
<?php
$extraHeadHtml = ob_get_clean();
include __DIR__ . '/../includes/header.php';
?>

<div class="aff-container">
    <?php if (!empty($message)): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <div class="legend">
        <span><input type="checkbox" checked disabled class="cell-check"> Affecté</span>
        <span><input type="checkbox" disabled class="cell-check"> Non affecté</span>
        <span style="margin-left:auto;font-size:12px;color:#888">Cliquez sur une case pour ajouter/retirer une affectation instantanément.</span>
    </div>

    <?php if (empty($professeurs) || empty($classes)): ?>
        <div style="text-align:center;padding:40px;color:#999"><p>Ajoutez des professeurs et des classes pour utiliser cette vue.</p></div>
    <?php else: ?>
    <div class="matrix-wrapper">
        <table class="matrix-table">
            <thead>
                <tr>
                    <th>Professeur</th>
                    <?php foreach ($classes as $c): ?>
                    <th title="<?= htmlspecialchars($c['niveau'] . ' - ' . $c['nom']) ?>"><?= htmlspecialchars($c['nom']) ?></th>
                    <?php endforeach; ?>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($professeurs as $p):
                    $profCount = 0;
                ?>
                <tr>
                    <td><?= htmlspecialchars($p['prenom'] . ' ' . $p['nom']) ?></td>
                    <?php foreach ($classes as $c):
                        $key = $p['id'] . '_' . $c['nom'];
                        $isAssigned = isset($existing[$key]);
                        if ($isAssigned) $profCount++;
                    ?>
                    <td>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="action" value="toggle_single">
                            <input type="hidden" name="prof_id" value="<?= $p['id'] ?>">
                            <input type="hidden" name="class_name" value="<?= htmlspecialchars($c['nom']) ?>">
                            <input type="checkbox" class="cell-check" <?= $isAssigned ? 'checked' : '' ?> data-fr-change="submitOwn">
                        </form>
                    </td>
                    <?php endforeach; ?>
                    <td><span class="count-badge"><?= $profCount ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:#f7fafc;font-weight:600">
                    <td style="text-align:right">Profs/classe →</td>
                    <?php foreach ($classes as $c):
                        $classCount = 0;
                        foreach ($professeurs as $p) { if (isset($existing[$p['id'].'_'.$c['nom']])) $classCount++; }
                    ?>
                    <td><span class="count-badge"><?= $classCount ?></span></td>
                    <?php endforeach; ?>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
