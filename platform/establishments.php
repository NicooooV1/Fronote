<?php
declare(strict_types=1);
/** Portail PLATEFORME — gestion du parc d'établissements (suspend/archive/delete/purge). */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

use API\Platform\PlatformEstablishmentService;

$base = defined('BASE_URL') ? BASE_URL : '';
platformAuthorize('platform.establishments.view');

$accId = (int) ($_SESSION['platform']['account_id'] ?? 0);
$svc = new PlatformEstablishmentService(getPDO());

$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken()) { $err = 'Session expirée.'; }
    else {
        $id = (int) ($_POST['id'] ?? 0);
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'suspend'  && platformCan('platform.establishments.suspend')) { $svc->suspend($id, $accId); $msg = 'Établissement suspendu.'; }
            elseif ($action === 'activate' && platformCan('platform.establishments.suspend')) { $svc->activate($id, $accId); $msg = 'Établissement réactivé.'; }
            elseif ($action === 'archive' && platformCan('platform.establishments.archive')) { $svc->archive($id, $accId); $msg = 'Établissement archivé (lecture seule).'; }
            elseif ($action === 'delete'  && platformCan('platform.establishments.delete')) { $svc->softDelete($id, $accId); $msg = 'Établissement supprimé (logique).'; }
            elseif ($action === 'purge'   && platformCan('platform.establishments.purge')) {
                if (($_POST['confirm'] ?? '') !== 'PURGER') { $err = 'Saisissez PURGER pour confirmer la purge définitive.'; }
                else { $r = $svc->purge($id, $accId); $msg = "Établissement purgé ({$r['purged_memberships']} appartenances, {$r['purged_accounts']} comptes, {$r['purged_tickets']} tickets)."; }
            } else { $err = "Action non autorisée."; }
        } catch (\Throwable $e) { $err = $e->getMessage(); }
    }
}

$list = $svc->listAll();
$canPurge = platformCan('platform.establishments.purge');

/* Recherche globale (top bar) : filtre nom / slug côté serveur. */
$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    $needle = mb_strtolower($q, 'UTF-8');
    $list = array_values(array_filter($list, static function ($e) use ($needle) {
        return mb_strpos(mb_strtolower((string) $e['nom'], 'UTF-8'), $needle) !== false
            || mb_strpos(mb_strtolower((string) $e['slug'], 'UTF-8'), $needle) !== false;
    }));
}

$h = fn($s) => htmlspecialchars((string) $s);
/* Statut → variante de pill de statut. */
$pill = [
    'active' => 'ok', 'onboarding' => 'info', 'suspended' => 'warn',
    'archived' => 'muted', 'draft' => 'muted', 'deleted' => 'crit', 'purged' => 'crit',
];

require_once __DIR__ . '/includes/layout.php';
pf_layout_header('establishments', 'Établissements', 'Parc');
?>
<?php if ($msg): ?><div class="pf-notice pf-notice--ok"><i class="fas fa-circle-check"></i><span><?= $h($msg) ?></span></div><?php endif; ?>
<?php if ($err): ?><div class="pf-notice pf-notice--crit"><i class="fas fa-triangle-exclamation"></i><span><?= $h($err) ?></span></div><?php endif; ?>

<div class="pf-card" style="margin-top:16px">
    <div class="pf-card__head">
        <h2 class="pf-card__title"><i class="fas fa-school"></i> Parc d'établissements</h2>
        <div class="pf-card__actions">
            <?php if ($q !== ''): ?><a class="pf-btn pf-btn--ghost pf-btn--sm" href="<?= $h($base) ?>/platform/establishments.php"><i class="fas fa-xmark"></i> « <?= $h($q) ?> »</a><?php endif; ?>
            <span class="pf-pill pf-pill--muted"><?= count($list) ?></span>
        </div>
    </div>
    <div class="pf-card__body--flush">
        <div class="pf-table-wrap">
            <table class="pf-table">
                <thead><tr><th>#</th><th>Nom</th><th>Slug</th><th>Type</th><th>Statut</th><th class="pf-num">Membres</th><th class="pf-num">Support</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (!$list): ?><tr><td colspan="8"><div class="pf-empty"><?= $q !== '' ? 'Aucun établissement pour « ' . $h($q) .' ».' : 'Aucun établissement.' ?></div></td></tr><?php endif; ?>
                <?php foreach ($list as $e): $st = $e['status']; ?>
                    <tr>
                        <td class="pf-mono"><?= (int) $e['id'] ?></td>
                        <td><?= $h($e['nom']) ?></td>
                        <td class="pf-mono pf-muted"><?= $h($e['slug']) ?></td>
                        <td class="pf-muted"><?= $h($e['type']) ?></td>
                        <td><span class="pf-pill pf-pill--<?= $pill[$st] ?? 'muted' ?>"><?= $h($st) ?></span></td>
                        <td class="pf-num"><?= (int) $e['members'] ?></td>
                        <td class="pf-num"><?= (int) $e['support_sessions'] ?></td>
                        <td>
                            <?php if ($st !== 'purged'): ?>
                                <div class="pf-row" style="gap:6px">
                                <?php if ($st === 'suspended'): ?>
                                    <?php if (platformCan('platform.establishments.suspend')): ?>
                                    <form style="display:inline" method="post"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="action" value="activate"><button class="pf-btn pf-btn--primary pf-btn--sm" type="submit"><i class="fas fa-play"></i> Réactiver</button></form>
                                    <?php endif; ?>
                                <?php elseif (platformCan('platform.establishments.suspend')): ?>
                                    <form style="display:inline" method="post" onsubmit="return confirm('Suspendre (bloque les connexions) ?')"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="action" value="suspend"><button class="pf-btn pf-btn--sm" type="submit"><i class="fas fa-pause"></i> Suspendre</button></form>
                                <?php endif; ?>
                                <?php if (platformCan('platform.establishments.archive') && $st !== 'archived'): ?>
                                    <form style="display:inline" method="post" onsubmit="return confirm('Archiver (lecture seule) ?')"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="action" value="archive"><button class="pf-btn pf-btn--sm" type="submit"><i class="fas fa-box-archive"></i> Archiver</button></form>
                                <?php endif; ?>
                                <?php if (platformCan('platform.establishments.delete') && $st !== 'deleted'): ?>
                                    <form style="display:inline" method="post" onsubmit="return confirm('Supprimer (logique) ?')"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="action" value="delete"><button class="pf-btn pf-btn--danger pf-btn--sm" type="submit"><i class="fas fa-trash"></i> Suppr.</button></form>
                                <?php endif; ?>
                                <?php if ($canPurge): ?>
                                    <form style="display:inline-flex;gap:6px;align-items:center" method="post" onsubmit="return confirm('PURGE DÉFINITIVE — irréversible. Continuer ?')"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="action" value="purge"><input name="confirm" placeholder="PURGER" style="width:88px;padding:5px 9px;border:1px solid var(--pf-crit);border-radius:var(--pf-radius-sm);background:var(--pf-surface);color:var(--pf-crit);font:inherit;font-family:var(--pf-mono);font-size:12px"><button class="pf-btn pf-btn--danger pf-btn--sm" type="submit"><i class="fas fa-radiation"></i> Purger</button></form>
                                <?php endif; ?>
                                </div>
                            <?php else: ?><span class="pf-muted">purgé</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<p class="pf-muted" style="margin-top:16px">Suspendre = connexions bloquées · Archiver = lecture seule · Supprimer = logique (réversible) · Purger = définitif (SuperAdmin), supprime les données 3-mondes de l'établissement.</p>
<?php
pf_layout_footer();
