<?php
declare(strict_types=1);
/**
 * Attribution des rôles applicatifs (RBAC) — assigne/révoque des rôles scopés et
 * temporisés (table user_roles) à un utilisateur. Réservé administration / direction /
 * super-admin. Le contrôle d'accès central (AccessControl) impose déjà admin/ ; on
 * double ici par tenantGate + garde-fous anti-escalade dans RoleManagementService.
 *
 * UI (refonte 2026) : plus de <select>+<table>. Les rôles attribuables sont présentés
 * en CARTES/boutons groupées par TIER (chip couleur de tier + libellé + description +
 * sélecteur de périmètre), un clic attribue (POST assign) ; les rôles courants sont des
 * puces retirables (× pour révoquer). Réutilise les couleurs de tier et descriptions de
 * RoleCatalog + le composant role_badges. Aucune logique de service modifiée.
 */
require_once __DIR__ . '/../../API/core.php';
require_once __DIR__ . '/../includes/admin_functions.php';
require_once __DIR__ . '/../../modules/profil/includes/role_badges.php';

requireAuth();
tenantGate('tenant.users.view');

use API\Services\RoleManagementService;
use API\Security\RoleCatalog;

$pdo   = getPDO();
$svc   = new RoleManagementService($pdo);
$actor = getCurrentUser();
$actorRoles = app('authz')->roleKeys();

$message = '';
$error   = '';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Cible courante (utilisateur à qui on gère les rôles).
$targetType = $_GET['ut'] ?? ($_POST['ut'] ?? '');
$targetId   = (int) ($_GET['uid'] ?? ($_POST['uid'] ?? 0));

