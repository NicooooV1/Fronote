<?php
declare(strict_types=1);

namespace API\UI;

/**
 * TopbarNav — construit le MODÈLE de la barre de navigation. Le template
 * (templates/shared_topbar_nav.php) ne fait qu'itérer et échapper.
 *
 * Principe (refonte 4.4) : un module courant = UN clic.
 *  - « raccourcis » affichés directement dans la barre : les favoris de l'utilisateur, sinon une
 *    sélection par rôle (emploi du temps, cahier de textes, notes…) ;
 *  - un seul menu « Modules » qui montre TOUT d'un coup d'œil, rangé en 5 familles lisibles
 *    (au lieu de 7 menus déroulants à ouvrir un par un) ;
 *  - seuls les modules réellement ouvrables par l'utilisateur apparaissent.
 *
 * Best-effort : chaque source de données est protégée ; une panne partielle n'empêche pas le rendu.
 */
final class TopbarNav
{
    /** Familles affichées dans le menu « Modules » (ordre = ordre d'affichage). */
    private const GROUPS = [
        'scolarite'      => ['label' => 'Scolarité',              'icon' => 'fas fa-graduation-cap'],
        'vie_scolaire'   => ['label' => 'Vie scolaire',           'icon' => 'fas fa-user-check'],
        'communication'  => ['label' => 'Communication',          'icon' => 'fas fa-comments'],
        'etablissement'  => ['label' => "Vie de l'établissement", 'icon' => 'fas fa-school'],
        'administration' => ['label' => 'Administration',         'icon' => 'fas fa-briefcase'],
    ];

    /** Rangement explicite des modules connus (le reste suit sa catégorie d'origine). */
    private const MODULE_GROUP = [
        // Scolarité : ce qui touche aux cours, au travail et aux résultats
        'emploi_du_temps' => 'scolarite', 'cahierdetextes' => 'scolarite', 'devoirs' => 'scolarite',
        'notes' => 'scolarite', 'evaluations' => 'scolarite', 'competences' => 'scolarite',
        'examens' => 'scolarite', 'bulletins' => 'scolarite', 'conseil_classe' => 'scolarite',
        'agenda' => 'scolarite', 'ressources' => 'scolarite', 'mediatheque' => 'scolarite',
        'projets_pedagogiques' => 'scolarite', 'parcours_educatifs' => 'scolarite',
        'orientation' => 'scolarite', 'echanges' => 'scolarite', 'accessibilite' => 'scolarite',
        'intelligence' => 'scolarite',
        // Vie scolaire : présence, comportement, suivi, santé
        'appel' => 'vie_scolaire', 'absences' => 'vie_scolaire', 'discipline' => 'vie_scolaire',
        'vie_scolaire' => 'vie_scolaire', 'besoins' => 'vie_scolaire', 'infirmerie' => 'vie_scolaire',
        'signalements' => 'vie_scolaire',
        // Communication
        'messagerie' => 'communication', 'annonces' => 'communication', 'reunions' => 'communication',
        'documents' => 'communication', 'trombinoscope' => 'communication', 'enquetes' => 'communication',
        'portail_parents' => 'communication',
        // Vie de l'établissement : services et activités
        'cantine' => 'etablissement', 'internat' => 'etablissement', 'garderie' => 'etablissement',
        'periscolaire' => 'etablissement', 'transports' => 'etablissement', 'clubs' => 'etablissement',
        'vie_associative' => 'etablissement', 'bibliotheque' => 'etablissement', 'salles' => 'etablissement',
        'stages' => 'etablissement', 'bourses' => 'etablissement', 'formations' => 'etablissement',
        'inventaire' => 'etablissement',
    ];

    /** Catégorie d'origine (modules_config.category) → famille, pour les modules non listés. */
    private const CATEGORY_GROUP = [
        'scolaire' => 'scolarite', 'vie_scolaire' => 'vie_scolaire', 'communication' => 'communication',
        'sante' => 'vie_scolaire', 'administration' => 'administration',
    ];

