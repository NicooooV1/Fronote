<?php
declare(strict_types=1);
/**
 * Portail PLATEFORME — GOUVERNANCE de la matrice rôles→permissions (établissements).
 *
 * Console centrale, propriété de la plateforme : elle définit, POUR TOUT LE PARC,
 * ce que chaque rôle d'établissement (\API\Security\RoleCatalog) accorde. Le
 * catalogue en code (RoleCatalog::grantsFor) reste la source de vérité ; on
 * n'écrit ici que des DÉVIATIONS dans la table GLOBALE rbac_grants :
 *   - force-grant  (granted = 1)  : accorde une permission absente du catalogue
 *   - force-deny   (granted = 0)  : retire une permission du catalogue (le deny gagne)
 *   - défaut       (aucune ligne) : retombe sur le catalogue (comportement d'origine)
 *
 * Remplace l'éditeur côté établissement (admin/modules/role_permissions.php) :
 * les directeurs ATTRIBUENT toujours les rôles aux utilisateurs, mais ne
 * configurent plus ce qu'un rôle accorde. Gate : platform.rbac.manage.
 */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

use API\Security\RoleCatalog;

$base = defined('BASE_URL') ? BASE_URL : '';
platformAuthorize('platform.rbac.manage');

$accId = (int) ($_SESSION['platform']['account_id'] ?? 0);
$pdo   = getPDO();
$h     = fn($s) => htmlspecialchars((string) $s);

/* Couleurs par tier (liseré du bandeau de rôle). Les chips utilisent .pf-badge--<tier>. */
$tierColors = [
    'plateforme'    => '#7c3aed',
    'direction'     => '#2563eb',
    'administratif' => '#0891b2',
    'vie_scolaire'  => '#16a34a',
    'pedagogique'   => '#ca8a04',
    'enseignant'    => '#ea580c',
    'famille'       => '#db2777',
    'eleve'         => '#64748b',
];
$tierColor = fn(string $t): string => $tierColors[$t] ?? '#64748b';

$rolesMeta = class_exists(RoleCatalog::class) ? RoleCatalog::roles() : [];
$permsMeta = class_exists(RoleCatalog::class) ? RoleCatalog::permissions() : [];

/* Défaut catalogue effectif pour (rôle, permission) — wildcards résolus. */
$catalogDefault = static function (string $role, string $pk) use ($permsMeta): bool {
    if (!class_exists(RoleCatalog::class)) return false;
    $grants = RoleCatalog::grantsFor($role);
    if (in_array('*', $grants, true)) return true;
    if (in_array($pk, $grants, true)) return true;
    return in_array(explode('.', $pk)[0] . '.*', $grants, true);
};

/* Rôle sélectionné (jamais super_admin : accès total non gouvernable). */
$selectedRole = (string) ($_POST['role'] ?? $_GET['role'] ?? '');
if (!isset($rolesMeta[$selectedRole])) {
    $selectedRole = $rolesMeta ? array_key_first($rolesMeta) : '';
}

