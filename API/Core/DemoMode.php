<?php
declare(strict_types=1);

namespace API\Core;

/**
 * Mode DÉMONSTRATION (instance publique à données fictives, réinitialisée périodiquement).
 *
 * Totalement inerte hors démo : tout passe par active(), qui exige DEMO_MODE=true dans le .env.
 * Une instance d'établissement normale n'a jamais cette variable → aucun changement de comportement.
 *
 * En démo :
 *  - les comptes « demo.* » se connectent sans enrôlement 2FA (sinon personne ne peut essayer) ;
 *  - entrée en un clic par rôle (/demo/?role=prof) ;
 *  - bandeau permanent « démo » + changement de rôle ;
 *  - actions sensibles bloquées : mots de passe, 2FA, configuration système, marketplace,
 *    imports, purge, usurpation d'identité, dépôt de fichiers.
 */
final class DemoMode
{
    /** rôle (slug public) => [identifiant, libellé, description courte] */
    public const ROLES = [
        'direction' => ['demo.direction', 'Direction',      'Claire Delmas, principale : pilotage, statistiques, administration.'],
        'prof'      => ['demo.prof',      'Professeur',     'Marc Lefort, mathématiques, professeur principal de 4A.'],
        'eleve'     => ['demo.eleve',     'Élève',          'Léa Martin, élève de 4A : notes, devoirs, emploi du temps.'],
        'parent'    => ['demo.parent',    'Parent',         'Nadia Martin, mère de Léa (4A) et Hugo (6B).'],
        'cpe'       => ['demo.cpe',       'Vie scolaire',   'Sophie Rivière, CPE : absences, retards, discipline.'],
    ];

    /** Chemins (relatifs à la racine app) dont toute écriture est refusée en démo. */
    private const BLOCKED_WRITE = [
        'login/change_password.php', 'login/setup_2fa.php', 'login/reset_password.php', 'login/reset_confirmation.php',
        'admin/systeme/', 'admin/modules/marketplace.php', 'admin/modules/configure.php',
        'admin/etablissement/purge.php', 'admin/etablissement/multi.php', 'admin/etablissement/switch.php',
        'admin/users/passwords.php', 'admin/users/import.php', 'admin/users/admins.php',
        'admin/themes/', 'rgpd/', 'parametres/',
    ];
    /** Chemins entièrement fermés en démo (même en lecture). */
    private const BLOCKED_ALL = ['impersonation/', 'install.php', 'admin/systeme/update.php', 'admin/systeme/technicien.php'];

