<?php
declare(strict_types=1);
/**
 * M34 – Support — Demandes internes : « Mes demandes » (tout le monde) / « Demandes reçues » (équipe).
 */
$pageTitle = 'Aide & support';
require_once __DIR__ . '/includes/header.php';

$cats = SupportService::categoriesDetail();
$filtreStatut = is_string($_GET['statut'] ?? null) ? $_GET['statut'] : 'actives';
$filtreCat = is_string($_GET['cat'] ?? null) && isset($cats[$_GET['cat']]) ? $_GET['cat'] : '';
$stats = null;
if ($estEquipe) {
    $filters = [];
    if (in_array($filtreStatut, ['ouvert', 'en_cours', 'resolu', 'ferme'], true)) { $filters['statut'] = $filtreStatut; }
    if ($filtreCat !== '') { $filters['categorie'] = $filtreCat; }
    $tickets = $supportService->getTousTickets($filters);
    if ($filtreStatut === 'actives') { $tickets = array_values(array_filter($tickets, fn($t) => in_array($t['statut'], ['ouvert', 'en_cours'], true))); }
    $stats = $supportService->getStatsTickets();
} else {
    $tickets = $supportService->getTicketsUser(getUserId(), getUserRole());
}
// Demandes déjà transmises à la plateforme (pastille)
$escalades = [];
if ($estEquipe && $tickets) {
    try {
        $ids = implode(',', array_map('intval', array_column($tickets, 'id')));
        foreach (getPDO()->query("SELECT ticket_id, statut FROM support_escalations WHERE ticket_id IN ($ids)")->fetchAll(PDO::FETCH_ASSOC) as $r) { $escalades[(int) $r['ticket_id']] = $r['statut']; }
    } catch (\Throwable $e) {}
}
$qs = static fn(array $p) => '?' . http_build_query(array_filter($p + ['statut' => $filtreStatut, 'cat' => $filtreCat], fn($v) => $v !== ''));
?>
<div class="sp">
    <?php if (!empty($_SESSION['success_message'])): ?>
        <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success_message']) ?></div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <div class="sp-head">
        <div>
            <h2 class="sp-title"><?= $estEquipe ? 'Demandes reçues' : 'Mes demandes' ?></h2>
            <p class="sp-sub"><?= $estEquipe ? 'Adressées à l\'établissement par les élèves, les familles et le personnel.' : 'Vos demandes à l\'équipe de l\'établissement.' ?></p>
        </div>
        <a href="nouveau_ticket.php" class="btn btn-primary"><i class="fas fa-pen-to-square"></i> Nouvelle demande</a>
    </div>

    <?php if ($stats): ?>
    <div class="sp-kpis">
        <a class="sp-kpi" href="<?= htmlspecialchars($qs(['statut' => 'ouvert'])) ?>"><strong><?= (int) $stats['ouverts'] ?></strong><span>Nouvelles</span></a>
        <a class="sp-kpi" href="<?= htmlspecialchars($qs(['statut' => 'en_cours'])) ?>"><strong><?= (int) $stats['en_cours'] ?></strong><span>En cours</span></a>
        <a class="sp-kpi sp-kpi--danger" href="<?= htmlspecialchars($qs(['statut' => 'actives'])) ?>"><strong><?= (int) $stats['urgents'] ?></strong><span>Urgentes</span></a>
        <a class="sp-kpi sp-kpi--ok" href="<?= htmlspecialchars($qs(['statut' => 'resolu'])) ?>"><strong><?= (int) $stats['resolus'] ?></strong><span>Résolues</span></a>
    </div>
    <div class="sp-filters">
        <?php foreach (['actives' => 'À traiter', 'ouvert' => 'Nouvelles', 'en_cours' => 'En cours', 'resolu' => 'Résolues', 'ferme' => 'Fermées', 'toutes' => 'Toutes'] as $k => $l): ?>
        <a class="filter-btn <?= $filtreStatut === $k ? 'active' : '' ?>" href="<?= htmlspecialchars($qs(['statut' => $k])) ?>"><?= $l ?></a>
        <?php endforeach; ?>
        <form method="get" class="sp-catfilter">
            <input type="hidden" name="statut" value="<?= htmlspecialchars($filtreStatut) ?>">
            <select name="cat" class="form-control form-control-sm" data-fr-change="submitOwn" aria-label="Catégorie">
                <option value="">Toutes les catégories</option>
                <?php foreach ($cats as $k => [$l]): ?><option value="<?= htmlspecialchars($k) ?>" <?= $filtreCat === $k ? 'selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?>
            </select>
        </form>
    </div>
    <?php endif; ?>

    <?php if (empty($tickets)): ?>
        <div class="empty-state"><i class="fas fa-inbox"></i><p><?= $estEquipe ? 'Aucune demande à traiter.' : 'Vous n\'avez pas encore fait de demande.' ?></p></div>
    <?php else: ?>
    <div class="sp-list">
        <?php foreach ($tickets as $t): $c = $cats[$t['categorie']] ?? [$t['categorie'], '', 'fas fa-circle']; ?>
        <a class="sp-item" href="voir_ticket.php?id=<?= (int) $t['id'] ?>">
            <span class="sp-item__ic"><i class="<?= htmlspecialchars($c[2]) ?>" aria-hidden="true"></i></span>
            <span class="sp-item__main">
                <strong><?= htmlspecialchars($t['sujet']) ?></strong>
                <span class="sp-item__meta">
                    <?= htmlspecialchars($c[0]) ?>
                    <?php if ($estEquipe && !empty($t['nom_utilisateur'])): ?> · <?= htmlspecialchars($t['nom_utilisateur']) ?> (<?= htmlspecialchars($t['user_type']) ?>)<?php endif; ?>
                    · <?= formatDateTime($t['date_creation']) ?>
                </span>
            </span>
            <span class="sp-item__badges">
                <?php if (isset($escalades[(int) $t['id']])): ?><span class="badge badge-info"><i class="fas fa-headset"></i> Plateforme</span><?php endif; ?>
                <?php if ($estEquipe && in_array($t['priorite'], ['haute', 'urgente'], true)): ?><?= SupportService::prioriteBadge($t['priorite']) ?><?php endif; ?>
                <?= SupportService::statutBadge($t['statut']) ?>
            </span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
