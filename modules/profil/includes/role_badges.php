<?php
declare(strict_types=1);
/**
 * Composant partagé : badges de rôles « façon Discord » + dérivation du poste.
 *
 * Réutilisable sur le profil et les futures vues admin des utilisateurs.
 * S'appuie sur \API\Security\RoleCatalog (couleur par tier + description par
 * rôle). Toutes les valeurs sont échappées via e()/htmlspecialchars.
 *
 * API exposée :
 *   rc_role_badges_css(): string        — CSS du composant, émis une seule fois
 *   rc_role_view(string): ?array        — méta d'affichage d'un rôle
 *   rc_derive_poste(array): ?array       — rôle de plus haut tier (le « poste »)
 *   rc_render_badges(array, ?string): string — liste de badges pour des rôles
 */

use API\Security\RoleCatalog;

if (!function_exists('rc_role_view')) {
    /** Méta d'affichage {key,label,tier,color,description} d'un rôle, ou null. */
    function rc_role_view(string $roleKey): ?array
    {
        return RoleCatalog::roleView($roleKey);
    }
}

if (!function_exists('rc_tier_rank')) {
    /** Rang de priorité d'un tier (plus petit = plus important) pour le poste. */
    function rc_tier_rank(string $tier): int
    {
        static $order = [
            'plateforme'    => 0,
            'direction'     => 1,
            'administratif' => 2,
            'organisation'  => 3,
            'pedagogique'   => 4,
            'enseignant'    => 4,
            'vie_scolaire'  => 5,
            'sante_social'  => 6,
            'communication' => 7,
            'documents'     => 8,
            'services'      => 9,
            'stages'        => 10,
            'controle'      => 11,
            'eleve_famille' => 12,
            'famille'       => 12,
            'eleve'         => 13,
            'systeme'       => 14,
        ];
        return $order[$tier] ?? 50;
    }
}

if (!function_exists('rc_derive_poste')) {
    /**
     * Dérive le « poste » : le rôle de plus haut tier parmi $roleKeys.
     * @param string[] $roleKeys
     * @return array|null méta rc_role_view() du rôle retenu, ou null.
     */
    function rc_derive_poste(array $roleKeys): ?array
    {
        $best     = null;
        $bestRank = PHP_INT_MAX;
        foreach ($roleKeys as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $view = rc_role_view($key);
            if ($view === null) {
                continue;
            }
            $rank = rc_tier_rank($view['tier']);
            if ($rank < $bestRank) {
                $bestRank = $rank;
                $best     = $view;
            }
        }
        return $best;
    }
}

if (!function_exists('rc_role_badges_css')) {
    /** CSS auto-suffisant du composant. Émis une seule fois par requête. */
    function rc_role_badges_css(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done  = true;
        $nonce = function_exists('csp_nonce') ? csp_nonce() : '';
        $attr  = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '"' : '';
        return '<style' . $attr . '>'
            . '.rc-badges{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0;padding:0;list-style:none}'
            . '.rc-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:9999px;'
            . 'font-size:.82rem;font-weight:600;line-height:1.45;color:#fff;white-space:nowrap;cursor:default;'
            . 'box-shadow:0 1px 2px rgba(0,0,0,.14)}'
            . '.rc-badge__dot{width:8px;height:8px;border-radius:50%;background:rgba(255,255,255,.9);flex:0 0 auto}'
            . '.rc-badge--principal{box-shadow:0 0 0 2px rgba(255,255,255,.65),0 1px 3px rgba(0,0,0,.28)}'
            . '.rc-poste{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px}'
            . '.rc-poste__chip{display:inline-flex;align-items:center;gap:9px;padding:9px 20px;border-radius:9999px;'
            . 'color:#fff;font-weight:700;font-size:1.08rem;box-shadow:0 2px 6px rgba(0,0,0,.2)}'
            . '.rc-poste__chip i{opacity:.85}'
            . '.rc-poste__meta{display:flex;flex-direction:column;gap:2px}'
            . '.rc-poste__kicker{font-size:.72rem;text-transform:uppercase;letter-spacing:.07em;font-weight:600;'
            . 'color:var(--text-muted,#64748b)}'
            . '.rc-poste__desc{font-size:.85rem;color:var(--text-muted,#64748b);max-width:52ch}'
            . '</style>';
    }
}

if (!function_exists('rc_render_badges')) {
    /**
     * Rend une liste <ul> de badges pour un ensemble de rôles.
     * @param string[] $roleKeys       clés de rôles à afficher
     * @param string|null $principalKey clé du rôle à marquer « principal » (contour)
     */
    function rc_render_badges(array $roleKeys, ?string $principalKey = null): string
    {
        $seen = [];
        $html = rc_role_badges_css() . '<ul class="rc-badges">';
        $rendered = 0;
        foreach ($roleKeys as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $view = rc_role_view($key);
            $canonical = $view['key'] ?? $key;
            if (isset($seen[$canonical])) {
                continue;
            }
            $seen[$canonical] = true;

            $label = $view['label'] ?? $key;
            $color = $view['color'] ?? RoleCatalog::TIER_COLOR_DEFAULT;
            $desc  = $view['description'] ?? $label;
            $isPrincipal = $principalKey !== null && $canonical === $principalKey;
            $cls = 'rc-badge' . ($isPrincipal ? ' rc-badge--principal' : '');

            $html .= '<li class="' . $cls . '"'
                . ' style="background:' . htmlspecialchars($color, ENT_QUOTES) . '"'
                . ' title="' . e($desc) . '">'
                . '<span class="rc-badge__dot"></span>'
                . e($label)
                . '</li>';
            $rendered++;
        }
        if ($rendered === 0) {
            $html .= '<li class="rc-badge" style="background:' . RoleCatalog::TIER_COLOR_DEFAULT . '">'
                . '<span class="rc-badge__dot"></span>' . e('Aucun rôle') . '</li>';
        }
        $html .= '</ul>';
        return $html;
    }
}
