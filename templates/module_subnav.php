<?php
declare(strict_types=1);
/**
 * Helper : rendu centralisé de la navigation secondaire de module.
 *
 * Chaque header de module déclare ses entrées en données et affecte le résultat
 * à $sidebarExtraContent (rendu en bandeau .module-subnav par shared_topbar.php) :
 *
 *   require_once __DIR__ . '/../../../templates/module_subnav.php';
 *   $sidebarExtraContent = renderModuleSubnav([
 *       ['href' => 'page.php', 'icon' => 'fas fa-list', 'label' => 'Libellé'],
 *       ['href' => 'gerer.php', 'icon' => 'fas fa-cog', 'label' => 'Gestion', 'visible' => $estGestionnaire],
 *   ]);
 *
 * Clés d'une entrée :
 *   href    (string, requis)  URL du lien (relative à la page, ou préfixée $rootPrefix).
 *   icon    (string)          Classes Font Awesome complètes (ex. 'fas fa-utensils').
 *   label   (string)          Libellé en texte brut (échappé ici).
 *   visible (bool, déf. true) Gate d'affichage (rôle / feature flag) — utiliser la même
 *                             condition que le garde de la page cible.
 *   active  (bool, optionnel) Force l'état actif (ex. liens ?folder=x d'une même page) ;
 *                             par défaut : basename du href === script courant.
 *   class   (string)          Classes CSS supplémentaires sur le lien.
 *   attrs   (array)           Attributs HTML additionnels (name => value), échappés.
 */

function renderModuleSubnav(array $items): string
{
    // SCRIPT_NAME et non PHP_SELF : PHP_SELF embarque le PATH_INFO contrôlable par
    // l'utilisateur (/page.php/extra), ce qui fausserait l'état actif.
    $current = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $links = '';
    foreach ($items as $item) {
        if (!($item['visible'] ?? true)) {
            continue;
        }
        $href = (string) ($item['href'] ?? '');
        $hrefFile = basename((string) (parse_url($href, PHP_URL_PATH) ?: ''));
        $active = $item['active'] ?? ($hrefFile !== '' && $hrefFile === $current);
        $class = 'sidebar-nav-item' . ($active ? ' active' : '');
        if (!empty($item['class'])) {
            $class .= ' ' . (string) $item['class'];
        }
        $extra = '';
        foreach (($item['attrs'] ?? []) as $name => $value) {
            $extra .= ' ' . htmlspecialchars((string) $name, ENT_QUOTES) . '="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"';
        }
        $links .= '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" class="' . htmlspecialchars($class, ENT_QUOTES) . '"' . $extra . '>'
                . '<span class="sidebar-nav-icon"><i class="' . htmlspecialchars((string) ($item['icon'] ?? ''), ENT_QUOTES) . '"></i></span>'
                . '<span>' . htmlspecialchars((string) ($item['label'] ?? '')) . '</span></a>';
    }
    return $links === '' ? '' : '<div class="sidebar-nav">' . $links . '</div>';
}
