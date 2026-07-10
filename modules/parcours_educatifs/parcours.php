<?php
declare(strict_types=1);
$activePage = 'parcours';
require_once __DIR__ . '/includes/header.php';

$user = $_SESSION['user'];
$role  = $user['type'] ?? 'eleve';
$filtres = [
    'type_parcours'  => $_GET['type'] ?? '',
    'annee_scolaire' => $_GET['annee'] ?? '',
];
if ($role === 'eleve') $filtres['eleve_id'] = $user['id'];
if ($role === 'parent') {
    // Sécurité (anti-IDOR + anti sur-exposition) : un parent ne doit JAMAIS voir
    // les parcours de tous les élèves de l'établissement. On le borne TOUJOURS à
    // un de SES enfants (vérifié contre parent_eleve). La clé session historique
    // `enfant_actif_id` n'était jamais alimentée -> le parent retombait dans la
    // branche « staff » et voyait tous les élèves. On s'appuie désormais sur la
    // clé canonique `selected_child_id` (posée/validée par la topbar) avec repli.
    $parentId = (int) ($user['id'] ?? 0);
    $childId  = (int) ($_SESSION['selected_child_id'] ?? $_SESSION['enfant_actif_id'] ?? 0);
    if ($childId && function_exists('parentOwnsEleve') && parentOwnsEleve($parentId, $childId)) {
        $filtres['eleve_id'] = $childId;
    } else {
        // Repli : premier enfant rattaché ; aucun -> 0 (ne montre rien).
        $stmtEnfant = $pdo->prepare("SELECT id_eleve FROM parent_eleve WHERE id_parent = ? ORDER BY id_eleve LIMIT 1");
        $stmtEnfant->execute([$parentId]);
        $filtres['eleve_id'] = (int) ($stmtEnfant->fetchColumn() ?: 0);
    }
}

$parcours = $parcoursService->getParcours($filtres);
$stats    = $parcoursService->getStatsByType($filtres['eleve_id'] ?? null);
$types    = ParcoursEducatifService::typesLabels();

/* POST valider */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($role, ['admin', 'professeur'])) {
    if (validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $parcoursService->valider((int)$_POST['entry_id'], true);
    }
    header('Location: ' . $_SERVER['REQUEST_URI']); exit;
}
?>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2><i class="fas fa-route me-2"></i><?= __('parcours_educatifs.title') ?></h2>
        <?php if (in_array($role, ['admin', 'professeur'])): ?>
            <a href="ajouter.php" class="btn btn-primary"><i class="fas fa-plus me-1"></i><?= __('parcours_educatifs.add') ?></a>
        <?php endif; ?>
    </div>

    <!-- Stats par type -->
    <div class="row g-3 mb-4">
        <?php foreach ($types as $key => $label): 
            $s = array_filter($stats, fn($r) => $r['type_parcours'] === $key);
            $s = $s ? array_values($s)[0] : ['total' => 0, 'valides' => 0];
        ?>
        <div class="col-md-3">
            <div class="parcours-type-card" style="border-left:4px solid <?= ParcoursEducatifService::typeColor($key) ?>">
                <div class="small fw-bold" style="color:<?= ParcoursEducatifService::typeColor($key) ?>"><?= $label ?></div>
                <div class="d-flex justify-content-between mt-1">
                    <span class="small text-muted"><?= $s['total'] ?> activité(s)</span>
                    <span class="small text-success"><?= $s['valides'] ?> validé(s)</span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filtres -->
    <form method="get" class="row g-2 mb-4 align-items-end">
        <div class="col-md-3">
            <label class="form-label"><?= __('label.type') ?></label>
            <select name="type" class="form-select form-select-sm">
                <option value=""><?= __('label.tous') ?></option>
                <?php foreach ($types as $k => $v): ?>
                    <option value="<?= $k ?>" <?= ($filtres['type_parcours'] === $k) ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Année scolaire</label>
            <input name="annee" class="form-control form-control-sm" placeholder="ex: 2024/2025" value="<?= htmlspecialchars($filtres['annee_scolaire']) ?>">
        </div>
        <div class="col-md-2"><button class="btn btn-sm btn-outline-primary"><?= __('btn.filter') ?></button></div>
    </form>

    <!-- Liste -->
    <?php if (empty($parcours)): ?>
        <div class="alert alert-info"><?= __('parcours_educatifs.no_activities') ?></div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th><?= __('label.type') ?></th><th><?= __('label.titre') ?></th>
                    <?php if (!in_array($role, ['eleve'])): ?><th><?= __('parcours_educatifs.student') ?></th><?php endif; ?>
                    <th><?= __('label.date') ?></th><th>Compétences</th><th><?= __('status.valide') ?></th>
                    <?php if (in_array($role, ['admin', 'professeur'])): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($parcours as $p): ?>
                <tr>
                    <td><span class="parcours-badge" style="background:<?= ParcoursEducatifService::typeColor($p['type_parcours']) ?>"><?= $types[$p['type_parcours']] ?? $p['type_parcours'] ?></span></td>
                    <td>
                        <strong><?= htmlspecialchars($p['titre']) ?></strong>
                        <?php if ($p['description']): ?><br><span class="small text-muted"><?= htmlspecialchars(mb_strimwidth($p['description'], 0, 80, '…')) ?></span><?php endif; ?>
                    </td>
                    <?php if (!in_array($role, ['eleve'])): ?><td class="small"><?= htmlspecialchars($p['eleve_nom'] ?? '#'.$p['eleve_id']) ?></td><?php endif; ?>
                    <td class="small"><?= date('d/m/Y', strtotime($p['date_activite'])) ?></td>
                    <td class="small"><?= htmlspecialchars($p['competences_visees'] ?? '—') ?></td>
                    <td>
                        <?php if ($p['validation']): ?>
                            <span class="badge bg-success"><i class="fas fa-check"></i></span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Non</span>
                        <?php endif; ?>
                    </td>
                    <?php if (in_array($role, ['admin', 'professeur'])): ?>
                    <td>
                        <?php if (!$p['validation']): ?>
                            <form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_hdr_csrf_token ?? '') ?>"><input type="hidden" name="entry_id" value="<?= $p['id'] ?>"><button class="btn btn-sm btn-outline-success">Valider</button></form>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
