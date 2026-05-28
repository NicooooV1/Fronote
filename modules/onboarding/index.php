<?php
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
requireRole('administrateur');

$etabService = app('etablissement');
$etabId      = \API\Core\EstablishmentContext::id();
$current     = $etabService->getCurrent() ?: [];

$success = '';
$error   = '';

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

            $ok = $etabService->update($etabId, [
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
            ]);

            if (!$ok) {
                $error = "Échec de l'enregistrement de l'établissement (le code est peut-être déjà utilisé).";
            } else {
                // Périodes.
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
                    $etabService->configurePeriodes($periodeSystem, $periodes);
                }

                // Classes + matières par défaut (optionnel).
                if (!empty($_POST['gen_defaults'])) {
                    $scopes = $type === 'polyvalent' ? ['primaire', 'college', 'lycee'] : [$type];
                    foreach ($scopes as $scope) {
                        foreach ($defaultClassesByType[$scope] ?? [] as $niveau => $noms) {
                            foreach ($noms as $classeNom) {
                                $etabService->addClasse($niveau, $classeNom);
                            }
                        }
                    }
                    foreach ($defaultMatieres as [$code, $matNom]) {
                        $etabService->addMatiere($code, $matNom);
                    }
                }

                $_SESSION['onboarding_done'] = true;
                header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/accueil/accueil.php');
                exit;
            }
        }
    }
}

// Valeurs de pré-remplissage des dates de périodes.
$curYear  = (int) date('Y');
$nextYear = $curYear + (date('n') >= 9 ? 1 : 0);
$baseYear = date('n') >= 9 ? $curYear : $curYear - 1;
$defTri = [
    ['debut' => "$baseYear-09-01", 'fin' => "$baseYear-12-15"],
    ['debut' => "$nextYear-01-03", 'fin' => "$nextYear-03-15"],
    ['debut' => "$nextYear-03-16", 'fin' => "$nextYear-06-30"],
];
$defSem = [
    ['debut' => "$baseYear-09-01", 'fin' => "$nextYear-01-31"],
    ['debut' => "$nextYear-02-01", 'fin' => "$nextYear-06-30"],
];

include __DIR__ . '/../../templates/shared_header.php';
include __DIR__ . '/../../templates/shared_topbar.php';
?>

<div class="onboarding-wrap" style="max-width:860px;margin:24px auto;padding:0 16px">
    <h1 style="font-size:1.5em;margin-bottom:4px">Bienvenue 👋</h1>
    <p style="color:var(--text-muted,#6b7280);margin-bottom:24px">
        Configurez votre établissement pour commencer à utiliser Fronote.
        Vous pourrez tout modifier plus tard depuis l'administration.
    </p>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="background:#fed7d7;color:#9b2c2c;padding:12px 16px;border-radius:8px;margin-bottom:16px">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="post" class="card" style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:24px">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_hdr_csrf_token) ?>">

        <h3 style="margin:0 0 14px;font-size:1.05em">🏫 Identité</h3>
        <div class="form-group" style="margin-bottom:14px">
            <label>Nom de l'établissement <span style="color:#e53e3e">*</span></label>
