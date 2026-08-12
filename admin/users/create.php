<?php
declare(strict_types=1);
/**
 * Ajouter un utilisateur — Formulaire dynamique selon le type
 */
require_once __DIR__ . '/../../API/core.php';
require_once __DIR__ . '/../includes/admin_functions.php';

requireAuth();
tenantGate('tenant.users.manage');

$pdo = getPDO();
$userObj = app('user');

$message = '';
$error = '';
$generatedPassword = '';
$generatedIdentifier = '';

// CSRF
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Charger classes et matières
$classesList = [];
$matieresList = [];
try {
    $classesList = $pdo->query("SELECT nom FROM classes WHERE actif = 1 ORDER BY niveau, nom")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}
try {
    $matieresList = $pdo->query("SELECT nom FROM matieres WHERE actif = 1 ORDER BY nom")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// Rôles catalogue attribuables par l'acteur courant (rôle principal + sous-rôles).
$rms = new \API\Services\RoleManagementService($pdo);
$assignableRoleKeys = [];
try { $assignableRoleKeys = $rms->assignableRoles(app('authz')->roleKeys()); } catch (\Throwable $e) {}
$assignableSet = array_flip($assignableRoleKeys);
$rolesByTier   = \API\Security\RoleCatalog::rolesByTier();
$tierLabels = [
    'plateforme'=>'Plateforme','direction'=>'Direction','administratif'=>'Administratif',
    'vie_scolaire'=>'Vie scolaire','pedagogique'=>'Pédagogique','sante_social'=>'Santé & social',
    'eleve_famille'=>'Élève & famille','organisation'=>'Organisation','communication'=>'Communication',
    'documents'=>'Documents','services'=>'Services','stages'=>'Stages & alternance',
    'controle'=>'Contrôle & lecture','systeme'=>'Système',
];

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
    $profil = $_POST['profil'] ?? '';
    
    $data = [
        'nom' => trim($_POST['nom'] ?? ''),
        'prenom' => trim($_POST['prenom'] ?? ''),
        'mail' => trim($_POST['mail'] ?? ''),
    ];

    // Champs optionnels communs
    if (isset($_POST['adresse']) && $_POST['adresse'] !== '') $data['adresse'] = trim($_POST['adresse']);
    if (isset($_POST['telephone']) && $_POST['telephone'] !== '') $data['telephone'] = trim($_POST['telephone']);

    // Champs spécifiques
    if ($profil === 'eleve') {
        $data['date_naissance'] = $_POST['date_naissance'] ?? '';
        $data['lieu_naissance'] = trim($_POST['lieu_naissance'] ?? '');
        $data['classe'] = $_POST['classe'] ?? '';
        if (empty($data['adresse'])) $data['adresse'] = '';
    }
    if ($profil === 'professeur') {
        $data['matiere'] = $_POST['matiere'] ?? '';
        $data['professeur_principal'] = $_POST['professeur_principal'] ?? 'non';
        if (empty($data['adresse'])) $data['adresse'] = '';
    }
    if ($profil === 'parent') {
        $data['metier'] = trim($_POST['metier'] ?? '');
        $data['est_parent_eleve'] = $_POST['est_parent_eleve'] ?? 'non';
        if (empty($data['adresse'])) $data['adresse'] = '';
    }
    if ($profil === 'vie_scolaire') {
        $data['est_CPE'] = $_POST['est_CPE'] ?? 'non';
        $data['est_infirmerie'] = $_POST['est_infirmerie'] ?? 'non';
    }
    // Administrateur : pas de champs spécifiques supplémentaires,
    // les champs communs (nom, prenom, mail) suffisent.

    if (empty($data['nom']) || empty($data['prenom']) || empty($data['mail'])) {
        $error = "Le nom, le prénom et l'email sont obligatoires.";
    } elseif (empty($profil)) {
        $error = "Veuillez sélectionner un type de profil.";
    } else {
        $result = $userObj->createUser($profil, $data);
        if ($result['success']) {
            $generatedPassword = $result['password'] ?? '';
            $generatedIdentifier = $result['identifiant'] ?? '';
            logAudit('user_created', $userObj->getTableName($profil), null, null, ['identifiant' => $generatedIdentifier, 'profil' => $profil]);
            $message = "Utilisateur créé avec succès.";

            // Attribution des rôles catalogue (rôle principal + sous-rôles) à la création.
            try {
                $tbl = $userObj->getTableName($profil);
                $idStmt = $pdo->prepare("SELECT id FROM `{$tbl}` WHERE identifiant = ? ORDER BY id DESC LIMIT 1");
                $idStmt->execute([$generatedIdentifier]);
                $newId = (int) $idStmt->fetchColumn();
                if ($newId > 0) {
                    $actor      = getCurrentUser();
                    $actorRoles = app('authz')->roleKeys();
                    $wanted = [];
                    if (!empty($_POST['principal_role'])) $wanted[] = (string) $_POST['principal_role'];
                    foreach ((array) ($_POST['sub_roles'] ?? []) as $sr) {
                        if ($sr !== '') $wanted[] = (string) $sr;
                    }
                    $assigned = [];
                    foreach (array_unique($wanted) as $rk) {
                        try {
                            $rms->assign($actor, $actorRoles, $profil, $newId, $rk, ['etablissement_id' => $actor['etablissement_id'] ?? null]);
                            $assigned[] = \API\Security\RoleCatalog::roles()[$rk]['label'] ?? $rk;
                        } catch (\Throwable $e) { /* rôle non attribuable → ignoré */ }
                    }
                    if ($assigned) $message .= ' Rôles attribués : ' . implode(', ', $assigned) . '.';
                }
            } catch (\Throwable $e) { /* l'attribution de rôle ne doit pas bloquer la création */ }
        } else {
            $error = $result['message'] ?? 'Erreur lors de la création.';
        }
    }
}

