<?php
declare(strict_types=1);

namespace API\UI;

/**
 * TopbarNav — construit le MODÈLE de la barre de navigation (modules groupés, favoris, badge de
 * notifications, établissement courant, sélecteur d'enfant parent). Extrait du template
 * templates/shared_topbar_nav.php pour l'en sortir toute logique métier/SQL : le template ne fait
 * plus qu'itérer et échapper (thème 7 de la revue de design).
 *
 * Best-effort : chaque source de données est protégée ; une panne partielle n'empêche pas le rendu.
 */
final class TopbarNav
{
    private const FALLBACK_META = [
        'scolaire'      => ['label' => 'Pédagogie',     'icon' => 'fas fa-graduation-cap', 'order' => 1],
        'vie_scolaire'  => ['label' => 'Vie scol.',     'icon' => 'fas fa-school',         'order' => 2],
        'communication' => ['label' => 'Communication', 'icon' => 'fas fa-comments',       'order' => 3],
        'sante'         => ['label' => 'Santé',         'icon' => 'fas fa-heartbeat',      'order' => 4],
        'etablissement' => ['label' => 'Établissement', 'icon' => 'fas fa-building',       'order' => 5],
        'logistique'    => ['label' => 'Logistique',    'icon' => 'fas fa-cogs',           'order' => 6],
        'systeme'       => ['label' => 'Outils',        'icon' => 'fas fa-tools',          'order' => 7],
    ];
    private const FALLBACK_OVERRIDES = ['messagerie' => 'communication', 'notifications' => 'communication', 'infirmerie' => 'sante', 'vie_associative' => 'systeme'];
    private const FALLBACK_EXCLUDE = ['accueil', 'parametres', 'profil', 'notifications'];

    /**
     * @return array{modules:array,favorites:array,fav_keys:array,notif_count:int,etab_name:string,children:array,selected_child:?array,is_parent:bool,role:string}
     */
    public static function build(): array
    {
        $role = function_exists('getUserRole') ? (getUserRole() ?? 'eleve') : 'eleve';
        // Rôles effectifs (base + attribués) : un compte avec un rôle attribué (infirmerie, cpe,
        // professeur_principal…) doit voir les modules de ce rôle dans la topbar.
        $roles = function_exists('getEffectiveRoles') ? getEffectiveRoles() : [$role];
        if (empty($roles)) {
            $roles = [$role];
        }
        $userId   = $_SESSION['user_id'] ?? null;
        $userType = $_SESSION['user_type'] ?? '';

        $modules   = self::modules($roles, $role, $userId, $userType, $favorites, $favKeys);

        return [
            'modules'        => $modules,
            'favorites'      => $favorites,
            'fav_keys'       => $favKeys,
            'notif_count'    => self::notifCount($userId, $userType),
            'etab_name'      => self::etabName(),
            'is_parent'      => ($userType === 'parent'),
            'children'       => ($children = self::children($userId, $userType, $selectedChild)),
            'selected_child' => $selectedChild,
            'role'           => $role,
        ];
    }

    /** Modules groupés par catégorie topbar (+ favoris), avec repli SQL direct. */
    private static function modules(array $roles, string $role, $userId, string $userType, ?array &$favorites, ?array &$favKeys): array
    {
        $modules = [];
        $favorites = [];
        $favKeys = [];
        try {
            $svc = app('modules');
            $modules = $svc->getForTopbar($roles);
            if (!empty($userId)) {
                $favorites = $svc->getFavorites((int) $userId, $userType, $role);
                foreach ($favorites as $fav) {
                    $favKeys[$fav['module_key']] = true;
                }
            }
        } catch (\Throwable $e) {
            error_log('[topbar] getForTopbar failed (' . get_class($e) . '): ' . $e->getMessage());
        }

        // Repli : requête directe quand la couche service ne renvoie rien
        // (garde contre cache singleton périmé, colonnes manquantes, erreurs au boot).
        if (empty($modules)) {
            try {
                $rows = getPDO()->query("SELECT module_key, label, icon, category, sort_order FROM modules_config WHERE enabled = 1 AND sidebar_hidden = 0 ORDER BY sort_order, label")->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($rows as $mod) {
                    $key = $mod['module_key'];
                    if (in_array($key, self::FALLBACK_EXCLUDE, true)) continue;
                    $cat = self::FALLBACK_OVERRIDES[$key] ?? $mod['category'];
                    if ($cat === 'navigation' || !isset(self::FALLBACK_META[$cat])) continue;
                    if (!isset($modules[$cat])) {
                        $modules[$cat] = [
                            'label'   => self::FALLBACK_META[$cat]['label'],
                            'icon'    => self::FALLBACK_META[$cat]['icon'],
                            'order'   => self::FALLBACK_META[$cat]['order'],
                            'modules' => [],
                        ];
                    }
                    $route = 'modules/' . $key . '/' . $key . '.php';
                    $modules[$cat]['modules'][] = array_merge($mod, ['route' => $route, 'module_key' => $key]);
                }
                uasort($modules, fn($a, $b) => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));
            } catch (\Throwable $e) {
                error_log('[topbar] direct-DB fallback failed: ' . $e->getMessage());
            }
        }
        return $modules;
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

    /**
     * Enfants d'un parent + enfant sélectionné (gère l'action switch_child, qui écrit en session
     * APRÈS avoir vérifié le lien parent_eleve — anti-IDOR).
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
