<?php
/**
 * Module SDK — Découverte, validation et gestion des modules via module.json
 *
 * Scanne les dossiers du projet pour trouver les fichiers module.json,
 * valide leur structure, synchronise avec la base de données (modules_config,
 * dashboard_widgets, module_permissions) et gère le cycle de vie des modules.
 *
 * Cycle de vie : discover → validate → install → enable → boot → disable → uninstall
 */

namespace API\Services;

class ModuleSDK
{
    private \PDO $pdo;
    private string $basePath;

    /** @var array|null Cache des manifestes découverts */
    private ?array $manifests = null;

    /** Vrai une fois les colonnes étendues de module_migrations garanties. */
    private bool $migColsEnsured = false;

    /** Champs obligatoires dans module.json */
    private const REQUIRED_FIELDS = ['key', 'name', 'icon', 'category'];

    /** Catégories valides */
    private const VALID_CATEGORIES = [
        'navigation', 'scolaire', 'vie_scolaire', 'communication',
        'etablissement', 'logistique', 'systeme', 'sante', 'custom'
    ];

    public function __construct(\PDO $pdo, string $basePath)
    {
        $this->pdo = $pdo;
        $this->basePath = rtrim($basePath, '/\\');
    }

    /**
     * Découvre tous les modules ayant un module.json
     *
     * @return array<string, array> [module_key => manifest]
     */
    public function discover(): array
    {
        if ($this->manifests !== null) {
            return $this->manifests;
        }

        $this->manifests = [];
        // Modules dans /modules/<m>/ (nouvelle structure) + modules essentiels restés à la racine.
        $dirs = array_merge(
            glob($this->basePath . '/modules/*/module.json') ?: [],
            glob($this->basePath . '/*/module.json') ?: []
        );

        foreach ($dirs as $jsonPath) {
            $content = file_get_contents($jsonPath);
            $manifest = json_decode($content, true);

            if (!is_array($manifest) || empty($manifest['key'])) {
                error_log("ModuleSDK: Invalid module.json at {$jsonPath}");
                continue;
            }

            $manifest['_path'] = dirname($jsonPath);
            $manifest['_json_path'] = $jsonPath;
            $this->manifests[$manifest['key']] = $manifest;
        }

        return $this->manifests;
    }

    /**
     * Valide la structure d'un manifeste module.json
     *
     * @return array ['valid' => bool, 'errors' => string[]]
     */
    public function validate(array $manifest): array
    {
        $errors = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            if (empty($manifest[$field])) {
                $errors[] = "Champ obligatoire manquant : {$field}";
            }
        }

        // Valider le key (alphanumérique + underscore)
        if (!empty($manifest['key']) && !preg_match('/^[a-z][a-z0-9_]*$/', $manifest['key'])) {
            $errors[] = "Le key du module doit être en minuscules, alphanumérique avec underscores";
        }

        // Valider la catégorie
        if (!empty($manifest['category']) && !in_array($manifest['category'], self::VALID_CATEGORIES, true)) {
            $errors[] = "Catégorie invalide : {$manifest['category']}. Valides : " . implode(', ', self::VALID_CATEGORIES);
        }

        // Valider name (doit être un objet avec au moins 'fr')
        if (isset($manifest['name']) && is_array($manifest['name'])) {
            if (empty($manifest['name']['fr'])) {
                $errors[] = "Le champ name doit contenir au moins la clé 'fr'";
            }
        }

        // Valider les widgets
        if (!empty($manifest['widgets'])) {
            foreach ($manifest['widgets'] as $i => $widget) {
                if (empty($widget['key'])) {
                    $errors[] = "Widget #{$i} : champ 'key' manquant";
                }
                if (empty($widget['name'])) {
                    $errors[] = "Widget #{$i} : champ 'name' manquant";
                }
            }
        }

        // Valider les permissions
        if (!empty($manifest['permissions'])) {
            foreach ($manifest['permissions'] as $action => $config) {
                if (!is_array($config) || !isset($config['default_roles'])) {
                    $errors[] = "Permission '{$action}' : doit contenir 'default_roles'";
                }
            }
        }

