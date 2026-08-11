<?php
declare(strict_types=1);
/** Portail PLATEFORME — sauvegardes (lister / créer / supprimer / nettoyer / restaurer). */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$base = defined('BASE_URL') ? BASE_URL : '';
platformAuthorize('platform.backups.view');

$accId = (int) ($_SESSION['platform']['account_id'] ?? 0);
$pdo   = getPDO();
$backup = app('backup');
$canRestore = platformCan('platform.backups.restore');

$audit = function (string $action, array $detail) use ($pdo, $accId): void {
    try {
        $pdo->prepare("INSERT INTO platform_audit_logs (platform_account_id, action, new_value, ip_address) VALUES (?, ?, ?, ?)")
            ->execute([$accId, $action, json_encode($detail, JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (\Throwable $e) { error_log('[platform backups audit] ' . $e->getMessage()); }
};

$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken()) { $err = 'Session expirée.'; }
    else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'create_db') {
                $f = $backup->createDatabaseBackup(); $audit('platform.backup.create_db', ['file' => basename((string) $f)]);
                $msg = 'Sauvegarde base créée : ' . basename((string) $f);
            } elseif ($action === 'create_full') {
                $r = $backup->createFullBackup(); $audit('platform.backup.create_full', $r);
                $msg = 'Sauvegarde complète créée.';
            } elseif ($action === 'cleanup') {
                $n = $backup->cleanup(5); $audit('platform.backup.cleanup', ['deleted' => $n]);
                $msg = "Nettoyage : {$n} ancienne(s) sauvegarde(s) supprimée(s).";
            } elseif ($action === 'delete' && $canRestore) {
                $backup->deleteBackup((string) $_POST['filename']); $audit('platform.backup.delete', ['file' => (string) $_POST['filename']]);
                $msg = 'Sauvegarde supprimée.';
            } elseif ($action === 'restore') {
                if (!$canRestore) { $err = 'Restauration réservée à platform.backups.restore.'; }
                elseif (($_POST['confirm'] ?? '') !== 'RESTAURER') { $err = 'Saisissez RESTAURER pour confirmer.'; }
                else {
                    $fname = basename((string) $_POST['filename']);
                    $path = null;
                    foreach ($backup->listBackups() as $b) { if ($b['filename'] === $fname) { $path = $b['path']; break; } }
                    if ($path === null) { $err = 'Sauvegarde introuvable.'; }
                    else {
                        $audit('platform.backup.restore', ['file' => $fname]); // tracé AVANT (la restauration écrase la base)
                        $ok = $backup->restoreDatabase($path);
                        $msg = $ok ? "Base restaurée depuis {$fname}." : 'Échec de la restauration.';
                        if (!$ok) { $err = 'Échec de la restauration (voir logs).'; }
                    }
                }
            } else { $err = 'Action non autorisée.'; }
        } catch (\Throwable $e) { $err = $e->getMessage(); }
    }
}

$list = $backup->listBackups();
$h = fn($s) => htmlspecialchars((string) $s);

require_once __DIR__ . '/includes/layout.php';
pf_layout_header('backups', 'Sauvegardes', 'Opérations');
?>

<?php if ($msg): ?><div class="pf-section"><div class="pf-notice pf-notice--ok"><i class="fas fa-circle-check"></i><span><?= $h($msg) ?></span></div></div><?php endif; ?>
<?php if ($err): ?><div class="pf-section"><div class="pf-notice pf-notice--crit"><i class="fas fa-triangle-exclamation"></i><span><?= $h($err) ?></span></div></div><?php endif; ?>

<section class="pf-section">
  <div class="pf-row">
    <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="create_db"><button class="pf-btn pf-btn--primary" type="submit"><i class="fas fa-database"></i> Sauvegarde base</button></form>
    <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="create_full"><button class="pf-btn pf-btn--primary" type="submit"><i class="fas fa-box-archive"></i> Sauvegarde complète</button></form>
    <form method="post" onsubmit="return confirm('Supprimer les anciennes (garder 5/type) ?')"><?= csrfField() ?><input type="hidden" name="action" value="cleanup"><button class="pf-btn" type="submit"><i class="fas fa-broom"></i> Nettoyer (garder 5)</button></form>
  </div>
</section>

<section class="pf-section">
  <div class="pf-card">
    <div class="pf-card__head">
      <h2 class="pf-card__title"><i class="fas fa-database"></i> Sauvegardes disponibles</h2>
    </div>
    <div class="pf-card__body pf-card__body--flush">
      <div class="pf-table-wrap">
        <table class="pf-table">
          <thead><tr><th>Fichier</th><th>Type</th><th class="pf-num">Taille</th><th>Créé</th><th></th></tr></thead>
          <tbody>
          <?php if (!$list): ?><tr><td colspan="5"><div class="pf-empty">Aucune sauvegarde.</div></td></tr><?php endif; ?>
          <?php foreach ($list as $b): ?>
            <tr>
              <td class="pf-mono"><?= $h($b['filename']) ?></td>
              <td><span class="pf-pill pf-pill--<?= $b['type'] === 'database' ? 'info' : 'muted' ?>"><?= $h($b['type']) ?></span></td>
              <td class="pf-num"><?= $h($b['size_mb']) ?> Mo</td>
              <td class="pf-mono pf-muted" style="white-space:nowrap;"><?= $h($b['created_at']) ?></td>
              <td>
                <div class="pf-row" style="gap:6px; justify-content:flex-end;">
                  <?php if ($canRestore && $b['type'] === 'database'): ?>
                    <form method="post" onsubmit="return confirm('RESTAURER écrase la base actuelle. Continuer ?')" style="display:flex; gap:6px; align-items:center;"><?= csrfField() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="filename" value="<?= $h($b['filename']) ?>"><input name="confirm" placeholder="RESTAURER" style="width:120px; padding:6px 10px; background:var(--pf-surface); border:1px solid var(--pf-crit); border-radius:var(--pf-radius-sm); color:var(--pf-crit); font-family:var(--pf-mono); font-size:12px;"><button class="pf-btn pf-btn--danger pf-btn--sm" type="submit"><i class="fas fa-rotate-left"></i> Restaurer</button></form>
                  <?php endif; ?>
                  <?php if ($canRestore): ?>
                    <form method="post" onsubmit="return confirm('Supprimer cette sauvegarde ?')"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="filename" value="<?= $h($b['filename']) ?>"><button class="pf-btn pf-btn--sm" type="submit"><i class="fas fa-trash"></i></button></form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <p class="pf-muted" style="margin-top:14px; font-size:12.5px;">Stockées hors web dans <span class="pf-mono">storage/backups</span>. Restauration réservée à <span class="pf-mono">platform.backups.restore</span> (SuperAdmin/Maintenance), confirmation « RESTAURER » exigée.</p>
</section>

<?php pf_layout_footer(); ?>
