<?php
declare(strict_types=1);
/**
 * Assistant de mise en route (onboarding).
 *
 * Affiché au premier login administrateur tant que l'établissement n'est pas
 * configuré (etablissements.code === 'default'). Configure l'identité de
 * l'établissement, les périodes scolaires et, en option, des classes/matières
 * par défaut. L'installation système (install.php) ne fait plus ce travail.
 */

// Empêche le gate d'onboarding de module_boot.php de boucler sur cette page.
define('FRONOTE_ONBOARDING', true);

$pageTitle  = 'Mise en route';
$activePage = '';
require_once __DIR__ . '/../../API/module_boot.php';
requireCapability('module.onboarding.access'); // (administrateur par défaut — éditable plateforme)

$etabService = app('etablissement');
$etabId      = \API\Core\EstablishmentContext::id();
$current     = $etabService->getCurrent() ?: [];

$success = '';
$error   = '';
$justSaved = false;

// Mode « créer un nouvel établissement » (étape multi-établissement).
$createNew = !empty($_GET['nouveau']) || !empty($_POST['create_new']);

// Établissements déjà configurés (code ≠ 'default').
$configuredEtabs = [];
try {
    foreach ($etabService->getAll(false) as $e) {
        if (($e['code'] ?? '') !== 'default') {
            $configuredEtabs[] = $e;
        }
    }
} catch (\Throwable $e) { error_log('[index.php] ' . $e->getMessage()); }

/**
 * Insère une classe pour un établissement donné (id explicite, sans dépendre
 * du contexte de session — nécessaire pour les établissements créés à la volée).
 */
$insertClasse = function (int $targetEtab, string $niveau, string $nom, string $annee) use ($pdo): void {
    $niveau = trim($niveau);
    $nom    = trim($nom);
    if ($niveau === '' || $nom === '') return;
    try {
        $pdo->prepare("INSERT INTO classes (niveau, nom, annee_scolaire, etablissement_id) VALUES (?,?,?,?)")
            ->execute([$niveau, $nom, $annee, $targetEtab]);
    } catch (\PDOException $e) { /* doublon nom/année → ignoré */ error_log('[index.php] ' . $e->getMessage()); }
};

// Classes par défaut selon le type (réutilisé de l'ancien install.php).
$defaultClassesByType = [
    'primaire' => ['CP' => ['CPA', 'CPB'], 'CE1' => ['CE1A', 'CE1B'], 'CE2' => ['CE2A', 'CE2B'], 'CM1' => ['CM1A', 'CM1B'], 'CM2' => ['CM2A', 'CM2B']],
    'college'  => ['6eme' => ['6A', '6B', '6C'], '5eme' => ['5A', '5B', '5C'], '4eme' => ['4A', '4B', '4C'], '3eme' => ['3A', '3B', '3C']],
    'lycee'    => ['2nde' => ['2A', '2B', '2C'], '1ere' => ['1A', '1B', '1C'], 'Tle' => ['TA', 'TB', 'TC']],
];
$defaultMatieres = [
    ['FRAN', 'Français'], ['MATH', 'Mathématiques'], ['HG', 'Histoire-Géographie'],
    ['ANG', 'Anglais'], ['ESP', 'Espagnol'], ['PC', 'Physique-Chimie'],
    ['SVT', 'SVT'], ['EPS', 'EPS'], ['ART', 'Arts plastiques'],
    ['MUS', 'Musique'], ['TECH', 'Technologie'],
];

$validTypes = ['primaire', 'college', 'lycee', 'polyvalent', 'superieur'];

