<?php
declare(strict_types=1);
/** Portail PLATEFORME — mode maintenance (verrouille l'app établissement ; la console plateforme reste accessible). */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$base = defined('BASE_URL') ? BASE_URL : '';
platformAuthorize('platform.maintenance.manage');

$accId = (int) ($_SESSION['platform']['account_id'] ?? 0);
$pdo   = getPDO();
$maint = app('maintenance');
$myIp  = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

$audit = function (string $action, array $detail) use ($pdo, $accId): void {
    try {
        $pdo->prepare("INSERT INTO platform_audit_logs (platform_account_id, action, new_value, ip_address) VALUES (?, ?, ?, ?)")
            ->execute([$accId, $action, json_encode($detail, JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (\Throwable $e) { error_log('[platform maintenance audit] ' . $e->getMessage()); }
};

$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken()) { $err = 'Session expirée.'; }
    else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'activate') {
                // Validation stricte AVANT stockage : une entrée malformée (« /99 ») ferait planter
                // le filtre CIDR du bootstrap (ArithmeticError) sur CHAQUE requête → DoS du site.
                $raw = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) ($_POST['allowed_ips'] ?? '')))));
                $isValidIp = static function (string $r): bool {
                    if (filter_var($r, FILTER_VALIDATE_IP)) { return true; }
                    if (strpos($r, '/') !== false) {
                        [$s, $b] = explode('/', $r, 2);
                        return filter_var($s, FILTER_VALIDATE_IP) !== false && ctype_digit($b) && (int) $b >= 0 && (int) $b <= 32;
                    }
                    return false;
                };
                $bad = array_values(array_filter($raw, static fn($r) => !$isValidIp($r)));
                if ($bad) {
                    $err = 'IP/CIDR invalide(s) : ' . implode(', ', $bad) . ' — format attendu 1.2.3.4 ou 1.2.3.0/24.';
                } else {
                    // On injecte TOUJOURS l'IP courante (anti-lockout de l'app établissement).
                    $ips = $raw;
                    if ($myIp !== '' && !in_array($myIp, $ips, true)) { $ips[] = $myIp; }
                    $eta = ($_POST['eta_minutes'] ?? '') !== '' ? max(1, (int) $_POST['eta_minutes']) : null;
                    $maint->activate(trim((string) ($_POST['message'] ?? '')), $ips, $eta);
                    $audit('platform.maintenance.activate', ['allowed_ips' => $ips, 'eta_minutes' => $eta]);
                    $msg = 'Mode maintenance ACTIVÉ.' . ($myIp === '' ? " (IP non détectée — ajoutez-en une manuellement pour garder l'accès à l'app établissement.)" : '');
                }
            } elseif ($action === 'deactivate') {
                $maint->deactivate();
                $audit('platform.maintenance.deactivate', []);
                $msg = 'Mode maintenance désactivé.';
            } else { $err = 'Action inconnue.'; }
        } catch (\Throwable $e) { $err = $e->getMessage(); }
    }
}

$active  = $maint->isActive();
$status  = $maint->getStatus();
$curIps  = $status['allowed_ips'] ?? [];
$h = fn($s) => htmlspecialchars((string) $s);

// Style partagé des champs de formulaire (aligné sur les tokens du design system).
$fieldStyle = 'width:100%; box-sizing:border-box; padding:9px 11px; border:1px solid var(--pf-border); border-radius:var(--pf-radius-sm); background:var(--pf-surface); color:var(--pf-text); font-family:var(--pf-sans); font-size:13px;';
$labelStyle = 'display:block; margin:14px 0 5px; font-size:11px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:var(--pf-muted);';

require_once __DIR__ . '/includes/layout.php';
pf_layout_header('maintenance', 'Maintenance', 'Opérations');
?>

<?php if ($msg): ?><div class="pf-section"><div class="pf-notice pf-notice--ok"><i class="fas fa-circle-check"></i><span><?= $h($msg) ?></span></div></div><?php endif; ?>
<?php if ($err): ?><div class="pf-section"><div class="pf-notice pf-notice--crit"><i class="fas fa-triangle-exclamation"></i><span><?= $h($err) ?></span></div></div><?php endif; ?>

<section class="pf-section">
  <div class="pf-card">
    <div class="pf-card__head">
      <h2 class="pf-card__title"><i class="fas fa-screwdriver-wrench"></i> État du service</h2>
      <div class="pf-card__actions">
        <?php if ($active): ?><span class="pf-pill pf-pill--warn">Maintenance active</span><?php else: ?><span class="pf-pill pf-pill--ok">En ligne</span><?php endif; ?>
      </div>
    </div>
    <div class="pf-card__body">
      <?php if ($active): ?>
        <p class="pf-muted" style="margin-top:0;">Message : <?= $h($status['message'] ?? '') ?><?php if (!empty($curIps)): ?> · IP autorisées : <span class="pf-mono"><?= $h(implode(', ', $curIps)) ?></span><?php endif; ?></p>
        <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="deactivate">
          <button class="pf-btn pf-btn--primary" type="submit"><i class="fas fa-play"></i> Désactiver la maintenance</button>
        </form>
      <?php else: ?>
        <p class="pf-muted" style="margin:0;">L'application établissement est en ligne et accessible.</p>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="pf-section">
  <div class="pf-card">
    <div class="pf-card__head">
      <h2 class="pf-card__title"><i class="fas fa-triangle-exclamation"></i> Activer la maintenance</h2>
    </div>
    <div class="pf-card__body">
      <div class="pf-notice" style="margin-bottom:14px;">
        <i class="fas fa-circle-info"></i>
        <span>Verrouille l'application établissement (HTTP 503). La <strong>console plateforme reste accessible</strong>. Votre IP (<span class="pf-mono"><?= $h($myIp) ?></span>) est ajoutée automatiquement aux IP autorisées.</span>
      </div>
      <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="activate">
        <label style="<?= $labelStyle ?>">Message affiché aux utilisateurs</label>
        <textarea name="message" rows="2" placeholder="Maintenance en cours. Merci de votre patience." style="<?= $fieldStyle ?>"><?= $h($status['message'] ?? '') ?></textarea>
        <label style="<?= $labelStyle ?>">IP autorisées (séparées par des espaces/virgules, CIDR accepté)</label>
        <input name="allowed_ips" value="<?= $h(implode(' ', $curIps ?: [$myIp])) ?>" style="<?= $fieldStyle ?> font-family:var(--pf-mono);">
        <label style="<?= $labelStyle ?>">Durée estimée (minutes, optionnel)</label>
        <input name="eta_minutes" type="number" min="1" value="<?= $h((string) ($status['eta_minutes'] ?? '')) ?>" style="<?= $fieldStyle ?> font-family:var(--pf-mono);">
        <div style="margin-top:16px;"><button class="pf-btn pf-btn--primary" type="submit"><i class="fas fa-power-off"></i> Activer la maintenance</button></div>
      </form>
    </div>
  </div>
</section>

<?php pf_layout_footer(); ?>
