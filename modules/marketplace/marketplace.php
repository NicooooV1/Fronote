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
requireRole('administrateur');

$marketplace = app('marketplace');
$message = null;
$messageType = 'info';
$errors = [];

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
        if (!preg_match('/\.fmod$/i', $name)) {
            $errors[] = 'Le fichier doit avoir l\'extension .fmod.';
        } else {
            $tmpDir = dirname(__DIR__, 2) . '/storage/tmp';
            if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
            $dest = $tmpDir . '/sideload_' . bin2hex(random_bytes(6)) . '.fmod';
            if (!move_uploaded_file($upload['tmp_name'], $dest)) {
                $errors[] = 'Impossible de déplacer le fichier téléversé.';
            } else {
                $result = $marketplace->installFromFmod($dest);
                @unlink($dest);
                if (!empty($result['success'])) {
                    $message = $result['message'];
                    $messageType = 'success';
                    logAudit('marketplace.install', 'module', null, null, [
                        'key'       => $result['module']['key']     ?? null,
                        'version'   => $result['module']['version'] ?? null,
                        'publisher' => $result['publisher']         ?? null,
                        'channel'   => 'sideload',
                    ]);
                } else {
                    $errors[] = $result['error'] ?? 'Échec inconnu.';
                    if (!empty($result['violations'])) {
                        foreach ($result['violations'] as $v) {
                            $errors[] = '— ' . (is_string($v) ? $v : json_encode($v));
                        }
                    }
                }
            }
        }
    }
}

// ─── Installations signées connues ──────────────────────────────────
$installed = [];
try {
    $st = $pdo->query("SELECT * FROM marketplace_installed ORDER BY installed_at DESC LIMIT 100");
    $installed = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    $errors[] = 'Table marketplace_installed indisponible (le module n\'est peut-être pas encore provisionné).';
}

$rootsDir = dirname(__DIR__, 2) . '/config/marketplace/roots';
$rootFiles = is_dir($rootsDir) ? array_values(array_filter(scandir($rootsDir) ?: [], fn($f) => str_ends_with($f, '.pub'))) : [];

include __DIR__ . '/../../templates/shared_header.php';
include __DIR__ . '/../../templates/shared_topbar.php';
include __DIR__ . '/../../templates/shared_topbar_nav.php';
?>
<div class="main-content">
    <h1 style="display:flex;align-items:center;gap:10px"><i class="fas fa-store"></i> Marketplace</h1>
    <p style="color:var(--text-muted,#64748b);max-width:780px">
        Installation locale (sideload) d'un paquet de module signé (<code>.fmod</code>). Chaque paquet est
        vérifié contre la <strong>chaîne de signature Ed25519</strong> qui remonte à une Root CA Fronote embarquée
        dans cette installation. Aucun module non signé ne peut être installé par cette voie.
    </p>

    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?>" style="padding:12px;border-radius:8px;background:#dcfce7;color:#166534;margin:16px 0">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" style="padding:12px;border-radius:8px;background:#fee2e2;color:#991b1b;margin:16px 0">
            <?php foreach ($errors as $err): ?>
                <div><?= htmlspecialchars((string) $err) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin:18px 0">
        <h2 style="margin:0 0 12px;font-size:1.1em">Téléverser un paquet <code>.fmod</code></h2>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_hdr_csrf_token ?? '') ?>">
            <input type="hidden" name="action" value="sideload">
            <input type="file" name="fmod" accept=".fmod,application/zip" required style="margin-bottom:10px">
            <button type="submit" style="padding:8px 18px;background:#0f4c81;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer">
                <i class="fas fa-shield-alt"></i> Vérifier &amp; installer
            </button>
        </form>
        <p style="margin-top:10px;font-size:.88em;color:#64748b">
            Root CA actuellement reconnues : <strong><?= count($rootFiles) ?></strong>
            <?= empty($rootFiles) ? ' — <span style="color:#b91c1c">aucune ; tout sideload sera refusé.</span>' : '' ?>
        </p>
    </section>

    <section style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin:18px 0">
        <h2 style="margin:0 0 12px;font-size:1.1em">Modules installés (signature vérifiée)</h2>
        <?php if (empty($installed)): ?>
            <p style="color:#64748b">Aucune installation signée enregistrée.</p>
        <?php else: ?>
            <table style="width:100%;border-collapse:collapse;font-size:.92em">
                <thead style="background:#f8fafc">
                    <tr>
                        <th style="text-align:left;padding:8px">Module</th>
                        <th style="text-align:left;padding:8px">Version</th>
                        <th style="text-align:left;padding:8px">Éditeur</th>
                        <th style="text-align:left;padding:8px">Canal</th>
                        <th style="text-align:left;padding:8px">Cert. fingerprint</th>
                        <th style="text-align:left;padding:8px">Vérifié</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($installed as $row): ?>
                    <tr style="border-top:1px solid #e2e8f0">
                        <td style="padding:8px"><code><?= htmlspecialchars((string) $row['module_key']) ?></code></td>
                        <td style="padding:8px"><?= htmlspecialchars((string) $row['version']) ?></td>
                        <td style="padding:8px"><?= htmlspecialchars((string) $row['publisher_id']) ?></td>
                        <td style="padding:8px"><?= htmlspecialchars((string) $row['channel']) ?></td>
                        <td style="padding:8px;font-family:monospace;font-size:.82em" title="<?= htmlspecialchars((string) $row['cert_fingerprint']) ?>">
                            <?= substr(htmlspecialchars((string) $row['cert_fingerprint']), 0, 16) ?>…
                        </td>
                        <td style="padding:8px"><?= htmlspecialchars((string) $row['signature_verified_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>
<?php include __DIR__ . '/../../templates/shared_footer.php'; ?>
