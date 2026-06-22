<?php
/**
 * Administration — Gestionnaire de thèmes
 * Installer, prévisualiser, activer et supprimer des thèmes CSS.
 */
require_once __DIR__ . '/../../API/core.php';
require_once __DIR__ . '/../includes/admin_functions.php';

requireAuth();
tenantGate('tenant.users.manage', ['administrateur']); // durci: page mutante, exige *.manage (exclut le role lecture seule responsable_permissions)

$themeService = app('themes');
$message = '';
$messageType = '';

// ─── Actions POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['csrf_token'] ?? '') === ($_SESSION['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        $result = $themeService->uploadTheme(
            $_FILES['css_file'] ?? [],
            $_POST['theme_key'] ?? '',
            $_POST['theme_name'] ?? '',
            $_POST['theme_description'] ?? '',
            $_FILES['preview_file'] ?? null
        );
        $message = $result['message'] ?? $result['error'] ?? '';
        $messageType = $result['success'] ? 'success' : 'error';
        if ($result['success']) {
            logAudit('theme.uploaded', 'themes', null, null, ['key' => $_POST['theme_key']]);
        }
    }

    if ($action === 'set_default') {
        $key = $_POST['theme_key'] ?? 'classic';
        // Liste blanche : apparences de la refonte + thèmes CSS intégrés + thème custom installé.
        $allowedDefault = ['classic', 'glass', 'light', 'dark', 'liquid', 'auto'];
        if (!in_array($key, $allowedDefault, true)) {
            try { if (!$themeService->cssFileFor($key)) { $key = 'classic'; } }
            catch (\Throwable $e) { $key = 'classic'; }
        }
        $themeService->setDefault($key);
        $message = 'Thème par défaut mis à jour.';
        $messageType = 'success';
        logAudit('theme.default_changed', 'themes', null, null, ['key' => $key]);
    }

    if ($action === 'delete') {
        $result = $themeService->delete($_POST['theme_key'] ?? '');
        $message = $result['message'] ?? $result['error'] ?? '';
        $messageType = $result['success'] ? 'success' : 'error';
    }

    if ($action === 'install_remote') {
        $marketplace = app('marketplace');
        $result = $marketplace->installTheme($_POST['theme_key'] ?? '');
        $message = $result['message'] ?? $result['error'] ?? '';
        $messageType = $result['success'] ? 'success' : 'error';
    }

    if ($action === 'save_tokens') {
        $targetTheme = preg_match('/^[a-z0-9_-]{2,30}$/', $_POST['target_theme'] ?? '') ? $_POST['target_theme'] : 'classic';
        $raw = $_POST['tok'] ?? [];
        $overrides = [];
        foreach (\API\Services\ThemeService::EDITABLE_TOKENS as $name => $default) {
            $v = trim($raw[$name] ?? '');
            if ($v !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
                $overrides[$name] = $v;
            }
        }
        $themeService->saveTokenOverrides($targetTheme, $overrides);
        logAudit('theme.tokens_saved', 'theme_token_overrides', null, null, ['theme' => $targetTheme]);

        // Validation contraste (WCAG) sur les couleurs effectives.
        $report = $themeService->contrastReport(array_merge(\API\Services\ThemeService::EDITABLE_TOKENS, $overrides));
        $fails = array_filter($report, static fn($r) => !$r['pass']);
        if (empty($fails)) {
            $message = "Tokens enregistrés pour « {$targetTheme} ». Contraste WCAG : OK.";
            $messageType = 'success';
        } else {
            $warns = array_map(static fn($r) => $r['label'] . ' (' . $r['ratio'] . ':1 < ' . $r['min'] . ')', $fails);
            $message = "Tokens enregistrés pour « {$targetTheme} », mais contraste insuffisant : " . implode(' ; ', $warns) . '.';
            $messageType = 'error';
        }
    }
}

// ─── Données ─────────────────────────────────────────────────────
$themes = $themeService->getAll();
$defaultTheme = $themeService->getDefault();
$tokens = $themeService->getTokens();