// ─── Traitement POST ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Jeton de sécurité invalide. Veuillez recharger la page.';
    } else {
        $nom = trim($_POST['nom'] ?? '');
        $type = in_array($_POST['type'] ?? '', $validTypes, true) ? $_POST['type'] : 'college';
        $annee = trim($_POST['annee_scolaire'] ?? '') ?: (date('Y') . '-' . (date('Y') + 1));

        if ($nom === '') {
            $error = "Le nom de l'établissement est requis.";
        } else {
            // Code unique court dérivé du nom (jamais 'default').
            $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $nom), '-'));
            if ($slug === '' || $slug === 'default') {
                $slug = 'etab-' . bin2hex(random_bytes(3));
            }

            $identity = [
                'nom'                => $nom,
                'code'               => $slug,
                'type'               => $type,
                'adresse'            => trim($_POST['adresse'] ?? '') ?: null,
                'code_postal'        => trim($_POST['code_postal'] ?? '') ?: null,
                'ville'              => trim($_POST['ville'] ?? '') ?: null,
                'telephone'          => trim($_POST['telephone'] ?? '') ?: null,
                'email'              => trim($_POST['email'] ?? '') ?: null,
                'academie'           => trim($_POST['academie'] ?? '') ?: null,
                'annee_scolaire'     => $annee,
                'couleur_primaire'   => trim($_POST['couleur_primaire'] ?? '') ?: '#003366',
                'couleur_secondaire' => trim($_POST['couleur_secondaire'] ?? '') ?: '#0066cc',
            ];

            $targetId = $etabId;
            $ok = true;
            if (!empty($_POST['create_new'])) {
                // Établissement supplémentaire : on en crée un nouveau.
                try {
                    $targetId = $etabService->create($identity);
                    $ok = $targetId > 0;
                } catch (\Throwable $ex) {
                    $ok = false;
                }
            } else {
                $ok = $etabService->update($etabId, $identity);
            }

            if (!$ok) {
                $error = "Échec de l'enregistrement de l'établissement (le code est peut-être déjà utilisé).";
            } else {
                // Périodes scolaires — configurées pour l'établissement CIBLE (le
                // principal au premier login, ou le nouvel établissement créé). On
                // passe explicitement $targetId pour ne pas dépendre du scope de
                // session (qui pointe encore l'ancien établissement pour un create_new).
                $periodeSystem = ($_POST['periode_system'] ?? 'trimestre') === 'semestre' ? 'semestre' : 'trimestre';
                $periodes = [];
                if ($periodeSystem === 'trimestre') {
                    $labels = ['1er trimestre', '2ème trimestre', '3ème trimestre'];
                    for ($i = 1; $i <= 3; $i++) {
                        $d = $_POST["p{$i}_debut"] ?? '';
                        $f = $_POST["p{$i}_fin"] ?? '';
                        if ($d && $f) $periodes[] = ['nom' => $labels[$i - 1], 'date_debut' => $d, 'date_fin' => $f];
                    }
                } else {
                    $labels = ['1er semestre', '2ème semestre'];
                    for ($i = 1; $i <= 2; $i++) {
                        $d = $_POST["s{$i}_debut"] ?? '';
                        $f = $_POST["s{$i}_fin"] ?? '';
                        if ($d && $f) $periodes[] = ['nom' => $labels[$i - 1], 'date_debut' => $d, 'date_fin' => $f];
                    }
                }
                if (!empty($periodes)) {
                    $etabService->configurePeriodes($periodeSystem, $periodes, $targetId);
                }

                // Classes par défaut selon le type (optionnel), insérées avec l'id
                // d'établissement cible explicite.
                if (!empty($_POST['gen_defaults'])) {
                    $scopes = $type === 'polyvalent' ? ['primaire', 'college', 'lycee'] : [$type];
                    foreach ($scopes as $scope) {
                        foreach ($defaultClassesByType[$scope] ?? [] as $niveau => $noms) {
                            foreach ($noms as $classeNom) {
                                $insertClasse($targetId, $niveau, $classeNom, $annee);
                            }
                        }
                    }
                    // Matières par défaut : uniquement pour l'établissement principal
                    // (matieres.code est unique globalement).
                    if (empty($_POST['create_new'])) {
                        foreach ($defaultMatieres as [$code, $matNom]) {
                            $etabService->addMatiere($code, $matNom);
                        }
                    }
                }

                // Classes personnalisées saisies à la main (niveau + nom répétables).
                $cn = $_POST['classe_niveau'] ?? [];
                $cm = $_POST['classe_nom'] ?? [];
                if (is_array($cn) && is_array($cm)) {
                    $n = min(count($cn), count($cm));
                    for ($i = 0; $i < $n; $i++) {
                        $insertClasse($targetId, (string) $cn[$i], (string) $cm[$i], $annee);
                    }
                }

                $_SESSION['onboarding_done'] = true;

                if (!empty($_POST['add_another'])) {
                    // Enchaîner sur la création d'un autre établissement.
                    header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/modules/onboarding/index.php?nouveau=1');
                    exit;
                }
                if (!empty($_POST['finish'])) {
                    header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/accueil/accueil.php');
                    exit;
                }
                // Par défaut : afficher le panneau de succès avec les deux choix.
                $justSaved = true;
                $success = "« " . htmlspecialchars($nom) . " » enregistré.";
                $configuredEtabs = [];
                foreach ($etabService->getAll(false) as $e) {
                    if (($e['code'] ?? '') !== 'default') $configuredEtabs[] = $e;
                }
            }
        }
    }
}

