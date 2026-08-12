<?php
/**
 * Marketplace — Page principale.
 *
 * Affiche les modules installés via signature et expose le sideload d'un
 * paquet .fmod (téléversement local, vérifié contre les Root CA embarquées).
 *
 * Pas de catalogue distant pour la phase MVP : on accepte uniquement le sideload.
 */

declare(strict_types=1);

$pageTitle  = 'Marketplace';
$activePage = 'marketplace';

require_once __DIR__ . '/../../API/module_boot.php';
requireCapability('module.marketplace.access'); // (administrateur par défaut — éditable plateforme)

$marketplace    = app('marketplace');
$message        = null;
$messageType    = 'info';
$errors         = [];
$pendingConsent = null;
$testAllowed    = $marketplace->isTestModulesAllowed();

// ─── Confirm install (après consentement) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_install') {
    if (!\API\Core\Facades\CSRF::validate($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token CSRF invalide.';
    } else {
        $sess = $_SESSION['marketplace_pending'] ?? null;
        if (!$sess || ($sess['expires_at'] ?? 0) < time()) {
            unset($_SESSION['marketplace_pending']);
            $errors[] = 'Session d\'installation expirée — recommencez.';
        } else {
            $granted  = array_keys(array_filter($_POST['consent'] ?? [], fn($v) => $v === '1'));
            $missing  = array_diff($sess['permissions'] ?? [], $granted);
            if (!empty($missing)) {
                $errors[] = 'Accordez toutes les permissions : ' . implode(', ', $missing);
            } else {
                try {
                    $user = app('auth')->user();
                    app('db')->getConnection()->prepare(
                        "INSERT INTO marketplace_consents (module_key, version, permissions_granted, granted_by, granted_by_name, granted_at)
                         VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE permissions_granted=VALUES(permissions_granted),granted_by=VALUES(granted_by),granted_by_name=VALUES(granted_by_name),granted_at=NOW()"
                    )->execute([$sess['manifest']['key'] ?? '', $sess['manifest']['version'] ?? '', json_encode($granted), $user['id'] ?? null, trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''))]);
                } catch (\Throwable $e) { error_log('[marketplace.php] ' . $e->getMessage()); }

                $result = $marketplace->confirmInstall($sess['staging'], $sess['manifest'], $sess['fmod_path'], $sess['sig_data']);
                unset($_SESSION['marketplace_pending']);
                if ($result['success']) {
                    $message = $result['message']; $messageType = 'success';
                    app('audit')->log('marketplace.install', null, ['new' => ['key' => $result['module']['key'] ?? null, 'version' => $result['module']['version'] ?? null, 'consent' => $granted]]);
                } else {
                    $errors[] = $result['error'] ?? 'Échec.';
                }
            }
        }
    }
}

// ─── Sideload : upload d'un .fmod ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sideload') {
    if (!\API\Core\Facades\CSRF::validate($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        $errors[] = 'Token CSRF invalide. Rafraîchissez la page.';
    } elseif (empty($_FILES['fmod']) || $_FILES['fmod']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Aucun fichier reçu.';
    } else {
        $upload = $_FILES['fmod'];
        $name = basename((string) $upload['name']);
        $maxBytes = 50 * 1024 * 1024; // 50 MB
        if (($upload['size'] ?? 0) > $maxBytes) {
            $errors[] = 'Fichier trop volumineux (max 50 Mo).';
        } elseif (!preg_match('/\.fmod$/i', $name)) {
            $errors[] = 'Le fichier doit avoir l\'extension .fmod.';
        } else {
            $tmpDir = dirname(__DIR__, 2) . '/storage/tmp';
            if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
            $dest = $tmpDir . '/sideload_' . bin2hex(random_bytes(6)) . '.fmod';
            if (!move_uploaded_file($upload['tmp_name'], $dest)) {
                $errors[] = 'Impossible de déplacer le fichier téléversé.';
            } else {
                $result = $marketplace->installFromFmod($dest);

                if (!empty($result['pending_consent'])) {
                    $_SESSION['marketplace_pending'] = [
                        'staging'    => $result['staging'],
                        'manifest'   => $result['manifest'],
                        'permissions'=> $result['permissions'],
                        'sig_data'   => $result['sig_data'],
                        'fmod_path'  => $result['fmod_path'],
                        'expires_at' => time() + 600,
                    ];
                    $pendingConsent = $result;
                    // staging gardé en vie; le .fmod original n'est plus nécessaire
                    @unlink($dest);
                } else {
                    @unlink($dest);
                    if (!empty($result['success'])) {
                        $message = $result['message']; $messageType = 'success';
                        app('audit')->log('marketplace.install', null, ['new' => ['key' => $result['module']['key'] ?? null, 'version' => $result['module']['version'] ?? null, 'publisher' => $result['publisher'] ?? null, 'channel' => 'sideload']]);
                    } else {
                        $errors[] = $result['error'] ?? 'Échec inconnu.';
                        if (!empty($result['violations'])) {
                            foreach ($result['violations'] as $v) {
                                $errors[] = '— ' . htmlspecialchars(is_string($v) ? $v : json_encode($v), ENT_QUOTES, 'UTF-8');
                            }
                        }
                    }
                }
            }
        }
    }
}

