<?php
declare(strict_types=1);
/**
 * M29 – Bibliothèque — Emprunts
 */
$pageTitle = 'Emprunts';
$activePage = 'emprunts';
require_once __DIR__ . '/includes/header.php';

$isGestionnaire = isAdmin() || isPersonnelVS();

// Action retour / prolongation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRFToken()) {
    $action = $_POST['action'] ?? '';
    $empruntId = (int)($_POST['emprunt_id'] ?? 0);
    // Securite (IDOR) : le retour est une action de gestion (reservee aux gestionnaires) ; la
    // prolongation est limitee a SES propres emprunts pour un usager, libre pour un gestionnaire.
    if ($action === 'retourner') {
        if ($isGestionnaire) $biblioService->retourner($empruntId);
    } elseif ($action === 'prolonger') {
        if ($isGestionnaire) {
            $biblioService->prolonger($empruntId);
        } else {
            $biblioService->prolonger($empruntId, 14, getUserId(), getUserRole());
        }
    }
    header('Location: emprunts.php');
    exit;
}

if ($isGestionnaire) {
    $filtreStatut = $_GET['statut'] ?? 'en_cours';
    $filters = ['statut' => $filtreStatut];
    if (isset($_GET['retard'])) $filters['retard'] = true;
    $emprunts = $biblioService->getTousEmprunts($filters);
} else {
    $emprunts = $biblioService->getEmpruntsActifs(getUserId(), getUserRole());
    $historique = $biblioService->getHistorique(getUserId(), getUserRole());
}
?>

<div class="content-wrapper">
    <div class="content-header">
        <h1><i class="fas fa-exchange-alt"></i> <?= $isGestionnaire ? 'Gestion des emprunts' : 'Mes emprunts' ?></h1>
    </div>

    <?php if ($isGestionnaire): ?>
    <div class="filter-bar">
        <a href="emprunts.php?statut=en_cours" class="filter-btn <?= ($filtreStatut ?? '') === 'en_cours' ? 'active' : '' ?>">En cours</a>
        <a href="emprunts.php?retard=1" class="filter-btn <?= isset($_GET['retard']) ? 'active' : '' ?>">En retard</a>
        <a href="emprunts.php?statut=rendu" class="filter-btn <?= ($filtreStatut ?? '') === 'rendu' ? 'active' : '' ?>">Retournés</a>
    </div>
    <?php endif; ?>

    <!-- Emprunts actifs -->
    <h2 class="section-title"><?= $isGestionnaire ? 'Emprunts' : 'En cours' ?></h2>
    <?php if (empty($emprunts)): ?>
        <div class="empty-state"><i class="fas fa-book-reader"></i><p>Aucun emprunt.</p></div>
    <?php else: ?>
    <div class="emprunt-list">
        <?php foreach ($emprunts as $e):
            $retard = $e['statut'] === 'en_cours' && strtotime($e['date_retour_prevue']) < time();
        ?>
        <div class="emprunt-item <?= $retard ? 'retard' : '' ?>">
            <div class="emprunt-book"><i class="fas fa-book"></i></div>
            <div class="emprunt-info">
                <h3><?= htmlspecialchars($e['titre']) ?></h3>
                <span class="emprunt-auteur"><?= htmlspecialchars($e['auteur'] ?? '') ?></span>
                <div class="emprunt-meta">
                    <span><i class="fas fa-calendar-plus"></i> <?= formatDate($e['date_emprunt']) ?></span>
                    <span><i class="fas fa-calendar-check"></i> Retour: <?= formatDate($e['date_retour_prevue']) ?></span>
                    <?php if ($retard): ?><span class="badge badge-danger">En retard</span><?php endif; ?>
                    <?php if ($e['statut'] === 'rendu'): ?><span class="badge badge-success">Retourné le <?= formatDate($e['date_retour_effective']) ?></span><?php endif; ?>
                </div>
            </div>
            <?php if ($e['statut'] === 'en_cours'): ?>
            <div class="emprunt-actions">
                <?php if ($isGestionnaire): ?>
                <form method="post" style="display:inline;">
                    <?= csrfField() ?>
                    <input type="hidden" name="emprunt_id" value="<?= $e['id'] ?>">
                    <button name="action" value="retourner" class="btn btn-sm btn-success"><i class="fas fa-undo"></i> Retour</button>
                </form>
                <?php endif; ?>
                <form method="post" style="display:inline;">
                    <?= csrfField() ?>
                    <input type="hidden" name="emprunt_id" value="<?= $e['id'] ?>">
                    <button name="action" value="prolonger" class="btn btn-sm btn-outline"><i class="fas fa-clock"></i> +14j</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!$isGestionnaire && !empty($historique)): ?>
    <h2 class="section-title">Historique</h2>
    <div class="emprunt-list">
        <?php foreach ($historique as $h): ?>
        <div class="emprunt-item historique">
            <div class="emprunt-book"><i class="fas fa-book"></i></div>
            <div class="emprunt-info">
                <h3><?= htmlspecialchars($h['titre']) ?></h3>
                <div class="emprunt-meta">
                    <span><?= formatDate($h['date_emprunt']) ?> → <?= $h['date_retour_effective'] ? formatDate($h['date_retour_effective']) : 'En cours' ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
