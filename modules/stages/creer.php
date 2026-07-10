<?php
declare(strict_types=1);
/**
 * M17 – Créer stage
 */
$pageTitle = 'Nouveau stage';
$activePage = 'creer';
require_once __DIR__ . '/includes/header.php';

if (!isAdmin() && !isPersonnelVS()) { redirect('modules/stages/stages.php'); }

$eleves = $stageService->getEleves();
$profs = $stageService->getProfesseurs();
$types = StageService::typesStage();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRFToken()) {
    $data = [
        'eleve_id' => (int)$_POST['eleve_id'], 'type' => $_POST['type'],
        'entreprise_nom' => trim($_POST['entreprise_nom']),
        'entreprise_adresse' => trim($_POST['entreprise_adresse'] ?? ''),
        'entreprise_tel' => trim($_POST['entreprise_tel'] ?? ''),
        'tuteur_nom' => trim($_POST['tuteur_nom'] ?? ''),
        'tuteur_email' => trim($_POST['tuteur_email'] ?? ''),
        'prof_referent_id' => $_POST['prof_referent_id'] ?: null,
        'date_debut' => $_POST['date_debut'], 'date_fin' => $_POST['date_fin'],
        'description' => trim($_POST['description'] ?? ''),
    ];

    // Anti-IDOR : refuse l'accès à un élève hors périmètre de l'utilisateur.
    if (!assertUserCanReadEleve((int) $data['eleve_id'])) {
        $_SESSION['error_message'] = "Vous n'avez pas accès à cet élève.";
        header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/accueil/accueil.php');
        exit;
    }
    $id = $stageService->creerStage($data);
    header('Location: detail.php?id=' . $id); exit;
}
?>

<div class="content-wrapper">
    <div class="content-header">
        <h1><i class="fas fa-plus-circle"></i> Nouveau stage</h1>
        <a href="stages.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> <?= __('btn.back') ?></a>
    </div>

    <div class="card"><div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <div class="form-grid-3">
                <div class="form-group"><label>Élève *</label><select name="eleve_id" class="form-control" required><option value="">—</option><?php foreach ($eleves as $e): ?><option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['nom'] . ' ' . $e['prenom']) ?> (<?= $e['classe_nom'] ?? '-' ?>)</option><?php endforeach; ?></select></div>
                <div class="form-group"><label><?= __('label.type') ?></label><select name="type" class="form-control"><?php foreach ($types as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Entreprise *</label><input type="text" name="entreprise_nom" class="form-control" required></div>
                <div class="form-group"><label><?= __('label.adresse') ?></label><input type="text" name="entreprise_adresse" class="form-control"></div>
                <div class="form-group"><label>Tél.</label><input type="text" name="entreprise_tel" class="form-control"></div>
                <div class="form-group"><label><?= __('stages.tutor') ?></label><input type="text" name="tuteur_nom" class="form-control"></div>
                <div class="form-group"><label>Email tuteur</label><input type="email" name="tuteur_email" class="form-control"></div>
                <div class="form-group"><label>Référent</label><select name="prof_referent_id" class="form-control"><option value="">—</option><?php foreach ($profs as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['prenom'] . ' ' . $p['nom']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Début *</label><input type="date" name="date_debut" class="form-control" required></div>
                <div class="form-group"><label>Fin *</label><input type="date" name="date_fin" class="form-control" required></div>
                <div class="form-group full-width"><label><?= __('label.description') ?></label><textarea name="description" class="form-control" rows="3"></textarea></div>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Créer</button><a href="stages.php" class="btn btn-outline"><?= __('btn.cancel') ?></a></div>
        </form>
    </div></div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
