<?php
declare(strict_types=1);

namespace API\Services;

use PDO;

/**
 * Service de gestion des modules
 * 
 * Permet d'activer/désactiver/configurer chaque module de l'application.
 * Les modules « core » ne peuvent pas être désactivés.
 * La sidebar consulte ce service pour savoir quels liens afficher.
 */
class ModuleService
{
    private PDO $pdo;
    private ?array $cache = null;
    // Établissement pour lequel $cache a été chargé : le cache mémoire est par-tenant,
    // rechargé si l'établissement courant change dans la requête (ex: impersonation support).
    private ?int $cacheEtab = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ─── Lecture ──────────────────────────────────────────────────────────

    /**
     * Récupère tous les modules indexés par module_key
     */
    public function getAll(): array
    {
        // Cloisonnement multi-établissement : la config des modules est PAR-TENANT
        // (modules_config a une ligne par (module_key, etablissement_id)). Lecture ET cache
        // sont scopés à l'établissement courant, sinon un tenant lirait/écraserait la config
        // d'un autre (l'ancien cache partagé « modules:all » fuyait entre établissements).
        $etab = \API\Core\EstablishmentContext::id();
        if ($this->cache !== null && $this->cacheEtab === $etab) {
            return $this->cache;
        }
        $this->cache = null;
        $this->cacheEtab = $etab;
        $cacheKey = 'modules:all:' . $etab;

        $fetched = app('cache')->remember($cacheKey, 300, function () {
            return $this->loadFromDb();
        });
        // Don't permanently cache null/empty from a transient error:
        // keep $this->cache as null so the next call in the same request retries.
        if (!empty($fetched)) {
            $this->cache = $fetched;
        }
        // Fallback: try direct DB query if in-memory cache still empty
        if (empty($this->cache)) {
            $direct = $this->loadFromDb();
            if (!empty($direct)) {
                $this->cache = $direct;
                try { app('cache')->put($cacheKey, $direct, 300); } catch (\Throwable $e) { error_log('[ModuleService.php] ' . $e->getMessage()); }
            }
        }
        return $this->cache ?? [];
    }

