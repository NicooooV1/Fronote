<?php
declare(strict_types=1);
/** Portail PLATEFORME — mises à jour applicatives (git) via UpdateService (sauvegarde + rollback intégrés). */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$base = defined('BASE_URL') ? BASE_URL : '';
platformAuthorize('platform.updates.manage');

$accId = (int) ($_SESSION['platform']['account_id'] ?? 0);
$pdo   = getPDO();
$upd   = app('updates');
$canApply = platformCan('platform.system.update');

$audit = function (string $action, array $detail) use ($pdo, $accId): void {
    try {
        $pdo->prepare("INSERT INTO platform_audit_logs (platform_account_id, action, new_value, ip_address) VALUES (?, ?, ?, ?)")
            ->execute([$accId, $action, json_encode($detail, JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (\Throwable $e) { error_log('[platform updates audit] ' . $e->getMessage()); }
};

$msg = ''; $err = ''; $check = null; $apply = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken()) { $err = 'Session expirée.'; }
    else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'check') {
                $check = $upd->checkForUpdate(); // git fetch + comparaison (lecture seule)
                $gitOk = $upd->isGitAvailable();
                $audit('platform.updates.check', ['git_available' => $gitOk, 'behind' => $check['behind'] ?? 0]);
                $msg = $check ? "Mise à jour disponible ({$check['behind']} commit(s))." : ($gitOk ? 'Application à jour.' : 'Git indisponible — vérification impossible.');
            } elseif ($action === 'apply') {
                if (!$canApply) { $err = 'Réservé à platform.system.update.'; }
                elseif (strtoupper(trim((string) ($_POST['confirm'] ?? ''))) !== 'METTRE A JOUR') { $err = 'Saisissez « METTRE A JOUR » pour confirmer.'; }
                else {
                    @set_time_limit(300);
                    if (function_exists('session_write_close')) { session_write_close(); } // libère le verrou de session pendant l'opération longue
                    $apply = $upd->applyUpdate(); // sauvegarde + git reset + schéma/migrations + rollback auto si échec
                    $audit('platform.updates.apply', ['success' => $apply['success'] ?? false, 'old' => $apply['old_version'] ?? null, 'new' => $apply['new_version'] ?? null, 'rolled_back' => $apply['rolled_back'] ?? false]);
                    $msg = !empty($apply['success']) ? "Mise à jour appliquée ({$apply['old_version']} → {$apply['new_version']})." : ('Échec : ' . ($apply['error'] ?? 'inconnu') . (!empty($apply['rolled_back']) ? ' (restauré)' : ''));
                }
            } else { $err = 'Action inconnue.'; }
        } catch (\Throwable $e) { $err = $e->getMessage(); }
    }
}

$version = $upd->getCurrentVersion();
$branch  = $upd->getBranch();
$git     = $upd->isGitAvailable();
$h = fn($s) => htmlspecialchars((string) $s);
// Sortie git : on masque d'éventuels identifiants intégrés dans une URL de remote.
$redact = static fn($s) => htmlspecialchars(preg_replace('#://[^/@\s:]+:[^/@\s]+@#', '://***:***@', (string) $s));

// Style partagé des blocs de sortie git (mono, fond creux, défilement).
$preStyle = 'background:var(--pf-surface-2); border:1px solid var(--pf-border); border-radius:var(--pf-radius-sm); padding:12px; overflow:auto; font-family:var(--pf-mono); font-size:12px; margin:0;';

require_once __DIR__ . '/includes/layout.php';
pf_layout_header('updates', 'Mises à jour', 'Opérations');
?>

<?php if ($msg): ?><div class="pf-section"><div class="pf-notice pf-notice--ok"><i class="fas fa-circle-check"></i><span><?= $h($msg) ?></span></div></div><?php endif; ?>
<?php if ($err): ?><div class="pf-section"><div class="pf-notice pf-notice--crit"><i class="fas fa-triangle-exclamation"></i><span><?= $h($err) ?></span></div></div><?php endif; ?>