        // Valider establishment_types
        if (isset($manifest['establishment_types']) && !is_array($manifest['establishment_types'])) {
            $errors[] = "establishment_types doit être un tableau";
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    /**
     * Synchronise tous les manifestes découverts avec la base de données.
     * Met à jour modules_config, dashboard_widgets et module_permissions.
     *
     * @return array ['synced' => int, 'errors' => string[]]
     */
    public function syncAll(): array
    {
        $manifests = $this->discover();
        $synced = 0;
        $errors = [];

        foreach ($manifests as $key => $manifest) {
            $validation = $this->validate($manifest);
            if (!$validation['valid']) {
                $errors[] = "Module '{$key}' invalide : " . implode(', ', $validation['errors']);
                continue;
            }

            try {
                $this->syncModule($manifest);
                $synced++;
            } catch (\Throwable $e) {
                $errors[] = "Module '{$key}' : " . $e->getMessage();
            }
        }

        return ['synced' => $synced, 'errors' => $errors];
    }

    /**
     * Synchronise un module individuel avec la base de données.
     */
    public function syncModule(array $manifest): void
    {
        $key = $manifest['key'];
        $name = is_array($manifest['name']) ? ($manifest['name']['fr'] ?? $key) : ($manifest['name'] ?? $key);
        $description = '';
        if (isset($manifest['description'])) {
            $description = is_array($manifest['description'])
                ? ($manifest['description']['fr'] ?? '')
                : $manifest['description'];
        }

        // Resolve route_path from module.json routes.main, relative au dossier réel
        // du module (modules/<key>/… ou <key>/… pour les essentiels restés à la racine).
        $routePath = null;
        if (!empty($manifest['routes']['main'])) {
            $relDir = $key;
            if (!empty($manifest['_path'])) {
                $relDir = trim(str_replace('\\', '/', substr($manifest['_path'], strlen($this->basePath))), '/');
            }
            $routePath = $relDir . '/' . $manifest['routes']['main'];
        }

        // Resolve sidebar_sort from module.json sidebar.sort_order
        $sidebarSort = $manifest['sidebar']['sort_order'] ?? 100;

        $isCore = !empty($manifest['core']) ? 1 : 0;

        // Upsert dans modules_config.
        // enabled : à la 1re insertion, core = activé, autres = désactivés (activation manuelle
        // via l'admin qui injecte+vérifie le SQL). ON DUPLICATE ne TOUCHE PAS enabled (préserve le choix admin).
        $sql = "INSERT INTO modules_config (module_key, label, description, icon, category, is_core, enabled, establishment_types, route_path, sidebar_sort)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    label = VALUES(label),
                    description = VALUES(description),
                    icon = VALUES(icon),
                    category = VALUES(category),
                    establishment_types = VALUES(establishment_types),
                    route_path = COALESCE(VALUES(route_path), route_path),
                    sidebar_sort = VALUES(sidebar_sort)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $key,
            $name,
            $description,
            $manifest['icon'] ?? 'fas fa-puzzle-piece',
            $manifest['category'] ?? 'custom',
            $isCore,
            $isCore, // enabled = is_core à l'insertion
            isset($manifest['establishment_types']) ? json_encode($manifest['establishment_types']) : null,
            $routePath,
            (int) $sidebarSort,
        ]);

        // Synchroniser les widgets
        if (!empty($manifest['widgets'])) {
            $this->syncWidgets($key, $manifest['widgets']);
        }

        // Synchroniser les settings_schema depuis module.json
        if (!empty($manifest['settings_schema'])) {
            $this->syncSettingsSchema($key, $manifest['settings_schema']);
        }

