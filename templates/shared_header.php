<?php
declare(strict_types=1);
/**
 * Template partagé : Header (ouverture HTML + <head> + top bar)
 * 
 * Variables attendues :
 *   $pageTitle      — string  : titre de la page (affiché dans <title> et le <h1>)
 *   $user_initials  — string  : initiales de l'utilisateur
 * 
 * Variables optionnelles :
 *   $pageSubtitle       — string : sous-titre sous le h1
 *   $extraCss           — array  : fichiers CSS supplémentaires à charger (chemins relatifs)
 *   $extraHeadHtml      — string : HTML supplémentaire dans le <head>
 *   $headerExtraActions — string : HTML d'actions supplémentaires dans le header-actions (boutons spécifiques au module)
 *   $user_fullname      — string : nom complet pour le tooltip de l'avatar
 */

// Gestion des variables globales pour tous les modules
$pageTitle = $pageTitle ?? 'FRONOTE';
$user_initials = $user_initials ?? '';
$pageSubtitle = $pageSubtitle ?? '';
$extraCss = $extraCss ?? [];
$extraHeadHtml = $extraHeadHtml ?? '';
$headerExtraActions = $headerExtraActions ?? '';
$user_fullname = $user_fullname ?? '';
$activePage = $activePage ?? '';
$isAdmin = $isAdmin ?? false;

// rootPrefix : chemin relatif de la page courante vers la racine du projet.
// On calcule la profondeur depuis le SCRIPT_NAME (URI) en priorité — l'URI est
// stable, alors que SCRIPT_FILENAME peut pointer hors du projet sous mod_rewrite
// ou via un lien symbolique résolu ailleurs. On rebascule sur SCRIPT_FILENAME +
// realpath uniquement si l'URI n'est pas exploitable.
if (!isset($rootPrefix)) {
    $rootPrefix = '../';
    $depth = -1;

    // 1) Tentative via l'URI demandée.
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    if ($scriptName !== '' && str_ends_with($scriptName, '.php')) {
        // On a besoin de la base URL (chemin de l'installation côté Apache).
        $baseUrl = defined('BASE_URL') ? (string) BASE_URL : '';
        if ($baseUrl === '') {
            $baseUrl = rtrim((string) getenv('BASE_URL'), '/');
        }
        $relUri = $scriptName;
        if ($baseUrl !== '' && str_starts_with($scriptName, $baseUrl . '/')) {
            $relUri = substr($scriptName, strlen($baseUrl) + 1);
        } else {
            $relUri = ltrim($scriptName, '/');
        }
        if ($relUri !== '' && !str_contains($relUri, '..')) {
            $depth = substr_count($relUri, '/');
        }
    }

    // 2) Fallback filesystem (SCRIPT_FILENAME vs realpath BASE_PATH).
    if ($depth < 0) {
        $projectRoot = realpath(__DIR__ . '/..');
        $scriptPath  = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
        if ($projectRoot && $scriptPath) {
            $rootN = str_replace('\\', '/', $projectRoot);
            $scrN  = str_replace('\\', '/', $scriptPath);
            if (str_starts_with($scrN, $rootN . '/')) {
                $depth = substr_count(substr($scrN, strlen($rootN) + 1), '/');
            }
        }
    }

    if ($depth >= 0) {
        $rootPrefix = $depth > 0 ? str_repeat('../', $depth) : './';
    }
}

// NOTE : $activePage doit être défini dans chaque page/module pour la coloration de la navigation
// Exemples : 'accueil', 'notes', 'agenda', 'cahierdetextes', 'messagerie', 'absences', 'admin'