// Designer : thème en cours d'édition + overrides existants + rapport contraste.
$editTheme = preg_match('/^[a-z0-9_-]{2,30}$/', $_GET['edit_theme'] ?? '') ? $_GET['edit_theme'] : 'classic';
$editOverrides = $themeService->getTokenOverrides($editTheme);
$editEffective = array_merge(\API\Services\ThemeService::EDITABLE_TOKENS, $editOverrides);
$editContrast = $themeService->contrastReport($editEffective);
$remoteCatalog = [];
try {
    $remoteCatalog = app('marketplace')->getCatalog('theme');
} catch (\Throwable $e) {}

$pageTitle = 'Gestion des thèmes';
$currentPage = 'themes';
$extraCss = ['../../assets/css/admin.css'];

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

include __DIR__ . '/../includes/header.php';
?>

<style>
.th-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.th-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-bottom:32px}
.th-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;transition:.2s}
.th-card:hover{box-shadow:0 4px 12px rgba(0,0,0,.08)}
.th-card.active{border-color:#667eea;box-shadow:0 0 0 2px rgba(102,126,234,.3)}
.th-preview{height:120px;background:#f7fafc;display:flex;align-items:center;justify-content:center;color:#cbd5e0;font-size:2em;position:relative}
.th-preview img{width:100%;height:100%;object-fit:cover}
.th-default-badge{position:absolute;top:8px;right:8px;background:#667eea;color:#fff;font-size:.7em;padding:3px 10px;border-radius:12px;font-weight:600}
.th-body{padding:16px}
.th-name{font-weight:700;font-size:.95em;color:#2d3748}
.th-desc{font-size:.82em;color:#718096;margin-top:4px}
.th-meta{display:flex;justify-content:space-between;align-items:center;margin-top:8px;font-size:.78em;color:#a0aec0}
.th-actions{display:flex;gap:6px;margin-top:12px}
.th-btn{padding:5px 12px;border:none;border-radius:5px;cursor:pointer;font-size:.8em;font-weight:600}
.th-btn-primary{background:#667eea;color:#fff}
.th-btn-danger{background:#fc8181;color:#fff}
.th-btn-outline{background:transparent;border:1px solid #e2e8f0;color:#4a5568}
.th-section{margin-bottom:32px}
.th-section h3{font-size:1em;color:#4a5568;margin-bottom:14px;padding-bottom:8px;border-bottom:2px solid #e2e8f0}
.th-upload{background:#f7fafc;border:2px dashed #e2e8f0;border-radius:10px;padding:24px;margin-bottom:24px}
.th-upload label{display:block;font-size:.85em;color:#4a5568;margin-bottom:6px;font-weight:600}
.th-upload input[type=text],.th-upload textarea{width:100%;padding:8px 12px;border:1px solid #e2e8f0;border-radius:6px;font-size:.9em;margin-bottom:12px;box-sizing:border-box}
.th-upload input[type=file]{margin-bottom:12px}
.th-tokens{max-height:300px;overflow-y:auto;font-size:.82em}
.th-token-row{display:flex;align-items:center;gap:10px;padding:4px 0;border-bottom:1px solid #f7fafc}
.th-token-name{font-family:monospace;color:#667eea;min-width:220px}
.th-token-swatch{width:24px;height:24px;border-radius:4px;border:1px solid #e2e8f0;flex-shrink:0}
</style>

<?php if ($message): ?>
<div style="margin-bottom:16px;padding:12px 16px;border-radius:8px;background:<?= $messageType === 'success' ? '#f0fff4' : '#fff5f5' ?>;color:<?= $messageType === 'success' ? '#276749' : '#c53030' ?>;border:1px solid <?= $messageType === 'success' ? '#c6f6d5' : '#fed7d7' ?>">
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<div class="th-header">
    <h2><i class="fas fa-palette"></i> Gestion des thèmes</h2>
</div>

<!-- Apparence par défaut de l'établissement (refonte : clair/sombre/liquide/auto) -->
<div class="th-section">
    <h3><i class="fas fa-wand-magic-sparkles"></i> Apparence par défaut de l'établissement</h3>
    <p style="font-size:.85em;color:#718096;margin:-6px 0 14px">Appliquée aux utilisateurs qui n'ont pas choisi de thème. Priorité : préférence de l'utilisateur, puis ce défaut, puis le défaut plateforme.</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
        <?php foreach (['light' => ['Clair','fa-sun'], 'dark' => ['Sombre','fa-moon'], 'liquid' => ['Liquide','fa-droplet'], 'auto' => ['Automatique','fa-circle-half-stroke']] as $tk => $ti):
            $isCur = ($defaultTheme === $tk); ?>
        <form method="POST" style="margin:0">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="action" value="set_default">
            <input type="hidden" name="theme_key" value="<?= $tk ?>">
            <button type="submit" class="th-btn <?= $isCur ? 'th-btn-primary' : 'th-btn-outline' ?>" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px">
                <i class="fas <?= $ti[1] ?>"></i> <?= $ti[0] ?><?= $isCur ? ' ✓' : '' ?>
            </button>
        </form>
        <?php endforeach; ?>
    </div>
</div>

<!-- Thèmes installés -->
<div class="th-section">
    <h3><i class="fas fa-swatchbook"></i> Thèmes disponibles</h3>
    <div class="th-grid">
        <?php foreach ($themes as $theme): ?>
        <div class="th-card <?= $theme['key'] === $defaultTheme ? 'active' : '' ?>">
            <div class="th-preview">
                <?php if (!empty($theme['preview_image'])): ?>
                <img src="../../<?= htmlspecialchars($theme['preview_image']) ?>" alt="Preview">
                <?php else: ?>
                <i class="fas fa-palette"></i>
                <?php endif; ?>
                <?php if ($theme['key'] === $defaultTheme): ?>
                <span class="th-default-badge">Par défaut</span>
                <?php endif; ?>
            </div>
            <div class="th-body">
                <div class="th-name"><?= htmlspecialchars($theme['name'] ?? $theme['key']) ?></div>
                <div class="th-desc"><?= htmlspecialchars($theme['description'] ?? '') ?></div>
                <div class="th-meta">
                    <span><?= htmlspecialchars($theme['author'] ?? '') ?></span>
                    <span>v<?= htmlspecialchars($theme['version'] ?? '1.0') ?></span>
                </div>
                <div class="th-actions">
                    <?php if ($theme['key'] !== $defaultTheme): ?>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="action" value="set_default">
                        <input type="hidden" name="theme_key" value="<?= htmlspecialchars($theme['key']) ?>">
                        <button class="th-btn th-btn-primary" type="submit"><i class="fas fa-check"></i> Activer</button>
                    </form>
                    <?php endif; ?>
                    <?php if (empty($theme['is_builtin'])): ?>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer ce thème ?')">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="theme_key" value="<?= htmlspecialchars($theme['key']) ?>">
                        <button class="th-btn th-btn-danger" type="submit"><i class="fas fa-trash"></i></button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Upload custom theme -->
<div class="th-section">
    <h3><i class="fas fa-upload"></i> Installer un thème personnalisé</h3>
    <form class="th-upload" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
        <input type="hidden" name="action" value="upload">
        <label>Clé du thème (ex: modern-blue)</label>
        <input type="text" name="theme_key" placeholder="modern-blue" required pattern="[a-z0-9_-]{2,30}">
        <label>Nom d'affichage</label>
        <input type="text" name="theme_name" placeholder="Modern Blue" required>
        <label>Description (optionnel)</label>
        <textarea name="theme_description" rows="2" placeholder="Un thème moderne aux tons bleus..."></textarea>
        <label>Fichier CSS (max 500 Ko)</label>
        <input type="file" name="css_file" accept=".css" required>
        <label>Image de preview (optionnel, max 2 Mo)</label>
        <input type="file" name="preview_file" accept="image/png,image/jpeg,image/webp">
        <button class="th-btn th-btn-primary" type="submit" style="margin-top:8px"><i class="fas fa-upload"></i> Installer le thème</button>
    </form>
</div>

<!-- Marketplace de thèmes -->
<?php if (!empty($remoteCatalog)): ?>
<div class="th-section">
    <h3><i class="fas fa-store"></i> Thèmes de la marketplace</h3>
    <div class="th-grid">
        <?php foreach ($remoteCatalog as $remote): ?>
        <div class="th-card">
            <div class="th-preview"><i class="fas fa-cloud-download-alt"></i></div>
            <div class="th-body">
                <div class="th-name"><?= htmlspecialchars($remote['name'] ?? $remote['key']) ?></div>
                <div class="th-desc"><?= htmlspecialchars($remote['description'] ?? '') ?></div>
                <div class="th-actions">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="action" value="install_remote">
                        <input type="hidden" name="theme_key" value="<?= htmlspecialchars($remote['key'] ?? '') ?>">
                        <button class="th-btn th-btn-primary"><i class="fas fa-download"></i> Installer</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Designer de tokens (couleurs) -->
<div class="th-section">
    <h3><i class="fas fa-palette"></i> Designer de couleurs</h3>
    <p style="font-size:.85em;color:#718096;margin-bottom:12px">
        Personnalisez les couleurs d'un thème sans toucher au CSS. Les valeurs sont appliquées par-dessus le thème (variables <code>:root</code>) et le contraste est vérifié (WCAG AA).
    </p>

    <form method="GET" style="margin-bottom:14px">
        <label style="font-size:.85em;color:#4a5568;font-weight:600;margin-right:8px">Thème à personnaliser :</label>
        <select name="edit_theme" onchange="this.form.submit()" style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px">
            <?php foreach ($themes as $t): ?>
            <option value="<?= htmlspecialchars($t['key']) ?>" <?= $t['key'] === $editTheme ? 'selected' : '' ?>><?= htmlspecialchars($t['name'] ?? $t['key']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
        <input type="hidden" name="action" value="save_tokens">
        <input type="hidden" name="target_theme" value="<?= htmlspecialchars($editTheme) ?>">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-bottom:16px">
            <?php
            $tokenLabels = [
                '--primary-color' => 'Couleur principale', '--primary-light' => 'Principale (claire)',
                '--primary-dark' => 'Principale (foncée)', '--background-color' => 'Fond',
                '--text-color' => 'Texte', '--success-color' => 'Succès',
                '--warning-color' => 'Avertissement', '--error-color' => 'Erreur / danger',
            ];
            foreach (\API\Services\ThemeService::EDITABLE_TOKENS as $name => $default):
                $val = $editOverrides[$name] ?? $default;
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $val)) $val = $default;
            ?>
            <label style="display:flex;align-items:center;gap:10px;font-size:.85em;color:#4a5568">
                <input type="color" name="tok[<?= htmlspecialchars($name) ?>]" value="<?= htmlspecialchars($val) ?>" style="width:38px;height:38px;border:1px solid #e2e8f0;border-radius:6px;padding:2px;cursor:pointer">
                <span><?= htmlspecialchars($tokenLabels[$name] ?? $name) ?><br><code style="font-size:.85em;color:#a0aec0"><?= htmlspecialchars($name) ?></code></span>
            </label>
            <?php endforeach; ?>
        </div>

        <!-- Rapport de contraste WCAG -->
        <div style="background:#f7fafc;border-radius:8px;padding:12px 14px;margin-bottom:14px;font-size:.85em">
            <strong style="color:#4a5568">Contraste (WCAG AA)</strong>
            <?php foreach ($editContrast as $c): ?>
            <div style="display:flex;justify-content:space-between;padding:3px 0">
                <span><?= htmlspecialchars($c['label']) ?></span>
                <span style="font-weight:600;color:<?= $c['pass'] ? '#276749' : '#c53030' ?>">
                    <?= $c['ratio'] ?>:1 <?= $c['pass'] ? '✓' : '✗ (min ' . $c['min'] . ')' ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>

        <button class="th-btn th-btn-primary" type="submit"><i class="fas fa-save"></i> Enregistrer les couleurs</button>
        <?php if (!empty($editOverrides)): ?>
        <span style="font-size:.8em;color:#a0aec0;margin-left:10px"><?= count($editOverrides) ?> override(s) actif(s) sur ce thème</span>
        <?php endif; ?>
    </form>
</div>

<!-- Token editor preview -->
<?php if (!empty($tokens)): ?>
<div class="th-section">
    <h3><i class="fas fa-sliders-h"></i> Variables CSS (design tokens)</h3>
    <p style="font-size:.85em;color:#718096;margin-bottom:12px">Variables du fichier <code>tokens.css</code> utilisées par tous les thèmes.</p>
    <div class="th-tokens">
        <?php foreach (array_slice($tokens, 0, 50) as $token): ?>
        <div class="th-token-row">
            <span class="th-token-name"><?= htmlspecialchars($token['name']) ?></span>
            <span class="th-token-swatch" style="background:<?= htmlspecialchars($token['value']) ?>"></span>
            <span><?= htmlspecialchars($token['value']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
