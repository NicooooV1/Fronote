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

    private static function deny(string $message): void
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

    /** Bandeau HTML affiché en haut de chaque page (vide hors démo). */
    public static function banner(?string $currentType = null): string
    {
        if (!self::active()) { return ''; }
        $label = [
            'administrateur' => 'Direction', 'professeur' => 'Professeur', 'eleve' => 'Élève',
            'parent' => 'Parent', 'vie_scolaire' => 'Vie scolaire',
        ][$currentType ?? ''] ?? null;
        $links = '';
        foreach (self::ROLES as $slug => [, $lib]) {
            $links .= '<a href="/demo/?role=' . $slug . '" style="color:#fff;text-decoration:none;padding:3px 9px;border-radius:999px;border:1px solid rgba(255,255,255,.35);white-space:nowrap">' . htmlspecialchars($lib, ENT_QUOTES, 'UTF-8') . '</a>';
        }
        $who = $label ? ' · vous êtes connecté en tant que <strong>' . $label . '</strong>' : '';
        return '<div class="fronote-demo-banner" role="note" style="position:relative;z-index:9999;background:#16243f;color:#f6f2e9;'
            . 'font:13px/1.4 system-ui,sans-serif;padding:7px 14px;display:flex;flex-wrap:wrap;gap:8px 14px;align-items:center;justify-content:center">'
            . '<span><strong style="color:#d8a24b">Démonstration</strong> · données 100 % fictives, réinitialisées toutes les ' . self::resetMinutes() . ' min' . $who . '</span>'
            . '<span style="display:flex;flex-wrap:wrap;gap:6px;align-items:center"><span style="opacity:.7">Changer de rôle :</span>' . $links . '</span>'
            . '<a href="' . htmlspecialchars(self::siteUrl(), ENT_QUOTES, 'UTF-8') . '" style="color:#d8a24b">← Retour au site Fronote</a></div>';
    }
}