$pageTitle = 'Ajouter un utilisateur';
$currentPage = 'users_create';
$extraCss = ['../../assets/css/admin.css'];

ob_start();
?>
<style>
    .create-container { max-width: 800px; margin: 0 auto; }
    .form-card { background: var(--bg-card); border-radius: 10px; padding: 25px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); margin-bottom: 20px; }
    .form-card h3 { margin: 0 0 20px; font-size: 16px; color: var(--text-color, #2d3748); }
    .form-group input:focus, .form-group select:focus { border-color: #0f4c81; outline: none; box-shadow: 0 0 0 3px rgba(15,76,129,0.1); }
    .profil-selector { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
    .profil-btn { padding: 10px 18px; border: 2px solid var(--border-color, #e2e8f0); border-radius: 8px; background: var(--bg-card); cursor: pointer; font-size: 14px; transition: all 0.15s; display: flex; align-items: center; gap: 8px; }
    .profil-btn:hover { border-color: #0f4c81; }
    .profil-btn.selected { border-color: #0f4c81; background: #eff6ff; color: #0f4c81; font-weight: 600; }
    .dynamic-fields { display: none; }
    .dynamic-fields.visible { display: block; }
    .credentials-box { background: #f0fdf4; border: 2px dashed #059669; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
    .credentials-box h4 { margin: 0 0 10px; color: #059669; }
    .credentials-box code { background: #e2e8f0; padding: 2px 8px; border-radius: 4px; font-size: 15px; }
</style>
<?php
$extraHeadHtml = ob_get_clean();
include __DIR__ . '/../includes/header.php';
?>

<div class="create-container">
    <?php if (!empty($message) && !empty($generatedPassword)): ?>
        <div class="credentials-box">
            <h4><i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?></h4>
            <p><strong>Identifiant :</strong> <code><?= htmlspecialchars($generatedIdentifier) ?></code></p>
            <p><strong>Mot de passe :</strong> <code><?= htmlspecialchars($generatedPassword) ?></code></p>
            <p style="font-size:13px;color:#666;margin-top:10px;"><i class="fas fa-exclamation-triangle"></i> Communiquez ces informations à l'utilisateur. Le mot de passe ne sera plus affiché.</p>
        </div>
    <?php elseif (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" class="form-card" id="createForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
        
        <h3><i class="fas fa-user-tag"></i> Type de profil</h3>
        <div class="profil-selector">
            <button type="button" class="profil-btn" data-profil="eleve" data-fr-click="selectProfil" data-fr-args='["eleve"]'><i class="fas fa-user-graduate"></i> Élève</button>
            <button type="button" class="profil-btn" data-profil="professeur" data-fr-click="selectProfil" data-fr-args='["professeur"]'><i class="fas fa-chalkboard-teacher"></i> Professeur</button>
            <button type="button" class="profil-btn" data-profil="parent" data-fr-click="selectProfil" data-fr-args='["parent"]'><i class="fas fa-users"></i> Parent</button>
            <button type="button" class="profil-btn" data-profil="vie_scolaire" data-fr-click="selectProfil" data-fr-args='["vie_scolaire"]'><i class="fas fa-user-tie"></i> Vie scolaire</button>
            <button type="button" class="profil-btn" data-profil="administrateur" data-fr-click="selectProfil" data-fr-args='["administrateur"]'><i class="fas fa-user-shield"></i> Administrateur</button>
        </div>
        <input type="hidden" name="profil" id="profilInput" value="">

        <!-- Champs communs -->
        <h3><i class="fas fa-id-card"></i> Informations générales</h3>
        <div class="form-row">
            <div class="form-group"><label>Nom *</label><input type="text" name="nom" required></div>
            <div class="form-group"><label>Prénom *</label><input type="text" name="prenom" required></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>Email *</label><input type="email" name="mail" required></div>
            <div class="form-group"><label>Téléphone</label><input type="text" name="telephone" placeholder="06 12 34 56 78"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>Adresse</label><input type="text" name="adresse"></div>
        </div>

        <!-- Champs élève -->
        <div class="dynamic-fields" id="fields-eleve">
            <h3><i class="fas fa-user-graduate"></i> Informations élève</h3>
            <div class="form-row">
                <div class="form-group"><label>Classe *</label>
                    <select name="classe">
                        <option value="">Sélectionner…</option>
                        <?php foreach ($classesList as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Date de naissance *</label><input type="date" name="date_naissance"></div>
                <div class="form-group"><label>Lieu de naissance</label><input type="text" name="lieu_naissance"></div>
            </div>
        </div>

        <!-- Champs professeur -->
        <div class="dynamic-fields" id="fields-professeur">
            <h3><i class="fas fa-chalkboard-teacher"></i> Informations professeur</h3>
            <div class="form-row">
                <div class="form-group"><label>Matière *</label>
                    <select name="matiere">
                        <option value="">Sélectionner…</option>
                        <?php foreach ($matieresList as $m): ?>
                        <option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Professeur principal</label>
                    <select name="professeur_principal">
                        <option value="non">Non</option>
                        <option value="oui">Oui</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Champs parent -->
        <div class="dynamic-fields" id="fields-parent">
            <h3><i class="fas fa-users"></i> Informations parent</h3>
            <div class="form-row">
                <div class="form-group"><label>Métier</label><input type="text" name="metier"></div>
                <div class="form-group"><label>Parent d'élève</label>
                    <select name="est_parent_eleve"><option value="non">Non</option><option value="oui">Oui</option></select>
                </div>
            </div>
        </div>

        <!-- Champs vie scolaire -->
        <div class="dynamic-fields" id="fields-vie_scolaire">
            <h3><i class="fas fa-user-tie"></i> Informations vie scolaire</h3>
            <div class="form-row">
                <div class="form-group"><label>CPE</label><select name="est_CPE"><option value="non">Non</option><option value="oui">Oui</option></select></div>
                <div class="form-group"><label>Infirmerie</label><select name="est_infirmerie"><option value="non">Non</option><option value="oui">Oui</option></select></div>
            </div>
        </div>

        <!-- Champs administrateur -->
        <div class="dynamic-fields" id="fields-administrateur">
            <h3><i class="fas fa-user-shield"></i> Administrateur</h3>
            <p style="font-size:13px;color:var(--text-light, #718096);">Aucun champ spécifique requis. L'utilisateur aura un accès complet au panneau d'administration.</p>
        </div>

        <!-- Rôles fonctionnels (catalogue) — rôle principal + sous-rôles, pour le personnel -->
        <div class="dynamic-fields" id="fields-roles">
            <h3><i class="fas fa-user-shield"></i> Rôles fonctionnels</h3>
            <p style="font-size:13px;color:var(--text-light, #718096);">Un <strong>rôle principal</strong> + des <strong>sous-rôles</strong> donnant des permissions spécifiques. Les élèves et les parents n'ont en général qu'un seul rôle (leur type) ; le personnel peut en cumuler plusieurs.</p>
            <div class="form-group">
                <label>Rôle principal</label>
                <select name="principal_role">
                    <option value="">— Aucun (rôle de base du type) —</option>
                    <?php foreach ($rolesByTier as $tier => $roles): ?>
                        <?php $opts = array_filter($roles, fn($rk) => isset($assignableSet[$rk]), ARRAY_FILTER_USE_KEY); if (!$opts) continue; ?>
                        <optgroup label="<?= htmlspecialchars($tierLabels[$tier] ?? $tier) ?>" data-tier="<?= htmlspecialchars($tier) ?>">
                        <?php foreach ($opts as $rk => $meta): ?>
                            <option value="<?= htmlspecialchars($rk) ?>"><?= htmlspecialchars($meta['label'] ?? $rk) ?><?= !empty($meta['sensitive']) ? ' 🔒' : '' ?></option>
                        <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Sous-rôles (permissions additionnelles)</label>
                <div style="max-height:240px;overflow-y:auto;border:1px solid var(--border-color, #e2e8f0);border-radius:8px;padding:10px">
                    <?php foreach ($rolesByTier as $tier => $roles):
                        $opts = array_filter($roles, fn($rk) => isset($assignableSet[$rk]), ARRAY_FILTER_USE_KEY);
                        if (!$opts) continue; ?>
                        <div class="role-tier" data-tier="<?= htmlspecialchars($tier) ?>">
                        <strong style="display:block;font-size:.78em;color:var(--text-light, #64748b);text-transform:uppercase;letter-spacing:.04em;margin:6px 0 3px"><?= htmlspecialchars($tierLabels[$tier] ?? $tier) ?></strong>
                        <?php foreach ($opts as $rk => $meta): ?>
                            <label style="display:inline-flex;align-items:center;gap:5px;margin:2px 12px 2px 0;font-weight:normal;font-size:.9em">
                                <input type="checkbox" name="sub_roles[]" value="<?= htmlspecialchars($rk) ?>"> <?= htmlspecialchars($meta['label'] ?? $rk) ?><?= !empty($meta['sensitive']) ? ' 🔒' : '' ?>
                            </label>
                        <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div style="margin-top: 20px;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> Créer l'utilisateur</button>
        </div>
    </form>
</div>

<script nonce="<?= csp_nonce() ?>">
// Tiers de rôles autorisés par type de compte (garde-fou : vie scolaire ≠ administration).
var ALLOWED_TIERS = <?= json_encode(\API\Security\RoleCatalog::accountAllowedTiers(), JSON_UNESCAPED_UNICODE) ?>;

function filterRolesByAccount(profil) {
    var allowed = ALLOWED_TIERS[profil] || [];
    document.querySelectorAll('#fields-roles .role-tier').forEach(function (div) {
        var ok = allowed.indexOf(div.getAttribute('data-tier')) !== -1;
        div.style.display = ok ? '' : 'none';
        if (!ok) div.querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = false; });
    });
    document.querySelectorAll('select[name="principal_role"] optgroup').forEach(function (og) {
        var ok = allowed.indexOf(og.getAttribute('data-tier')) !== -1;
        og.disabled = !ok; og.style.display = ok ? '' : 'none';
        og.querySelectorAll('option').forEach(function (o) { o.disabled = !ok; if (!ok && o.selected) o.selected = false; });
    });
}

function selectProfil(profil) {
    document.getElementById('profilInput').value = profil;
    document.querySelectorAll('.profil-btn').forEach(b => b.classList.remove('selected'));
    document.querySelector(`.profil-btn[data-profil="${profil}"]`).classList.add('selected');
    document.querySelectorAll('.dynamic-fields').forEach(f => f.classList.remove('visible'));
    const fields = document.getElementById('fields-' + profil);
    if (fields) fields.classList.add('visible');
    // Rôles fonctionnels : affichés pour le personnel (pas pour élève/parent).
    const staff = ['professeur', 'vie_scolaire', 'administrateur'].includes(profil);
    const rolesBox = document.getElementById('fields-roles');
    if (rolesBox) rolesBox.classList.toggle('visible', staff);
    filterRolesByAccount(profil);
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