    /** Modules qui ne sont pas des destinations de la barre (accessibles ailleurs). */
    private const NOT_IN_MENU = ['accueil', 'parametres', 'profil', 'notifications', 'support'];

    /** Raccourcis par défaut (tant que l'utilisateur n'a rien épinglé). */
    private const DEFAULT_SHORTCUTS = [
        'eleve'          => ['emploi_du_temps', 'cahierdetextes', 'notes', 'messagerie'],
        'parent'         => ['notes', 'cahierdetextes', 'absences', 'messagerie'],
        'professeur'     => ['emploi_du_temps', 'appel', 'cahierdetextes', 'notes', 'messagerie'],
        'vie_scolaire'   => ['absences', 'appel', 'discipline', 'vie_scolaire', 'messagerie'],
        'administrateur' => ['vie_scolaire', 'absences', 'annonces', 'messagerie', 'reporting'],
    ];

    /**
     * @return array{groups:array,modules:array,favorites:array,fav_keys:array,shortcuts:array,
     *   defaults:array,notif_count:int,etab_name:string,children:array,selected_child:?array,
     *   is_parent:bool,role:string,role_label:string}
     */
    public static function build(): array
    {
        $role = function_exists('getUserRole') ? (getUserRole() ?? 'eleve') : 'eleve';
        // Rôles effectifs (base + attribués) : un compte avec un rôle attribué (infirmerie, cpe,
        // professeur_principal…) doit voir les modules de ce rôle.
        $roles = function_exists('getEffectiveRoles') ? getEffectiveRoles() : [$role];
        if (empty($roles)) {
            $roles = [$role];
        }
        $userId   = $_SESSION['user_id'] ?? null;
        $userType = $_SESSION['user_type'] ?? '';

        $byCategory = self::modulesByCategory($roles);
        [$groups, $flat] = self::regroup($byCategory);
        $favorites = self::favorites($userId, $userType, $role, $flat);

        // Module Support : hors du menu (ce n'est pas un outil du quotidien), proposé dans le menu
        // du compte s'il est ouvrable par l'utilisateur.
        $supportRoute = null;
        foreach ($byCategory as $category) {
            foreach ($category['modules'] ?? [] as $mod) {
                if (($mod['module_key'] ?? '') === 'support') {
                    $supportRoute = (string) ($mod['route'] ?? '');
                }
            }
        }

        $favKeys = [];
        foreach ($favorites as $f) {
            $favKeys[$f['module_key']] = true;
        }

        $defaults = [];
        foreach (self::DEFAULT_SHORTCUTS[$userType] ?? self::DEFAULT_SHORTCUTS[$role] ?? [] as $k) {
            if (isset($flat[$k])) {
                $defaults[] = self::link($flat[$k]);
            }
        }
        // Sans réglage pour ce rôle : les premiers modules de la première famille.
        if (!$defaults && $flat) {
            foreach (array_slice($flat, 0, 4) as $m) {
                $defaults[] = self::link($m);
            }
        }

        return [
            'groups'         => $groups,
            'modules'        => $flat,
            'favorites'      => $favorites,
            'fav_keys'       => $favKeys,
            'shortcuts'      => $favorites ?: $defaults,
            'defaults'       => $defaults,
            'notif_count'    => self::notifCount($userId, $userType),
            'etab_name'      => self::etabName(),
            'is_parent'      => ($userType === 'parent'),
            'children'       => self::children($userId, $userType, $selectedChild),
            'selected_child' => $selectedChild,
            'role'           => $role,
            'role_label'     => self::roleLabel($role),
            'support_route'  => $supportRoute,
        ];
    }