// Cloisonnement multi-tenant : un admin d'établissement ne peut lister/gérer les rôles
// que d'un utilisateur de SON etab ; le super_admin conserve la portée globale.
$isSuper       = function_exists('isSuperAdmin') && isSuperAdmin();
$targetLoaded  = $targetType && $targetId > 0;
$targetAllowed = $targetLoaded && ($isSuper || adminCanManageUser($targetId, $targetType));
if ($targetLoaded && !$targetAllowed) {
    $error = "Utilisateur hors de votre établissement.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'assign' && $targetAllowed) {
            // Périmètre fin optionnel (scope_json), p.ex. {"class_ids":[2,4]} pour own_classes,
            // {"student_ids":[5,7]} pour assigned, {"etablissement_ids":[1,3]} pour establishments.
            $scopeRaw = trim((string) ($_POST['scope_json'] ?? ''));
            $scopeArr = null;
            if ($scopeRaw !== '') {
                $scopeArr = json_decode($scopeRaw, true);
                if (!is_array($scopeArr)) {
                    throw new \RuntimeException('Périmètre JSON invalide. Exemple : {"class_ids":[2,4]} ou {"student_ids":[5,7]}.');
                }
            }
            $svc->assign($actor, $actorRoles, $targetType, $targetId, $_POST['role_key'] ?? '', [
                'etablissement_id' => $_POST['etablissement_id'] ?? '',
                'scope_type'       => ($_POST['scope_type'] ?? '') !== '' ? $_POST['scope_type'] : null,
                'scope'            => $scopeArr,
                'valid_until'      => $_POST['valid_until'] ?? null,
                'reason'           => $_POST['reason'] ?? null,
            ]);
            $message = 'Rôle attribué.';
        } elseif ($action === 'revoke' && $targetAllowed) {
            $svc->revoke($actor, $actorRoles, (int) ($_POST['row_id'] ?? 0));
            $message = 'Rôle révoqué.';
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$assignable = $svc->assignableRoles($actorRoles);
$catalog    = RoleCatalog::roles();
$current    = $targetAllowed ? $svc->listUserRoles($targetType, $targetId) : [];
$etabs      = $pdo->query("SELECT id, nom FROM etablissements WHERE actif = 1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$scopeTypes = ['establishment', 'establishments', 'global', 'self', 'children', 'assigned', 'own_classes'];
$accountTypes = ['eleve', 'parent', 'professeur', 'vie_scolaire', 'administrateur', 'super_admin', 'technicien'];

// Rôles réellement compatibles avec le type de compte cible (garde-fou métier du
// catalogue) : un super_admin n'est pas limité et se voit proposer tout l'assignable.
$rolesForAccount = $targetType ? RoleCatalog::rolesForAccount($targetType) : [];

// Regroupement des rôles proposés par TIER, ordonnés par priorité de tier.
$offer = [];
foreach ($assignable as $rk) {
    if (!isset($catalog[$rk])) {
        continue;
    }
    if (!$isSuper && !isset($rolesForAccount[$rk])) {
        continue; // masque les rôles que le service refuserait (incompatibles avec le compte)
    }
    $meta = $catalog[$rk];
    $tier = $meta['tier'] ?? 'autre';
    $offer[$tier][] = [
        'key'       => $rk,
        'label'     => $meta['label'] ?? $rk,
        'color'     => RoleCatalog::tierColor($tier),
        'desc'      => RoleCatalog::roleDescription($rk),
        'sensitive' => !empty($meta['sensitive']),
    ];
}
uksort($offer, static fn($a, $b) => rc_tier_rank($a) <=> rc_tier_rank($b));

$tierLabels = [
    'plateforme'    => 'Plateforme',
    'direction'     => 'Direction',
    'administratif' => 'Administratif',
    'organisation'  => 'Organisation & EDT',
    'pedagogique'   => 'Pédagogique',
    'enseignant'    => 'Enseignant',
    'vie_scolaire'  => 'Vie scolaire',
    'sante_social'  => 'Santé & social',
    'communication' => 'Communication',
    'documents'     => 'Documents',
    'services'      => 'Services annexes',
    'stages'        => 'Stages & alternance',
    'controle'      => 'Contrôle & lecture',
    'eleve_famille' => 'Élèves & familles',
    'famille'       => 'Familles',
    'eleve'         => 'Élèves',
    'systeme'       => 'Système',
];

$pageTitle   = 'Attribution des rôles';
$currentPage = 'roles';
$pageBack    = 'admin/users/index.php';
$extraCss    = ['../../assets/css/admin.css'];

ob_start();
?>
<style>
    .rl-wrap { max-width: 1080px; margin: 0 auto; }
    .rl-eyebrow { font-size: .72rem; text-transform: uppercase; letter-spacing: .08em; font-weight: 700;
        color: var(--text-muted, #64748b); margin: 0 0 8px; }
    .rl-panel { background: var(--surface, #fff); border: 1px solid var(--border, #e2e8f0);
        border-radius: 14px; padding: 18px 20px; margin-bottom: 18px; }
    .rl-panel__head { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
    .rl-panel__head h2 { font-size: 1.06rem; margin: 0; color: var(--text, #0f172a); }
    .rl-mono { font-family: ui-monospace, "SFMono-Regular", Menlo, monospace; font-size: .9em;
        color: var(--text-muted, #64748b); }

    /* Sélecteur de la cible */
    .rl-picker { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
    .rl-field { display: flex; flex-direction: column; gap: 4px; }
    .rl-field > label { font-size: .78rem; font-weight: 600; color: var(--text-muted, #64748b); }
    .rl-field select, .rl-field input { padding: 9px 11px; border: 1px solid var(--border, #e2e8f0);
        border-radius: 9px; font-size: 15px; background: var(--surface, #fff); color: var(--text, #0f172a);
        min-height: 40px; }
    .rl-field input[type=number] { width: 130px; }

    /* Rôles courants -> puces retirables */
    .rl-chips { display: flex; flex-wrap: wrap; gap: 10px; }
    .rl-chip { display: inline-flex; align-items: center; gap: 10px; padding: 8px 8px 8px 14px;
        border-radius: 9999px; color: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.14); }
    .rl-chip__body { display: flex; flex-direction: column; line-height: 1.25; }
    .rl-chip__label { font-weight: 700; font-size: .9rem; }
    .rl-chip__meta { font-size: .7rem; opacity: .85; font-family: ui-monospace, "SFMono-Regular", Menlo, monospace; }
    .rl-chip__x { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px;
        border: 0; border-radius: 50%; background: rgba(255,255,255,.22); color: #fff; cursor: pointer;
        font-size: 13px; line-height: 1; transition: background .12s ease; }
    .rl-chip__x:hover { background: rgba(255,255,255,.4); }
    .rl-revoke-form { margin: 0; }
    .rl-empty { color: var(--text-muted, #64748b); font-style: italic; margin: 0; }

    /* Groupes par tier + grille de cartes */
    .rl-tier { margin-bottom: 22px; }
    .rl-tier:last-child { margin-bottom: 0; }
    .rl-tier__head { display: flex; align-items: center; gap: 10px; margin: 0 0 10px; }
    .rl-tier__dot { width: 10px; height: 10px; border-radius: 3px; flex: 0 0 auto; }
    .rl-tier__title { font-size: .74rem; text-transform: uppercase; letter-spacing: .06em; font-weight: 700;
        color: var(--text, #0f172a); }
    .rl-tier__count { font-size: .7rem; color: var(--text-muted, #64748b);
        font-family: ui-monospace, "SFMono-Regular", Menlo, monospace; }

    .rl-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(288px, 1fr)); gap: 12px; }
    .rl-card { position: relative; display: flex; flex-direction: column; gap: 10px;
        border: 1px solid var(--border, #e2e8f0); border-left: 4px solid var(--tc, #64748b);
        border-radius: 12px; padding: 13px 14px; background: var(--surface, #fff);
        cursor: pointer; transition: box-shadow .14s ease, transform .14s ease, border-color .14s ease; }
    .rl-card:hover { box-shadow: 0 6px 18px rgba(15,23,42,.10); transform: translateY(-1px); }
    .rl-card.is-busy { opacity: .55; pointer-events: none; }
    .rl-card__top { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .rl-badge { display: inline-flex; align-items: center; gap: 6px; padding: 3px 11px; border-radius: 9999px;
        background: var(--tc, #64748b); color: #fff; font-size: .8rem; font-weight: 700; white-space: nowrap; }
    .rl-badge__dot { width: 7px; height: 7px; border-radius: 50%; background: rgba(255,255,255,.9); }
    .rl-key { font-family: ui-monospace, "SFMono-Regular", Menlo, monospace; font-size: .68rem;
        color: var(--text-muted, #64748b); }
    .rl-lock { color: #dc2626; font-size: .72rem; margin-left: auto; }
    .rl-card__desc { font-size: .82rem; color: var(--text-muted, #64748b); line-height: 1.4; margin: 0; }
    .rl-card__form { display: flex; flex-direction: column; gap: 8px; margin: 2px 0 0; }
    .rl-card__row { display: flex; gap: 8px; align-items: center; }
    .rl-card__row select, .rl-card__row input { flex: 1; min-width: 0; padding: 7px 9px;
        border: 1px solid var(--border, #e2e8f0); border-radius: 8px; font-size: 13px;
        background: var(--surface, #fff); color: var(--text, #0f172a); }
    .rl-go { display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; cursor: pointer;
        border: 1px solid #7c3aed; background: #7c3aed; color: #fff; font-weight: 600; font-size: 13px;
        padding: 8px 13px; border-radius: 8px; transition: background .12s ease; }
    .rl-go:hover { background: #6d28d9; border-color: #6d28d9; }
    .rl-reason { display: none; }
    .rl-card--sensitive .rl-reason { display: block; }

    /* Options avancées partagées */
    .rl-adv { border: 1px dashed var(--border, #e2e8f0); border-radius: 10px; padding: 0 14px; margin-bottom: 18px; }
    .rl-adv > summary { cursor: pointer; padding: 12px 0; font-weight: 600; font-size: .86rem;
        color: var(--text, #0f172a); }
    .rl-adv__grid { display: flex; flex-wrap: wrap; gap: 14px; padding: 4px 0 16px; }
    .rl-adv small { display: block; margin-top: 4px; color: var(--text-muted, #64748b); font-size: .74rem; }

    :focus-visible { outline: 2px solid #7c3aed; outline-offset: 2px; }
    @media (prefers-reduced-motion: reduce) { .rl-card, .rl-go, .rl-chip__x { transition: none; } }
    @media (max-width: 640px) { .rl-grid { grid-template-columns: 1fr; } }

    @media (prefers-color-scheme: dark) {
        .rl-panel, .rl-card, .rl-field select, .rl-field input,
        .rl-card__row select, .rl-card__row input { background: #0f172a; border-color: #1e293b; color: #e2e8f0; }
        .rl-panel__head h2, .rl-tier__title, .rl-adv > summary { color: #e2e8f0; }
        .rl-adv { border-color: #1e293b; }
    }
</style>
<?php
$extraHeadHtml = ob_get_clean();
include __DIR__ . '/../includes/header.php';
?>
<div class="rl-wrap">
    <div class="page-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px">
        <h1 style="margin:0"><i class="fas fa-user-shield"></i> Attribution des rôles</h1>
        <a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Utilisateurs</a>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <!-- Sélecteur de la cible -->
    <div class="rl-panel">
        <p class="rl-eyebrow">Cible</p>
        <form method="get" class="rl-picker">
            <div class="rl-field">
                <label for="rl-ut">Type de compte</label>
                <select name="ut" id="rl-ut">
                    <?php foreach ($accountTypes as $t): ?>
                        <option value="<?= e($t) ?>" <?= $targetType === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="rl-field">
                <label for="rl-uid">ID utilisateur</label>
                <input type="number" name="uid" id="rl-uid" value="<?= $targetId ?: '' ?>" min="1">
            </div>
            <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i> Charger</button>
        </form>
    </div>

    <?php if ($targetLoaded && $targetAllowed): ?>

    <!-- Rôles courants : puces retirables -->
    <div class="rl-panel">
        <div class="rl-panel__head">
            <p class="rl-eyebrow" style="margin:0">Rôles actuels</p>
            <span class="rl-mono"><?= e($targetType) ?> #<?= $targetId ?></span>
        </div>
        <?php if (!$current): ?>
            <p class="rl-empty">Aucun rôle attribué (le rôle de base = type de compte s'applique).</p>
        <?php else: ?>
            <div class="rl-chips">
                <?php foreach ($current as $r):
                    $view  = rc_role_view((string) $r['role_key']);
                    $color = $view['color'] ?? RoleCatalog::TIER_COLOR_DEFAULT;
                    $label = $view['label'] ?? ($r['label'] ?? $r['role_key']);
                    $etab  = $r['etablissement_id'] !== null ? ('étab. ' . (int) $r['etablissement_id']) : 'tous étab.';
                    $meta  = [$etab];
                    if (!empty($r['scope_type']))  { $meta[] = (string) $r['scope_type']; }
                    if (!empty($r['valid_until'])) { $meta[] = 'exp. ' . substr((string) $r['valid_until'], 0, 10); }
                ?>
                    <span class="rl-chip" style="background:<?= e($color) ?>">
                        <span class="rl-chip__body">
                            <span class="rl-chip__label"><?= e($label) ?></span>
                            <span class="rl-chip__meta"><?= e(implode(' · ', $meta)) ?></span>
                        </span>
                        <form method="post" class="rl-revoke-form" data-fr-confirm="Révoquer le rôle « <?= e($label) ?> » ?">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="ut" value="<?= e($targetType) ?>">
                            <input type="hidden" name="uid" value="<?= $targetId ?>">
                            <input type="hidden" name="action" value="revoke">
                            <input type="hidden" name="row_id" value="<?= (int) $r['id'] ?>">
                            <button type="submit" class="rl-chip__x" title="Révoquer" aria-label="Révoquer le rôle <?= e($label) ?>">&times;</button>
                        </form>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$assignable): ?>
        <div class="rl-panel"><p class="rl-empty">Votre rôle ne permet pas d'attribuer de rôles.</p></div>
    <?php else: ?>

    <!-- Options avancées partagées (fusionnées dans la carte au moment de l'attribution) -->
    <details class="rl-adv">
        <summary><i class="fas fa-sliders-h"></i> Options avancées d'attribution</summary>
        <div class="rl-adv__grid">
            <?php if ($isSuper): ?>
            <div class="rl-field">
                <label for="rl-adv-etab">Établissement</label>
                <select id="rl-adv-etab">
                    <option value="">— tous (super-admin) —</option>
                    <?php foreach ($etabs as $ee): ?>
                        <option value="<?= (int) $ee['id'] ?>"><?= e($ee['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="rl-field">
                <label for="rl-adv-until">Expire le (optionnel)</label>
                <input type="datetime-local" id="rl-adv-until">
            </div>
            <div class="rl-field" style="flex:1;min-width:240px">
                <label for="rl-adv-scope">Périmètre fin — JSON (optionnel)</label>
                <input type="text" id="rl-adv-scope" placeholder='{"class_ids":[2,4]}'>
                <small>own_classes → class_ids ; assigned → student_ids ; establishments → etablissement_ids</small>
            </div>
        </div>
    </details>

    <!-- Rôles attribuables : cartes/boutons groupées par tier -->
    <div class="rl-panel">
        <p class="rl-eyebrow">Attribuer un rôle</p>
        <?php if (!$offer): ?>
            <p class="rl-empty">Aucun rôle compatible avec un compte « <?= e($targetType) ?> ».</p>
        <?php endif; ?>
        <?php foreach ($offer as $tier => $roles):
            $tcolor = RoleCatalog::tierColor($tier);
            $tlabel = $tierLabels[$tier] ?? ucfirst(str_replace('_', ' ', (string) $tier));
        ?>
            <div class="rl-tier">
                <div class="rl-tier__head">
                    <span class="rl-tier__dot" style="background:<?= e($tcolor) ?>"></span>
                    <span class="rl-tier__title"><?= e($tlabel) ?></span>
                    <span class="rl-tier__count"><?= count($roles) ?></span>
                </div>
                <div class="rl-grid">
                    <?php foreach ($roles as $role):
                        $sens = $role['sensitive'];
                    ?>
                        <div class="rl-card<?= $sens ? ' rl-card--sensitive' : '' ?>" style="--tc:<?= e($role['color']) ?>">
                            <div class="rl-card__top">
                                <span class="rl-badge"><span class="rl-badge__dot"></span><?= e($role['label']) ?></span>
                                <?php if ($sens): ?><span class="rl-lock" title="Rôle sensible — justification requise"><i class="fas fa-lock"></i></span><?php endif; ?>
                            </div>
                            <span class="rl-key"><?= e($role['key']) ?></span>
                            <p class="rl-card__desc"><?= e($role['desc']) ?></p>
                            <form method="post" class="rl-assign-form">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="ut" value="<?= e($targetType) ?>">
                                <input type="hidden" name="uid" value="<?= $targetId ?>">
                                <input type="hidden" name="action" value="assign">
                                <input type="hidden" name="role_key" value="<?= e($role['key']) ?>">
                                <!-- champs fusionnés depuis les options avancées via JS (défauts serveur si absents) -->
                                <input type="hidden" name="etablissement_id" value="">
                                <input type="hidden" name="valid_until" value="">
                                <input type="hidden" name="scope_json" value="">
                                <?php if ($sens): ?>
                                <div class="rl-reason">
                                    <input type="text" name="reason" placeholder="Justification (obligatoire)" required>
                                </div>
                                <?php endif; ?>
                                <div class="rl-card__form">
                                    <div class="rl-card__row">
                                        <select name="scope_type" aria-label="Périmètre">
                                            <option value="">— périmètre par défaut —</option>
                                            <?php foreach ($scopeTypes as $s): ?>
                                                <option value="<?= e($s) ?>"><?= e($s) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="rl-go"><i class="fas fa-plus"></i> Attribuer</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php endif; /* assignable */ ?>

    <?php elseif ($targetLoaded): /* chargé mais hors périmètre : message déjà affiché */ ?>
    <?php else: ?>
    <div class="rl-panel">
        <p class="rl-empty">Sélectionnez un type de compte et un identifiant, puis « Charger » pour gérer ses rôles.</p>
    </div>
    <?php endif; ?>
</div>

<script nonce="<?= csp_nonce() ?>">
(function () {
    'use strict';
    var advEtab  = document.getElementById('rl-adv-etab');
    var advUntil = document.getElementById('rl-adv-until');
    var advScope = document.getElementById('rl-adv-scope');

    function setField(form, name, val) {
        var i = form.querySelector('input[name="' + name + '"]');
        if (i) { i.value = val || ''; }
    }

    // Fusionne les options avancées partagées dans le formulaire soumis + état optimiste.
    var forms = document.querySelectorAll('.rl-assign-form');
    for (var i = 0; i < forms.length; i++) {
        forms[i].addEventListener('submit', function () {
            if (advEtab)  { setField(this, 'etablissement_id', advEtab.value); }
            if (advUntil) { setField(this, 'valid_until', advUntil.value); }
            if (advScope) { setField(this, 'scope_json', advScope.value); }
            var card = this.closest('.rl-card');
            if (card) { card.classList.add('is-busy'); }
        });
    }

    // « Un clic sur la carte attribue » : hors contrôles de formulaire, soumet la carte
    // (requestSubmit conserve la validation native, p.ex. justification requise).
    var cards = document.querySelectorAll('.rl-card');
    for (var c = 0; c < cards.length; c++) {
        cards[c].addEventListener('click', function (ev) {
            if (ev.target.closest('input, select, textarea, button, a, label')) { return; }
            var f = this.querySelector('.rl-assign-form');
            if (!f) { return; }
            if (f.requestSubmit) { f.requestSubmit(); } else { f.submit(); }
        });
    }
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</content>
</invoke>