    /**
     * Charge les modules depuis la base de données.
     * Retourne [] en cas d'erreur (loggée) ou de table vide.
     */
    private function loadFromDb(): array
    {
        try {
            $etab = \API\Core\EstablishmentContext::id();
            $stmt = $this->pdo->prepare("SELECT * FROM modules_config WHERE etablissement_id = ? ORDER BY sort_order, label");
            $stmt->execute([$etab]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Auto-réparation : un établissement sans config propre (nouvel établissement,
            // migration de backfill pas encore jouée) est seedé depuis le modèle, pour ne
            // JAMAIS renvoyer une navigation vide. La config devient alors éditable par-tenant.
            if (empty($rows)) {
                $this->seedEstablishmentFromTemplate($etab);
                $stmt->execute([$etab]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $result = [];
            foreach ($rows as $row) {
                $row['config'] = !empty($row['config_json']) ? json_decode($row['config_json'], true) : [];
                $row['roles_autorises'] = !empty($row['roles_autorises']) ? json_decode($row['roles_autorises'], true) : null;
                $result[$row['module_key']] = $row;
            }
            return $result;
        } catch (\Throwable $e) {
            error_log("ModuleService::getAll error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Seed IDEMPOTENT de la config d'un établissement à partir de la ligne « modèle »
     * (plus petit etablissement_id) de chaque module. Garantit qu'un établissement
     * possède ses lignes modules_config avant toute lecture/écriture par-tenant.
     */
    private function seedEstablishmentFromTemplate(int $etab): void
    {
        try {
            $cols = 'module_key, label, description, icon, route_path, category, enabled, '
                  . 'establishment_types, config_json, roles_autorises, sort_order, sidebar_sort, '
                  . 'is_core, sidebar_hidden, topbar_category, topbar_sort_order';
            $sql = "INSERT INTO modules_config (etablissement_id, {$cols})
                    SELECT ?, t.module_key, t.label, t.description, t.icon, t.route_path, t.category,
                           t.enabled, t.establishment_types, t.config_json, t.roles_autorises, t.sort_order,
                           t.sidebar_sort, t.is_core, t.sidebar_hidden, t.topbar_category, t.topbar_sort_order
                    FROM (
                        SELECT m.* FROM modules_config m
                        JOIN (
                            SELECT module_key, MIN(etablissement_id) AS mid
                            FROM modules_config GROUP BY module_key
                        ) x ON m.module_key = x.module_key AND m.etablissement_id = x.mid
                    ) t
                    WHERE NOT EXISTS (
                        SELECT 1 FROM modules_config mc
                        WHERE mc.module_key = t.module_key AND mc.etablissement_id = ?
                    )";
            $this->pdo->prepare($sql)->execute([$etab, $etab]);
        } catch (\Throwable $e) {
            error_log('[ModuleService] seedEstablishmentFromTemplate: ' . $e->getMessage());
        }
    }

    /**
     * Récupère un module par sa clé
     */
    public function get(string $key): ?array
    {
        $all = $this->getAll();
        return $all[$key] ?? null;
    }

    /**
     * Vérifie si un module est activé
     */
    public function isEnabled(string $key): bool
    {
        $module = $this->get($key);
        // Module inconnu → considéré activé (rétrocompat)
        if ($module === null) return true;
        return !empty($module['enabled']);
    }

    /**
     * Vérifie si un module est « core » (ne peut pas être désactivé)
     */
    public function isCore(string $key): bool
    {
        $module = $this->get($key);
        return $module !== null && !empty($module['is_core']);
    }

    /**
     * Récupère les modules par catégorie
     */
    public function getByCategory(): array
    {
        $all = $this->getAll();
        $categories = [];
        foreach ($all as $m) {
            $cat = $m['category'] ?? 'general';
            $categories[$cat][] = $m;
        }
        return $categories;
    }

    /**
     * Labels des catégories
     */
    public static function categoryLabels(): array
    {
        return [
            'navigation'    => 'Accueil',
            'scolaire'      => 'Pédagogie',
            'vie_scolaire'  => 'Vie scolaire',
            'communication' => 'Communication',
            'sante'         => 'Santé',
            'etablissement' => 'Établissement',
            'logistique'    => 'Outils',
            'systeme'       => 'Outils',
            'administration'=> 'Administration',
        ];
    }

    // ─── Écriture ────────────────────────────────────────────────────────

    /**
     * Active ou désactive un module
     */
    public function setEnabled(string $key, bool $enabled): bool
    {
        if ($this->isCore($key)) {
            return false; // Modules core ne peuvent pas être désactivés
        }

        // À l'ACTIVATION : injecter + vérifier le SQL du module avant d'activer.
        // Si le provisioning échoue, on n'active pas (pas de module à moitié installé).
        if ($enabled) {
            try {
                $prov = app('module_sdk')->provisionSql($key);
                if (empty($prov['success'])) {
                    error_log("ModuleService::setEnabled — provisioning SQL échoué pour '{$key}' : " . implode(' | ', $prov['errors'] ?? []));
                    return false;
                }
            } catch (\Throwable $e) {
                error_log("ModuleService::setEnabled — provisioning error '{$key}': " . $e->getMessage());
                return false;
            }
        }

        try {
            $etab = \API\Core\EstablishmentContext::id();
            $stmt = $this->pdo->prepare("UPDATE modules_config SET enabled = ? WHERE module_key = ? AND is_core = 0 AND etablissement_id = ?");
            $result = $stmt->execute([(int)$enabled, $key, $etab]);
            $this->cache = null; $this->cacheEtab = null; app('cache')->forget('modules:all:' . $etab);
            return $result;
        } catch (\PDOException $e) {
            error_log("ModuleService::setEnabled error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Active/désactive plusieurs modules en batch
     * @param array $states ['module_key' => bool, ...]
     */
    public function batchSetEnabled(array $states): int
    {
        $count = 0;
        foreach ($states as $key => $enabled) {
            if ($this->setEnabled($key, (bool)$enabled)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Met à jour la configuration JSON d'un module
     */
    public function updateConfig(string $key, array $config): bool
    {
        try {
            $etab = \API\Core\EstablishmentContext::id();
            $stmt = $this->pdo->prepare("UPDATE modules_config SET config_json = ? WHERE module_key = ? AND etablissement_id = ?");
            $result = $stmt->execute([json_encode($config, JSON_UNESCAPED_UNICODE), $key, $etab]);
            $this->cache = null; $this->cacheEtab = null; app('cache')->forget('modules:all:' . $etab);
            return $result;
        } catch (\PDOException $e) {
            error_log("ModuleService::updateConfig error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Récupère la configuration JSON d'un module
     */
    public function getConfig(string $key): array
    {
        $module = $this->get($key);
        if ($module === null) return [];
        return $module['config'] ?? [];
    }

    /**
     * Met à jour les rôles autorisés à voir un module.
     * Passer un tableau vide ou null pour revenir au comportement par défaut (tous les rôles).
     */
    public function updateRolesAutorises(string $key, ?array $roles): bool
    {
        try {
            $value = ($roles !== null && count($roles) > 0) ? json_encode(array_values($roles)) : null;
            $etab = \API\Core\EstablishmentContext::id();
            $stmt = $this->pdo->prepare("UPDATE modules_config SET roles_autorises = ? WHERE module_key = ? AND etablissement_id = ?");
            $result = $stmt->execute([$value, $key, $etab]);
            $this->cache = null; $this->cacheEtab = null; app('cache')->forget('modules:all:' . $etab);
            return $result;
        } catch (\PDOException $e) {
            error_log("ModuleService::updateRolesAutorises error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Met à jour le label et la description d'un module
     */
    public function updateInfo(string $key, array $data): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE modules_config SET
                    label = COALESCE(?, label),
                    description = COALESCE(?, description),
                    icon = COALESCE(?, icon),
                    sort_order = COALESCE(?, sort_order)
                WHERE module_key = ? AND etablissement_id = ?
            ");
            $etab = \API\Core\EstablishmentContext::id();
            $result = $stmt->execute([
                $data['label'] ?? null,
                $data['description'] ?? null,
                $data['icon'] ?? null,
                isset($data['sort_order']) ? (int)$data['sort_order'] : null,
                $key,
                $etab,
            ]);
            $this->cache = null; $this->cacheEtab = null; app('cache')->forget('modules:all:' . $etab);
            return $result;
        } catch (\PDOException $e) {
            error_log("ModuleService::updateInfo error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Vide le cache
     */
    public function clearCache(): void
    {
        $this->cache = null; app('cache')->forget('modules:all');
    }

    // ─── Stats ───────────────────────────────────────────────────────────

    /**
     * Compteurs rapides pour le dashboard admin
     */
    public function getStats(): array
    {
        $all = $this->getAll();
        $total = count($all);
        $enabled = count(array_filter($all, fn($m) => !empty($m['enabled'])));
        $core = count(array_filter($all, fn($m) => !empty($m['is_core'])));
        return compact('total', 'enabled', 'core');
    }

    // ─── Sidebar Integration ─────────────────────────────────────────────

    // Static route map and role visibility removed — now sourced from DB
    // (populated by ModuleSDK::syncModule from module.json).

    /**
     * Category display order + icons for sidebar headings.
     * Includes both new sidebar categories and legacy DB category keys.
     */
    public static function categoryMeta(): array
    {
        return [
            'navigation'    => ['label' => 'Accueil',              'icon' => 'fas fa-home',           'order' => 0],
            'scolaire'      => ['label' => 'Pédagogie',           'icon' => 'fas fa-graduation-cap', 'order' => 1],
            'vie_scolaire'  => ['label' => 'Vie scolaire',        'icon' => 'fas fa-school',         'order' => 2],
            'communication' => ['label' => 'Communication',       'icon' => 'fas fa-comments',       'order' => 3],
            'sante'         => ['label' => 'Santé',               'icon' => 'fas fa-heartbeat',      'order' => 4],
            'etablissement' => ['label' => 'Établissement',       'icon' => 'fas fa-building',       'order' => 5],
            'logistique'    => ['label' => 'Logistique',          'icon' => 'fas fa-cogs',           'order' => 6],
            'systeme'       => ['label' => 'Outils',              'icon' => 'fas fa-tools',          'order' => 7],
        ];
    }

    /**
     * Remap legacy DB category keys to new sidebar categories.
     * Modules whose module_key matches a key here get moved to the specified category.
     * This lets us reorganise the sidebar without changing the DB.
     */
    public static function sidebarCategoryOverrides(): array
    {
        return [
            // Move messagerie & notifications from navigation -> communication
            'messagerie'      => 'communication',
            'notifications'   => 'communication',
            // Move infirmerie from etablissement -> sante
            'infirmerie'      => 'sante',
            // Move vie_associative from etablissement -> systeme (outils)
            'vie_associative' => 'systeme',
        ];
    }

    /**
     * Get the route URL for a module.
     * Reads route_path from DB (populated by ModuleSDK::syncModule from module.json).
     * Falls back to convention: {key}/{key}.php.
     */
    public function getRoute(string $moduleKey): string
    {
        $module = $this->get($moduleKey);
        if ($module !== null && !empty($module['route_path'])) {
            return $module['route_path'];
        }
        // Fallback : nouvelle structure modulaire (modules/<key>/<key>.php).
        return 'modules/' . $moduleKey . '/' . $moduleKey . '.php';
    }

    /**
     * Check if a module should be visible for a given role.
     * Uses the roles_autorises column from DB (editable via admin UI,
     * populated initially by ModuleSDK::syncModule from module.json permissions).
     * If no role restriction is set, the module is visible to all roles.
     */
    public function isVisibleForRole(string $moduleKey, string $role): bool
    {
        return $this->isVisibleForRoles($moduleKey, [$role]);
    }

    /**
     * Visibilité d'un module pour un ENSEMBLE de rôles effectifs (type de base +
     * rôles attribués). Un module est visible si l'un quelconque des rôles est
     * autorisé. super_admin voit tout. Sans restriction configurée → visible à tous.
     *
     * @param string[] $roles rôles effectifs (cf. getEffectiveRoles())
     */
    public function isVisibleForRoles(string $moduleKey, array $roles): bool
    {
        if (in_array('super_admin', $roles, true)) {
            return true;
        }

        $module = $this->get($moduleKey);

        if ($module !== null && isset($module['roles_autorises'])) {
            $rolesDb = is_array($module['roles_autorises'])
                ? $module['roles_autorises']
                : json_decode($module['roles_autorises'], true);
            if (is_array($rolesDb) && count($rolesDb) > 0) {
                return (bool) array_intersect($roles, $rolesDb);
            }
        }

        // No restriction configured — visible to all
        return true;
    }

    /** Rôles considérés comme gestionnaires (accès à la catégorie « administration »). */
    private const MANAGER_ROLES = ['administrateur', 'direction', 'chef_etablissement', 'direction_adjointe', 'responsable_permissions'];

    /**
     * L'utilisateur peut-il RÉELLEMENT ouvrir ce module (pour ne pas afficher un lien mort) ?
     * Complète isVisibleForRoles (fail-open car roles_autorises souvent NULL) :
     *  - la catégorie « administration » (admin/personnel/rgpd) est réservée aux gestionnaires ;
     *  - si le module déclare une permission d'accès module.<clé>.access, on la respecte
     *    (même vérité que requireCapability sur la page).
     */
    private function navAccessAllowed(string $moduleKey, string $category, array $roles): bool
    {
        if (in_array('super_admin', $roles, true)) {
            return true;
        }
        if ($category === 'administration') {
            return (bool) array_intersect($roles, self::MANAGER_ROLES);
        }
        $perm = 'module.' . $moduleKey . '.access';
        try {
            if (array_key_exists($perm, \API\Security\RoleCatalog::permissions())) {
                return (bool) app('authz')->can($perm);
            }
        } catch (\Throwable $e) {
            // authz indisponible au moment du build nav → ne pas masquer (comportement historique).
        }
        return true;
    }

    /**
     * Returns modules grouped by category for sidebar display.
     * Only includes enabled modules visible to the given role.
     * Skips 'accueil' and 'parametres' (handled separately in sidebar template).
     *
     * @return array<string, array> Keyed by category
     */
    public function getForSidebar(array|string $role): array
    {
        $roles = is_array($role) ? array_values($role) : [$role];
        $all = $this->getAll();
        $grouped = [];
        $categoryMeta = self::categoryMeta();
        $catOverrides = self::sidebarCategoryOverrides();

        foreach ($all as $key => $mod) {
            if (empty($mod['enabled'])) continue;
            if (!empty($mod['sidebar_hidden'])) continue; // manifest sidebar.hidden = true
            if (!$this->isVisibleForRoles($key, $roles)) continue;
            if (in_array($key, ['accueil', 'parametres'])) continue;

            // Apply sidebar category override if defined, otherwise use DB category
            $cat = $catOverrides[$key] ?? ($mod['category'] ?? 'general');
            $mod['route'] = $this->getRoute($key);
            $mod['module_key'] = $key;
            $grouped[$cat][] = $mod;
        }

        // Sort categories by meta order
        uksort($grouped, function ($a, $b) use ($categoryMeta) {
            $oa = $categoryMeta[$a]['order'] ?? 99;
            $ob = $categoryMeta[$b]['order'] ?? 99;
            return $oa <=> $ob;
        });

        // Sort modules within each category by sort_order
        foreach ($grouped as &$modules) {
            usort($modules, fn($a, $b) => ($a['sort_order'] ?? 100) <=> ($b['sort_order'] ?? 100));
        }

        return $grouped;
    }

    /**
     * Returns modules grouped by topbar category for horizontal navigation.
     * Uses topbar_category from DB (or falls back to category mapping).
     *
     * @return array<string, array{label: string, icon: string, modules: array}>
     */
    /**
     * Auto-heal : ajoute topbar_category et topbar_sort_order si absents (bases antérieures à leur introduction).
     */
    private bool $topbarColsEnsured = false;
    private function ensureTopbarColumns(): void
    {
        if ($this->topbarColsEnsured) return;
        $this->topbarColsEnsured = true;
        // Sentinelle PERSISTANTE (APCu) : une fois les colonnes garanties, éviter le
        // SHOW COLUMNS à CHAQUE requête (introspection sur le chemin chaud du topbar).
        // Repli sur la mémoïsation per-request si APCu indisponible.
        if (function_exists('apcu_fetch')) {
            $ok = false;
            apcu_fetch('fronote_topbar_cols_ok', $ok);
            if ($ok === true) return;
        }
        // On vérifie l'existence des colonnes AVANT d'altérer : "ADD COLUMN IF NOT EXISTS"
        // n'est pas supporté par toutes les versions MySQL/MariaDB (erreur de syntaxe à
        // chaque page sinon).
        $wanted = [
            'topbar_category'   => "ADD COLUMN `topbar_category` VARCHAR(50) DEFAULT NULL",
            'topbar_sort_order' => "ADD COLUMN `topbar_sort_order` INT NOT NULL DEFAULT 50",
        ];
        try {
            $existing = $this->pdo->query("SHOW COLUMNS FROM `modules_config`")->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            error_log('[ModuleService] ensureTopbarColumns SHOW COLUMNS: ' . $e->getMessage());
            return;
        }
        foreach ($wanted as $col => $clause) {
            if (in_array($col, $existing, true)) continue;
            try {
                $this->pdo->exec("ALTER TABLE `modules_config` {$clause}");
            } catch (\Throwable $e) {
                error_log('[ModuleService] ensureTopbarColumns ALTER ' . $col . ': ' . $e->getMessage());
            }
        }
        // Colonnes garanties : mémoriser de façon persistante (1 h) pour court-circuiter
        // le SHOW COLUMNS des prochaines requêtes.
        if (function_exists('apcu_store')) {
            apcu_store('fronote_topbar_cols_ok', true, 3600);
        }
    }

    public function getForTopbar(array|string $role): array
    {
        $roles = is_array($role) ? array_values($role) : [$role];
        $this->ensureTopbarColumns();
        $all = $this->getAll();
        $categoryMeta = self::categoryMeta();
        $catOverrides = self::sidebarCategoryOverrides();

        // Topbar category labels (display names for dropdown headers)
        $topbarLabels = [
            'scolaire'      => 'Pédagogie',
            'vie_scolaire'  => 'Vie scol.',
            'communication' => 'Communication',
            'sante'         => 'Santé',
            'etablissement' => 'Établissement',
            'logistique'    => 'Logistique',
            'systeme'       => 'Outils',
            'general'       => 'Modules',
        ];

        $grouped = [];

        foreach ($all as $key => $mod) {
            if (empty($mod['enabled'])) continue;
            if (!$this->isVisibleForRoles($key, $roles)) continue;
            if (!empty($mod['sidebar_hidden'])) continue; // module masqué/headless : pas un item de nav cliquable
            if (in_array($key, ['accueil', 'parametres', 'profil', 'notifications'])) continue;

            // Determine category: topbar_category (DB) > override > category (DB)
            $cat = $mod['topbar_category'] ?? $catOverrides[$key] ?? ($mod['category'] ?? 'systeme');
            if ($cat === 'navigation') continue; // Skip navigation items (handled separately)
            // Les catégories 'systeme' et 'outils' portaient toutes deux le libellé « Outils »
            // → DEUX menus « Outils » identiques. On les fusionne en un seul.
            if ($cat === 'outils') { $cat = 'systeme'; }

            // Aligner la nav sur l'accès RÉEL des pages : ne pas afficher un lien que
            // l'utilisateur ne peut pas ouvrir (les pages redirigent en silence → liens en
            // cul-de-sac). roles_autorises étant souvent NULL, isVisibleForRoles est fail-open ;
            // on complète avec (1) la catégorie « administration » réservée aux gestionnaires
            // et (2) la permission d'accès module.<clé>.access quand elle est définie.
            if (!$this->navAccessAllowed($key, $cat, $roles)) continue;

            $mod['route'] = $this->getRoute($key);
            $mod['module_key'] = $key;

            if (!isset($grouped[$cat])) {
                $meta = $categoryMeta[$cat] ?? ['label' => ucfirst($cat), 'icon' => 'fas fa-folder', 'order' => 99];
                $grouped[$cat] = [
                    'label' => $topbarLabels[$cat] ?? $meta['label'],
                    'icon'  => $meta['icon'],
                    'order' => $meta['order'],
                    'modules' => [],
                ];
            }

            $grouped[$cat]['modules'][] = $mod;
        }

        // Sort categories by order
        uasort($grouped, fn($a, $b) => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));

        // Sort modules within each category
        foreach ($grouped as &$group) {
            usort($group['modules'], function ($a, $b) {
                $oa = $a['topbar_sort_order'] ?? $a['sort_order'] ?? 100;
                $ob = $b['topbar_sort_order'] ?? $b['sort_order'] ?? 100;
                return $oa <=> $ob;
            });
        }

        return $grouped;
    }

    /**
     * Returns a flat list of ALL modules with route info (for admin management).
     */
    public function getAllWithRoutes(): array
    {
        $all = $this->getAll();
        foreach ($all as $key => &$mod) {
            $mod['route'] = $this->getRoute($key);
        }
        return $all;
    }

    // ─── Favoris utilisateur ─────────────────────────────────────────────

    /**
     * Clés des modules épinglés par l'utilisateur, dans l'ordre.
     *
     * @return string[]
     */
    public function getFavoriteKeys(int $userId, string $userType): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT module_key FROM user_favorites
                 WHERE user_id = ? AND user_type = ?
                 ORDER BY position, id"
            );
            $stmt->execute([$userId, $userType]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\PDOException $e) {
            error_log("ModuleService::getFavoriteKeys error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Modules épinglés, résolus (route/icon/label) et filtrés par état activé
     * + visibilité de rôle. Conserve l'ordre des favoris.
     */
    public function getFavorites(int $userId, string $userType, string $role): array
    {
        $this->ensureFavoritesColumns();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT module_key, target_type, target_url, label, icon
                 FROM user_favorites WHERE user_id = ? AND user_type = ? ORDER BY position, id"
            );
            $stmt->execute([$userId, $userType]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            error_log("ModuleService::getFavorites error: " . $e->getMessage());
            return [];
        }

        $all = $this->getAll();
        $favorites = [];
        foreach ($rows as $row) {
            if (($row['target_type'] ?? 'module') === 'page') {
                // Favori de page : métadonnées stockées, URL interne déjà absolue.
                $favorites[] = [
                    'module_key' => $row['module_key'],
                    'label'      => $row['label'] ?: 'Page',
                    'icon'       => $row['icon'] ?: 'fas fa-bookmark',
                    'route'      => $row['target_url'] ?? '#',
                    'type'       => 'page',
                ];
                continue;
            }
            $key = $row['module_key'];
            if (!isset($all[$key])) continue;
            $mod = $all[$key];
            if (empty($mod['enabled'])) continue;
            if (!$this->isVisibleForRole($key, $role)) continue;
            $mod['route'] = $this->getRoute($key);
            $mod['module_key'] = $key;
            $mod['type'] = 'module';
            $favorites[] = $mod;
        }
        return $favorites;
    }

    /**
     * Garantit la présence des colonnes étendues de user_favorites
     * (auto-réparation des bases antérieures à l'ajout des favoris de page).
     */
    private bool $favColsEnsured = false;
    private function ensureFavoritesColumns(): void
    {
        if ($this->favColsEnsured) {
            return;
        }
        $this->favColsEnsured = true;
        // Colonnes à garantir (nom => clause ADD). On teste la présence via
        // information_schema AVANT d'altérer : évite d'exécuter un DDL à chaque requête
        // et de logger une erreur "Duplicate column" en boucle (bruit + coût).
        $columns = [
            'target_type' => "ADD COLUMN `target_type` ENUM('module','page') NOT NULL DEFAULT 'module'",
            'target_url'  => "ADD COLUMN `target_url` VARCHAR(255) NULL",
            'label'       => "ADD COLUMN `label` VARCHAR(150) NULL",
            'icon'        => "ADD COLUMN `icon` VARCHAR(64) NULL",
        ];
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_favorites'"
            );
            $stmt->execute();
            $existing = array_map('strtolower', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        } catch (\Throwable $e) {
            // Impossible de lire le schéma → on n'altère pas (pas de DDL aveugle).
            return;
        }
        $missing = [];
        foreach ($columns as $name => $clause) {
            if (!in_array(strtolower($name), $existing, true)) {
                $missing[] = $clause;
            }
        }
        if (!$missing) {
            return; // rien à faire (cas nominal) — aucun DDL, aucun log.
        }
        try {
            $this->pdo->exec('ALTER TABLE `user_favorites` ' . implode(', ', $missing));
        } catch (\Throwable $e) {
            error_log('[ModuleService.php] ensureFavoritesColumns: ' . $e->getMessage());
        }
    }

    /**
     * Épingle une page arbitraire (chemin app interne). Idempotent.
     */
    public function addPageFavorite(int $userId, string $userType, string $url, string $label, string $icon = 'fas fa-bookmark'): bool
    {
        $this->ensureFavoritesColumns();
        $url = trim($url);
        // N'accepter qu'un chemin interne absolu (pas d'URL externe ni de schéma).
        if ($url === '' || !preg_match('#^/[A-Za-z0-9_\-./?=&%,]*$#', $url)) {
            return false;
        }
        $key = 'page:' . sha1($url);
        $label = mb_substr(trim($label) !== '' ? trim($label) : $url, 0, 150);
        if (!preg_match('/^[a-z0-9 _-]{1,64}$/i', $icon)) {
            $icon = 'fas fa-bookmark';
        }
        try {
            $chk = $this->pdo->prepare("SELECT 1 FROM user_favorites WHERE user_id = ? AND user_type = ? AND module_key = ? LIMIT 1");
            $chk->execute([$userId, $userType, $key]);
            if ($chk->fetchColumn()) {
                return true; // déjà épinglé
            }
            $posStmt = $this->pdo->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM user_favorites WHERE user_id = ? AND user_type = ?");
            $posStmt->execute([$userId, $userType]);
            $position = (int) $posStmt->fetchColumn();

            $stmt = $this->pdo->prepare(
                "INSERT INTO user_favorites (user_id, user_type, module_key, target_type, target_url, label, icon, position)
                 VALUES (?, ?, ?, 'page', ?, ?, ?, ?)"
            );
            $stmt->execute([$userId, $userType, $key, $url, $label, $icon, $position]);
            return true;
        } catch (\PDOException $e) {
            error_log("ModuleService::addPageFavorite error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Retire un favori (module ou page) par sa clé.
     */
    public function removeFavorite(int $userId, string $userType, string $key): bool
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM user_favorites WHERE user_id = ? AND user_type = ? AND module_key = ?");
            $stmt->execute([$userId, $userType, $key]);
            return true;
        } catch (\PDOException $e) {
            error_log("ModuleService::removeFavorite error: " . $e->getMessage());
            return false;
        }
    }

    public function isFavorite(int $userId, string $userType, string $key): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM user_favorites WHERE user_id = ? AND user_type = ? AND module_key = ? LIMIT 1"
            );
            $stmt->execute([$userId, $userType, $key]);
            return (bool) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Épingle/désépingle un module. Retourne le nouvel état (true = épinglé).
     * Refuse les clés de module inconnues.
     */
    public function toggleFavorite(int $userId, string $userType, string $key): bool
    {
        if ($this->get($key) === null) {
            return false;
        }
        try {
            if ($this->isFavorite($userId, $userType, $key)) {
                $stmt = $this->pdo->prepare(
                    "DELETE FROM user_favorites WHERE user_id = ? AND user_type = ? AND module_key = ?"
                );
                $stmt->execute([$userId, $userType, $key]);
                return false;
            }
            $posStmt = $this->pdo->prepare(
                "SELECT COALESCE(MAX(position), -1) + 1 FROM user_favorites WHERE user_id = ? AND user_type = ?"
            );
            $posStmt->execute([$userId, $userType]);
            $position = (int) $posStmt->fetchColumn();

            $stmt = $this->pdo->prepare(
                "INSERT INTO user_favorites (user_id, user_type, module_key, position)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$userId, $userType, $key, $position]);
            return true;
        } catch (\PDOException $e) {
            error_log("ModuleService::toggleFavorite error: " . $e->getMessage());
            return $this->isFavorite($userId, $userType, $key);
        }
    }

    /**
     * Réordonne les favoris selon la liste de clés fournie.
     */
    public function reorderFavorites(int $userId, string $userType, array $orderedKeys): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE user_favorites SET position = ? WHERE user_id = ? AND user_type = ? AND module_key = ?"
            );
            $pos = 0;
            foreach ($orderedKeys as $key) {
                $stmt->execute([$pos++, $userId, $userType, (string) $key]);
            }
            return true;
        } catch (\PDOException $e) {
            error_log("ModuleService::reorderFavorites error: " . $e->getMessage());
            return false;
        }
    }
}
