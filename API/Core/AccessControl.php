<?php
declare(strict_types=1);

namespace API\Core;

/**
 * AccessControl — couche d'autorisation centralisée ("front controller" de sécurité).
 *
 * L'application n'a pas de routeur unique : chaque .php est un point d'entrée direct.
 * Plutôt qu'une réécriture risquée vers un docroot public/, cette classe est invoquée
 * UNE fois depuis API/bootstrap.php (que TOUS les points d'entrée chargent) et impose,
 * de façon FAIL-CLOSED, l'authentification et le rôle minimal requis selon le chemin
 * du fichier appelé. Elle complète (ne remplace pas) les gardes par page existantes :
 * une page qui oublierait requireAuth() est désormais quand même protégée.
 *
 * Hiérarchie effective (du + puissant au - puissant) :
 *   super_admin > administrateur ≈ technicien > cpe > vie_scolaire > professeur > {parent, eleve}
 *
 * - super_admin : accès total, multi-établissement (god mode).
 * - administrateur / technicien : back-office d'un (ou plusieurs) établissement(s).
 * - les zones admin/etablissement/{switch,multi,purge}.php sont réservées au super_admin.
 */
final class AccessControl
{
    /** Points d'entrée PHP publics (aucune authentification requise). */
    private const PUBLIC_EXACT = [
        'install.php',
        'install_guard.php',
        'API/endpoints/health.php',
        'API/endpoints/cookie_consent.php',
        // Rapports CSP : POST navigateur (report-uri), sans session, autonome.
        'API/endpoints/csp_report.php',
        // Autorisation de rooms WebSocket : appel SERVEUR→SERVEUR (Node → PHP), authentifié
        // par le secret partagé X-WS-Secret (temps constant) + JWT WS dans le corps. Sans
        // session utilisateur → doit être exempté du garde de session (il porte sa propre auth).
        'API/endpoints/ws_authorize.php',
        'rgpd/mentions_legales.php',
        'rgpd/confidentialite.php',
    ];

    /** Préfixes publics (le dossier login complet, les pages d'erreur, le service worker). */
    private const PUBLIC_PREFIX = [
        'login/',
        'templates/errors/',
        'templates/maintenance.php',
    ];

    /**
     * Répertoires dont l'accès est protégé (authentification obligatoire).
     * NOTE : depuis la bascule DENY-BY-DEFAULT, cette liste n'est plus le seul périmètre protégé
     * (tout ce qui n'est ni public ni self-guarded exige une auth). Elle sert à documenter les
     * zones protégées connues et à la vérification de classement (test).
     */
    private const ENFORCED_PREFIX = [
        'admin/',
        'modules/',
        'accueil/',
        'parametres/',
        'rgpd/',
        'tutorat/',
        'securite/',
        'API/endpoints/',
    ];

    /**
     * Mondes à AUTHENTIFICATION PROPRE : plateforme et établissement (tenant) gèrent leur session
     * (`platform.account_id` / `tenant.account_id`), pas le `user_id` legacy. AccessControl ne leur
     * applique donc pas la garde de rôle legacy (leurs gardes de page font foi) mais les reconnaît
     * comme CLASSÉS (pas « oubliés » sous le deny-by-default).
     */
    private const SELF_GUARDED_PREFIX = [
        'platform/',
        'tenant/',
        'impersonation/',
        'director/',
    ];

    /** Réservé au super_admin (opérations infrastructure / cross-établissement). */
    private const SUPER_ADMIN_ONLY = [
        'admin/etablissement/switch.php',
        'admin/etablissement/multi.php',
        'admin/etablissement/purge.php',
    ];

    /** Back-office : rôles autorisés à entrer dans admin/. */
    private const ADMIN_AREA_ROLES = ['administrateur', 'technicien', 'super_admin'];

    /**
     * Point d'entrée unique appelé par bootstrap.php.
     */
    public static function enforce(string $basePath): void
    {
        if (PHP_SAPI === 'cli') {
            return; // crons / scripts CLI : hors du contrôle d'accès web
        }

        $rel = self::currentPath($basePath);
        if ($rel === null) {
            return; // chemin inclassable → ne pas verrouiller (les gardes par page restent actives)
        }

        // 1) Public explicite → laisser passer.
        if (self::isPublic($rel)) {
            return;
        }

        // 2) Mondes à AUTHENTIFICATION PROPRE (plateforme/tenant/…) → leurs gardes de page font foi.
        if (self::isSelfGuarded($rel)) {
            return;
        }

        // 3) DENY-BY-DEFAULT : tout le reste exige une authentification. Auparavant, un chemin hors
        //    ENFORCED_PREFIX était laissé passer (délégué aux gardes de page) → un nouveau dossier de
        //    haut niveau mal classé était exposé par défaut. Désormais : protégé sauf allow-list.
        $role = self::currentRole();
        if ($role === null) {
            self::deny(true, $basePath);
        }

        // super_admin = god mode : accès total.
        if ($role === 'super_admin') {
            return;
        }

        // 4) Zones réservées au super_admin.
        if (in_array($rel, self::SUPER_ADMIN_ONLY, true)) {
            self::deny(false, $basePath);
        }

        // 5) Back-office admin/ : rôles admin legacy OU permission d'administration établissement
        //    (bascule 3-mondes). Le contrôle fin reste assuré par les gardes de page (tenantGate).
        if (self::startsWith($rel, 'admin/')
            && !in_array($role, self::ADMIN_AREA_ROLES, true)
            && !self::tenantHasAdminAccess()) {
            self::deny(false, $basePath);
        }

        // Sinon : tout utilisateur authentifié peut atteindre la page ; le contrôle
        // fin (par module / par rôle CPE/VS/prof/parent/élève) reste géré par RBAC
        // et les en-têtes de module.
    }

