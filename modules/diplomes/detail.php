<?php
declare(strict_types=1);
/**
 * M44 – Détail/Modifier diplôme
 */
$pageTitle = 'Détail diplôme';
require_once __DIR__ . '/includes/header.php';

if (!isAdmin() && !isPersonnelVS()) { redirect('/modules/diplomes/diplomes.php'); }

$id = (int)($_GET['id'] ?? 0);
$diplome = $diplService->getDiplome($id);
if (!$diplome) { redirect('/modules/diplomes/diplomes.php'); }
// Anti-IDOR : interdit la consultation/modification/suppression d'un diplôme dont
// l'élève est hors du périmètre de l'utilisateur (borne établissement incluse).
if (!empty($diplome['eleve_id']) && !assertUserCanReadEleve((int) $diplome['eleve_id'])) {
    redirect('/modules/diplomes/diplomes.php');
}

$types = DiplomeService::typesDiplome();
$mentions = DiplomeService::mentions();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRFToken()) {
    if (isset($_POST['supprimer'])) {
        $diplService->supprimerDiplome($id);
        header('Location: diplomes.php'); exit;
    }
    $diplService->modifierDiplome($id, [
        'intitule' => trim($_POST['intitule']),
        'type' => $_POST['type'],
        'mention' => $_POST['mention'] ?: null,
        'date_obtention' => $_POST['date_obtention'],
        'description' => trim($_POST['description'] ?? ''),
    ]);
    header('Location: detail.php?id=' . $id); exit;
}
?>

<div class="content-wrapper">
    <div class="content-header">
        <h1><i class="fas fa-award"></i> <?= htmlspecialchars($diplome['intitule']) ?></h1>
        <div class="header-actions">
            <a href="export_diplome.php?id=<?= $id ?>" class="btn btn-outline" title="Attestation PDF"><i class="fas fa-file-pdf"></i> Attestation PDF</a>
            <a href="diplomes.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> <?= __('btn.back') ?></a>
        </div>
    </div>

    <div class="info-grid">
        <div class="info-item"><i class="fas fa-user-graduate"></i> <?= htmlspecialchars($diplome['eleve_nom']) ?></div>
        <div class="info-item"><span class="badge badge-primary"><?= $types[$diplome['type']] ?? $diplome['type'] ?></span></div>
        <div class="info-item"><i class="fas fa-calendar"></i> <?= formatDate($diplome['date_obtention']) ?></div>
        <div class="info-item"><i class="fas fa-hashtag"></i> <?= htmlspecialchars($diplome['numero']) ?></div>
        <?php if ($diplome['mention']): ?><div class="info-item"><?= DiplomeService::badgeMention($diplome['mention']) ?></div><?php endif; ?>
    </div>

    <?php if ($diplome['description']): ?>
    <div class="card" style="margin-bottom:1.5rem;"><div class="card-body"><p><?= nl2br(htmlspecialchars($diplome['description'])) ?></p></div></div>
    <?php endif; ?>

    <div class="card"><div class="card-header"><h2><?= __('diplomes.edit') ?></h2></div><div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <div class="form-grid-2">
                <div class="form-group"><label>Intitulé *</label><input type="text" name="intitule" class="form-control" value="<?= htmlspecialchars($diplome['intitule']) ?>" required></div>
                <div class="form-group"><label><?= __('label.type') ?></label><select name="type" class="form-control"><?php foreach ($types as $k => $v): ?><option value="<?= $k ?>" <?= $diplome['type'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label><?= __('diplomes.mention') ?></label><select name="mention" class="form-control"><option value="">—</option><?php foreach ($mentions as $k => $v): ?><option value="<?= $k ?>" <?= ($diplome['mention'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Date d'obtention *</label><input type="date" name="date_obtention" class="form-control" value="<?= $diplome['date_obtention'] ?>" required></div>
                <div class="form-group full-width"><label><?= __('label.description') ?></label><textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($diplome['description'] ?? '') ?></textarea></div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= __('btn.save') ?></button>
                <button type="submit" name="supprimer" value="1" class="btn btn-danger" data-fr-confirm="Supprimer ce diplôme ?"><i class="fas fa-trash"></i> <?= __('btn.delete') ?></button>
            </div>
        </form>
    </div></div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