    /**
     * Métadonnées d'un module pour l'en-tête de page (icône, libellé, famille), même s'il n'est pas
     * dans la barre de l'utilisateur : lues dans modules/<clé>/module.json.
     */
    public static function moduleMeta(string $key): ?array
    {
        static $cache = [];
        if ($key === '' || !preg_match('/^[a-z0-9_]+$/', $key)) { return null; }
        if (array_key_exists($key, $cache)) { return $cache[$key]; }
        $file = dirname(__DIR__, 2) . '/modules/' . $key . '/module.json';
        $j = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($j)) { return $cache[$key] = null; }
        $name = $j['name'] ?? $key;
        $label = is_array($name) ? (string) ($name['fr'] ?? reset($name)) : (string) $name;
        $group = self::MODULE_GROUP[$key] ?? self::CATEGORY_GROUP[(string) ($j['category'] ?? '')] ?? 'administration';
        return $cache[$key] = ['module_key' => $key, 'label' => $label, 'icon' => (string) ($j['icon'] ?? 'fas fa-circle'), 'group' => $group, 'route' => '', 'type' => 'module'];
    }

    /** Libellés des familles (pour l'en-tête de page). */
    public static function groupLabel(string $group): string
    {
        return self::GROUPS[$group]['label'] ?? '';
    }

    /** Lien normalisé (ce que le template et le JS consomment). */
    private static function link(array $m): array
    {
        return [
            'module_key' => (string) $m['module_key'],
            'label'      => (string) ($m['label'] ?? $m['module_key']),
            'icon'       => (string) ($m['icon'] ?? 'fas fa-circle'),
            'route'      => (string) ($m['route'] ?? ''),
            'type'       => (string) ($m['type'] ?? 'module'),
        ];
    }

    /** Modules ouvrables par l'utilisateur, groupés par catégorie d'origine (service + repli SQL). */
    private static function modulesByCategory(array $roles): array
    {
        $modules = [];
        try {
            $modules = app('modules')->getForTopbar($roles);
        } catch (\Throwable $e) {
            error_log('[topbar] getForTopbar failed (' . get_class($e) . '): ' . $e->getMessage());
        }
        if (!empty($modules)) {
            return $modules;
        }
        // Repli : requête directe quand la couche service ne renvoie rien
        // (garde contre cache singleton périmé, colonnes manquantes, erreurs au boot).
        try {
            $stmt = getPDO()->prepare("SELECT module_key, label, icon, category, sort_order, route_path FROM modules_config WHERE enabled = 1 AND sidebar_hidden = 0 AND etablissement_id = ? ORDER BY sort_order, label");
            $stmt->execute([\API\Core\EstablishmentContext::id()]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $mod) {
                $key = $mod['module_key'];
                $route = !empty($mod['route_path']) ? $mod['route_path'] : 'modules/' . $key . '/' . $key . '.php';
                $modules[$mod['category'] ?? 'systeme']['modules'][] = array_merge($mod, ['route' => $route, 'module_key' => $key]);
            }
        } catch (\Throwable $e) {
            error_log('[topbar] direct-DB fallback failed: ' . $e->getMessage());
        }
        return $modules;
    }

    /**
     * Range les modules dans les 5 familles.
     * @return array{0:array<string,array>,1:array<string,array>} [familles, modules à plat par clé]
     */
    private static function regroup(array $byCategory): array
    {
        $groups = [];
        foreach (self::GROUPS as $g => $meta) {
            $groups[$g] = $meta + ['modules' => []];
        }
        $flat = [];
        foreach ($byCategory as $cat => $category) {
            foreach ($category['modules'] ?? [] as $mod) {
                $key = (string) ($mod['module_key'] ?? '');
                if ($key === '' || isset($flat[$key]) || in_array($key, self::NOT_IN_MENU, true)) {
                    continue;
                }
                $g = self::MODULE_GROUP[$key] ?? self::CATEGORY_GROUP[$cat] ?? 'etablissement';
                $link = self::link($mod) + ['group' => $g];
                $groups[$g]['modules'][] = $link;
                $flat[$key] = $link;
            }
        }
        $groups = array_filter($groups, fn($g) => !empty($g['modules']));
        // Ordre de $flat = ordre d'affichage (famille puis rang du module).
        $ordered = [];
        foreach ($groups as $g) {
            foreach ($g['modules'] as $m) {
                $ordered[$m['module_key']] = $m;
            }
        }
        return [$groups, $ordered];
    }

    /** Favoris de l'utilisateur, limités aux modules qu'il peut ouvrir (pages : conservées). */
    private static function favorites($userId, string $userType, string $role, array $flat): array
    {
        if (empty($userId)) {
            return [];
        }
        try {
            $out = [];
            foreach (app('modules')->getFavorites((int) $userId, $userType, $role) as $fav) {
                $isPage = ($fav['type'] ?? 'module') === 'page';
                if (!$isPage && !isset($flat[$fav['module_key']])) {
                    continue;
                }
                $out[] = self::link($fav);
            }
            return $out;
        } catch (\Throwable $e) {
            error_log('[topbar] favorites failed: ' . $e->getMessage());
            return [];
        }
    }

    /** Nombre de notifications non lues (badge). */
    private static function notifCount($userId, string $userType): int
    {
        if (empty($userId)) {
            return 0;
        }
        try {
            // Table socle = notifications_globales, colonne non-lu = `lu`.
            $stmt = getPDO()->prepare("SELECT COUNT(*) FROM notifications_globales WHERE user_id = ? AND user_type = ? AND lu = 0");
            $stmt->execute([$userId, $userType]);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Nom de l'établissement courant. */
    private static function etabName(): string
    {
        try {
            return (string) (app('etablissement')->getCurrent()['nom'] ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function roleLabel(string $role): string
    {
        try {
            $roles = \API\Security\RoleCatalog::roles();
            if (!empty($roles[$role]['label'])) {
                return (string) $roles[$role]['label'];
            }
        } catch (\Throwable $e) {
        }
        return ucfirst(str_replace('_', ' ', $role));
    }

    /**
     * Enfants d'un parent + enfant sélectionné (gère l'action switch_child, qui écrit en session
     * APRÈS avoir vérifié le lien parent_eleve — anti-IDOR). La sélection est partagée avec
     * l'accueil (même clé de session selected_child_id).
     * @param-out ?array $selected
     */
    private static function children($userId, string $userType, ?array &$selected): array
    {
        $selected = null;
        if ($userType !== 'parent' || empty($userId)) {
            return [];
        }
        try {
            $pdo = getPDO();
            if (!empty($_REQUEST['switch_child'])) {
                $switchId = (int) $_REQUEST['switch_child'];
                $chk = $pdo->prepare("SELECT COUNT(*) FROM parent_eleve WHERE id_parent = ? AND id_eleve = ?");
                $chk->execute([$userId, $switchId]);
                if ((int) $chk->fetchColumn() > 0) {
                    $_SESSION['selected_child_id'] = $switchId;
                }
            }
            $stmt = $pdo->prepare("
                SELECT e.id, e.nom, e.prenom, c.nom AS classe_nom
                FROM parent_eleve pe JOIN eleves e ON e.id = pe.id_eleve
                LEFT JOIN classes c ON e.classe = c.nom
                WHERE pe.id_parent = ? AND e.actif = 1
                ORDER BY e.nom, e.prenom
            ");
            $stmt->execute([$userId]);
            $children = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (!empty($children)) {
                $selectedId = $_SESSION['selected_child_id'] ?? null;
                foreach ($children as $child) {
                    if ((int) $child['id'] === (int) $selectedId) {
                        $selected = $child;
                        break;
                    }
                }
                if (!$selected) {
                    $selected = $children[0];
                    $_SESSION['selected_child_id'] = (int) $children[0]['id'];
                }
            }
            return $children;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