    /** Chemin du fichier appelé, relatif à la racine projet (séparateurs normalisés). */
    private static function currentPath(string $basePath): ?string
    {
        $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
        $real   = $script ? realpath($script) : false;
        $base   = realpath($basePath);
        if ($real === false || $base === false) {
            return null;
        }
        $real = str_replace('\\', '/', $real);
        $base = str_replace('\\', '/', $base);
        if (strpos($real, $base) !== 0) {
            return null;
        }
        return ltrim(substr($real, strlen($base)), '/');
    }

    private static function isPublic(string $rel): bool
    {
        if (in_array($rel, self::PUBLIC_EXACT, true)) {
            return true;
        }
        foreach (self::PUBLIC_PREFIX as $p) {
            if (self::startsWith($rel, $p)) {
                return true;
            }
        }
        return false;
    }

    private static function isEnforced(string $rel): bool
    {
        foreach (self::ENFORCED_PREFIX as $p) {
            if (self::startsWith($rel, $p)) {
                return true;
            }
        }
        return false;
    }

    /** Le chemin relève-t-il d'un monde à authentification propre (plateforme/tenant/…) ? */
    private static function isSelfGuarded(string $rel): bool
    {
        foreach (self::SELF_GUARDED_PREFIX as $p) {
            if (self::startsWith($rel, $p)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Liste des préfixes/exacts explicitement classés (pour le test de classement). Un dossier de
     * haut niveau contenant des points d'entrée web DOIT tomber dans l'une de ces catégories,
     * sinon il serait protégé par le deny-by-default sans intention explicite (à classer).
     * @return array{public:string[],enforced:string[],self_guarded:string[]}
     */
    public static function classification(): array
    {
        return [
            'public'       => array_merge(self::PUBLIC_EXACT, self::PUBLIC_PREFIX),
            'enforced'     => self::ENFORCED_PREFIX,
            'self_guarded' => self::SELF_GUARDED_PREFIX,
        ];
    }

    /**
     * Bascule 3-mondes : l'utilisateur détient-il une permission d'administration établissement
     * (via son appartenance — connexion /e/{slug} OU repli legacy) ? Permet d'entrer dans /admin/
     * sans le rôle legacy « administrateur » ; les gardes de page (tenantGate) affinent ensuite.
     */
    private static function tenantHasAdminAccess(): bool
    {
        try {
            return function_exists('tenantCan') && tenantCan('tenant.users.view');
        } catch (\Throwable $e) {
            error_log('[AccessControl] tenantHasAdminAccess: ' . $e->getMessage());
            return false; // fail-closed
        }
    }

    /** Rôle courant depuis la session (posé par SessionGuard::login). */
    private static function currentRole(): ?string
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        $t = $_SESSION['user_type'] ?? ($_SESSION['user']['type'] ?? null);
        return is_string($t) && $t !== '' ? $t : null;
    }

    private static function startsWith(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }

    /**
     * Refuse l'accès : 401/403 JSON pour les requêtes XHR/JSON, sinon redirection.
     * @param bool $needAuth true = manque l'authentification (→ login), false = rôle insuffisant.
     */
    private static function deny(bool $needAuth, string $basePath): void
    {
        $baseUrl = defined('BASE_URL') ? BASE_URL : '';

        if (self::isJsonRequest()) {
            http_response_code($needAuth ? 401 : 403);
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'error'   => true,
                'code'    => $needAuth ? 401 : 403,
                'message' => $needAuth ? 'Authentification requise' : 'Accès refusé',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!headers_sent()) {
            if ($needAuth) {
                header('Location: ' . $baseUrl . '/login/index.php');
            } else {
                $_SESSION['error_message'] = "Vous n'avez pas les droits pour accéder à cette page.";
                header('Location: ' . $baseUrl . '/accueil/accueil.php');
            }
        }
        exit;
    }

    private static function isJsonRequest(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $ctype  = $_SERVER['CONTENT_TYPE'] ?? '';
        $xhr    = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        return str_contains($accept, 'application/json')
            || str_contains($ctype, 'application/json')
            || strtolower((string) $xhr) === 'xmlhttprequest';
    }
}