$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken()) {
        $err = 'Session expirée.';
    } elseif (!platformCan('platform.rbac.manage')) {
        $err = 'Action non autorisée.';
    } else {
        $role = (string) ($_POST['role'] ?? '');
        if (!isset($rolesMeta[$role])) {
            $err = 'Rôle inconnu.';
        } elseif ($role === 'super_admin') {
            $err = 'Le super-administrateur a un accès total non gouvernable.';
        } else {
            $states = (array) ($_POST['state'] ?? []);
            // État courant des déviations pour ce rôle (avant écriture) → détecter les changements.
            $current = [];
            try {
                $st = $pdo->prepare("SELECT permission, granted FROM rbac_grants WHERE role = ?");
                $st->execute([$role]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $current[$r['permission']] = (int) $r['granted']; }
            } catch (\Throwable $e) { /* table absente → aucune déviation */ }

            $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
            $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

            $upsert = $pdo->prepare(
                "INSERT INTO rbac_grants (role, permission, granted, updated_by_platform_id, updated_at)
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE granted = VALUES(granted),
                                         updated_by_platform_id = VALUES(updated_by_platform_id),
                                         updated_at = NOW()"
            );
            $delete = $pdo->prepare("DELETE FROM rbac_grants WHERE role = ? AND permission = ?");
            $audit  = $pdo->prepare(
                "INSERT INTO platform_audit_logs (platform_account_id, action, target_type, target_id, new_value, ip_address, user_agent)
                 VALUES (?, ?, 'rbac_role', NULL, ?, ?, ?)"
            );

            $changes = 0;
            try {
                $pdo->beginTransaction();
                foreach (array_keys($permsMeta) as $pk) {
                    $want = $states[$pk] ?? 'default';       // default | grant | deny
                    if (!in_array($want, ['default', 'grant', 'deny'], true)) { $want = 'default'; }

                    $hadRow  = array_key_exists($pk, $current);
                    $wasVal  = $hadRow ? $current[$pk] : null; // 1 | 0 | null(=default)
                    $nowVal  = $want === 'grant' ? 1 : ($want === 'deny' ? 0 : null);

                    if ($nowVal === $wasVal) { continue; } // pas de changement

                    if ($nowVal === null) {
                        $delete->execute([$role, $pk]);
                        $action = 'rbac.grant.reset';
                    } else {
                        $upsert->execute([$role, $pk, $nowVal, $accId ?: null]);
                        $action = $nowVal === 1 ? 'rbac.grant.force_grant' : 'rbac.grant.force_deny';
                    }
                    $audit->execute([
                        $accId ?: null,
                        $action,
                        json_encode(['role' => $role, 'permission' => $pk, 'granted' => $nowVal], JSON_UNESCAPED_UNICODE),
                        $ip,
                        $ua,
                    ]);
                    $changes++;
                }
                $pdo->commit();
                $msg = $changes > 0
                    ? "Matrice mise à jour pour « " . ($rolesMeta[$role]['label'] ?? $role) . " » ({$changes} changement" . ($changes > 1 ? 's' : '') . ")."
                    : "Aucun changement pour « " . ($rolesMeta[$role]['label'] ?? $role) . " ».";
                $selectedRole = $role;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $err = "Erreur lors de l'enregistrement : " . $e->getMessage();
            }
        }
    }
}

/* Déviations actuelles du rôle sélectionné (pour préselectionner les tri-states). */
$deviations = [];
try {
    $st = $pdo->prepare("SELECT permission, granted FROM rbac_grants WHERE role = ?");
    $st->execute([$selectedRole]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $deviations[$r['permission']] = (int) $r['granted']; }
} catch (\Throwable $e) { /* table absente */ }

/* Permissions groupées par catégorie (domaine). */
$byCat = [];
foreach ($permsMeta as $pk => $meta) { $byCat[$meta['category'] ?? 'autre'][$pk] = $meta; }
ksort($byCat);

/* Rôles groupés par tier pour le roster de badges. */
$byTier = [];
foreach ($rolesMeta as $rk => $rm) { $byTier[$rm['tier'] ?? 'autre'][$rk] = $rm; }

$selMeta  = $rolesMeta[$selectedRole] ?? [];
$selTier  = (string) ($selMeta['tier'] ?? 'autre');
$selColor = $tierColor($selTier);
$devCount = count($deviations);

require_once __DIR__ . '/includes/layout.php';
pf_layout_header('roles', 'Rôles & permissions', 'Accès');
?>
<p class="pf-muted" style="max-width:70ch">Console centrale de la plateforme. Le catalogue applicatif reste la référence ; vous ne posez ici que des <strong>déviations globales</strong> (force-accorder / force-refuser), appliquées à <strong>tous les établissements</strong>. Les directeurs continuent d'attribuer les rôles aux utilisateurs.</p>

<?php if ($msg): ?><div class="pf-notice pf-notice--ok"><i class="fas fa-circle-check"></i><span><?= $h($msg) ?></span></div><?php endif; ?>
<?php if ($err): ?><div class="pf-notice pf-notice--crit"><i class="fas fa-triangle-exclamation"></i><span><?= $h($err) ?></span></div><?php endif; ?>

<?php if (!$rolesMeta): ?>
    <div class="pf-notice pf-notice--crit" style="margin-top:12px"><i class="fas fa-triangle-exclamation"></i><span>Catalogue des rôles introuvable (RoleCatalog).</span></div>
<?php else: ?>

