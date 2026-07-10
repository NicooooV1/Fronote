<?php
declare(strict_types=1);
/**
 * M19 – Internat — Mouvements (entrées/sorties)
 */
$activePage = 'mouvements';
$pageTitle = 'Internat — Mouvements';
require_once __DIR__ . '/includes/header.php';

if (!$isGestionnaire) { header('Location: affectations.php'); exit; }

$dateVue = $_GET['date'] ?? date('Y-m-d');
// Securite (XSS) : $dateVue est reinjecte sans echappement (attribut value) plus bas.
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateVue)) { $dateVue = date('Y-m-d'); }
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enregistrer'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Jeton de sécurité invalide. Veuillez recharger la page.';
    } else {

        // Anti-IDOR : refuse l'accès à un élève hors périmètre de l'utilisateur.
        if (!assertUserCanReadEleve((int)$_POST['eleve_id'])) {
            $_SESSION['error_message'] = "Vous n'avez pas accès à cet élève.";
            header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/accueil/accueil.php');
            exit;
        }
    $internatService->enregistrerMouvement(
        (int)$_POST['eleve_id'], (int)$_POST['chambre_id'],
        $_POST['type'], $_POST['motif'] ?? null, $user['id']
    );
    $message = 'Mouvement enregistré.';
    }
}

$mouvements = $internatService->getMouvementsJour($dateVue);
$affectations = $internatService->getAffectations();
?>

    <div class="page-header">
        <h1><i class="fas fa-exchange-alt"></i> Mouvements du <?= date('d/m/Y', strtotime($dateVue)) ?></h1>
        <form method="get" class="inline-form"><input type="date" name="date" value="<?= $dateVue ?>" class="form-control" data-fr-change="submitOwn"></form>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <div class="card" style="margin-bottom:20px">
        <div class="card-header"><h3>Enregistrer un mouvement</h3></div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_hdr_csrf_token ?? '') ?>">
                <input type="hidden" name="enregistrer" value="1">
                <div class="form-row">
                    <div class="form-group"><label><?= __('internat.student') ?></label>
                        <select name="eleve_id" required class="form-select" data-fr-change="updateChambre" data-fr-pass="this">
                            <option value="">— Choisir —</option>
                            <?php foreach ($affectations as $af): if ($af['statut'] !== 'actif') continue; ?>
                                <option value="<?= $af['eleve_id'] ?>" data-chambre="<?= $af['chambre_id'] ?>">
                                    <?= htmlspecialchars($af['prenom'] . ' ' . $af['nom'] . ' — Ch.' . $af['chambre_numero']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <input type="hidden" name="chambre_id" id="chambre_id_hidden">
                    <div class="form-group"><label><?= __('label.type') ?></label>
                        <select name="type" class="form-select">
                            <option value="entree">Entrée</option><option value="sortie">Sortie</option>
                            <option value="absence">Absence</option><option value="retard">Retard</option>
                        </select>
                    </div>
                    <div class="form-group"><label><?= __('label.motif') ?></label><input type="text" name="motif" class="form-control"></div>
                </div>
                <button type="submit" class="btn btn-primary"><?= __('btn.save') ?></button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Mouvements du jour (<?= count($mouvements) ?>)</h3></div>
        <div class="card-body">
            <table class="table">
                <thead><tr><th><?= __('label.heure') ?></th><th>Élève</th><th>Chambre</th><th><?= __('label.type') ?></th><th><?= __('label.motif') ?></th></tr></thead>
                <tbody>
                <?php foreach ($mouvements as $m):
                    $typeColors = ['entree' => 'success', 'sortie' => 'info', 'absence' => 'danger', 'retard' => 'warning'];
                ?>
                    <tr>
                        <td><?= date('H:i', strtotime($m['date_heure'])) ?></td>
                        <td><?= htmlspecialchars($m['prenom'] . ' ' . $m['nom']) ?></td>
                        <td><?= htmlspecialchars($m['chambre_numero']) ?></td>
                        <td><span class="badge badge-<?= $typeColors[$m['type']] ?? 'secondary' ?>"><?= ucfirst($m['type']) ?></span></td>
                        <td><?= htmlspecialchars($m['motif'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<script nonce="<?= csp_nonce() ?>">
function updateChambre(sel) {
    var opt = sel.options[sel.selectedIndex];
    document.getElementById('chambre_id_hidden').value = opt.dataset.chambre || '';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