// ─── Installations signées connues ──────────────────────────────────
try {
    $allInstalled = $marketplace->getInstalled();
} catch (\Throwable $e) {
    $allInstalled = [];
    $errors[] = 'Table marketplace_installed indisponible.';
}
$page      = max(1, (int) ($_GET['page'] ?? 1));
$perPage   = 20;
$total     = count($allInstalled);
$installed = array_slice($allInstalled, ($page - 1) * $perPage, $perPage);
$pages     = max(1, (int) ceil((float) ($total / $perPage)));

$rootsDir  = BASE_PATH . '/config/marketplace/roots';
$rootFiles = is_dir($rootsDir) ? array_values(array_filter(scandir($rootsDir) ?: [], fn($f) => str_ends_with($f, '.pub'))) : [];

$PERM_LABELS = [
    'db_read' => 'Lecture BDD', 'db_write' => 'Écriture BDD',
    'filesystem' => 'Accès fichiers', 'network' => 'Appels réseau', 'email' => 'Envoi emails',
];

include __DIR__ . '/../../templates/shared_header.php';
include __DIR__ . '/../../templates/shared_topbar.php';
?>
<h1 style="display:flex;align-items:center;gap:10px"><i class="fas fa-store"></i> Marketplace</h1>

<?php if (!$testAllowed): ?>
<div style="background:#fef3c7;border:1px solid #d97706;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:.9em">
    <strong>⚠ Mode production</strong> — Modules <code>test_only</code> refusés. Ajoutez <code>ALLOW_TEST_MODULES=true</code> dans .env pour les instances dev/staging.
</div>
<?php endif; ?>

    <?php if ($message): ?>
        <div style="padding:12px;border-radius:8px;background:#dcfce7;color:#166534;margin:16px 0">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div style="padding:12px;border-radius:8px;background:#fee2e2;color:#991b1b;margin:16px 0">
            <?php foreach ($errors as $err): ?><div><?= htmlspecialchars((string) $err) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php if ($pendingConsent): ?>
    <!-- ─── Écran consentement ─── -->
    <section style="background:#fffbeb;border:2px solid #f59e0b;border-radius:10px;padding:20px;margin:18px 0">
        <h2 style="margin:0 0 12px;color:#b45309"><i class="fas fa-exclamation-triangle"></i> <?= __('marketplace.consentement_requis') ?></h2>
        <p>Le module <strong><?= htmlspecialchars($pendingConsent['manifest']['key'] ?? '') ?> v<?= htmlspecialchars($pendingConsent['manifest']['version'] ?? '') ?></strong>
           demande les permissions suivantes. Cochez chacune pour continuer.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_hdr_csrf_token ?? '') ?>">
            <input type="hidden" name="action" value="confirm_install">
            <table style="margin:12px 0">
                <?php foreach ($pendingConsent['permissions'] as $perm): ?>
                <tr><td style="padding:8px 0">
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                        <input type="checkbox" name="consent[<?= htmlspecialchars($perm) ?>]" value="1" required>
                        <strong><?= htmlspecialchars($perm) ?></strong> — <?= htmlspecialchars($PERM_LABELS[$perm] ?? $perm) ?>
                    </label>
                </td></tr>
                <?php endforeach; ?>
            </table>
            <div style="display:flex;gap:10px;margin-top:12px">
                <button type="submit" style="padding:8px 18px;background:#b45309;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer">
                    <i class="fas fa-check"></i> <?= __('marketplace.accorder_installer') ?>
                </button>
                <a href="?" style="padding:8px 18px;background:#e5e7eb;color:#374151;border-radius:6px;text-decoration:none;line-height:2"><?= __('btn.cancel') ?></a>
            </div>
        </form>
    </section>