<!-- Roster de rôles (chips par tier) — « des boutons, pas des listes » -->
<div class="pf-card" style="margin-top:16px">
    <div class="pf-card__head"><h2 class="pf-card__title"><i class="fas fa-users-gear"></i> Rôles d'établissement</h2></div>
    <div class="pf-card__body" style="display:flex;flex-direction:column;gap:14px">
        <?php foreach ($byTier as $tier => $roles): ?>
            <div>
                <div class="pf-eyebrow" style="margin-bottom:6px"><?= $h($tier) ?></div>
                <div class="pf-toggle-set">
                    <?php foreach ($roles as $rk => $rm): $sel = $rk === $selectedRole; ?>
                        <a class="pf-badge pf-badge--<?= $h($tier) ?>"
                           style="text-decoration:none;padding:5px 11px;font-size:12.5px;<?= $sel ? 'box-shadow:0 0 0 2px var(--pf-accent-ring);' : 'opacity:.78;' ?>"
                           href="?role=<?= urlencode($rk) ?>"<?= $sel ? ' aria-current="true"' : '' ?>>
                            <?= $h($rm['label'] ?? $rk) ?><?= !empty($rm['sensitive']) ? ' <i class="fas fa-triangle-exclamation" aria-hidden="true" style="font-size:10px"></i>' : '' ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <noscript>
            <form method="get" style="display:flex;gap:8px;margin-top:4px">
                <select name="role" onchange="this.form.submit()" style="padding:8px 11px;border:1px solid var(--pf-border);border-radius:var(--pf-radius-sm);background:var(--pf-surface);color:var(--pf-text);font:inherit;font-size:13px">
                    <?php foreach ($rolesMeta as $rk => $rm): ?>
                        <option value="<?= $h($rk) ?>" <?= $rk === $selectedRole ? 'selected' : '' ?>><?= $h($rm['label'] ?? $rk) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="pf-btn pf-btn--primary" type="submit">Charger</button>
            </form>
        </noscript>
    </div>
</div>

<!-- En-tête du rôle sélectionné -->
<div class="pf-card" style="margin-top:16px;border-left:4px solid <?= $h($selColor) ?>">
    <div class="pf-card__body">
        <div class="pf-row">
            <span class="pf-badge pf-badge--<?= $h($selTier) ?>"><?= $h($selMeta['label'] ?? $selectedRole) ?></span>
            <span class="pf-muted"><?php $desc = (string) ($selMeta['description'] ?? ''); echo $desc !== '' ? $h($desc) : 'Tier ' . $h($selTier) . ' · aucune description au catalogue.'; ?></span>
            <span style="margin-left:auto"><span class="pf-pill pf-pill--<?= $devCount > 0 ? 'info' : 'muted' ?>"><?= $devCount ?> déviation<?= $devCount > 1 ? 's' : '' ?></span></span>
        </div>
    </div>
</div>

<?php if ($selectedRole === 'super_admin'): ?>
    <div class="pf-notice pf-notice--crit" style="margin-top:16px"><i class="fas fa-crown"></i><span><strong>Super-administrateur</strong> : accès total non gouvernable (aucune déviation applicable).</span></div>
