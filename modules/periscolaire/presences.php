<?php
declare(strict_types=1);
/**
 * M16 – Présences périscolaire
 */
$pageTitle = 'Présences périscolaire';
$activePage = 'presences';
require_once __DIR__ . '/includes/header.php';

if (!isAdmin() && !isPersonnelVS()) { redirect('/modules/periscolaire/services.php'); }

$services = $periService->getServices();
$serviceId = (int)($_GET['service_id'] ?? ($services[0]['id'] ?? 0));
$date = $_GET['date'] ?? date('Y-m-d');
// Securite (XSS) : $date est reinjecte sans echappement (chaine JS / attribut value) plus bas.
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = date('Y-m-d'); }
$presences = $serviceId ? $periService->getPresences($serviceId, $date) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRFToken()) {
    foreach ($_POST['presence'] as $inscriptionId => $val) {
        $periService->enregistrerPresence((int)$inscriptionId, $date, $val === '1');
    }
    $_SESSION['success_message'] = 'Présences enregistrées.';
    header("Location: presences.php?service_id={$serviceId}&date={$date}"); exit;
}
?>

<div class="content-wrapper">
    <div class="content-header"><h1><i class="fas fa-clipboard-check"></i> Présences périscolaire</h1></div>

    <?php if (!empty($_SESSION['success_message'])): ?><div class="alert alert-success"><?= $_SESSION['success_message'] ?></div><?php unset($_SESSION['success_message']); endif; ?>

    <div class="filter-bar">
        <select data-fr-change="frPr1" class="form-control" style="width:auto;">
            <?php foreach ($services as $s): ?><option value="<?= $s['id'] ?>" <?= $s['id'] == $serviceId ? 'selected' : '' ?>><?= htmlspecialchars($s['nom']) ?></option><?php endforeach; ?>
        </select>
        <input type="date" value="<?= $date ?>" data-fr-change="frPr2" class="form-control" style="width:auto;">
    </div>

    <?php if (empty($presences)): ?>
        <div class="empty-state"><i class="fas fa-clipboard-check"></i><p>Aucun inscrit pour ce service.</p></div>
    <?php else: ?>
    <form method="post">
        <?= csrfField() ?>
        <table class="table">
            <thead><tr><th>Élève</th><th>Présent</th></tr></thead>
            <tbody>
                <?php foreach ($presences as $p): ?>
                <tr>
                    <td><?= htmlspecialchars($p['eleve_nom']) ?></td>
                    <td>
                        <label><input type="radio" name="presence[<?= $p['inscription_id'] ?>]" value="1" <?= $p['present'] === '1' || $p['present'] === 1 ? 'checked' : '' ?>> Présent</label>
                        <label><input type="radio" name="presence[<?= $p['inscription_id'] ?>]" value="0" <?= $p['present'] === '0' || $p['present'] === 0 ? 'checked' : '' ?>> Absent</label>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
    </form>
    <?php endif; ?>
</div>

<script nonce="<?= csp_nonce() ?>">
window.frPr1 = function () { location.href='presences.php?service_id='+this.value+'&date=<?= $date ?>'; };
window.frPr2 = function () { location.href='presences.php?service_id=<?= $serviceId ?>&date='+this.value; };
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