// ─── Theme loading (cached) ──────────────────────────────────────────────────
// Priorité : ClientCache (session+cookie) → DB → fallback 'classic'
// Élimine la requête SQL sur chaque page après le premier chargement.
$_hdr_theme = 'classic';
$_hdr_dark_mode = 'light';
try {
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['user_type'])) {
        /** @var \API\Core\ClientCache $cc */
        $cc = class_exists('\\API\\Core\\ClientCache') ? new \API\Core\ClientCache() : null;

        $_hdr_raw_theme = null;
        if ($cc) {
            $_hdr_raw_theme = $cc->get('user_theme');
        }

        // Fallback DB si pas en cache
        if ($_hdr_raw_theme === null) {
            $_hdr_pdo = getPDO();
            $_hdr_stmt = $_hdr_pdo->prepare("SELECT theme FROM user_settings WHERE user_id = ? AND user_type = ? LIMIT 1");
            $_hdr_stmt->execute([$_SESSION['user_id'], $_SESSION['user_type']]);
            $_hdr_raw_theme = $_hdr_stmt->fetchColumn();
            if ($_hdr_raw_theme === false || $_hdr_raw_theme === '' || $_hdr_raw_theme === null) {
                // Pas de préférence utilisateur → défaut de l'établissement.
                try { $_hdr_raw_theme = app('themes')->getDefault() ?: 'classic'; }
                catch (\Throwable $e) { $_hdr_raw_theme = 'classic'; }
            }
            // Mettre en cache (TTL 1h — invalidé à la modification dans parametres)
            if ($cc) {
                $cc->set('user_theme', $_hdr_raw_theme, 3600);
            }
        }

        // Support both old (light/dark/auto) and new (classic/glass/custom) theme values
        if (in_array($_hdr_raw_theme, ['classic', 'glass'], true)) {
            $_hdr_theme = $_hdr_raw_theme;
        } elseif (in_array($_hdr_raw_theme, ['light', 'dark', 'auto', 'liquid'], true)) {
            // 'liquid' = thème de la refonte (design-system.css) ; piloté par data-theme.
            $_hdr_theme = 'classic';
            $_hdr_dark_mode = $_hdr_raw_theme;
        } elseif ($_hdr_raw_theme) {
            // Thème custom installé : valider (chemin CSS sûr) avant de l'appliquer.
            try { $_hdr_theme = app('themes')->cssFileFor($_hdr_raw_theme) ? $_hdr_raw_theme : 'classic'; }
            catch (\Throwable $e) { $_hdr_theme = 'classic'; }
        }
    }
} catch (Exception $e) { /* fallback to classic */ }

// For dark mode, let JS handle 'auto' via prefers-color-scheme
$_hdr_effective_dark = $_hdr_dark_mode;
if ($_hdr_dark_mode === 'auto') {
    $_hdr_effective_dark = 'light';
}

// ─── CSRF token ──────────────────────────────────────────────────────────────
// Utilise la facade CSRF (token bucket avec rotation) pour éviter deux systèmes parallèles.
// Stocke également dans $_SESSION['csrf_token'] pour rétrocompatibilité avec les formulaires.
try {
    $_hdr_csrf_token = \API\Core\Facades\CSRF::generate();
} catch (\Throwable $_hdr_csrf_err) {
    // Fallback si le container n'est pas encore initialisé
    $_hdr_csrf_token = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
}
// Token hérité STABLE pour les formulaires legacy qui valident par comparaison
// directe avec $_SESSION['csrf_token']. Il ne doit PAS être réécrit à chaque
// chargement : sinon le token intégré au formulaire (lu avant l'inclusion de ce
// header) se désynchronise de la session et la validation POST échoue en silence
// (permissions/modules non enregistrés, aucun log). Le token tournant exposé via
// $_hdr_csrf_token (meta + formulaires récents validés par app('csrf')->validate)
// reste indépendant.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ─── Nonce CSP ───────────────────────────────────────────────────────────────
$_hdr_nonce = csp_nonce() ?: base64_encode(random_bytes(16));

