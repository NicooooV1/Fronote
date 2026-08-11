<?php
declare(strict_types=1);
/**
 * platform/includes/layout.php — LAYOUT PARTAGÉ de la console opérateur PLATEFORME.
 *
 * Fournit une chrome UNIQUE et uniforme (« le centre de la toile ») à toutes les
 * pages platform/*.php : sidebar sombre groupée + top bar + conteneur de contenu.
 * Remplace les <style> inline page-par-page et le $menu inline de dashboard.php.
 *
 * API (appelée par chaque page) :
 *   pf_layout_header(string $active, string $title, string $eyebrow = '')
 *       — ouvre <html>/<head>/<body>, charge assets/css/platform.css via asset_bust(),
 *         rend la sidebar (nav GROUPÉE gatée par platformCan), la top bar
 *         (titre/eyebrow, recherche globale, pill env+version, identité + logout),
 *         puis OUVRE <main class="pf-content"> pour le contenu de la page.
 *   pf_layout_footer()
 *       — ferme <main>, la chrome et </html>.
 *
 * Le paramètre $active est le SLUG de la page courante (voir pf_layout_nav()).
 * Le gating réutilise \API\Security\WorldContext::platformAuth()->can() — la MÊME
 * autorisation que les pages (platformAuthorize / platformCan).
 */

