<?php
declare(strict_types=1);
/**
 * Backfill de `modules_config` en mode PAR ÉTABLISSEMENT (idempotent).
 *
 * La table porte une ligne par (module_key, etablissement_id) — clé unique
 * `uk_module_etab` — mais l'historique (install + syncModule) n'écrivait qu'une
 * ligne par module (etablissement_id = défaut), si bien que des établissements se
 * retrouvaient SANS aucune ligne → nav vide une fois le service passé en tenant-aware.
 *
 * Cette migration garantit que CHAQUE établissement possède une ligne pour chaque
 * module_key connu, en copiant la ligne « modèle » (celle du plus petit
 * etablissement_id pour ce module_key). Protégée par NOT EXISTS → rejouable sans doublon
 * (la clé unique uk_module_etab est de toute façon un garde-fou).
 *
 * MySQL uniquement (SQLite de test n'a pas ces données). Échec journalisé, non bloquant.
 */
return new class {
    public function up(\PDO $pdo): void
    {
        $driver = '';
        try { $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME); } catch (\Throwable $e) {}
        if ($driver !== 'mysql') {
            return; // rien à backfiller hors MySQL
        }

        try {
            // Colonnes copiées depuis la ligne modèle (on omet id [auto] et updated_at [auto]).
            $cols = 'module_key, label, description, icon, route_path, category, enabled, '
                  . 'establishment_types, config_json, roles_autorises, sort_order, sidebar_sort, '
                  . 'is_core, sidebar_hidden, topbar_category, topbar_sort_order';

            $sql = "INSERT INTO modules_config (etablissement_id, {$cols})
                    SELECT e.id, t.module_key, t.label, t.description, t.icon, t.route_path, t.category,
                           t.enabled, t.establishment_types, t.config_json, t.roles_autorises, t.sort_order,
                           t.sidebar_sort, t.is_core, t.sidebar_hidden, t.topbar_category, t.topbar_sort_order
                    FROM etablissements e
                    CROSS JOIN (
                        SELECT m.* FROM modules_config m
                        JOIN (
                            SELECT module_key, MIN(etablissement_id) AS mid
                            FROM modules_config GROUP BY module_key
                        ) x ON m.module_key = x.module_key AND m.etablissement_id = x.mid
                    ) t
                    WHERE NOT EXISTS (
                        SELECT 1 FROM modules_config mc
                        WHERE mc.module_key = t.module_key AND mc.etablissement_id = e.id
                    )";

            $affected = $pdo->exec($sql);
            error_log('[migration backfill_modules_config] lignes ajoutées = ' . var_export($affected, true));
        } catch (\Throwable $e) {
            error_log('[migration backfill_modules_config] échec (non bloquant) : ' . $e->getMessage());
        }
    }
};