<?php else: ?>
    <section style="background:var(--bg-card);border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin:18px 0">
        <h2 style="margin:0 0 12px;font-size:1.1em"><?= __('marketplace.televerser_paquet') ?> <code>.fmod</code></h2>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_hdr_csrf_token ?? '') ?>">
            <input type="hidden" name="action" value="sideload">
            <input type="file" name="fmod" accept=".fmod" required style="margin-bottom:10px">
            <button type="submit" style="padding:8px 18px;background:#0f4c81;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer">
                <i class="fas fa-shield-alt"></i> <?= __('marketplace.verifier_installer') ?>
            </button>
        </form>
        <p style="margin-top:10px;font-size:.88em;color:#64748b">
            <?= __('marketplace.root_ca_reconnues') ?> <strong><?= count($rootFiles) ?></strong>
            <?php foreach ($rootFiles as $rf): ?><code style="margin-left:6px"><?= htmlspecialchars($rf) ?></code><?php endforeach; ?>
            <?= empty($rootFiles) ? ' — <span style="color:#b91c1c">aucune ; tout sideload sera refusé.</span>' : '' ?>
        </p>
    </section>
<?php endif; ?>

    <section style="background:var(--bg-card);border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin:18px 0">
        <h2 style="margin:0 0 12px;font-size:1.1em"><?= __('marketplace.modules_installes_signature') ?> (<?= $total ?>)</h2>
        <?php if (empty($installed)): ?>
            <p style="color:#64748b"><?= __('marketplace.aucune_installation') ?></p>
        <?php else: ?>
            <table style="width:100%;border-collapse:collapse;font-size:.92em">
                <thead style="background:var(--bg-secondary)">
                    <tr>
                        <th style="text-align:left;padding:8px"><?= __('marketplace.module') ?></th>
                        <th style="text-align:left;padding:8px"><?= __('marketplace.version') ?></th>
                        <th style="text-align:left;padding:8px"><?= __('marketplace.editeur') ?></th>
                        <th style="text-align:left;padding:8px"><?= __('marketplace.canal') ?></th>
                        <th style="text-align:left;padding:8px"><?= __('marketplace.cert_fingerprint') ?></th>
                        <th style="text-align:left;padding:8px"><?= __('marketplace.verifie') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($installed as $row): ?>
                    <tr style="border-top:1px solid #e2e8f0">
                        <td style="padding:8px">
                            <code><?= htmlspecialchars((string) $row['module_key']) ?></code>
                            <?php if (($row['channel'] ?? '') === 'sideload'): ?>
                                <span style="background:#e0f2fe;color:#0369a1;padding:1px 6px;border-radius:8px;font-size:.78em;margin-left:4px">sideload</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:8px"><?= htmlspecialchars((string) $row['version']) ?></td>
                        <td style="padding:8px"><?= htmlspecialchars((string) $row['publisher_id']) ?></td>
                        <td style="padding:8px"><?= htmlspecialchars((string) $row['channel']) ?></td>
                        <td style="padding:8px;font-family:monospace;font-size:.82em" title="<?= htmlspecialchars((string) $row['cert_fingerprint']) ?>">
                            <?= htmlspecialchars(substr((string) $row['cert_fingerprint'], 0, 16)) ?>…
                        </td>
                        <td style="padding:8px"><?= htmlspecialchars((string) $row['signature_verified_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($pages > 1): ?>
            <div style="display:flex;gap:6px;margin-top:12px;justify-content:center">
                <?php for ($p = 1; $p <= $pages; $p++): ?>
                    <a href="?page=<?= $p ?>" style="padding:4px 10px;border-radius:4px;text-decoration:none;<?= $p === $page ? 'background:#0f4c81;color:#fff;font-weight:600' : 'background:#f1f5f9;color:#374151' ?>"><?= $p ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php include __DIR__ . '/../../templates/shared_footer.php'; ?>