// Valeurs de pré-remplissage des dates de périodes.
$curYear  = (int) date('Y');
// Année scolaire saisie (ou par défaut) → plages de périodes déduites automatiquement.
$anneeForm = $_POST['annee_scolaire'] ?? ($current['annee_scolaire'] ?? ($curYear . '-' . ($curYear + 1)));
$mapDef = fn(array $rows) => array_map(fn($r) => ['debut' => $r['date_debut'], 'fin' => $r['date_fin']], $rows);
$defTri = $mapDef(\Modules\EmploiDuTemps\Services\PeriodeService::defaultPeriodes($anneeForm, 'trimestre'));
$defSem = $mapDef(\Modules\EmploiDuTemps\Services\PeriodeService::defaultPeriodes($anneeForm, 'semestre'));

include __DIR__ . '/../../templates/shared_header.php';
include __DIR__ . '/../../templates/shared_topbar.php';
?>

<div class="onboarding-wrap" style="max-width:860px;margin:24px auto;padding:0 16px">
    <h1 style="font-size:1.5em;margin-bottom:4px">
        <?= $createNew ? __('onboarding.new_etab_title') : __('onboarding.welcome_title') ?>
    </h1>
    <p style="color:var(--text-muted,#6b7280);margin-bottom:24px">
        <?= $createNew
            ? __('onboarding.intro_new')
            : __('onboarding.intro_welcome') ?>
    </p>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="background:#fed7d7;color:#9b2c2c;padding:12px 16px;border-radius:8px;margin-bottom:16px">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($configuredEtabs)): ?>
        <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:14px 16px;margin-bottom:18px">
            <div style="font-size:.85em;color:var(--text-muted,#6b7280);margin-bottom:6px"><?= __('onboarding.configured_etabs') ?> (<?= count($configuredEtabs) ?>)</div>
            <div style="display:flex;flex-wrap:wrap;gap:8px">
                <?php foreach ($configuredEtabs as $ce): ?>
                <span style="background:#eef2ff;color:#3730a3;border-radius:16px;padding:4px 12px;font-size:.85em">
                    <i class="fas fa-school"></i> <?= htmlspecialchars($ce['nom'] ?? '') ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($justSaved): ?>
        <div style="background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;padding:16px 18px;border-radius:10px;margin-bottom:18px">
            <strong><i class="fas fa-check-circle"></i> <?= $success ?></strong>
            <p style="margin:8px 0 0;font-size:.9em"><?= __('onboarding.saved_question') ?></p>
        </div>
        <div style="display:flex;gap:12px;flex-wrap:wrap">
            <a href="index.php?nouveau=1" class="btn" style="background:var(--surface,#fff);border:1px solid var(--primary,#003366);color:var(--primary,#003366);padding:10px 22px;border-radius:8px;text-decoration:none;font-weight:600">
                <i class="fas fa-plus"></i> <?= __('onboarding.add_another_etab') ?>
            </a>
            <a href="<?= (defined('BASE_URL') ? BASE_URL : '') ?>/accueil/accueil.php" class="btn btn-primary" style="background:var(--primary,#003366);color:#fff;padding:10px 22px;border-radius:8px;text-decoration:none;font-weight:600">
                <?= __('onboarding.go_home') ?>
            </a>
        </div>
    <?php else: ?>

    <form method="post" class="card" style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:24px">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_hdr_csrf_token) ?>">
        <?php if ($createNew): ?><input type="hidden" name="create_new" value="1"><?php endif; ?>

        <h3 style="margin:0 0 14px;font-size:1.05em"><?= __('onboarding.section_identity') ?></h3>
        <div class="form-group" style="margin-bottom:14px">
            <label><?= __('onboarding.etab_name') ?> <span style="color:#e53e3e">*</span></label>
