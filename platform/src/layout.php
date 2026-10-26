<?php
declare(strict_types=1);
/** Mise en page de la console (design « pf-* » repris de l'ancienne console plateforme). */

function pf_nav(): array
{
    return [
        ['Pilotage', [
            ['dashboard', 'Tableau de bord', 'fa-gauge-high', '/'],
        ]],
        ['Parc', [
            ['etablissements', 'Établissements', 'fa-school', '/etablissements'],
            ['distribution', 'Distribution', 'fa-box-open', '/distribution'],
            ['demo', 'Démo publique', 'fa-flask', '/demo'],
        ]],
        ['Assistance', [
            ['support', 'Demandes escaladées', 'fa-life-ring', '/support'],
        ]],
        ['Contrôle', [
            ['journal', 'Journal', 'fa-list-check', '/journal'],
        ]],
    ];
}

function pf_header(string $active, string $title, string $eyebrow = '', array $badges = []): void
{
    $op = pf_operator() ?? ['name' => '?', 'user' => '?'];
    $initial = strtoupper(mb_substr($op['name'], 0, 1));
    $v = @filemtime(PF_ROOT . '/public/assets/platform.css') ?: 1;
    $f = pf_flash();
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex, nofollow">';
    echo '<title>' . e($title) . ' — Plateforme Fronote</title>';
    echo '<link rel="stylesheet" href="/assets/fontawesome/css/all.min.css?v=' . $v . '">';
    echo '<link rel="stylesheet" href="/assets/platform.css?v=' . $v . '">';
    echo '<link rel="stylesheet" href="/assets/app.css?v=' . (@filemtime(PF_ROOT . '/public/assets/app.css') ?: 1) . '">';
    echo '</head><body class="pf-body"><div class="pf-shell">';
    echo '<input type="checkbox" id="pf-nav-toggle" class="pf-nav-toggle" hidden>';
    echo '<aside class="pf-sidebar" aria-label="Navigation plateforme">';
    echo '<div class="pf-sidebar__brand"><span class="pf-sidebar__glyph"><i class="fas fa-network-wired"></i></span><span>Plateforme<small>Fronote — console globale</small></span></div>';
    echo '<nav class="pf-sidebar__nav">';
    foreach (pf_nav() as [$label, $items]) {
        echo '<div class="pf-nav-group"><div class="pf-nav-group__label">' . e($label) . '</div>';
        foreach ($items as [$slug, $text, $icon, $href]) {
            $on = $slug === $active;
            $badge = isset($badges[$slug]) && $badges[$slug] > 0 ? '<span class="pf-nav-badge">' . (int) $badges[$slug] . '</span>' : '';
            echo '<a class="pf-nav-item' . ($on ? ' is-active' : '') . '" href="' . e($href) . '"' . ($on ? ' aria-current="page"' : '') . '>'
               . '<i class="fas ' . e($icon) . '" aria-hidden="true"></i><span>' . e($text) . '</span>' . $badge . '</a>';
        }
        echo '</div>';
    }
    echo '</nav><div class="pf-sidebar__foot">Accès : SSO + réseau local / VPN</div></aside>';
    echo '<div class="pf-main"><header class="pf-topbar">';
    echo '<label for="pf-nav-toggle" class="pf-hamburger" aria-label="Menu"><i class="fas fa-bars"></i></label>';
    echo '<div class="pf-topbar__titles">' . ($eyebrow !== '' ? '<div class="pf-eyebrow">' . e($eyebrow) . '</div>' : '') . '<h1 class="pf-topbar__title">' . e($title) . '</h1></div>';
    echo '<div class="pf-topbar__spacer"></div>';
    echo '<div class="pf-identity"><span class="pf-identity__avatar">' . e($initial) . '</span><span class="pf-identity__who"><span class="pf-identity__name">' . e($op['name']) . '</span><span class="pf-identity__role">Opérateur</span></span></div>';
    echo '</header><main class="pf-content">';
    if ($f) {
        [$kind, $msg] = $f;
        echo '<div class="pf-notice pf-notice--' . ($kind === 'ok' ? 'ok' : ($kind === 'warn' ? 'warn' : 'crit')) . '" role="status">' . $msg . '</div>';
    }
}

function pf_footer(): void
{
    echo '</main></div></div><script src="/assets/app.js?v=' . (@filemtime(PF_ROOT . '/public/assets/app.js') ?: 1) . '"></script></body></html>';
}

/** Pastille de statut. */
function pf_pill(string $text, string $tone = 'muted'): string
{
    return '<span class="pf-pill pf-pill--' . e($tone) . '">' . e($text) . '</span>';
}

function pf_ago(?string $dt): string
{
    if (!$dt) { return '—'; }
    $t = strtotime($dt . (str_contains($dt, 'T') || str_ends_with($dt, 'Z') ? '' : '')) ?: 0;
    $d = time() - $t;
    return match (true) {
        $d < 60 => "à l'instant",
        $d < 3600 => 'il y a ' . intdiv($d, 60) . ' min',
        $d < 86400 => 'il y a ' . intdiv($d, 3600) . ' h',
        $d < 172800 => 'hier',
        default => date('d/m/Y', $t),
    };
}
