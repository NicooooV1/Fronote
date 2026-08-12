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
            // Périmètre fin construit depuis des SÉLECTEURS (plus de JSON à écrire) :
            // classes cochées → class_ids ; élèves choisis → student_ids. Seules ces deux
            // clés sont lues par le moteur (Authorization) ; on n'émet rien d'autre.
            $classIds   = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['class_ids'] ?? [])))));
            $studentIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['student_ids'] ?? [])))));
            $scopeArr = [];
            if ($classIds)   { $scopeArr['class_ids'] = $classIds; }
            if ($studentIds) { $scopeArr['student_ids'] = $studentIds; }
            $svc->assign($actor, $actorRoles, $targetType, $targetId, $_POST['role_key'] ?? '', [
                'etablissement_id' => $_POST['etablissement_id'] ?? '',
                'scope_type'       => ($_POST['scope_type'] ?? '') !== '' ? $_POST['scope_type'] : null,
                'scope'            => $scopeArr ?: null,
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
$targetName = $targetAllowed ? $svc->userLabel($targetType, $targetId) : null;
// Libellés FR des types de compte (pour un affichage lisible « Marie Dupont · professeur »).
$typeLabelsFr = [
    'eleve' => 'élève', 'parent' => 'parent', 'professeur' => 'professeur',
    'vie_scolaire' => 'vie scolaire', 'administrateur' => 'administrateur',
];
$targetDisplay = $targetName !== null
    ? $targetName . ' · ' . ($typeLabelsFr[$targetType] ?? $targetType)
    : ($typeLabelsFr[$targetType] ?? $targetType) . ' #' . $targetId;
$etabs      = $pdo->query("SELECT id, nom FROM etablissements WHERE actif = 1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

// Périmètres en LANGAGE CLAIR (value = enum technique, texte = libellé FR).
$scopeLabels = [
    'establishment'  => "Tout l'établissement",
    'own_classes'    => 'Certaines classes seulement',
    'assigned'       => 'Certains élèves seulement',
    'children'       => 'Ses enfants',
    'self'           => 'Ses propres données',
    'global'         => 'Toute la plateforme',
];
// Périmètres proposés (on masque 'establishments' non câblé au runtime ; 'global' réservé super-admin).
$scopeTypes = array_keys($scopeLabels);
// Seuls les 5 types rattachés à un établissement (super_admin/technicien : pas de cible ici).
$accountTypes = ['eleve', 'parent', 'professeur', 'vie_scolaire', 'administrateur'];

// Classes de l'établissement cible — pour le sélecteur « certaines classes » (own_classes).
$targetClasses = [];
if ($targetAllowed) {
    try {
        $etabForClasses = $isSuper && !empty($current[0]['etablissement_id'])
            ? (int) $current[0]['etablissement_id']
            : (int) \API\Core\EstablishmentContext::id();
        $cs = $pdo->prepare("SELECT id, nom, niveau FROM classes WHERE etablissement_id = ? AND actif = 1 ORDER BY niveau, nom");
        $cs->execute([$etabForClasses]);
        $targetClasses = $cs->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { $targetClasses = []; }
}

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
        'scope'     => $meta['scope'] ?? 'establishment',
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

    /* Recherche de cible par nom */
    .rl-search-field { position: relative; flex: 1 1 320px; }
    .rl-results { position: absolute; top: 100%; left: 0; right: 0; z-index: 30; margin-top: 4px;
        background: var(--surface, #fff); border: 1px solid var(--border, #e2e8f0); border-radius: 10px;
        box-shadow: 0 12px 30px rgba(15,23,42,.16); max-height: 320px; overflow-y: auto; }
    .rl-res { display: flex; flex-direction: column; width: 100%; text-align: left; gap: 2px;
        padding: 9px 12px; background: transparent; border: 0; border-bottom: 1px solid var(--border-light, #eef1f5); cursor: pointer; }
    .rl-res:last-child { border-bottom: 0; }
    .rl-res:hover, .rl-res:focus-visible { background: var(--surface-muted, #f1f5f9); }
    .rl-res-name { font-weight: 600; color: var(--text, #0f172a); font-size: .92rem; }
    .rl-res-meta { font-size: .74rem; color: var(--text-muted, #64748b); }
    .rl-res-empty { padding: 12px; color: var(--text-muted, #64748b); font-style: italic; }
    .rl-picker-current { margin: 12px 0 0; font-size: .86rem; color: var(--text-secondary, #475569); }

    /* Périmètre : sélecteur clair + panneaux concrets */
    .rl-scope-lab { display: flex; flex-direction: column; gap: 4px; font-size: .76rem; font-weight: 600; color: var(--text-muted, #64748b); }
    .rl-scope-lab select { padding: 8px 10px; border: 1px solid var(--border, #e2e8f0); border-radius: 8px; font-size: 13px; background: var(--surface, #fff); color: var(--text, #0f172a); }
    .rl-scope-panel { border: 1px solid var(--border-light, #eef1f5); border-radius: 9px; padding: 10px; background: var(--surface-muted, #f8fafc); }
    .rl-scope-hint { margin: 0 0 8px; font-size: .76rem; color: var(--text-secondary, #475569); }
    .rl-scope-hint em { color: var(--text-muted, #64748b); font-style: italic; }
    .rl-checks { display: flex; flex-wrap: wrap; gap: 6px 14px; }
    .rl-check { display: inline-flex; align-items: center; gap: 6px; font-size: .82rem; color: var(--text, #0f172a); cursor: pointer; }
    .rl-stu-search { width: 100%; padding: 7px 9px; border: 1px solid var(--border, #e2e8f0); border-radius: 8px; font-size: 13px; background: var(--surface, #fff); color: var(--text, #0f172a); }
    .rl-stu-results { margin-top: 4px; border: 1px solid var(--border, #e2e8f0); border-radius: 8px; max-height: 200px; overflow-y: auto; background: var(--surface, #fff); }
    .rl-stu-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
    .rl-chip-mini { display: inline-flex; align-items: center; gap: 6px; padding: 3px 6px 3px 10px; border-radius: 9999px; background: var(--primary, #7c3aed); color: #fff; font-size: .78rem; }
    .rl-chip-mini button { border: 0; background: rgba(255,255,255,.25); color: #fff; width: 18px; height: 18px; border-radius: 50%; cursor: pointer; line-height: 1; }

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

    <!-- Sélecteur de la cible : RECHERCHE PAR NOM (plus d'id numérique à connaître) -->
    <div class="rl-panel">
        <p class="rl-eyebrow">Choisir l'utilisateur</p>
        <div class="rl-picker">
            <div class="rl-field rl-search-field">
                <label for="rl-search">Rechercher par nom ou prénom</label>
                <input type="text" id="rl-search" autocomplete="off" placeholder="Ex. Dupont, Marie…"
                       aria-controls="rl-results" aria-expanded="false" role="combobox">
                <div id="rl-results" class="rl-results" role="listbox" hidden></div>
            </div>
            <div class="rl-field">
                <label for="rl-type-filter">Type (optionnel)</label>
                <select id="rl-type-filter">
                    <option value="">Tous les types</option>
                    <?php foreach ($accountTypes as $t): ?>
                        <option value="<?= e($t) ?>"><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php if ($targetLoaded && $targetAllowed): ?>
            <p class="rl-picker-current">Utilisateur chargé : <strong><?= e($targetDisplay) ?></strong> — vous pouvez gérer ses rôles ci-dessous, ou en rechercher un autre.</p>
        <?php endif; ?>
    </div>

    <?php if ($targetLoaded && $targetAllowed): ?>

    <!-- Rôles courants : puces retirables -->
    <div class="rl-panel">
        <div class="rl-panel__head">
            <p class="rl-eyebrow" style="margin:0">Rôles actuels</p>
            <span class="rl-mono"><?= e($targetDisplay) ?></span>
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
        </div>
    </details>

    <!-- Rôles attribuables : cartes/boutons groupées par tier -->
    <div class="rl-panel">
        <p class="rl-eyebrow">Attribuer un rôle</p>
        <?php if (!$offer): ?>
            <p class="rl-empty">Aucun rôle compatible avec un compte « <?= e($typeLabelsFr[$targetType] ?? $targetType) ?> ».</p>
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
                                <!-- établissement + expiration fusionnés depuis les options avancées via JS -->
                                <input type="hidden" name="etablissement_id" value="">
                                <input type="hidden" name="valid_until" value="">
                                <?php if ($sens): ?>
                                <div class="rl-reason">
                                    <input type="text" name="reason" placeholder="Justification (obligatoire)" required>
                                </div>
                                <?php endif; ?>
                                <div class="rl-card__form">
                                    <label class="rl-scope-lab">Périmètre
                                        <select name="scope_type" class="rl-scope-select" data-default="<?= e($role['scope']) ?>">
                                            <?php foreach ($scopeTypes as $s):
                                                if ($s === 'global' && !$isSuper) { continue; } ?>
                                                <option value="<?= e($s) ?>" <?= $s === $role['scope'] ? 'selected' : '' ?>><?= e($scopeLabels[$s] ?? $s) ?><?= $s === $role['scope'] ? ' — par défaut' : '' ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <div class="rl-scope-panel rl-scope-classes" data-scope="own_classes" hidden>
                                        <?php if ($targetClasses): ?>
                                            <p class="rl-scope-hint">Classes concernées <em>(aucune cochée = toutes ses classes)</em> :</p>
                                            <div class="rl-checks">
                                                <?php foreach ($targetClasses as $cl): ?>
                                                    <label class="rl-check"><input type="checkbox" name="class_ids[]" value="<?= (int) $cl['id'] ?>"> <?= e($cl['nom']) ?></label>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <p class="rl-scope-hint">Aucune classe dans cet établissement.</p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="rl-scope-panel rl-scope-students" data-scope="assigned" hidden>
                                        <p class="rl-scope-hint">Élèves concernés :</p>
                                        <input type="text" class="rl-stu-search" placeholder="Rechercher un élève…" autocomplete="off">
                                        <div class="rl-stu-results" hidden></div>
                                        <div class="rl-stu-chips"></div>
                                    </div>
                                    <div class="rl-card__row">
                                        <button type="submit" class="rl-go"><i class="fas fa-plus"></i> Attribuer ce rôle</button>
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
        <p class="rl-empty">Recherchez un utilisateur par son nom ci-dessus pour gérer ses rôles.</p>
    </div>
    <?php endif; ?>
</div>

<script nonce="<?= csp_nonce() ?>">
(function () {
    'use strict';
    var advEtab  = document.getElementById('rl-adv-etab');
    var advUntil = document.getElementById('rl-adv-until');
    function setField(form, name, val) { var i = form.querySelector('input[name="' + name + '"]'); if (i) { i.value = val || ''; } }
    function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]; }); }
    function debounce(fn, ms){ var t; return function(){ var a=arguments, c=this; clearTimeout(t); t=setTimeout(function(){ fn.apply(c, a); }, ms); }; }

    // ── Recherche de la cible par NOM → ouvre roles.php pré-chargé ─────────
    var search = document.getElementById('rl-search');
    var results = document.getElementById('rl-results');
    var typeFilter = document.getElementById('rl-type-filter');
    if (search && results) {
        var run = debounce(function () {
            var q = search.value.trim();
            if (q.length < 2) { results.hidden = true; return; }
            var url = 'roles_search.php?q=' + encodeURIComponent(q) + (typeFilter && typeFilter.value ? '&type=' + encodeURIComponent(typeFilter.value) : '');
            fetch(url).then(function (r) { return r.json(); }).then(function (d) {
                var items = d.results || [];
                results.innerHTML = items.length
                    ? items.map(function (u) { return '<button type="button" class="rl-res" data-ut="' + esc(u.type) + '" data-uid="' + u.id + '"><span class="rl-res-name">' + esc(u.label) + '</span><span class="rl-res-meta">' + esc(u.type) + (u.sub ? ' · ' + esc(u.sub) : '') + '</span></button>'; }).join('')
                    : '<div class="rl-res-empty">Aucun résultat</div>';
                results.hidden = false;
            }).catch(function () { results.hidden = true; });
        }, 220);
        search.addEventListener('input', run);
        if (typeFilter) { typeFilter.addEventListener('change', function () { if (search.value.trim().length >= 2) { run(); } }); }
        results.addEventListener('click', function (ev) {
            var b = ev.target.closest('.rl-res'); if (!b || !b.dataset.uid) { return; }
            window.location = 'roles.php?ut=' + encodeURIComponent(b.dataset.ut) + '&uid=' + encodeURIComponent(b.dataset.uid);
        });
        document.addEventListener('click', function (ev) { if (!ev.target.closest('.rl-search-field')) { results.hidden = true; } });
    }

    // ── Périmètre : afficher le sélecteur concret selon le choix ──────────
    function updatePanels(select) {
        var form = select.closest('.rl-assign-form'); if (!form) { return; }
        var val = select.value;
        form.querySelectorAll('.rl-scope-panel').forEach(function (pan) {
            var on = pan.getAttribute('data-scope') === val;
            pan.hidden = !on;
            pan.querySelectorAll('input').forEach(function (inp) { inp.disabled = !on; }); // pas de class_ids/student_ids parasites
        });
    }
    document.querySelectorAll('.rl-scope-select').forEach(function (sel) {
        updatePanels(sel);
        sel.addEventListener('change', function () { updatePanels(sel); });
    });

    // ── Recherche d'élèves (périmètre « certains élèves ») → chips ────────
    document.querySelectorAll('.rl-scope-students').forEach(function (pan) {
        var input = pan.querySelector('.rl-stu-search');
        var res = pan.querySelector('.rl-stu-results');
        var chips = pan.querySelector('.rl-stu-chips');
        var form = pan.closest('.rl-assign-form');
        function addChip(id, label) {
            if (form.querySelector('input[name="student_ids[]"][value="' + id + '"]')) { return; }
            var chip = document.createElement('span'); chip.className = 'rl-chip-mini';
            chip.innerHTML = esc(label) + ' <button type="button" aria-label="Retirer">&times;</button><input type="hidden" name="student_ids[]" value="' + id + '">';
            chip.querySelector('button').addEventListener('click', function () { chip.remove(); });
            chips.appendChild(chip);
        }
        var run = debounce(function () {
            var q = input.value.trim(); if (q.length < 2) { res.hidden = true; return; }
            fetch('roles_search.php?type=eleve&q=' + encodeURIComponent(q)).then(function (r) { return r.json(); }).then(function (d) {
                var items = d.results || [];
                res.innerHTML = items.length ? items.map(function (u) { return '<button type="button" class="rl-res" data-uid="' + u.id + '" data-lab="' + esc(u.label) + '"><span class="rl-res-name">' + esc(u.label) + '</span><span class="rl-res-meta">' + esc(u.sub || '') + '</span></button>'; }).join('') : '<div class="rl-res-empty">Aucun élève</div>';
                res.hidden = false;
            }).catch(function () { res.hidden = true; });
        }, 220);
        input.addEventListener('input', run);
        res.addEventListener('click', function (ev) { var b = ev.target.closest('.rl-res'); if (!b) { return; } addChip(b.dataset.uid, b.dataset.lab); input.value = ''; res.hidden = true; });
    });

    // ── Soumission : fusionne établissement + expiration (options avancées) ─
    document.querySelectorAll('.rl-assign-form').forEach(function (form) {
        form.addEventListener('submit', function () {
            if (advEtab)  { setField(form, 'etablissement_id', advEtab.value); }
            if (advUntil) { setField(form, 'valid_until', advUntil.value); }
            var card = form.closest('.rl-card'); if (card) { card.classList.add('is-busy'); }
        });
    });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</content>
</invoke>
