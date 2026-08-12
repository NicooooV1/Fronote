<?php
declare(strict_types=1);
/**
 * Mises à jour — un seul bouton : git pull du dépôt + réconciliation du schéma SQL.
 * Aucune migration, aucun reset manuel : les changements de schéma sont appliqués
 * de façon idempotente (SchemaSyncService) après le pull.
 */
require_once __DIR__ . '/../../API/core.php';
require_once __DIR__ . '/../includes/admin_functions.php';

requireAuth();
tenantGate('tenant.users.manage');

$projectRoot = dirname(__DIR__, 2);
$envPath     = $projectRoot . '/.env';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

/** Met à jour des clés dans .env (création si absente). */
function updateEnvValues(array $updates, string $envPath): void
{
    $lines = file_exists($envPath) ? file($envPath, FILE_IGNORE_NEW_LINES) : [];
    $touched = array_fill_keys(array_keys($updates), false);
    foreach ($lines as &$line) {
        if (preg_match('/^\s*([A-Z0-9_]+)\s*=/', $line, $m) && array_key_exists($m[1], $updates)) {
            $val = $updates[$m[1]];
            $line = $m[1] . '=' . (str_contains($val, ' ') ? '"' . addslashes($val) . '"' : $val);
            $touched[$m[1]] = true;
        }
    }
    unset($line);
    foreach ($touched as $key => $done) {
        if (!$done) {
            $val = $updates[$key];
            $lines[] = $key . '=' . (str_contains($val, ' ') ? '"' . addslashes($val) . '"' : $val);
        }
    }
    file_put_contents($envPath, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
}

// ─── Actions AJAX / POST ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Token CSRF invalide.']);
        exit;
    }

    if ($action === 'update') {
        header('Content-Type: application/json');
        @set_time_limit(180);
        try {
            echo json_encode(app('updates')->applyUpdate());
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage(), 'steps' => []]);
        }
        exit;
    }

    if ($action === 'check') {
        header('Content-Type: application/json');
        try {
            $r = app('updates')->checkForUpdate();
            echo json_encode($r ?? ['available' => false]);
        } catch (\Throwable $e) {
            echo json_encode(['available' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'save_config') {
        $updates = [];
        foreach (['GITHUB_BRANCH', 'GIT_BINARY'] as $k) {
            if (isset($_POST[$k]) && trim($_POST[$k]) !== '') $updates[$k] = trim($_POST[$k]);
        }
        if ($updates) {
            updateEnvValues($updates, $envPath);
            foreach ($updates as $k => $v) { putenv("$k=$v"); $_ENV[$k] = $v; }
        }
        $successMsg = 'Configuration enregistrée.';
    }
}

// ─── Données d'affichage ────────────────────────────────────────────────────
$update = app('updates');
$currentVersion = $update->getCurrentVersion();
$branch = $update->getBranch();
$gitOk = $update->isGitAvailable();

$pageTitle   = 'Mises à jour';
$currentPage = 'update';
$extraCss    = ['../../assets/css/admin.css'];

ob_start(); ?>
<style>
  .upd-wrap { max-width: 820px; margin: 0 auto; }
  .upd-card { background:var(--bg-card); border-radius:10px; box-shadow:0 2px 8px rgba(0,0,0,.06); padding:22px 24px; margin-bottom:16px; }
  .upd-row { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
  .badge-ok{background:#d1fae5;color:#065f46;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600}
  .badge-warn{background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600}
  .badge-err{background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600}
  .upd-steps{background:#1a202c;color:#e2e8f0;border-radius:8px;padding:14px 16px;font-family:monospace;font-size:12px;line-height:1.7;margin-top:14px;white-space:pre-wrap;display:none}
  .upd-form label{display:block;font-size:13px;font-weight:600;color:#4a5568;margin-bottom:4px}
  .upd-form input{width:100%;padding:8px 10px;border:1px solid #d2d6dc;border-radius:6px;font-size:13px;box-sizing:border-box}
  .upd-form .field{margin-bottom:14px}
  .muted{color:#718096;font-size:13px}
</style>
<?php $extraHeadHtml = ob_get_clean();
include __DIR__ . '/../includes/header.php';
?>

<div class="upd-wrap">

<?php if (!empty($successMsg)): ?>
  <div style="background:#d1fae5;color:#065f46;border-radius:8px;padding:10px 16px;margin-bottom:16px">
    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($successMsg) ?>
  </div>
<?php endif; ?>

  <div class="upd-card">
    <div class="upd-row" style="justify-content:space-between">
      <div class="upd-row">
        <strong style="font-size:16px">Version</strong>
        <span class="badge-ok"><?= htmlspecialchars($currentVersion) ?></span>
        <span class="muted">branche <code><?= htmlspecialchars($branch) ?></code></span>
      </div>
      <?php if ($gitOk): ?><span class="badge-ok"><i class="fas fa-check"></i> git OK</span>
      <?php else: ?><span class="badge-err"><i class="fas fa-times"></i> git introuvable</span><?php endif; ?>
    </div>
  </div>

  <div class="upd-card">
    <p class="muted" style="margin:0 0 14px">
      Récupère le dernier code depuis GitHub (<code>git reset --hard origin/<?= htmlspecialchars($branch) ?></code>)
      puis applique automatiquement les changements de schéma SQL (tables/colonnes manquantes) —
      sans migration ni réinitialisation.
    </p>
    <div class="upd-row">
      <button id="btn-update" class="btn btn-primary" data-fr-click="doUpdate" <?= $gitOk ? '' : 'disabled' ?>>
        <i class="fas fa-rotate"></i> Mettre à jour maintenant
      </button>
      <button id="btn-check" class="btn btn-secondary" data-fr-click="doCheck" <?= $gitOk ? '' : 'disabled' ?>>
        <i class="fas fa-search"></i> Vérifier
      </button>
      <span id="upd-inline" class="muted"></span>
    </div>
    <div id="upd-steps" class="upd-steps"></div>
  </div>

  <div class="upd-card">
    <form method="post" class="upd-form">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
      <input type="hidden" name="action" value="save_config">
      <div class="field">
        <label for="GITHUB_BRANCH">Branche à suivre</label>
        <input type="text" id="GITHUB_BRANCH" name="GITHUB_BRANCH" value="<?= htmlspecialchars(getenv('GITHUB_BRANCH') ?: 'main') ?>" placeholder="main">
      </div>
      <div class="field">
        <label for="GIT_BINARY">Chemin de git <span class="muted">(si absent du PATH, ex. Windows)</span></label>
        <input type="text" id="GIT_BINARY" name="GIT_BINARY" value="<?= htmlspecialchars(getenv('GIT_BINARY') ?: '') ?>" placeholder="git">
      </div>
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
    </form>
  </div>
</div>

<script nonce="<?= csp_nonce() ?>">
const CSRF = <?= json_encode($csrfToken) ?>;
function post(action) {
  return fetch(window.location.href, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=' + action + '&csrf_token=' + encodeURIComponent(CSRF)
  }).then(r => r.json());
}
function showSteps(lines) {
  const box = document.getElementById('upd-steps');
  box.style.display = 'block';
  box.textContent = (lines || []).join('\n');
}
function doUpdate() {
  const btn = document.getElementById('btn-update');
  const inline = document.getElementById('upd-inline');
  if (!confirm('Mettre à jour le serveur depuis GitHub ? Le code local sera écrasé par la branche distante.')) return;
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mise à jour…'; inline.textContent = '';
  post('update').then(d => {
    const steps = d.steps || [];
    if (d.schema && d.schema.altered) {
      for (const t in d.schema.altered) steps.push('  + ' + t + ' : colonnes ' + d.schema.altered[t].join(', '));
    }
    if (d.schema && (d.schema.errors || []).length) steps.push('Erreurs schéma : ' + d.schema.errors.join(' | '));
    if (d.error) steps.push('ERREUR : ' + d.error);
    if (d.old_version) steps.push('Version : ' + d.old_version + ' → ' + d.new_version);
    showSteps(steps);
    inline.innerHTML = d.success
      ? '<span style="color:#065f46"><i class="fas fa-check-circle"></i> Mise à jour terminée.</span>'
      : '<span style="color:#991b1b"><i class="fas fa-times-circle"></i> Échec — voir le détail.</span>';
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-rotate"></i> Mettre à jour maintenant';
  }).catch(() => {
    inline.innerHTML = '<span style="color:#991b1b">Erreur réseau.</span>';
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-rotate"></i> Mettre à jour maintenant';
  });
}
function doCheck() {
  const inline = document.getElementById('upd-inline');
  inline.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Vérification…';
  post('check').then(d => {
    if (d.available) {
      inline.innerHTML = '<span style="color:#92400e"><i class="fas fa-arrow-up"></i> ' + d.behind + ' commit(s) en attente.</span>';
      showSteps(d.commits || []);
    } else {
      inline.innerHTML = '<span style="color:#065f46"><i class="fas fa-check"></i> À jour' + (d.error ? ' (' + d.error + ')' : '') + '.</span>';
    }
  }).catch(() => { inline.innerHTML = '<span style="color:#991b1b">Erreur réseau.</span>'; });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
