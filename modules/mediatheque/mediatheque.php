<?php
/**
 * Module Médiathèque numérique.
 * Recherche + recommandations + favoris. Prof : suivi quota de stockage.
 */
$pageTitle  = 'Médiathèque';
$activePage = 'mediatheque';
require_once __DIR__ . '/../../API/module_boot.php';
requireRole('administrateur', 'professeur', 'eleve');

require_once __DIR__ . '/includes/MediathequeService.php';
$svc    = new \Mediatheque\MediathequeService($pdo);
$etabId = \API\Core\EstablishmentContext::id();
$role   = getUserRole();
$uid    = getUserId();

$q = trim($_GET['q'] ?? '');
$resultats = $q !== '' ? $svc->rechercherContenus($etabId, $q) : [];
$reco      = $q === '' ? $svc->getRecommandations($uid, $role, 12) : [];
$favoris   = $svc->getFavoris($uid, $role);
$quota     = $role === 'professeur' ? $svc->getQuotaInfo($uid) : null;

function med_card(array $c): string {
    $icon = match ($c['type_contenu'] ?? '') {
        'video' => 'fa-video', 'audio' => 'fa-headphones', 'pdf' => 'fa-file-pdf', default => 'fa-photo-video'
    };
    $h  = '<div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:14px;width:230px">';
    $h .= '<div style="font-size:1.6em;color:var(--primary,#0f4c81)"><i class="fas ' . $icon . '"></i></div>';
    $h .= '<div style="font-weight:600;margin-top:6px">' . htmlspecialchars($c['titre'] ?? '') . '</div>';
    if (!empty($c['professeur_nom'])) $h .= '<div style="font-size:.8em;color:var(--text-muted,#64748b)">' . htmlspecialchars($c['professeur_nom']) . '</div>';
    if (isset($c['note_moyenne']) && $c['note_moyenne'] !== null) $h .= '<div style="font-size:.8em;color:#f59e0b">★ ' . htmlspecialchars((string) $c['note_moyenne']) . '</div>';
    $h .= '</div>';
    return $h;
}

include __DIR__ . '/../../templates/shared_header.php';
include __DIR__ . '/../../templates/shared_topbar.php';
?>

<div style="max-width:1100px;margin:24px auto;padding:0 16px">
    <h1 style="font-size:1.5em;margin:0 0 16px"><i class="fas fa-photo-video"></i> Médiathèque numérique</h1>

    <form method="get" style="display:flex;gap:8px;margin-bottom:24px">
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Rechercher un contenu, une matière…" style="flex:1;padding:10px 14px;border:1px solid var(--border,#cbd5e0);border-radius:8px">
        <button type="submit" style="background:var(--primary,#0f4c81);color:#fff;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-weight:600"><i class="fas fa-search"></i></button>
    </form>

    <?php if ($quota !== null): ?>
    <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:14px 16px;margin-bottom:24px">
        <div style="display:flex;justify-content:space-between;font-size:.85em;margin-bottom:6px">
            <span>Stockage : <?= (int) $quota['utilise_mo'] ?> / <?= (int) $quota['quota_mo'] ?> Mo</span>
            <span style="color:var(--text-muted,#64748b)"><?= htmlspecialchars((string) $quota['pourcentage']) ?> %</span>
        </div>
        <div style="height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden">
            <div style="height:100%;width:<?= min(100, (float) $quota['pourcentage']) ?>%;background:<?= $quota['pourcentage'] > 90 ? '#dc2626' : 'var(--primary,#0f4c81)' ?>"></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($q !== ''): ?>
    <h2 style="font-size:1.1em;margin:0 0 12px">Résultats pour « <?= htmlspecialchars($q) ?> » (<?= count($resultats) ?>)</h2>
    <?php if (empty($resultats)): ?>
    <p style="color:var(--text-muted,#64748b);background:#f7fafc;padding:16px;border-radius:8px">Aucun contenu trouvé.</p>
    <?php else: ?>
    <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:28px"><?php foreach ($resultats as $c) echo med_card($c); ?></div>
    <?php endif; ?>
    <?php else: ?>
    <h2 style="font-size:1.1em;margin:0 0 12px"><i class="fas fa-magic"></i> Recommandé pour vous</h2>
    <?php if (empty($reco)): ?>
    <p style="color:var(--text-muted,#64748b);background:#f7fafc;padding:16px;border-radius:8px">Aucun contenu disponible pour le moment.</p>
    <?php else: ?>
    <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:28px"><?php foreach ($reco as $c) echo med_card($c); ?></div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($favoris)): ?>
    <h2 style="font-size:1.1em;margin:0 0 12px"><i class="fas fa-star"></i> Mes favoris (<?= count($favoris) ?>)</h2>
    <div style="display:flex;flex-wrap:wrap;gap:12px"><?php foreach ($favoris as $c) echo med_card($c); ?></div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../templates/shared_footer.php'; ?>