<?php $nomPrefill = ($current['nom'] ?? '') === 'Établissement Scolaire' ? '' : ($current['nom'] ?? ''); ?>
            <input type="text" name="nom" required value="<?= htmlspecialchars($_POST['nom'] ?? $nomPrefill) ?>" placeholder="Ex: Lycée Jean Monnet" style="width:100%;padding:8px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
            <div class="form-group">
                <label>Type d'établissement</label>
                <?php $curType = $_POST['type'] ?? ($current['type'] ?? 'college'); ?>
                <select name="type" style="width:100%;padding:8px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px">
                    <option value="primaire"   <?= $curType === 'primaire' ? 'selected' : '' ?>>Primaire</option>
                    <option value="college"    <?= $curType === 'college' ? 'selected' : '' ?>>Collège</option>
                    <option value="lycee"      <?= $curType === 'lycee' ? 'selected' : '' ?>>Lycée</option>
                    <option value="polyvalent" <?= $curType === 'polyvalent' ? 'selected' : '' ?>>Polyvalent (primaire + collège + lycée)</option>
                    <option value="superieur"  <?= $curType === 'superieur' ? 'selected' : '' ?>>Supérieur</option>
                </select>
            </div>
            <div class="form-group">
                <label>Année scolaire</label>
                <input type="text" name="annee_scolaire" value="<?= htmlspecialchars($_POST['annee_scolaire'] ?? ($current['annee_scolaire'] ?? ($curYear . '-' . ($curYear + 1)))) ?>" placeholder="2025-2026" style="width:100%;padding:8px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px">
            </div>
        </div>
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px;margin-bottom:14px">
            <div class="form-group"><label>Adresse</label><input type="text" name="adresse" value="<?= htmlspecialchars($_POST['adresse'] ?? '') ?>" style="width:100%;padding:8px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px"></div>
            <div class="form-group"><label>Code postal</label><input type="text" name="code_postal" value="<?= htmlspecialchars($_POST['code_postal'] ?? '') ?>" style="width:100%;padding:8px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px"></div>
            <div class="form-group"><label>Ville</label><input type="text" name="ville" value="<?= htmlspecialchars($_POST['ville'] ?? '') ?>" style="width:100%;padding:8px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:20px">
            <div class="form-group"><label>Téléphone</label><input type="text" name="telephone" value="<?= htmlspecialchars($_POST['telephone'] ?? '') ?>" style="width:100%;padding:8px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px"></div>
            <div class="form-group"><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" style="width:100%;padding:8px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px"></div>
            <div class="form-group"><label>Académie</label><input type="text" name="academie" value="<?= htmlspecialchars($_POST['academie'] ?? '') ?>" style="width:100%;padding:8px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px"></div>
        </div>

        <h3 style="margin:0 0 14px;font-size:1.05em">📅 Périodes scolaires</h3>
        <div class="form-group" style="margin-bottom:14px">
            <label>Système de périodes</label>
            <select name="periode_system" id="periodeSystem" onchange="togglePeriodes()" style="width:100%;padding:8px 10px;border:1px solid var(--border,#cbd5e0);border-radius:6px">
                <option value="trimestre">Trimestres (3 périodes)</option>
                <option value="semestre">Semestres (2 périodes)</option>
            </select>
        </div>
        <div id="trimestre-fields">
            <?php for ($i = 0; $i < 3; $i++): ?>
            <div style="display:flex;gap:12px;align-items:center;margin-bottom:8px">
                <strong style="min-width:120px"><?= $i === 0 ? '1er' : ($i + 1) . 'ème' ?> trimestre</strong>
                <label style="font-size:12px;margin:0">Du</label>
                <input type="date" name="p<?= $i + 1 ?>_debut" value="<?= $defTri[$i]['debut'] ?>" style="flex:1;padding:6px;border:1px solid var(--border,#cbd5e0);border-radius:6px">
                <label style="font-size:12px;margin:0">Au</label>
                <input type="date" name="p<?= $i + 1 ?>_fin" value="<?= $defTri[$i]['fin'] ?>" style="flex:1;padding:6px;border:1px solid var(--border,#cbd5e0);border-radius:6px">
            </div>
            <?php endfor; ?>
        </div>
        <div id="semestre-fields" style="display:none">
            <?php for ($i = 0; $i < 2; $i++): ?>
            <div style="display:flex;gap:12px;align-items:center;margin-bottom:8px">
                <strong style="min-width:120px"><?= $i === 0 ? '1er' : '2ème' ?> semestre</strong>
                <label style="font-size:12px;margin:0">Du</label>
                <input type="date" name="s<?= $i + 1 ?>_debut" value="<?= $defSem[$i]['debut'] ?>" style="flex:1;padding:6px;border:1px solid var(--border,#cbd5e0);border-radius:6px">
                <label style="font-size:12px;margin:0">Au</label>
                <input type="date" name="s<?= $i + 1 ?>_fin" value="<?= $defSem[$i]['fin'] ?>" style="flex:1;padding:6px;border:1px solid var(--border,#cbd5e0);border-radius:6px">
            </div>
            <?php endfor; ?>
        </div>

        <h3 style="margin:20px 0 14px;font-size:1.05em">🎨 Couleurs (optionnel)</h3>
        <div style="display:flex;gap:24px;margin-bottom:20px">
            <label style="display:flex;align-items:center;gap:8px">Primaire
                <input type="color" name="couleur_primaire" value="<?= htmlspecialchars($current['couleur_primaire'] ?? '#003366') ?>">
            </label>
            <label style="display:flex;align-items:center;gap:8px">Secondaire
                <input type="color" name="couleur_secondaire" value="<?= htmlspecialchars($current['couleur_secondaire'] ?? '#0066cc') ?>">
            </label>
        </div>

        <label style="display:flex;align-items:center;gap:8px;margin-bottom:20px;cursor:pointer">
            <input type="checkbox" name="gen_defaults" value="1" checked>
            Générer des classes et matières par défaut pour ce type d'établissement
        </label>

        <div style="display:flex;justify-content:flex-end">
            <button type="submit" class="btn btn-primary" style="background:var(--primary,#003366);color:#fff;border:none;padding:10px 24px;border-radius:8px;cursor:pointer;font-weight:600">
                Terminer la configuration →
            </button>
        </div>
    </form>
</div>

<script>
function togglePeriodes() {
    var sys = document.getElementById('periodeSystem').value;
    document.getElementById('trimestre-fields').style.display = sys === 'trimestre' ? '' : 'none';
    document.getElementById('semestre-fields').style.display = sys === 'semestre' ? '' : 'none';
}
</script>

<?php include __DIR__ . '/../../templates/shared_footer.php'; ?>