// ─── WebSocket global config ─────────────────────────────────────────────────
// Génère le JWT pour le client WS et injecte window.FRONOTE_WS dans le <head>.
$_hdr_ws_config = 'null';
try {
    $wsEnabled = env('WEBSOCKET_ENABLED', 'true');
    if (!empty($_SESSION['user_id']) && $wsEnabled !== 'false' && $wsEnabled !== false) {
        $wsToken = \API\Core\WebSocket::generateToken(
            (int) $_SESSION['user_id'],
            $_SESSION['user_type'] ?? $_SESSION['role'] ?? ''
        );
        if ($wsToken) {
            $_hdr_ws_config = json_encode([
                'url'      => env('WEBSOCKET_CLIENT_URL', 'http://localhost:3000'),
                'token'    => $wsToken,
                'userId'   => (int) $_SESSION['user_id'],
                'userType' => $_SESSION['user_type'] ?? $_SESSION['role'] ?? '',
                'userRole' => $_SESSION['role'] ?? $_SESSION['user_type'] ?? '',
            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        }
    }
} catch (\Throwable $_hdr_ws_err) { /* WS optionnel — ne jamais bloquer le rendu */ }

// ─── Security headers ────────────────────────────────────────────────────────
if (!headers_sent()) {
    // CSP : 'unsafe-eval' supprimé, sources http:// supprimées (anti-MITM CDN),
    // object-src 'none'. 'unsafe-inline' conservé tant que les <script>/onclick/style
    // inline (parametres.php, footer, modules tiers) ne sont pas externalisés — à
    // durcir dans un second temps (nonce + refactor des handlers inline).
    // upgrade-insecure-requests désactivé sur HTTP-only (sinon ERR_CONNECTION_REFUSED).
    $_hdr_isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    // CSP ENFORCE stricte : plus de 'unsafe-inline' sur script-src (tous les <script>
    // portent un nonce, tous les handlers inline ont été convertis en data-fr-* via le
    // dispatcher délégué). 'strict-dynamic' : seuls les scripts noncés (et ceux qu'ils
    // chargent) s'exécutent ; l'allowlist d'hôtes est ignorée → un CDN nonce-é passe.
    // style-src : 'unsafe-inline' est CONSERVÉ VOLONTAIREMENT. De très nombreuses vues
    // legacy portent des attributs style="…" inline (largeurs de barres, couleurs
    // calculées côté serveur, display:none/block toggles). Les noncer un par un
    // exigerait un refactor massif et risquerait de casser le rendu FR. Le risque
    // résiduel est FAIBLE et ACCEPTÉ : sans 'unsafe-eval', avec object-src 'none',
    // base-uri/form-action 'self' et un script-src strict (nonce + strict-dynamic),
    // l'injection de style seule n'ouvre pas d'exécution de code. NE PAS retirer
    // 'unsafe-inline' de style-src sans avoir d'abord externalisé tous les style= inline.
    $_hdr_csp = "default-src 'self'; "
        . "script-src 'self' 'nonce-{$_hdr_nonce}' 'strict-dynamic' https:; "
        . "style-src 'self' 'unsafe-inline'; "
        . "font-src 'self' data:; "
        . "img-src 'self' data: blob: https:; "
        . "connect-src 'self' ws: wss:; "
        . "object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; "
        . "report-uri /API/endpoints/csp_report.php;"
        . ($_hdr_isHttps ? ' upgrade-insecure-requests;' : '');
    header("Content-Security-Policy: {$_hdr_csp}");
    // Report-Only STRICTEMENT PLUS DUR que l'enforce : sans lui, une politique RO identique à
    // l'enforce ne mesure rien. Ici on RETIRE 'unsafe-inline' de style-src → chaque style inline
    // restant génère une violation (rapportée, non bloquante) : on quantifie ainsi la dette de
    // styles inline à externaliser avant de pouvoir durcir l'enforce à son tour.
    $_hdr_cspRO = "default-src 'self'; "
        . "script-src 'self' 'nonce-{$_hdr_nonce}' 'strict-dynamic' https:; "
        . "style-src 'self'; "
        . "font-src 'self' data:; "
        . "img-src 'self' data: blob: https:; "
        . "connect-src 'self' ws: wss:; "
        . "object-src 'none'; base-uri 'self'; frame-ancestors 'none'; "
        . "report-uri /API/endpoints/csp_report.php;";
    header("Content-Security-Policy-Report-Only: {$_hdr_cspRO}");
    header("X-Frame-Options: DENY");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
    if ($_hdr_isHttps) {
        header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
    }
}
?>
<!DOCTYPE html>
<?php
$_hdr_locale = 'fr';
$_hdr_dir = 'ltr';
try {
    $translator = app('translator');
    $_hdr_locale = $translator->getLocale();
    $_hdr_dir = $translator->isRtl() ? 'rtl' : 'ltr';
} catch (\Throwable $_e) {}
?>
<html lang="<?= htmlspecialchars($_hdr_locale) ?>" dir="<?= $_hdr_dir ?>" data-theme="<?= htmlspecialchars($_hdr_effective_dark) ?>" data-theme-pref="<?= htmlspecialchars($_hdr_dark_mode) ?>" data-css-theme="<?= htmlspecialchars($_hdr_theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_hdr_csrf_token) ?>">
    <meta name="theme-color" content="#667eea">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="manifest" href="<?= $rootPrefix ?>manifest.webmanifest">
    <link rel="icon" href="<?= $rootPrefix ?>favicon.ico" sizes="any">
    <link rel="icon" type="image/png" href="<?= $rootPrefix ?>assets/icons/icon-192.png">
    <link rel="apple-touch-icon" href="<?= $rootPrefix ?>assets/icons/icon-192.png">
    <title><?= htmlspecialchars($pageTitle) ?> - FRONOTE</title>
    <?php
    // Cache-busting : ajoute ?v=<mtime> pour forcer le rechargement apres edition
    $_assetRoot = realpath(__DIR__ . '/..');
    $_assetVersion = function(string $relPath) use ($_assetRoot, $rootPrefix): string {
        $abs = $_assetRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
        $v = @filemtime($abs) ?: time();
        return $rootPrefix . $relPath . '?v=' . $v;
    };
    ?>
    <!-- CSS : base + tokens + classic (always) + glass overlay (if selected) -->
    <link rel="stylesheet" href="<?= $_assetVersion('assets/css/cookie-consent.css') ?>">
    <link rel="stylesheet" href="<?= $_assetVersion('assets/css/topbar.css') ?>">
    <link rel="stylesheet" href="<?= $_assetVersion('assets/css/base.css') ?>">
    <link rel="stylesheet" href="<?= $_assetVersion('assets/css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= $_assetVersion('assets/css/components.css') ?>">
    <link rel="stylesheet" href="<?= $_assetVersion('assets/css/theme-classic.css') ?>">
    <?php if ($_hdr_theme === 'glass' || $_hdr_theme === 'auto-glass'): ?>
    <link rel="stylesheet" href="<?= $_assetVersion('assets/css/theme-glass.css') ?>">
    <?php elseif ($_hdr_theme !== 'classic'):
        // Thème custom : superposer son CSS (chemin déjà validé par cssFileFor).
        try { $_hdr_custom_css = app('themes')->cssFileFor($_hdr_theme); } catch (\Throwable $e) { $_hdr_custom_css = null; }
        if ($_hdr_custom_css): ?>
    <link rel="stylesheet" href="<?= $_assetVersion($_hdr_custom_css) ?>">
    <?php endif; endif; ?>
    <?php
    // Branding établissement (couleurs) — priorité BASSE : surchargé par le thème/utilisateur (CDC §13.5).
    // Injecté avant les overrides de thème pour que ceux-ci gagnent dans la cascade.
    $_bcc = $cc ?? null;
    $_hdr_brand_css = '';
    try {
        $_etab = app('etablissement')->getCurrent();
        if ($_etab) {
            $_brandKey = 'etab_branding_' . ($_etab['id'] ?? 0);
            $_hdr_brand_css = $_bcc ? $_bcc->get($_brandKey) : null;
            if ($_hdr_brand_css === null) {
                $_map = [
                    'couleur_primaire'   => ['--primary-color', '--primary', '--ds-primary'],
                    'couleur_secondaire' => ['--secondary-color', '--secondary'],
                ];
                $_decls = [];
                foreach ($_map as $_col => $_vars) {
                    $_v = (string) ($_etab[$_col] ?? '');
                    if (preg_match('/^#[0-9a-fA-F]{6}$/', $_v)) {
                        foreach ($_vars as $_vn) { $_decls[] = $_vn . ':' . $_v; }
                    }
                }
                $_hdr_brand_css = $_decls ? ':root{' . implode(';', $_decls) . '}' : '';
                if ($_bcc) { $_bcc->set($_brandKey, $_hdr_brand_css, 3600); }
            }
        }
    } catch (\Throwable $e) { $_hdr_brand_css = ''; }
    if ($_hdr_brand_css !== ''): ?>
    <style id="establishment-branding" nonce="<?= $_hdr_nonce ?>"><?= $_hdr_brand_css ?></style>
    <?php endif; ?>
    <?php
    // Overlays de tokens du thème actif (mis en cache pour éviter une requête par page).
    $_hdr_override_css = '';
    $_hdr_cc = $cc ?? null;
    try {
        $_ovKey = 'theme_overrides_' . $_hdr_theme;
        $_hdr_override_css = $_hdr_cc ? $_hdr_cc->get($_ovKey) : null;
        if ($_hdr_override_css === null) {
            $_hdr_override_css = app('themes')->renderOverrideCss($_hdr_theme);
            if ($_hdr_cc) $_hdr_cc->set($_ovKey, $_hdr_override_css, 3600);
        }
    } catch (\Throwable $e) { $_hdr_override_css = ''; }
    if ($_hdr_override_css !== ''): ?>
    <style id="theme-token-overrides" nonce="<?= $_hdr_nonce ?>"><?= $_hdr_override_css ?></style>
    <?php endif; ?>
    <?php if ($_hdr_dir === 'rtl'): ?>
    <link rel="stylesheet" href="<?= $_assetVersion('assets/css/rtl.css') ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= $_assetVersion('assets/lib/fontawesome/css/all.min.css') ?>">
    <?php foreach ($extraCss as $css):
        // Append ?v=<mtime> when the file resolves to a local asset
        $_cssHref = $css;
        $_cssRel = null;
        if (strpos($css, '://') === false) {
            $_cssRel = ltrim(preg_replace('#^(?:\.\./)+#', '', $css), '/');
            $_cssAbs = $_assetRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $_cssRel);
            $_cssMtime = @filemtime($_cssAbs);
            if ($_cssMtime) {
                $_cssHref .= (strpos($css, '?') === false ? '?v=' : '&v=') . $_cssMtime;
            }
        }
    ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($_cssHref) ?>">
    <?php endforeach; ?>
    <!-- Couche de cohérence du thème sombre : EN DERNIER pour primer sur le CSS des modules + les styles inline. -->
    <link rel="stylesheet" href="<?= $_assetVersion('assets/css/dark-overrides.css') ?>">
    <!-- Design System (refonte UX) : tokens + thèmes (clair/sombre/liquide) + composants .ds-* (additif). -->
    <link rel="stylesheet" href="<?= $_assetVersion('assets/css/design-system.css') ?>">
    <!-- Couche de modernisation (refonte 2026) : polish transverse façon Apple. -->
    <link rel="stylesheet" href="<?= $_assetVersion('assets/css/modernize.css') ?>">
    <!-- Couche RESPONSIVE centrale : adaptation ordinateur/tablette/téléphone — EN TOUT DERNIER. -->
    <link rel="stylesheet" href="<?= $_assetVersion('assets/css/responsive.css') ?>">
    <?= $extraHeadHtml ?>
    <!-- WebSocket global -->
    <script nonce="<?= $_hdr_nonce ?>">window.FRONOTE_WS = <?= $_hdr_ws_config ?>;</script>
    <!-- Pont d'actions CSP (délégué, sans eval) : permet de retirer 'unsafe-inline'. -->
    <script src="<?= $_assetVersion('assets/js/csp-actions.js') ?>" nonce="<?= $_hdr_nonce ?>"></script>
    <script src="<?= $_assetVersion('assets/lib/socketio/socket.io.min.js') ?>" nonce="<?= $_hdr_nonce ?>" defer></script>
    <script src="<?= $_assetVersion('assets/js/topbar.js') ?>" nonce="<?= $_hdr_nonce ?>" defer></script>
    <script src="<?= $_assetVersion('assets/js/components.js') ?>" nonce="<?= $_hdr_nonce ?>" defer></script>
    <script src="<?= $_assetVersion('assets/js/ui/interactions.js') ?>" nonce="<?= $_hdr_nonce ?>" defer></script>
    <script src="<?= $_assetVersion('assets/js/fronote-ajax.js') ?>" nonce="<?= $_hdr_nonce ?>" defer></script>
    <script src="<?= $_assetVersion('assets/js/ws-global.js') ?>" nonce="<?= $_hdr_nonce ?>" defer></script>
    <script src="<?= $_assetVersion('assets/js/push-manager.js') ?>" nonce="<?= $_hdr_nonce ?>" defer></script>
    <?php
    // ─── Pont i18n côté client ───────────────────────────────────────────────
    // Expose quelques clés d'UI communes traduites côté PHP à la couche JS via
    // window.I18N + le helper window.__(clé, fallback). Chaque clé fournit un
    // fallback FR : si la traduction est absente, le rendu reste identique.
    $_hdr_tr = static function (string $key, string $default): string {
        return function_exists('__') ? __($key, ['default' => $default]) : $default;
    };
    $_hdr_i18n = [
        'save'           => $_hdr_tr('btn.save', 'Enregistrer'),
        'cancel'         => $_hdr_tr('btn.cancel', 'Annuler'),
        'close'          => $_hdr_tr('common.close', 'Fermer'),
        'send'           => $_hdr_tr('btn.send', 'Envoyer'),
        'loading'        => $_hdr_tr('btn.loading', 'Chargement...'),
        'error'          => $_hdr_tr('common.error', 'Erreur'),
        'confirm_delete' => $_hdr_tr('common.confirm_delete', 'Êtes-vous sûr de vouloir supprimer cet élément ?'),
        'delete_success' => $_hdr_tr('common.delete_success', 'Supprimé avec succès.'),
    ];
    ?>
    <script nonce="<?= $_hdr_nonce ?>">
    window.I18N = <?= json_encode($_hdr_i18n, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    window.__ = function (k, def) { return (window.I18N && window.I18N[k]) || def; };
    </script>
    <script nonce="<?= $_hdr_nonce ?>">
    window.FRONOTE_BASE_URL = <?= json_encode(rtrim($rootPrefix, '/') . '/') ?>;
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register(<?= json_encode($rootPrefix . 'sw.js') ?>, { scope: <?= json_encode($rootPrefix) ?> })
            .catch(function(e) { console.warn('SW registration failed:', e); });
    }
    </script>
    <script nonce="<?= $_hdr_nonce ?>">
    // Application instantanée du thème + accessibilité (anti-flash) — gère clair/sombre/LIQUIDE/auto.
    (function() {
        var el = document.documentElement;
        var stored = null;
        try { stored = localStorage.getItem('fronote_dark_mode'); } catch(e) {}
        var pref = stored || el.getAttribute('data-theme-pref') || 'light';
        var theme = pref;
        if (pref === 'auto' || !pref) {
            theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        } else if (['light','dark','liquid'].indexOf(pref) < 0) {
            theme = 'light';
        }
        el.setAttribute('data-theme', theme);
        el.setAttribute('data-theme-pref', pref);
        try {
            el.setAttribute('data-reduce-motion', localStorage.getItem('fronote_reduce_motion') === 'true' ? 'true' : 'false');
            el.setAttribute('data-reduce-transparency', localStorage.getItem('fronote_reduce_transparency') === 'true' ? 'true' : 'false');
        } catch(e) {}
    })();
    </script>
</head>
<body>

<a href="#main-content" class="skip-link"><?php echo function_exists('__') ? __('nav.skip_to_content', ['default' => 'Aller au contenu principal']) : 'Aller au contenu principal'; ?></a>

<?php include __DIR__ . '/cookie_consent.php'; ?>

<div class="app-container">