<?php else: ?>
<form method="post" style="margin-top:16px">
    <?= csrfField() ?>
    <input type="hidden" name="role" value="<?= $h($selectedRole) ?>">

    <?php foreach ($byCat as $cat => $perms): ?>
        <div class="pf-card" style="margin-bottom:14px">
            <div class="pf-card__head"><h3 class="pf-card__title" style="text-transform:capitalize"><i class="fas fa-layer-group"></i> <?= $h($cat) ?></h3></div>
            <div class="pf-card__body">
                <div class="pf-grid" style="grid-template-columns:repeat(auto-fill,minmax(300px,1fr))">
                    <?php foreach ($perms as $pk => $meta):
                        $def    = $catalogDefault($selectedRole, $pk);           // défaut catalogue (bool)
                        $devVal = array_key_exists($pk, $deviations) ? $deviations[$pk] : null;
                        $state  = $devVal === 1 ? 'grant' : ($devVal === 0 ? 'deny' : 'default');
                        $nm     = 'state[' . $pk . ']';
                        $radioStyle = 'position:absolute;opacity:0;width:0;height:0';
                        // Style par option (base compacte + surbrillance de l'état sélectionné).
                        $optStyle = function (string $opt) use ($state): string {
                            $s = 'padding:5px 10px;font-size:12px;';
                            if ($state === $opt && $opt === 'default') { $s .= 'background:var(--pf-surface-2);border-color:var(--pf-border-strong);color:var(--pf-text);'; }
                            return $s;
                        };
                        $optClass = function (string $opt) use ($state): string {
                            if ($state !== $opt) { return 'pf-toggle'; }
                            if ($opt === 'grant') { return 'pf-toggle is-on'; }
                            if ($opt === 'deny')  { return 'pf-toggle is-deny'; }
                            return 'pf-toggle';
                        };
                    ?>
                        <div style="border:1px solid var(--pf-border);border-radius:var(--pf-radius-sm);padding:10px 12px">
                            <div style="font-size:13px;font-weight:600<?= !empty($meta['sensitive']) ? ';color:var(--pf-crit)' : '' ?>"><?= $h($meta['label'] ?? $pk) ?><?= !empty($meta['sensitive']) ? ' <i class="fas fa-lock" aria-hidden="true" style="font-size:10px"></i>' : '' ?></div>
                            <div class="pf-mono pf-muted" style="font-size:11px;margin-bottom:8px"><?= $h($pk) ?></div>
                            <div class="pf-toggle-set pf-tri" role="group" aria-label="<?= $h($meta['label'] ?? $pk) ?>">
                                <label class="<?= $optClass('default') ?>" style="<?= $optStyle('default') ?>"><input type="radio" name="<?= $h($nm) ?>" value="default" <?= $state === 'default' ? 'checked' : '' ?> style="<?= $radioStyle ?>">Défaut</label>
                                <label class="<?= $optClass('grant') ?>" style="<?= $optStyle('grant') ?>"><input type="radio" name="<?= $h($nm) ?>" value="grant" <?= $state === 'grant' ? 'checked' : '' ?> style="<?= $radioStyle ?>">Accorder</label>
                                <label class="<?= $optClass('deny') ?>" style="<?= $optStyle('deny') ?>"><input type="radio" name="<?= $h($nm) ?>" value="deny" <?= $state === 'deny' ? 'checked' : '' ?> style="<?= $radioStyle ?>">Refuser</label>
                            </div>
                            <div class="pf-muted" style="font-size:11px;margin-top:6px">Catalogue : <?= $def ? 'accordé' : 'non accordé' ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (!$byCat): ?>
        <div class="pf-notice pf-notice--crit"><i class="fas fa-triangle-exclamation"></i><span>Aucune permission au catalogue.</span></div>
    <?php endif; ?>

    <div class="pf-row" style="position:sticky;bottom:0;background:var(--pf-bg);padding:14px 0;margin-top:8px;border-top:1px solid var(--pf-border);gap:12px">
        <button class="pf-btn pf-btn--primary" type="submit"><i class="fas fa-floppy-disk"></i> Enregistrer la matrice de ce rôle</button>
        <span class="pf-muted">« Défaut » supprime la déviation (retour au catalogue).</span>
    </div>
</form>
<?php endif; ?>

<?php endif; /* rolesMeta */ ?>

<script nonce="<?= csp_nonce() ?>">
// Reflet visuel immédiat du tri-state : Accorder=accent, Refuser=rouge, Défaut=neutre sélectionné.
document.querySelectorAll('.pf-tri').forEach(function (group) {
    group.addEventListener('change', function () {
        group.querySelectorAll('label').forEach(function (l) {
            l.classList.remove('is-on', 'is-deny');
            l.style.background = ''; l.style.borderColor = ''; l.style.color = '';
        });
        var checked = group.querySelector('input:checked');
        if (!checked) { return; }
        var lbl = checked.closest('label');
        if (checked.value === 'grant') { lbl.classList.add('is-on'); }
        else if (checked.value === 'deny') { lbl.classList.add('is-deny'); }
        else { lbl.style.background = 'var(--pf-surface-2)'; lbl.style.borderColor = 'var(--pf-border-strong)'; lbl.style.color = 'var(--pf-text)'; }
    });
});
</script>
<?php
pf_layout_footer();