if (!function_exists('pf_layout_nav')) {

    /** Échappement local (indépendant de e()/$h de la page). */
    function pf_e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

    /**
     * STRUCTURE DE NAVIGATION — groupes ordonnés → items.
     * Chaque item : [permission, slug, label, icône FA, href (relatif racine)].
     * Les autres agents ajoutent un item en éditant CE tableau uniquement.
     * @return array<int,array{label:string,items:array<int,array{0:string,1:string,2:string,3:string,4:string}>}>
     */
    function pf_layout_nav(): array
    {
        return [
            ['label' => 'Parc', 'items' => [
                ['platform.establishments.view',     'establishments',       'Établissements',        'fa-school',            '/platform/establishments.php'],
                ['platform.director_invites.create', 'director-invitations', 'Invitations Directeur', 'fa-envelope-open-text','/platform/director-invitations.php'],
            ]],
            ['label' => 'Observabilité', 'items' => [
                ['platform.dashboard.view', 'dashboard',     'Tableau de bord', 'fa-gauge-high',       '/platform/dashboard.php'],
                ['platform.system.view',    'observability', 'Observabilité',   'fa-wave-square',      '/platform/observability.php'],
                ['platform.audit.view',     'audit',         'Audit global',    'fa-list-check',       '/platform/audit.php'],
            ]],
            ['label' => 'Accès', 'items' => [
                ['platform.rbac.manage',    'roles',    'Rôles & permissions', 'fa-user-lock',     '/platform/roles.php'],
                ['platform.security.view',  'security', 'Sécurité & comptes',  'fa-shield-halved', '/platform/security.php'],
            ]],
            ['label' => 'Opérations', 'items' => [
                ['platform.backups.view',       'backups',     'Sauvegardes',  'fa-database',           '/platform/backups.php'],
                ['platform.updates.manage',     'updates',     'Mises à jour', 'fa-cloud-arrow-down',   '/platform/updates.php'],
                ['platform.maintenance.manage', 'maintenance', 'Maintenance',  'fa-screwdriver-wrench', '/platform/maintenance.php'],
                ['platform.system.view',        'system',      'Système',      'fa-server',             '/platform/system.php'],
            ]],
            ['label' => 'Support', 'items' => [
                ['platform.support.ticket.view', 'support', 'Support', 'fa-life-ring', '/platform/support/tickets.php'],
            ]],
        ];
    }

    /** Métadonnées env+version depuis version.json (repli gracieux). */
    function pf_layout_version(): array
    {
        $root = dirname(__DIR__, 2);
        $out  = ['version' => '?', 'channel' => 'stable', 'codename' => ''];
        $f = $root . '/version.json';
        if (is_file($f)) {
            $j = json_decode((string) @file_get_contents($f), true);
            if (is_array($j)) {
                $out['version']  = (string) ($j['version']  ?? $out['version']);
                $out['channel']  = (string) ($j['channel']  ?? $out['channel']);
                $out['codename'] = (string) ($j['codename'] ?? '');
            }
        }
        return $out;
    }

    /**
     * Ouvre la page et rend la chrome. À appeler AVANT tout HTML de la page.
     */
    function pf_layout_header(string $active, string $title, string $eyebrow = ''): void
    {
        $base = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : '';

        // Même autorisation que les pages ; une seule instanciation réutilisée.
        try { $auth = \API\Security\WorldContext::platformAuth(); }
        catch (\Throwable $e) { $auth = null; }
        $can = static function (string $perm) use ($auth): bool {
            if ($auth === null) { return false; }
            try { return $auth->can($perm); } catch (\Throwable $e) { return false; }
        };

        $username = (string) ($_SESSION['platform']['username'] ?? 'opérateur');
        $initial  = strtoupper(mb_substr(trim($username) !== '' ? $username : 'O', 0, 1, 'UTF-8'));
        $ver      = pf_layout_version();
        $envKey   = $ver['channel'] === 'stable' ? 'prod' : $ver['channel'];

        $cssHref = asset_bust($base . '/assets/css/platform.css');
        $faHref  = asset_bust($base . '/assets/lib/fontawesome/css/all.min.css');

        echo '<!doctype html>' . "\n";
        echo '<html lang="fr">' . "\n<head>\n";
        echo '<meta charset="utf-8">' . "\n";
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
        echo '<title>' . pf_e($title) . ' — Plateforme Fronote</title>' . "\n";
        // Bootstrap thème : reprend la préférence localStorage de l'app, sinon prefers-color-scheme.
        echo '<script>(function(){var e=document.documentElement,s=null;try{s=localStorage.getItem("fronote_dark_mode");}catch(x){}'
           . 'var p=s||"auto",t=p;if(p==="auto"){t=matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light";}'
           . 'else if(["light","dark","liquid"].indexOf(p)<0){t="light";}e.setAttribute("data-theme",t);'
           . 'try{e.setAttribute("data-reduce-motion",localStorage.getItem("fronote_reduce_motion")==="true"?"true":"false");}catch(x){}})();</script>' . "\n";
        echo '<link rel="stylesheet" href="' . pf_e($faHref) . '">' . "\n";
        echo '<link rel="stylesheet" href="' . pf_e($cssHref) . '">' . "\n";
        echo "</head>\n";
        echo '<body class="pf-body">' . "\n";

        // Toggle sidebar mobile (pur CSS) + scrim.
        echo '<div class="pf-shell">' . "\n";
        echo '<input type="checkbox" id="pf-nav-toggle" class="pf-nav-toggle" hidden>' . "\n";

        // ─── Sidebar ───
        echo '<aside class="pf-sidebar" aria-label="Navigation plateforme">' . "\n";
        echo '  <div class="pf-sidebar__brand"><span class="pf-sidebar__glyph"><i class="fas fa-network-wired"></i></span>'
           . '<span>Plateforme<small>Console opérateur</small></span></div>' . "\n";
        echo '  <nav class="pf-sidebar__nav">' . "\n";
        foreach (pf_layout_nav() as $group) {
            $visible = array_filter($group['items'], static fn($it) => $can($it[0]));
            if (!$visible) { continue; }
            echo '    <div class="pf-nav-group">' . "\n";
            echo '      <div class="pf-nav-group__label">' . pf_e($group['label']) . '</div>' . "\n";
            foreach ($visible as $it) {
                [$perm, $slug, $label, $icon, $href] = $it;
                $isActive = ($slug === $active);
                echo '      <a class="pf-nav-item' . ($isActive ? ' is-active' : '') . '" href="' . pf_e($base . $href) . '"'
                   . ($isActive ? ' aria-current="page"' : '') . '>'
                   . '<i class="fas ' . pf_e($icon) . '" aria-hidden="true"></i><span>' . pf_e($label) . '</span></a>' . "\n";
            }
            echo '    </div>' . "\n";
        }
        echo '  </nav>' . "\n";
        echo '  <div class="pf-sidebar__foot">Fronote v' . pf_e($ver['version'])
           . ($ver['codename'] !== '' ? ' · ' . pf_e($ver['codename']) : '') . '</div>' . "\n";
        echo '</aside>' . "\n";
        echo '<label for="pf-nav-toggle" class="pf-scrim" aria-hidden="true"></label>' . "\n";

        // ─── Zone principale ───
        echo '<div class="pf-main">' . "\n";

        // Top bar
        echo '  <header class="pf-topbar">' . "\n";
        echo '    <label for="pf-nav-toggle" class="pf-hamburger" aria-label="Menu"><i class="fas fa-bars"></i></label>' . "\n";
        echo '    <div class="pf-topbar__titles">' . "\n";
        if ($eyebrow !== '') { echo '      <div class="pf-eyebrow">' . pf_e($eyebrow) . '</div>' . "\n"; }
        echo '      <h1 class="pf-topbar__title">' . pf_e($title) . '</h1>' . "\n";
        echo '    </div>' . "\n";
        echo '    <div class="pf-topbar__spacer"></div>' . "\n";
        // Recherche globale (jump tenant/utilisateur → establishments.php?q=)
        echo '    <form class="pf-search" role="search" method="get" action="' . pf_e($base . '/platform/establishments.php') . '">' . "\n";
        echo '      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>' . "\n";
        echo '      <input type="search" name="q" placeholder="Rechercher un établissement, un compte…" aria-label="Recherche globale" autocomplete="off">' . "\n";
        echo '    </form>' . "\n";
        // Pill env + version
        echo '    <span class="pf-envpill" data-env="' . pf_e($envKey) . '" title="Canal ' . pf_e($ver['channel']) . '">'
           . '<span class="pf-dot"></span><span>' . pf_e(strtoupper($ver['channel'])) . '</span>'
           . '<b>v' . pf_e($ver['version']) . '</b></span>' . "\n";
        // Identité + logout
        echo '    <div class="pf-identity">' . "\n";
        echo '      <span class="pf-identity__avatar" aria-hidden="true">' . pf_e($initial) . '</span>' . "\n";
        echo '      <span class="pf-identity__who"><span class="pf-identity__name">' . pf_e($username) . '</span>'
           . '<span class="pf-identity__role">opérateur</span></span>' . "\n";
        echo '      <a class="pf-btn pf-btn--ghost pf-btn--sm" href="' . pf_e($base . '/platform/logout.php') . '" title="Déconnexion">'
           . '<i class="fas fa-arrow-right-from-bracket"></i></a>' . "\n";
        echo '    </div>' . "\n";
        echo '  </header>' . "\n";

        // Conteneur de contenu (laissé OUVERT — la page écrit dedans, pf_layout_footer ferme)
        echo '  <main class="pf-content">' . "\n";
    }

    /** Ferme le contenu + la chrome. À appeler APRÈS le HTML de la page. */
    function pf_layout_footer(): void
    {
        echo "\n  </main>\n";   // .pf-content
        echo "</div>\n";        // .pf-main
        echo "</div>\n";        // .pf-shell
        echo "</body>\n</html>\n";
    }
}