    public static function active(): bool
    {
        $v = getenv('DEMO_MODE');
        return $v !== false && in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function isDemoAccount(?string $username): bool
    {
        return self::active() && is_string($username) && str_starts_with($username, 'demo.');
    }

    public static function password(): string
    {
        return (string) (getenv('DEMO_PASSWORD') ?: 'demo');
    }

    public static function resetMinutes(): int
    {
        return max(5, (int) (getenv('DEMO_RESET_MINUTES') ?: 60));
    }

    /** URL du site vitrine (lien « retour »). */
    public static function siteUrl(): string
    {
        return (string) (getenv('DEMO_SITE_URL') ?: 'https://dist.nicov1.fr/');
    }

    /** Chemin de la requête relatif à la racine de l'application. */
    private static function relPath(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $root = str_replace('\\', '/', \defined('BASE_PATH') ? (string) BASE_PATH : dirname(__DIR__, 2));
        $rel = str_starts_with($script, $root . '/') ? substr($script, strlen($root) + 1) : ltrim((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        return $rel;
    }

    /**
     * Garde appelée au démarrage de chaque requête web. Refuse (403) les actions sensibles.
     * No-op hors démo et en CLI.
     */
    public static function guard(): void
    {
        if (!self::active() || PHP_SAPI === 'cli') { return; }
        $rel = self::relPath();
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        foreach (self::BLOCKED_ALL as $p) {
            if (str_starts_with($rel, $p)) { self::deny('Cette fonction est fermée dans la démonstration publique.'); }
        }
        if ($method !== 'GET' && $method !== 'HEAD') {
            foreach (self::BLOCKED_WRITE as $p) {
                if (str_starts_with($rel, $p)) { self::deny('Modification désactivée dans la démonstration publique (mots de passe, sécurité et configuration restent en lecture seule).'); }
            }
            // Aucun dépôt de fichier : la démo est publique et partagée entre visiteurs.
            foreach ($_FILES as $f) {
                $names = is_array($f['name'] ?? null) ? $f['name'] : [$f['name'] ?? ''];
                foreach ($names as $n) {
                    if ((string) $n !== '') { self::deny('Le dépôt de fichiers est désactivé dans la démonstration publique.'); }
                }
            }
        }
    }

    private static function deny(string $message): never
    {
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        http_response_code(403);
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'demo' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        $m = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $back = htmlspecialchars((string) ($_SERVER['HTTP_REFERER'] ?? '/accueil/accueil.php'), ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Démonstration — action indisponible</title>'
            . '<body style="font-family:system-ui,sans-serif;background:#f6f2e9;color:#16243f;display:grid;place-items:center;min-height:100vh;margin:0;padding:24px">'
            . '<div style="max-width:520px;background:#fffdf8;border:1px solid #d5ccb6;border-radius:14px;padding:28px 30px">'
            . '<p style="font:500 12px ui-monospace,monospace;letter-spacing:.14em;text-transform:uppercase;color:#b9772a;margin:0 0 8px">Démonstration</p>'
            . '<h1 style="font-size:1.4rem;margin:0 0 10px">Action indisponible ici</h1><p style="margin:0 0 18px;color:#586173">' . $m . '</p>'
            . '<a href="' . $back . '" style="display:inline-block;background:#16243f;color:#f6f2e9;padding:10px 18px;border-radius:9px;text-decoration:none">Revenir</a></div></body></html>';
        exit;
    }

    /**
     * Barre « démonstration » (vide hors démo) : une seule ligne de hauteur FIXE en bas d'écran.
     * Sa hauteur est publiée dans --fdb-h ; tout ce qui est ancré en bas (barre de navigation
     * mobile, bandeau cookies, tiroir de menu, notifications) est décalé d'autant : la barre
     * déplace le contenu au lieu de le recouvrir. Sur mobile, les rôles sont dans un menu dépliant.
     */
    public static function banner(?string $currentType = null): string
    {
        if (!self::active()) { return ''; }
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $label = [
            'administrateur' => 'Direction', 'professeur' => 'Professeur', 'eleve' => 'Élève',
            'parent' => 'Parent', 'vie_scolaire' => 'Vie scolaire',
        ][$currentType ?? ''] ?? null;
        $chips = ''; $items = '';
        foreach (self::ROLES as $slug => [, $lib, $desc]) {
            $on = $lib === $label;
            $chips .= '<a class="fdb-chip' . ($on ? ' is-on' : '') . '" href="/demo/?role=' . $slug . '"' . ($on ? ' aria-current="true"' : '') . '>' . $h($lib) . '</a>';
            $items .= '<a class="fdb-item' . ($on ? ' is-on' : '') . '" href="/demo/?role=' . $slug . '"><b>' . $h($lib) . '</b><span>' . $h($desc) . '</span></a>';
        }
        $css = ':root{--fdb-h:46px}'
            . '.fronote-demo-banner{position:fixed;left:0;right:0;bottom:0;height:var(--fdb-h);z-index:9990;box-sizing:border-box;'
            . 'display:flex;align-items:center;gap:14px;padding:0 14px;white-space:nowrap;background:#16243f;color:#f6f2e9;'
            . 'font:13px/1.2 system-ui,-apple-system,sans-serif;box-shadow:0 -6px 24px rgba(22,36,63,.18)}'
            . '.fronote-demo-banner a{color:#fff;text-decoration:none}'
            . '.fdb-tag{font-weight:700;color:#d8a24b;letter-spacing:.02em}.fdb-txt{opacity:.85;overflow:hidden;text-overflow:ellipsis;min-width:0}'
            . '.fdb-roles{display:flex;gap:6px;align-items:center;margin-left:auto}'
            . '.fdb-chip{padding:4px 10px;border-radius:999px;border:1px solid rgba(255,255,255,.3)}.fdb-chip:hover{background:rgba(255,255,255,.1)}'
            . '.fdb-chip.is-on{background:#f6f2e9;color:#16243f!important;border-color:#f6f2e9;font-weight:600}'
            . '.fdb-site{color:#d8a24b!important}'
            . '.fdb-pick{display:none;position:relative;margin-left:auto}'
            . '.fdb-pick summary{list-style:none;cursor:pointer;padding:6px 12px;border-radius:999px;background:#f6f2e9;color:#16243f;font-weight:600}'
            . '.fdb-pick summary::-webkit-details-marker{display:none}'
            . '.fdb-menu{position:absolute;right:0;bottom:calc(100% + 10px);width:min(320px,calc(100vw - 24px));background:#fffdf8;color:#16243f;'
            . 'border-radius:14px;box-shadow:0 18px 50px rgba(22,36,63,.35);padding:6px;white-space:normal}'
            . '.fdb-item{display:block;padding:9px 12px;border-radius:10px;color:#16243f!important}.fdb-item:hover,.fdb-item.is-on{background:#f1ebdd}'
            . '.fdb-item b{display:block;font-size:14px}.fdb-item span{font-size:12px;color:#586173}'
            // Le contenu est DÉPLACÉ, jamais recouvert
            . 'body{padding-bottom:var(--fdb-h)!important}'
            . '.ds-bottom-bar{bottom:calc(var(--fdb-h) + 8px + env(safe-area-inset-bottom))!important}'
            . '.cc{bottom:calc(var(--fdb-h) + 10px)!important}.cc-card{max-height:calc(100dvh - var(--fdb-h) - 90px)!important}'
            . '.tb-drawer{bottom:var(--fdb-h)!important}'
            . '.nc-toasts{bottom:calc(var(--fdb-h) + 14px)!important}.msg-toast{bottom:calc(var(--fdb-h) + 20px)!important}'
            . '.login-cookies{bottom:calc(var(--fdb-h) + 8px)!important}'
            . '@media(max-width:760px){:root{--fdb-h:44px}.fdb-txt,.fdb-roles,.fdb-site-long{display:none}.fdb-pick{display:block}}'
            . '@media(min-width:761px){.fdb-site-short{display:none}}';
        return '<style>' . $css . '</style>'
            . '<div class="fronote-demo-banner" role="region" aria-label="Démonstration">'
            . '<span class="fdb-tag">Démo</span>'
            . '<span class="fdb-txt">Données 100 % fictives, réinitialisées toutes les ' . self::resetMinutes() . ' min</span>'
            . ($label ? '<span class="fdb-who" style="opacity:.85">· ' . $h($label) . '</span>' : '')
            . '<span class="fdb-roles"><span style="opacity:.6">Changer de rôle :</span>' . $chips . '</span>'
            . '<details class="fdb-pick"><summary>Changer de rôle</summary><div class="fdb-menu">' . $items . '</div></details>'
            . '<a class="fdb-site" href="' . $h(self::siteUrl()) . '"><span class="fdb-site-long">← Site Fronote</span><span class="fdb-site-short">Site</span></a>'
            . '</div>';
    }
}