<?php $nomPrefill = ($current['nom'] ?? '') === 'Établissement Scolaire' ? '' : ($current['nom'] ?? ''); ?>
            <input type="text" name="nom" required value="<?= htmlspecialchars($_POST['nom'] ?? $nomPrefill) ?>" placeholder="<?= __('onboarding.etab_name_placeholder') ?>" style="width:100%;padding:8px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
            <div class="form-group">
                <label><?= __('onboarding.etab_type') ?></label>
                <?php $curType = $_POST['type'] ?? ($current['type'] ?? 'college'); ?>
                <select name="type" style="width:100%;padding:8px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px">
                    <option value="primaire"   <?= $curType === 'primaire' ? 'selected' : '' ?>><?= __('onboarding.type_primaire') ?></option>
                    <option value="college"    <?= $curType === 'college' ? 'selected' : '' ?>><?= __('onboarding.type_college') ?></option>
                    <option value="lycee"      <?= $curType === 'lycee' ? 'selected' : '' ?>><?= __('onboarding.type_lycee') ?></option>
                    <option value="polyvalent" <?= $curType === 'polyvalent' ? 'selected' : '' ?>><?= __('onboarding.type_polyvalent') ?></option>
                    <option value="superieur"  <?= $curType === 'superieur' ? 'selected' : '' ?>><?= __('onboarding.type_superieur') ?></option>
                </select>
            </div>
            <div class="form-group">
                <label><?= __('onboarding.school_year') ?></label>
                <input type="text" name="annee_scolaire" value="<?= htmlspecialchars($_POST['annee_scolaire'] ?? ($current['annee_scolaire'] ?? ($curYear . '-' . ($curYear + 1)))) ?>" placeholder="2025-2026" style="width:100%;padding:8px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px">
            </div>
        </div>
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px;margin-bottom:14px">
            <div class="form-group"><label><?= __('label.adresse') ?></label><input type="text" name="adresse" value="<?= htmlspecialchars($_POST['adresse'] ?? '') ?>" style="width:100%;padding:8px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px"></div>
            <div class="form-group"><label><?= __('onboarding.postal_code') ?></label><input type="text" name="code_postal" value="<?= htmlspecialchars($_POST['code_postal'] ?? '') ?>" style="width:100%;padding:8px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px"></div>
            <div class="form-group"><label><?= __('onboarding.city') ?></label><input type="text" name="ville" value="<?= htmlspecialchars($_POST['ville'] ?? '') ?>" style="width:100%;padding:8px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:20px">
            <div class="form-group"><label><?= __('label.telephone') ?></label><input type="text" name="telephone" value="<?= htmlspecialchars($_POST['telephone'] ?? '') ?>" style="width:100%;padding:8px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px"></div>
            <div class="form-group"><label><?= __('label.email') ?></label><input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" style="width:100%;padding:8px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px"></div>
            <div class="form-group"><label><?= __('onboarding.academy') ?></label><input type="text" name="academie" value="<?= htmlspecialchars($_POST['academie'] ?? '') ?>" style="width:100%;padding:8px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px"></div>
        </div>

        <h3 style="margin:0 0 14px;font-size:1.05em"><?= __('onboarding.section_periods') ?></h3>
        <div class="form-group" style="margin-bottom:14px">
            <label><?= __('onboarding.period_system') ?></label>
            <select name="periode_system" id="periodeSystem" data-fr-change="togglePeriodes" style="width:100%;padding:8px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px">
                <option value="trimestre"><?= __('onboarding.trimestre_option') ?></option>
                <option value="semestre"><?= __('onboarding.semestre_option') ?></option>
            </select>
        </div>
        <div id="trimestre-fields">
            <?php for ($i = 0; $i < 3; $i++): ?>
            <div style="display:flex;gap:12px;align-items:center;margin-bottom:8px">
                <strong style="min-width:120px"><?= $i === 0 ? '1er' : ($i + 1) . 'ème' ?> trimestre</strong>
                <label style="font-size:12px;margin:0"><?= __('onboarding.date_from') ?></label>
                <input type="date" name="p<?= $i + 1 ?>_debut" value="<?= $defTri[$i]['debut'] ?>" style="flex:1;padding:6px;border:1px solid var(--border,#cbd5e0);border-radius:6px">
                <label style="font-size:12px;margin:0"><?= __('onboarding.date_to') ?></label>
                <input type="date" name="p<?= $i + 1 ?>_fin" value="<?= $defTri[$i]['fin'] ?>" style="flex:1;padding:6px;border:1px solid var(--border,#cbd5e0);border-radius:6px">
            </div>
            <?php endfor; ?>
        </div>
        <div id="semestre-fields" style="display:none">
            <?php for ($i = 0; $i < 2; $i++): ?>
            <div style="display:flex;gap:12px;align-items:center;margin-bottom:8px">
                <strong style="min-width:120px"><?= $i === 0 ? '1er' : '2ème' ?> semestre</strong>
                <label style="font-size:12px;margin:0"><?= __('onboarding.date_from') ?></label>
                <input type="date" name="s<?= $i + 1 ?>_debut" value="<?= $defSem[$i]['debut'] ?>" style="flex:1;padding:6px;border:1px solid var(--border,#cbd5e0);border-radius:6px">
                <label style="font-size:12px;margin:0"><?= __('onboarding.date_to') ?></label>
                <input type="date" name="s<?= $i + 1 ?>_fin" value="<?= $defSem[$i]['fin'] ?>" style="flex:1;padding:6px;border:1px solid var(--border,#cbd5e0);border-radius:6px">
            </div>
            <?php endfor; ?>
        </div>

        <h3 style="margin:20px 0 14px;font-size:1.05em"><?= __('onboarding.section_colors') ?></h3>
        <div style="display:flex;gap:24px;margin-bottom:20px">
            <label style="display:flex;align-items:center;gap:8px"><?= __('onboarding.color_primary') ?>
                <input type="color" name="couleur_primaire" value="<?= htmlspecialchars($current['couleur_primaire'] ?? '#003366') ?>">
            </label>
            <label style="display:flex;align-items:center;gap:8px"><?= __('onboarding.color_secondary') ?>
                <input type="color" name="couleur_secondaire" value="<?= htmlspecialchars($current['couleur_secondaire'] ?? '#0066cc') ?>">
            </label>
        </div>

        <label style="display:flex;align-items:center;gap:8px;margin-bottom:18px;cursor:pointer">
            <input type="checkbox" name="gen_defaults" value="1" checked>
            <?= __('onboarding.gen_defaults') ?><?= $createNew ? '' : ' ' . __('onboarding.gen_defaults_matieres') ?>
        </label>

        <h3 style="margin:6px 0 10px;font-size:1.05em"><?= __('onboarding.section_custom_classes') ?></h3>
        <p style="color:var(--text-muted,#6b7280);font-size:.85em;margin:0 0 10px">
            <?= __('onboarding.custom_classes_hint') ?>
        </p>
        <div id="classes-rows" style="display:flex;flex-direction:column;gap:8px;margin-bottom:10px"></div>
        <button type="button" data-fr-click="frOI1" style="background:var(--surface,#fff);border:1px dashed var(--border,#cbd5e0);color:var(--text,#333);padding:8px 14px;border-radius:8px;cursor:pointer;font-size:.88em;margin-bottom:20px">
            <i class="fas fa-plus"></i> <?= __('onboarding.add_class') ?>
        </button>

        <div style="display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap">
            <button type="submit" name="add_another" value="1" class="btn" style="background:var(--surface,#fff);border:1px solid var(--primary,#003366);color:var(--primary,#003366);padding:10px 22px;border-radius:8px;cursor:pointer;font-weight:600">
                <?= __('onboarding.save_add_another') ?>
            </button>
            <button type="submit" name="finish" value="1" class="btn btn-primary" style="background:var(--primary,#003366);color:#fff;border:none;padding:10px 24px;border-radius:8px;cursor:pointer;font-weight:600">
                <?= __('onboarding.finish_home') ?>
            </button>
        </div>
    </form>
    <?php endif; ?>
