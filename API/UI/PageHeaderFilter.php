<?php
declare(strict_types=1);

namespace API\UI;

/**
 * Un seul en-tête par page.
 *
 * L'en-tête de module (titre, sous-titre, actions, onglets) est rendu par
 * templates/shared_topbar.php. Beaucoup de pages répètent ensuite leur propre titre
 * (`.content-header`, `.page-header`, un `<h1>` en tête de contenu, un bandeau `.welcome-banner`).
 * Ce filtre de sortie retire ce doublon et REMONTE ses éléments utiles dans l'en-tête de module :
 *   - les boutons/liens d'action  → zone d'actions de l'en-tête ;
 *   - la phrase descriptive       → sous-titre (si la page n'en a pas déjà un).
 *
 * Il ne regarde que le DÉBUT du contenu (le premier bloc d'en-tête, avant tout tableau ou
 * formulaire) et ne réécrit jamais le reste du document : aucun risque pour les scripts,
 * formulaires ou contenus de la page. Une page peut s'en exclure avec $keepPageHeading = true.
 */
final class PageHeaderFilter
{
    public const ACTIONS_MARK = '<!--fronote:header-actions-->';
    public const SUBTITLE_MARK = '<!--fronote:header-subtitle-->';
    public const CONTENT_MARK = '<!--fronote:content-start-->';

    /** Fenêtre (octets) après le début du contenu où l'on cherche l'en-tête doublon. */
    private const WINDOW = 4000;

    public static function apply(string $html): string
    {
        $start = strpos($html, self::CONTENT_MARK);
        if ($start === false) { return $html; }
        $from = $start + strlen(self::CONTENT_MARK);
        $region = substr($html, $from, self::WINDOW);

        // Titre de l'en-tête de module (pour reconnaître un <h2>/<h3> qui le répète)
        $title = '';
        if (preg_match_all('#<h1\b[^>]*>(.*?)(?:<button|</h1>)#si', substr($html, 0, $start), $tm) && $tm[1]) {
            $title = self::norm(end($tm[1]));
        }
        $block = self::findHeaderBlock($region, $title);
        if ($block === null) { return $html; }
        [$bStart, $bLen, $inner, $kind] = $block;

        // Sous-titre éventuel (welcome-banner : première phrase descriptive)
        $subtitle = '';
        if (preg_match('#<p\b[^>]*>(.*?)</p>#si', $inner, $m)) {
            $txt = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($txt !== '' && mb_strlen($txt) <= 160) { $subtitle = $txt; }
        }
        // Actions : liens/boutons/formulaires courts restants, une fois titres et paragraphes retirés
        $rest = preg_replace('#<h[1-3]\b[^>]*>.*?</h[1-3]>#si', '', $inner) ?? '';
        $rest = preg_replace('#<p\b[^>]*>.*?</p>#si', '', $rest) ?? '';
        $actions = '';
        if (preg_match_all('#<(a|button)\b[^>]*>.*?</\1>|<form\b[^>]*>.*?</form>#si', $rest, $mm)) {
            $actions = implode('', $mm[0]);
        }
        // Le bandeau ne contenait que du texte décoratif (icône…) : rien d'autre à garder.
        if ($kind === 'banner' && $actions === '' && $subtitle === '') { $subtitle = ''; }

        $html = substr_replace($html, '', $from + $bStart, $bLen);
        if ($actions !== '') {
            $html = str_replace(self::ACTIONS_MARK, $actions . self::ACTIONS_MARK, $html);
        }
        if ($subtitle !== '' && str_contains($html, self::SUBTITLE_MARK)) {
            $html = str_replace(self::SUBTITLE_MARK, '<p class="subtitle mh-sub">' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</p>', $html);
        }
        return $html;
    }

    /**
     * Premier bloc d'en-tête du contenu : [début, longueur, html interne, type] ou null.
     * On s'arrête dès qu'on rencontre du « vrai » contenu (tableau, formulaire, liste, carte…).
     */
    private static function norm(string $s): string
    {
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($s)) ?? '');
    }

    private static function findHeaderBlock(string $region, string $title = ''): ?array
    {
        $stop = PHP_INT_MAX;
        if (preg_match('#<(table|form|ul|ol|section|article)\b|class="[^"]*\b(card|stat-card|stats-row|stats-grid|filter-bar|ds-card|empty-state|alert)\b#i', $region, $m, PREG_OFFSET_CAPTURE)) {
            $stop = $m[0][1];
        }
        // 1) Bloc d'en-tête de page explicite
        if (preg_match('#<(div|header)\b[^>]*class="([^"]*\b(content-header|page-header|module-header|welcome-banner)\b[^"]*)"[^>]*>#i', $region, $m, PREG_OFFSET_CAPTURE)
            && $m[0][1] < $stop) {
            $open = $m[0][1];
            $tag = strtolower($m[1][0]);
            $close = self::matchClose($region, $open, $tag);
            if ($close === null) { return null; }
            $innerStart = $open + strlen($m[0][0]);
            $inner = substr($region, $innerStart, $close - $innerStart);
            // Un vrai en-tête contient un titre ; un welcome-banner est toujours décoratif
            $kind = stripos($m[2][0], 'welcome-banner') !== false ? 'banner' : 'header';
            if ($kind === 'header' && !preg_match('#<h[12]\b#i', $inner)) { return null; }
            $end = $close + strlen("</$tag>");
            return [$open, $end - $open, $inner, $kind];
        }
        // 2) Un titre isolé en tête de contenu : tout <h1>, ou un <h2>/<h3> qui répète le titre de la page
        if (preg_match('#<h([1-3])\b[^>]*>(.*?)</h\1>#si', $region, $m, PREG_OFFSET_CAPTURE) && $m[0][1] < $stop) {
            if ($m[1][0] === '1' || ($title !== '' && self::norm($m[2][0]) === $title)) {
                return [$m[0][1], strlen($m[0][0]), $m[0][0], 'h1'];
            }
        }
        return null;
    }

    /** Position de la balise fermante correspondant à l'ouvrante en $open (comptage de profondeur). */
    private static function matchClose(string $s, int $open, string $tag): ?int
    {
        $depth = 0;
        if (!preg_match_all('#<(/?)' . $tag . '\b[^>]*>#i', $s, $m, PREG_OFFSET_CAPTURE, $open)) { return null; }
        foreach ($m[0] as $i => [$t, $pos]) {
            $depth += $m[1][$i][0] === '/' ? -1 : 1;
            if ($depth === 0) { return $pos; }
        }
        return null;
    }
}
