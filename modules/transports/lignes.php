<?php
declare(strict_types=1);
/**
 * M32 – Lignes de transport
 */
$pageTitle = 'Transports scolaires';
require_once __DIR__ . '/includes/header.php';

$filtreType = $_GET['type'] ?? '';
$lignes = $tiService->getLignes($filtreType ?: null);
$types = TransportInternatService::typesTransport();
$isGestionnaire = isAdmin() || isPersonnelVS();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRFToken() && $isGestionnaire && ($_POST['action'] ?? '') === 'creer') {
    $tiService->creerLigne([
        'nom' => trim($_POST['nom']), 'type' => $_POST['type'],
        'itineraire' => trim($_POST['itineraire'] ?? ''),
        'horaire_depart' => $_POST['horaire_depart'] ?: null,
        'horaire_arrivee' => $_POST['horaire_arrivee'] ?: null,
        'capacite' => $_POST['capacite'] ?: null,
    ]);
    header('Location: lignes.php'); exit;
}
?>

<div class="content-wrapper">
    <div class="content-header"><h1><i class="fas fa-bus"></i> Transports scolaires</h1></div>

    <div class="filter-bar">
        <a href="lignes.php" class="btn <?= !$filtreType ? 'btn-primary' : 'btn-outline' ?>"><?= __('label.tous') ?></a>
        <?php foreach ($types as $k => $v): ?>
        <a href="lignes.php?type=<?= $k ?>" class="btn <?= $filtreType === $k ? 'btn-primary' : 'btn-outline' ?>"><?= $v ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($isGestionnaire): ?>
    <div class="card" style="margin-bottom:1.5rem;">
        <div class="card-header"><h2>Nouvelle ligne</h2></div>
        <div class="card-body">
            <form method="post">
                <?= csrfField() ?><input type="hidden" name="action" value="creer">
                <div class="form-grid-3">
                    <div class="form-group"><label>Nom *</label><input type="text" name="nom" class="form-control" required></div>
                    <div class="form-group"><label><?= __('label.type') ?></label><select name="type" class="form-control"><?php foreach ($types as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Capacité</label><input type="number" name="capacite" class="form-control" min="1"></div>
                    <div class="form-group"><label>Heure de départ</label><input type="time" name="horaire_depart" class="form-control"></div>
                    <div class="form-group"><label>Heure d'arrivée</label><input type="time" name="horaire_arrivee" class="form-control"></div>
                    <div class="form-group full-width"><label>Itinéraire</label><textarea name="itineraire" class="form-control" rows="2"></textarea></div>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top:.5rem;"><i class="fas fa-plus"></i> Créer</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($lignes)): ?>
        <div class="empty-state"><i class="fas fa-bus"></i><p>Aucune ligne.</p></div>
    <?php else: ?>
    <div class="lignes-list">
        <?php foreach ($lignes as $l): ?>
        <div class="ligne-card">
            <div class="ligne-icon"><i class="fas fa-<?= $l['type'] === 'train' ? 'train' : 'bus' ?>"></i></div>
            <div class="ligne-info">
                <h3><a href="detail_ligne.php?id=<?= $l['id'] ?>"><?= htmlspecialchars($l['nom']) ?></a></h3>
                <div class="ligne-meta">
                    <span class="badge badge-secondary"><?= $types[$l['type']] ?? $l['type'] ?></span>
                    <span><i class="fas fa-users"></i> <?= $l['nb_inscrits'] ?><?= $l['capacite'] ? '/' . $l['capacite'] : '' ?></span>
                    <?php if (!empty($l['horaire_depart'])): ?><span><i class="fas fa-clock"></i> <?= htmlspecialchars(substr((string)$l['horaire_depart'], 0, 5)) ?><?php if (!empty($l['horaire_arrivee'])): ?> → <?= htmlspecialchars(substr((string)$l['horaire_arrivee'], 0, 5)) ?><?php endif; ?></span><?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