        // Synchroniser les permissions
        if (!empty($manifest['permissions'])) {
            $this->syncPermissions($key, $manifest['permissions']);
        }
    }

    /**
     * Synchronise les widgets d'un module avec dashboard_widgets
     */
    private function syncWidgets(string $moduleKey, array $widgets): void
    {
        foreach ($widgets as $widget) {
            $widgetKey = $widget['key'];
            $label = is_array($widget['name']) ? ($widget['name']['fr'] ?? $widgetKey) : ($widget['name'] ?? $widgetKey);
            $description = '';
            if (isset($widget['description'])) {
                $description = is_array($widget['description'])
                    ? ($widget['description']['fr'] ?? '')
                    : $widget['description'];
            }

            $sql = "INSERT INTO dashboard_widgets (widget_key, label, description, icon, type, module_key, roles_autorises, default_width, is_default, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        label = VALUES(label),
                        description = VALUES(description),
                        icon = VALUES(icon),
                        type = VALUES(type),
                        roles_autorises = VALUES(roles_autorises),
                        default_width = VALUES(default_width)";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $widgetKey,
                $label,
                $description,
                $widget['icon'] ?? 'fas fa-th',
                $widget['type'] ?? 'list',
                $moduleKey,
                !empty($widget['roles']) ? json_encode($widget['roles']) : null,
                $widget['default_size']['width'] ?? 2,
                !empty($widget['is_default']) ? 1 : 0,
                $widget['sort_order'] ?? 50,
            ]);
        }
    }

    /**
     * Synchronise les permissions d'un module avec module_permissions
     */
    private function syncPermissions(string $moduleKey, array $permissions): void
    {
        foreach ($permissions as $action => $config) {
            $defaultRoles = $config['default_roles'] ?? [];

            // Vérifier si la table module_permissions existe
            try {
                $sql = "INSERT INTO module_permissions (module_key, action_key, default_roles)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            default_roles = VALUES(default_roles)";

                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    $moduleKey,
                    $action,
                    json_encode($defaultRoles),
                ]);
            } catch (\Throwable $e) {
                // Table peut ne pas exister encore — silencieux
                error_log("ModuleSDK: Cannot sync permission {$moduleKey}.{$action}: " . $e->getMessage());
            }
        }
    }

    /**
     * Récupère le manifeste d'un module par sa clé.
     */
    public function getManifest(string $key): ?array
    {
        $manifests = $this->discover();
        return $manifests[$key] ?? null;
    }

    /**
     * Retourne la configuration des widgets d'un module.
     * Utilisé par le DashboardService pour résoudre les data_providers.
     *
     * @return array<string, array> [widget_key => widget_config]
     */
    public function getWidgetConfigs(string $moduleKey): array
    {
        $manifest = $this->getManifest($moduleKey);
        if (!$manifest || empty($manifest['widgets'])) {
            return [];
        }

        $result = [];
        foreach ($manifest['widgets'] as $widget) {
            $widget['_module_path'] = $manifest['_path'] ?? '';
            $result[$widget['key']] = $widget;
        }
        return $result;
    }

    /**
     * Retourne toutes les configurations de widgets de tous les modules.
     *
     * @return array<string, array> [widget_key => widget_config_with_module_info]
     */
    public function getAllWidgetConfigs(): array
    {
        $manifests = $this->discover();
        $result = [];

        foreach ($manifests as $moduleKey => $manifest) {
            if (empty($manifest['widgets'])) {
                continue;
            }
            foreach ($manifest['widgets'] as $widget) {
                $widget['_module_key'] = $moduleKey;
                $widget['_module_path'] = $manifest['_path'] ?? '';
                $result[$widget['key']] = $widget;
            }
        }

        return $result;
    }

    /**
     * Résout et instancie le WidgetDataProvider d'un widget.
     *
     * @return \API\Contracts\WidgetDataProvider|null
     */
    public function resolveWidgetProvider(string $widgetKey): ?\API\Contracts\WidgetDataProvider
    {
        $allWidgets = $this->getAllWidgetConfigs();
        $config = $allWidgets[$widgetKey] ?? null;

        if (!$config || empty($config['data_provider'])) {
            return null;
        }

        $modulePath = $config['_module_path'] ?? '';
        $providerPath = $modulePath . '/' . $config['data_provider'];

        if (!file_exists($providerPath)) {
            error_log("ModuleSDK: Widget provider not found at {$providerPath}");
            return null;
        }

        // Lire le fichier pour extraire le namespace + classe
        $fileContent = file_get_contents($providerPath);
        $namespace = '';
        if (preg_match('/namespace\s+([^;]+);/', $fileContent, $nsMatch)) {
            $namespace = trim($nsMatch[1]) . '\\';
        }
        $shortName = pathinfo($config['data_provider'], PATHINFO_FILENAME);

        require_once $providerPath;

        // Essayer avec namespace puis sans
        $className = $namespace . $shortName;
        if (!class_exists($className)) {
            $className = $shortName; // Fallback sans namespace
        }
        if (!class_exists($className)) {
            error_log("ModuleSDK: Class '{$shortName}' not found in {$providerPath}");
            return null;
        }

        $instance = new $className();

        if (!($instance instanceof \API\Contracts\WidgetDataProvider)) {
            error_log("ModuleSDK: Class '{$className}' does not implement WidgetDataProvider");
            return null;
        }

        return $instance;
    }

    /**
     * Résout le chemin du template d'un widget.
     */
    public function resolveWidgetTemplate(string $widgetKey): ?string
    {
        $allWidgets = $this->getAllWidgetConfigs();
        $config = $allWidgets[$widgetKey] ?? null;

        if (!$config || empty($config['template'])) {
            return null;
        }

        $modulePath = $config['_module_path'] ?? '';
        $templatePath = $modulePath . '/' . $config['template'];

        if (!file_exists($templatePath)) {
            return null;
        }

        return $templatePath;
    }

    /**
     * Exécute les migrations SQL déclarées dans module.json sous la clé "migrations".
     * Chaque migration est un fichier .sql relatif au répertoire du module.
     * Suivi dans la table module_migrations pour n'exécuter chaque fichier qu'une fois.
     *
     * @param string $moduleKey Clé du module (ex: 'absences')
     * @return array ['executed' => string[], 'skipped' => string[], 'errors' => string[]]
     */
    public function migrate(string $moduleKey, string $triggeredBy = 'system'): array
    {
        $manifest = $this->getManifest($moduleKey);
        if (!$manifest) {
            return ['executed' => [], 'skipped' => [], 'errors' => ["Module '{$moduleKey}' introuvable"]];
        }

        $this->ensureMigrationsColumns();
        $migrations = $manifest['migrations'] ?? [];
        if (empty($migrations)) {
            return ['executed' => [], 'skipped' => [], 'errors' => []];
        }

        $modulePath = $manifest['_path'] ?? '';
        $executed   = [];
        $skipped    = [];
        $errors     = [];

        foreach ($migrations as $migrationFile) {
            $version = preg_match('/(\d+\.\d+\.\d+)/', basename($migrationFile), $m)
                ? $m[1] : ($manifest['version'] ?? null);

            // Vérifier si déjà exécutée AVEC SUCCÈS (une exécution échouée doit pouvoir être rejouée)
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM module_migrations WHERE module_key = ? AND migration_file = ? AND status = 'success'"
                );
                $stmt->execute([$moduleKey, $migrationFile]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $skipped[] = $migrationFile;
                    continue;
                }
            } catch (\Throwable $e) {
                $errors[] = "Impossible de vérifier module_migrations : " . $e->getMessage();
                continue;
            }

            // Lire le fichier SQL
            $sqlPath = $modulePath . '/' . $migrationFile;
            if (!file_exists($sqlPath)) {
                $errors[] = "Fichier introuvable : {$sqlPath}";
                continue;
            }

            $sql = file_get_contents($sqlPath);
            if ($sql === false || trim($sql) === '') {
                $errors[] = "Fichier vide ou illisible : {$migrationFile}";
                continue;
            }

            $checksum = hash('sha256', $sql);
            $start = microtime(true);

            // Exécuter dans une transaction
            try {
                $this->pdo->beginTransaction();
                $this->pdo->exec($sql);
                $this->pdo->commit();

                $ms = (int) round((microtime(true) - $start) * 1000);
                $this->recordMigration($moduleKey, $migrationFile, $version, $checksum, 'success', null, $ms, $triggeredBy);
                $executed[] = $migrationFile;
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $ms = (int) round((microtime(true) - $start) * 1000);
                // Tracer l'échec (hors transaction annulée) pour qu'il soit visible en admin.
                $this->recordMigration($moduleKey, $migrationFile, $version, $checksum, 'failed', $e->getMessage(), $ms, $triggeredBy);
                $errors[] = "Échec migration '{$migrationFile}' : " . $e->getMessage();
            }
        }

        return ['executed' => $executed, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Provisionne le SQL d'un module : exécute son install.sql (idempotent) puis
     * ses migrations. Appelé À L'ACTIVATION du module. Le résultat indique si le
     * SQL a été injecté et vérifié sans erreur.
     *
     * @return array ['success' => bool, 'errors' => string[]]
     */
    public function provisionSql(string $moduleKey): array
    {
        $manifest = $this->getManifest($moduleKey);
        if (!$manifest) {
            return ['success' => false, 'errors' => ["Module '{$moduleKey}' introuvable"]];
        }

        $errors = [];
        $modulePath = $manifest['_path'] ?? '';

        // 1) install.sql du module (déclaré dans module.json: database.install, sinon défaut).
        $installRel = $manifest['database']['install'] ?? 'Database/install.sql';
        $installPath = $modulePath . '/' . $installRel;
        if ($modulePath && is_file($installPath)) {
            $sql = (string) @file_get_contents($installPath);
            if (trim($sql) !== '') {
                try {
                    $this->pdo->beginTransaction();
                    $this->pdo->exec($sql);
                    $this->pdo->commit();
                } catch (\Throwable $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    $errors[] = "install.sql : " . $e->getMessage();
                }
            }
        }

        // 2) migrations incrémentales (idempotentes, tracées dans module_migrations).
        $mig = $this->migrate($moduleKey, 'activation');
        $errors = array_merge($errors, $mig['errors']);

        return ['success' => empty($errors), 'errors' => $errors];
    }

    /**
     * Upsert d'une ligne de suivi de migration (succès ou échec).
     */
    private function recordMigration(
        string $moduleKey, string $file, ?string $version, ?string $checksum,
        string $status, ?string $error, int $ms, string $triggeredBy
    ): void {
        try {
            $this->pdo->prepare(
                "INSERT INTO module_migrations
                    (module_key, migration_file, migration_version, checksum, status, error_message, execution_time_ms, triggered_by, executed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    migration_version = VALUES(migration_version),
                    checksum          = VALUES(checksum),
                    status            = VALUES(status),
                    error_message     = VALUES(error_message),
                    execution_time_ms = VALUES(execution_time_ms),
                    triggered_by      = VALUES(triggered_by),
                    executed_at       = NOW()"
            )->execute([$moduleKey, $file, $version, $checksum, $status, $error, $ms, $triggeredBy]);
        } catch (\Throwable $e) {
            error_log('ModuleSDK::recordMigration: ' . $e->getMessage());
        }
    }

    /**
     * Garantit la présence des colonnes étendues de module_migrations
     * (auto-réparation des bases installées avant l'enrichissement du schéma).
     */
    private function ensureMigrationsColumns(): void
    {
        if ($this->migColsEnsured) {
            return;
        }
        $this->migColsEnsured = true;

        $columns = [
            "ADD COLUMN `migration_version` VARCHAR(50) NULL",
            "ADD COLUMN `checksum` VARCHAR(64) NULL",
            "ADD COLUMN `status` ENUM('success','failed','rolled_back') NOT NULL DEFAULT 'success'",
            "ADD COLUMN `error_message` TEXT NULL",
            "ADD COLUMN `execution_time_ms` INT NULL",
            "ADD COLUMN `triggered_by` VARCHAR(100) NULL",
        ];
        foreach ($columns as $clause) {
            try {
                $this->pdo->exec("ALTER TABLE `module_migrations` {$clause}");
            } catch (\Throwable $e) {
                // Colonne déjà présente (installation récente) → ignorer.
            }
        }
    }

    /**
     * Historique des migrations (toutes ou filtrées par module), plus récentes d'abord.
     */
    public function getMigrations(?string $moduleKey = null, int $limit = 100): array
    {
        try {
            $this->ensureMigrationsColumns();
            if ($moduleKey !== null) {
                $stmt = $this->pdo->prepare(
                    "SELECT * FROM module_migrations WHERE module_key = ? ORDER BY executed_at DESC, id DESC LIMIT ?"
                );
                $stmt->bindValue(1, $moduleKey);
                $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
            } else {
                $stmt = $this->pdo->prepare(
                    "SELECT * FROM module_migrations ORDER BY executed_at DESC, id DESC LIMIT ?"
                );
                $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
            }
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Synchronise les settings_schema d'un module depuis module.json vers la table module_settings_schema.
     */
    private function syncSettingsSchema(string $moduleKey, array $schema): void
    {
        $sortOrder = 0;
        foreach ($schema as $fieldKey => $fieldDef) {
            try {
                $sql = "INSERT INTO module_settings_schema (module_key, field_key, field_type, label, default_value, options, hint, sort_order)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            field_type = VALUES(field_type),
                            label = VALUES(label),
                            default_value = VALUES(default_value),
                            options = VALUES(options),
                            hint = VALUES(hint),
                            sort_order = VALUES(sort_order)";

                $this->pdo->prepare($sql)->execute([
                    $moduleKey,
                    $fieldKey,
                    $fieldDef['type'] ?? 'text',
                    $fieldDef['label'] ?? $fieldKey,
                    $fieldDef['default'] ?? null,
                    isset($fieldDef['options']) ? json_encode($fieldDef['options']) : null,
                    $fieldDef['hint'] ?? null,
                    $sortOrder++,
                ]);
            } catch (\Throwable $e) {
                error_log("ModuleSDK: Cannot sync settings_schema {$moduleKey}.{$fieldKey}: " . $e->getMessage());
            }
        }
    }

    /**
     * Vide le cache des manifestes (après modification de fichiers).
     */
    public function clearCache(): void
    {
        $this->manifests = null;
    }
}
