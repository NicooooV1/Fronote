<?php
declare(strict_types=1);
/** Portail PLATEFORME — sécurité (comptes internes, sessions support, audit sensible). */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

use API\Platform\PlatformAccountService;
use API\Support\SupportSessionService;

$base = defined('BASE_URL') ? BASE_URL : '';
platformAuthorize('platform.security.view');

$pdo   = getPDO();
$accId = (int) ($_SESSION['platform']['account_id'] ?? 0);
$paSvc = new PlatformAccountService($pdo);
$ssSvc = new SupportSessionService($pdo);
$canManage = platformCan('platform.security.manage');

$audit = function (string $action, array $detail) use ($pdo, $accId): void {
    try {
        $pdo->prepare("INSERT INTO platform_audit_logs (platform_account_id, action, new_value, ip_address) VALUES (?, ?, ?, ?)")
            ->execute([$accId, $action, json_encode($detail, JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (\Throwable $e) { error_log('[platform security audit] ' . $e->getMessage()); }
};

$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken()) { $err = 'Session expirée.'; }
    elseif (!$canManage) { $err = "Action réservée à la permission platform.security.manage."; }
    else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'disable_account') {
                $tid = (int) $_POST['account_id'];
                if ($tid === $accId) { $err = "Vous ne pouvez pas désactiver votre propre compte."; }
                else { $paSvc->disableAccount($tid); $audit('platform.account.disable', ['account' => $tid]); $msg = 'Compte plateforme désactivé.'; }
            } elseif ($action === 'force_stop') {
                $ssSvc->securityStop((int) $_POST['session_id'], $accId, trim((string) ($_POST['reason'] ?? 'Arrêt de sécurité')));
                $msg = 'Session support arrêtée (sécurité).';
            } else { $err = 'Action inconnue.'; }
        } catch (\Throwable $e) { $err = $e->getMessage(); }
    }
}