</div>

<script nonce="<?= csp_nonce() ?>">
function togglePeriodes() {
    var el = document.getElementById('periodeSystem');
    if (!el) return;
    var sys = el.value;
    document.getElementById('trimestre-fields').style.display = sys === 'trimestre' ? '' : 'none';
    document.getElementById('semestre-fields').style.display = sys === 'semestre' ? '' : 'none';
}

function addClasseRow(niveau, nom) {
    var wrap = document.getElementById('classes-rows');
    if (!wrap) return;
    var row = document.createElement('div');
    row.style.cssText = 'display:flex;gap:8px;align-items:center';
    row.innerHTML =
        '<input type="text" name="classe_niveau[]" placeholder="Niveau (ex: 6eme)" value="' + (niveau || '') + '" style="flex:1;padding:8px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px">' +
        '<input type="text" name="classe_nom[]" placeholder="Nom (ex: 6A)" value="' + (nom || '') + '" style="flex:1;padding:8px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px">' +
        '<button type="button" title="Retirer" style="background:none;border:none;color:#e53e3e;cursor:pointer;font-size:1.1em" data-fr-click="frOI2"><i class="fas fa-times"></i></button>';
    wrap.appendChild(row);
}
window.frOI1 = function () { addClasseRow(); };
window.frOI2 = function () { this.parentNode.remove(); };
</script>

<?php include __DIR__ . '/../../templates/shared_footer.php'; ?>