<section class="pf-section">
  <div class="pf-card">
    <div class="pf-card__head">
      <h2 class="pf-card__title"><i class="fas fa-cloud-arrow-down"></i> État applicatif</h2>
      <div class="pf-card__actions"><span class="pf-pill pf-pill--<?= $git ? 'ok' : 'muted' ?>">Git <?= $git ? 'disponible' : 'indisponible' ?></span></div>
    </div>
    <div class="pf-card__body">
      <div class="pf-row" style="gap:24px;">
        <div>
          <div class="pf-stat__label">Version</div>
          <div class="pf-mono" style="font-size:18px; font-weight:600;"><?= $h($version) ?></div>
        </div>
        <div>
          <div class="pf-stat__label">Branche</div>
          <div class="pf-mono" style="font-size:18px; font-weight:600;"><?= $h($branch) ?></div>
        </div>
      </div>
      <?php if ($git): ?>
        <div style="margin-top:16px;">
          <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="check"><button class="pf-btn pf-btn--primary" type="submit"><i class="fas fa-arrows-rotate"></i> Vérifier les mises à jour</button></form>
        </div>
      <?php else: ?>
        <p class="pf-muted" style="margin:16px 0 0;">Git n'est pas disponible — vérification/installation impossibles.</p>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if ($check !== null): ?>
<section class="pf-section">
  <div class="pf-card">
    <div class="pf-card__head">
      <h2 class="pf-card__title"><i class="fas fa-code-branch"></i> Mise à jour disponible</h2>
    </div>
    <div class="pf-card__body">
      <p style="margin-top:0;"><span class="pf-mono" style="font-weight:600;"><?= (int) ($check['behind'] ?? 0) ?></span> commit(s) en retard sur <span class="pf-mono"><?= $h($check['branch'] ?? $branch) ?></span>.</p>
      <?php if (!empty($check['commits'])): ?><pre style="<?= $preStyle ?>"><?php foreach ($check['commits'] as $c) { echo $redact($c) . "\n"; } ?></pre><?php endif; ?>
      <?php if ($canApply): ?>
      <form method="post" onsubmit="return confirm('Appliquer la mise à jour ? L\'app passe en maintenance, une sauvegarde est créée, rollback automatique en cas d\'échec.')" style="margin-top:14px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <?= csrfField() ?><input type="hidden" name="action" value="apply">
        <input name="confirm" placeholder="METTRE A JOUR" style="width:180px; padding:8px 10px; background:var(--pf-surface); border:1px solid var(--pf-crit); border-radius:var(--pf-radius-sm); color:var(--pf-crit); font-family:var(--pf-mono); font-size:12px;">
        <button class="pf-btn pf-btn--danger" type="submit"><i class="fas fa-download"></i> Installer maintenant</button>
      </form>
      <p class="pf-muted" style="margin:12px 0 0; font-size:12.5px;">Sauvegarde + maintenance + rollback automatique gérés par UpdateService.</p>
      <?php else: ?><p class="pf-muted" style="margin:14px 0 0;">Installation réservée à la permission <span class="pf-mono">platform.system.update</span>.</p><?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($apply !== null): ?>
<section class="pf-section">
  <div class="pf-card">
    <div class="pf-card__head">
      <h2 class="pf-card__title"><i class="fas fa-list-check"></i> Résultat de l'installation</h2>
      <div class="pf-card__actions"><span class="pf-pill pf-pill--<?= !empty($apply['success']) ? 'ok' : 'crit' ?>"><?= !empty($apply['success']) ? 'succès' : 'échec' ?></span></div>
    </div>
    <div class="pf-card__body">
      <?php if (!empty($apply['steps'])): ?><pre style="<?= $preStyle ?>"><?php foreach ($apply['steps'] as $s) { echo $redact($s) . "\n"; } ?></pre><?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php pf_layout_footer(); ?>