$accounts = $pdo->query(
    "SELECT pa.id, pa.username, pa.email, pa.status, pa.last_login_at,
            (SELECT GROUP_CONCAT(pr.role_key) FROM platform_account_roles par JOIN platform_roles pr ON pr.id = par.platform_role_id WHERE par.platform_account_id = pa.id AND par.is_active = 1) AS roles
       FROM platform_accounts pa ORDER BY pa.username"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$activeSessions = $ssSvc->allActive();
$sensitive = [];
try {
    $sensitive = $pdo->query(
        "SELECT l.created_at, l.action, l.platform_account_id, pa.username AS actor FROM platform_audit_logs l
           LEFT JOIN platform_accounts pa ON pa.id = l.platform_account_id
          WHERE l.action LIKE '%purge%' OR l.action LIKE '%disable%' OR l.action LIKE '%security%' OR l.action LIKE '%restore%'
          ORDER BY l.id DESC LIMIT 30"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {}
$h = fn($s) => htmlspecialchars((string) $s);
/* Statut de compte → variante de pill. */
$pill = ['active' => 'ok', 'inactive' => 'muted', 'locked' => 'warn', 'archived' => 'crit'];

require_once __DIR__ . '/includes/layout.php';
pf_layout_header('security', 'Sécurité & comptes', 'Accès');
?>
<?php if ($msg): ?><div class="pf-notice pf-notice--ok"><i class="fas fa-circle-check"></i><span><?= $h($msg) ?></span></div><?php endif; ?>
<?php if ($err): ?><div class="pf-notice pf-notice--crit"><i class="fas fa-triangle-exclamation"></i><span><?= $h($err) ?></span></div><?php endif; ?>

<div class="pf-card" style="margin-top:16px">
    <div class="pf-card__head">
        <h2 class="pf-card__title"><i class="fas fa-user-shield"></i> Comptes internes Fronote</h2>
        <div class="pf-card__actions"><span class="pf-pill pf-pill--muted"><?= count($accounts) ?></span></div>
    </div>
    <div class="pf-card__body--flush">
        <div class="pf-table-wrap">
            <table class="pf-table">
                <thead><tr><th>Identifiant</th><th>Email</th><th>Rôles</th><th>Statut</th><th>Dernière connexion</th><th></th></tr></thead>
                <tbody>
                <?php if (!$accounts): ?><tr><td colspan="6"><div class="pf-empty">Aucun compte interne.</div></td></tr><?php endif; ?>
                <?php foreach ($accounts as $a): ?>
                    <tr>
                        <td><?= $h($a['username']) ?><?= (int) $a['id'] === $accId ? ' <span class="pf-muted">(vous)</span>' : '' ?></td>
                        <td class="pf-mono pf-muted"><?= $h($a['email']) ?></td>
                        <td><?php $roles = $a['roles'] ?? ''; if ($roles === '' || $roles === null): ?><span class="pf-muted">—</span><?php else: foreach (explode(',', (string) $roles) as $rk): ?><span class="pf-badge pf-badge--plateforme" style="margin:1px 2px 1px 0"><?= $h($rk) ?></span><?php endforeach; endif; ?></td>
                        <td><span class="pf-pill pf-pill--<?= $pill[$a['status']] ?? 'muted' ?>"><?= $h($a['status']) ?></span></td>
                        <td class="pf-mono pf-muted"><?= $h($a['last_login_at'] ?? '—') ?></td>
                        <td><?php if ($canManage && $a['status'] === 'active' && (int) $a['id'] !== $accId): ?>
                            <form style="display:inline" method="post" onsubmit="return confirm('Désactiver ce compte interne ?')"><?= csrfField() ?><input type="hidden" name="action" value="disable_account"><input type="hidden" name="account_id" value="<?= (int) $a['id'] ?>"><button class="pf-btn pf-btn--danger pf-btn--sm" type="submit"><i class="fas fa-user-slash"></i> Désactiver</button></form>
                        <?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="pf-card" style="margin-top:20px">
    <div class="pf-card__head">
        <h2 class="pf-card__title"><i class="fas fa-headset"></i> Sessions support actives</h2>
        <div class="pf-card__actions"><span class="pf-muted">tous établissements</span><span class="pf-pill pf-pill--<?= $activeSessions ? 'warn' : 'muted' ?>"><?= count($activeSessions) ?></span></div>
    </div>
    <div class="pf-card__body--flush">
        <div class="pf-table-wrap">
            <table class="pf-table">
                <thead><tr><th>Établissement</th><th>Niveau</th><th>Expire</th><th></th></tr></thead>
                <tbody>
                <?php if (!$activeSessions): ?><tr><td colspan="4"><div class="pf-empty">Aucune session active.</div></td></tr><?php endif; ?>
                <?php foreach ($activeSessions as $s): ?>
                    <tr>
                        <td><?= $h($s['establishment_name']) ?></td>
                        <td><span class="pf-pill pf-pill--info"><?= $h($s['access_level']) ?></span></td>
                        <td class="pf-mono pf-muted"><?= $h($s['expires_at']) ?></td>
                        <td><?php if ($canManage): ?>
                            <form style="display:inline" method="post" onsubmit="return confirm('Arrêt de sécurité de cette session ?')"><?= csrfField() ?><input type="hidden" name="action" value="force_stop"><input type="hidden" name="session_id" value="<?= (int) $s['id'] ?>"><button class="pf-btn pf-btn--danger pf-btn--sm" type="submit"><i class="fas fa-hand"></i> Arrêt sécurité</button></form>
                        <?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="pf-card" style="margin-top:20px">
    <div class="pf-card__head"><h2 class="pf-card__title"><i class="fas fa-clock-rotate-left"></i> Événements sensibles récents</h2></div>
    <div class="pf-card__body--flush">
        <div class="pf-table-wrap">
            <table class="pf-table pf-table--compact">
                <thead><tr><th>Date</th><th>Acteur</th><th>Action</th></tr></thead>
                <tbody>
                <?php if (!$sensitive): ?><tr><td colspan="3"><div class="pf-empty">Aucun événement sensible récent.</div></td></tr><?php endif; ?>
                <?php foreach ($sensitive as $l): ?>
                    <tr>
                        <td class="pf-mono pf-muted"><?= $h($l['created_at']) ?></td>
                        <td><?= $h($l['actor'] ?? ('#' . $l['platform_account_id'])) ?></td>
                        <td><span class="pf-badge pf-badge--soft"><?= $h($l['action']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
pf_layout_footer();
