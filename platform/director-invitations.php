<?php
declare(strict_types=1);
/** Portail PLATEFORME — invitations Directeur (créer / lister / révoquer). */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

use API\Platform\DirectorInvitationService;

$base = defined('BASE_URL') ? BASE_URL : '';
platformAuthorize('platform.director_invites.create');

$pdo  = getPDO();
$accId = (int) ($_SESSION['platform']['account_id'] ?? 0);
$svc  = new DirectorInvitationService($pdo);

$msg = ''; $err = ''; $link = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken()) { $err = 'Session expirée.'; }
    else {
        try {
            if (($_POST['action'] ?? '') === 'create') {
                $type = (string) $_POST['invitation_type'];
                $opts = ['first_name' => $_POST['first_name'] ?? null, 'last_name' => $_POST['last_name'] ?? null, 'ttl_hours' => 72];
                if ($type !== 'create_establishment') {
                    $opts['allowed_establishment_ids'] = array_map('intval', (array) ($_POST['estabs'] ?? []));
                }
                $res = $svc->create($accId, trim((string) $_POST['email']), $type, $opts);
                $link = $base . '/director/accept.php?token=' . $res['token'];
                $msg = 'Invitation créée. Transmettez ce lien au Directeur (affiché une seule fois).';
            } elseif (($_POST['action'] ?? '') === 'revoke' && platformCan('platform.director_invites.revoke')) {
                $svc->revoke((int) $_POST['invite_id']);
                $msg = 'Invitation révoquée.';
            }
        } catch (\Throwable $e) { $err = $e->getMessage(); }
    }
}

$svc->expireStale();
$pending = $svc->listPending();
$etabs   = $pdo->query("SELECT id, nom FROM etablissements WHERE status NOT IN ('deleted','purged') ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$h = fn($s) => htmlspecialchars((string) $s);

/* Style de champ partagé (tokens plateforme, aucune feuille inline). */
$fld = 'width:100%;box-sizing:border-box;padding:8px 11px;margin:4px 0;border:1px solid var(--pf-border);border-radius:var(--pf-radius-sm);background:var(--pf-surface);color:var(--pf-text);font:inherit;font-size:13px';

require_once __DIR__ . '/includes/layout.php';
pf_layout_header('director-invitations', 'Invitations Directeur', 'Parc');
?>
<?php if ($msg): ?><div class="pf-notice pf-notice--ok"><i class="fas fa-circle-check"></i><span><?= $h($msg) ?></span></div><?php endif; ?>
<?php if ($err): ?><div class="pf-notice pf-notice--crit"><i class="fas fa-triangle-exclamation"></i><span><?= $h($err) ?></span></div><?php endif; ?>
<?php if ($link): ?>
    <div class="pf-notice pf-notice--warn" style="margin-top:12px;flex-direction:column;align-items:stretch;gap:8px">
        <div><i class="fas fa-link"></i> <strong>Lien d'invitation</strong> — affiché une seule fois, copiez-le maintenant.</div>
        <code class="pf-mono" style="display:block;padding:10px;border:1px dashed var(--pf-accent);border-radius:var(--pf-radius-sm);background:var(--pf-surface);color:var(--pf-text);word-break:break-all"><?= $h($link) ?></code>
    </div>
<?php endif; ?>

<div class="pf-grid pf-grid--2" style="margin-top:16px;align-items:start">
    <div class="pf-card">
        <div class="pf-card__head"><h2 class="pf-card__title"><i class="fas fa-envelope-open-text"></i> Nouvelle invitation</h2></div>
        <div class="pf-card__body">
            <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="create">
                <label class="pf-eyebrow" for="inv-email">Email du Directeur</label>
                <input id="inv-email" name="email" type="email" placeholder="directeur@etablissement.fr" required style="<?= $fld ?>">
                <div style="display:flex;gap:8px">
                    <input name="first_name" placeholder="Prénom" style="<?= $fld ?>">
                    <input name="last_name" placeholder="Nom" style="<?= $fld ?>">
                </div>
                <label class="pf-eyebrow" for="inv-type" style="display:block;margin-top:8px">Type d'invitation</label>
                <select id="inv-type" name="invitation_type" style="<?= $fld ?>">
                    <option value="create_establishment">Créer un établissement</option>
                    <option value="join_establishment">Rejoindre un établissement</option>
                    <option value="manage_multiple_establishments">Gérer plusieurs établissements</option>
                </select>
                <fieldset style="border:1px solid var(--pf-border);border-radius:var(--pf-radius-sm);margin-top:10px;padding:10px 12px">
                    <legend class="pf-eyebrow" style="padding:0 6px">Établissements (rejoindre / multi)</legend>
                    <?php if (!$etabs): ?><span class="pf-muted">Aucun établissement disponible.</span><?php endif; ?>
                    <?php foreach ($etabs as $e): ?>
                        <label style="display:flex;align-items:center;gap:8px;padding:3px 0;font-size:13px"><input type="checkbox" name="estabs[]" value="<?= (int) $e['id'] ?>"> <?= $h($e['nom']) ?> <span class="pf-mono pf-muted">#<?= (int) $e['id'] ?></span></label>
                    <?php endforeach; ?>
                </fieldset>
                <div style="margin-top:12px"><button class="pf-btn pf-btn--primary" type="submit"><i class="fas fa-paper-plane"></i> Créer l'invitation</button></div>
            </form>
        </div>
    </div>

    <div class="pf-card">
        <div class="pf-card__head">
            <h2 class="pf-card__title"><i class="fas fa-clock"></i> Invitations en attente</h2>
            <div class="pf-card__actions"><span class="pf-pill pf-pill--muted"><?= count($pending) ?></span></div>
        </div>
        <div class="pf-card__body--flush">
            <div class="pf-table-wrap">
                <table class="pf-table">
                    <thead><tr><th>Email</th><th>Type</th><th>Expire</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$pending): ?><tr><td colspan="4"><div class="pf-empty">Aucune invitation en attente.</div></td></tr><?php endif; ?>
                    <?php foreach ($pending as $i): ?>
                        <tr>
                            <td><?= $h($i['email']) ?></td>
                            <td><span class="pf-badge pf-badge--soft"><?= $h($i['invitation_type']) ?></span></td>
                            <td class="pf-mono pf-muted"><?= $h($i['expires_at']) ?></td>
                            <td><?php if (platformCan('platform.director_invites.revoke')): ?>
                                <form method="post" style="display:inline" onsubmit="return confirm('Révoquer ?')"><?= csrfField() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="invite_id" value="<?= (int) $i['id'] ?>"><button class="pf-btn pf-btn--danger pf-btn--sm" type="submit"><i class="fas fa-ban"></i> Révoquer</button></form>
                            <?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php
pf_layout_footer();
