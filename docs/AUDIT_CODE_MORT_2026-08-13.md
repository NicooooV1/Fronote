# Audit code mort / doublons / dette technique — 2026-08-13

Audit multi-agent (7 dimensions recherche + vérification adversariale de l'atteignabilité).
54 constats vérifiés. La règle projet « pages non liées mais LIVE » a été respectée : seules des
classes/méthodes/tables à **0 référence** ont été classées mortes ; ~60 pages orphelines
atteignables par URL ont été explicitement exclues.

---

## ✅ Corrigé dans cette passe (4 vagues, crash-scan 905 vérifs OK, non poussé)

**Vague A — fatals live & sécurité**
- `cahierdetextes/form_devoir.php` : `FileUploader::` (classe supprimée) → `\API\Services\FileUploadService::`. Fatal dès l'édition d'un devoir avec pièce jointe.
- `vie_scolaire/VieScolaireService.php` : table `infirmerie_passages` (inexistante) → `passages_infirmerie` + ajout du cloisonnement `etablissement_id` (le décompte était cross-tenant).
- `admin/users/{index,admins}.php` : la révocation de session à la désactivation d'un compte était avalée → désormais journalisée.

**Vague B — code mort supprimé (0 réf. code ET clé étrangère vérifiées)**
- `API/Database/QueryBuilder.php` (classe entière, 350 l.) + `Database::table()` (0 appelant).
- `AuditService` : `class_alias('Pronote\Services\AuditService')` périmé.
- `ModuleSDK::splitSqlStatementsLegacy()` et `FmodService::extract()` (`@deprecated`, 0 appel).
- `pronote.sql` : 12 tables jamais livrées (6 `securite_*` PPMS, 5 `tutorat_*`, `job_queue`).

**Vague C — dérive de schéma**
- `parcours_educatifs` : JOIN sur `parcours_modeles` (inexistante) → `parcours_educatifs_modeles`.
- `ExportService::logExport` : INSERT dans `export_jobs` (table fantôme, perte silencieuse de l'audit d'export = données élèves) → redirigé vers `import_export_logs` (table réelle déjà lue par `admin/systeme/import_export.php`).

**Vague D — diagnosticabilité** : `error_log` ajouté à 5 `catch` vides critiques (bootstrap evenements/periodes, autorisation users/create, déchiffrement export RGPD) — sans changer le flux.

---

## ⚠️ Cause racine à traiter — double propriété du schéma

~55 tables sont définies **à la fois** dans `pronote.sql` (socle) **et** dans un `install.sql` de module.
`HeadlessInstaller` importe `pronote.sql` en premier ; le `CREATE IF NOT EXISTS` du module devient
un no-op → toute divergence de colonnes livre **silencieusement** le mauvais schéma. C'est la source
des fatals « table/colonne inexistante ». **Établir une source de vérité unique par table**
(lint disponible : `tests/schema_duplicates_lint.php`).

---

## 📋 Reste à faire (non appliqué — décision/risque à arbitrer)

### Dérive de schéma — tables manquantes (fatals sur pages module concernées)
Chaque service interroge des tables définies nulle part → créer/scoper le `install.sql` du module,
**ou** retirer le chemin de code abandonné :

| Module / fichier | Tables absentes |
|---|---|
| `reporting/ReportingService.php:619` | `reporting_kpis`, `reporting_planification`, `reporting_custom` |
| `orientation/OrientationService.php:404` | `orientation_alumni`, `orientation_fiches_metiers`, `orientation_parcoursup` |
| `vie_associative/VieAssociativeService.php:289` | `association_votes(_bulletins)`, `association_adhesions(_campagnes)` |
| `transports/…:188` | `transport_arrets`, `transport_presences`, `transport_autorisations_sortie` |
| `discipline/…:637` | `discipline_contrats`, `discipline_regles_escalade` |
| `notifications/…:357` | `notifications_planifiees` |
| `ressources/…:199` | `ressources_versions`, `ressources_vues` |
| `signalements/…:268` | `signalements_assignations`, `signalements_suivi` |
| `documents` (+5) | `document_dossiers`, `stages_offres`, `diplomes_telechargements`, `archives_planifiees`, `periscolaire_waitlist`, `salles_maintenance` |
| `projets_pedagogiques/…:270` | **dérive de COLONNES** : code attend `date_debut/date_fin`, table `projets_pedagogiques_etapes` a `date_echeance` (+ `projets_depenses/autorisations/evaluations` absentes) — décision métier |

### Doublons à factoriser (dette pure, sans impact runtime sauf mention)
1. Constructeur PDO recopié dans **108 services** → extraire `API\Services\AbstractTenantService`.
2. `etabId()` recopié dans **27 services** → hisser sur la base commune (`AbstractWidgetProvider` l'expose déjà).
3. Getters de référence dupliqués (`getProfesseurs`/`getMatieres`/`getClasses`/`getEnfantsParent` avec scoping tenant **incohérent**) → `EtablissementService` source unique (bénéfice **sécurité**).
4. 6 `WidgetProvider` recopient `pdo()/etabId()` au lieu d'`extends AbstractWidgetProvider`.
5. CSRF : token généré inline dans **23 pages**, comparaison réimplémentée dans ~10 handlers → router vers `csrf_token()`/`csrf_verify()` (`Bridge.php`) — **bénéfice sécurité** (échec dur au lieu de no-op).
6. `formatDate/formatDateTime` définis 2× (`Bridge.php` vs `TranslationService`) → canoniser.
7. CSS messagerie : `styles.css` vs `messagerie.css` ~43 sélecteurs dupliqués (dédup **sensible à la cascade**).

### Améliorations techniques (impact/risque)
1. **N+1** `VieScolaireService::detecterRisqueDecrochage` : ~4 requêtes × 2370 élèves + `tableExists()` par élève → agrégats `GROUP BY`, join en PHP. Impact élevé, risque faible.
2. Retirer le probing `tableExists()` runtime une fois les schémas corrigés (masque la dérive).
3. Autres N+1 mineurs : `NoteService:341`, `NotificationService:247/371`, `ArchiveService:294` (UPDATE ligne-par-ligne → `WHERE id IN (...)`).
4. Index composites multi-tenant `etablissement_id`-leading (`notes/install.sql:21`).
5. Unifier le bootstrap messagerie (seul module avec `config/`+`core/` privés — double la surface de durcissement CSRF/auth).
6. God-files à découper : `EdtService` 1432 l., `RoleCatalog` 1253, `DashboardService` 1244, `NoteService` 1217.

### Incertain (regard humain avant suppression)
- `modules/agenda/endpoints/persons.php` (shim proxy « backward compat », 0 conso in-repo — vérifier appelants externes).
- `API/endpoints/test_catalog.php` + `modules/hello_world` (scaffolding dev, gaté `ALLOW_TEST_MODULES`).

### Faux positif retenu
`marketplace_sources` a été signalée « morte » (0 requête PHP) mais **3 clés étrangères** la référencent → **conservée**. Toujours vérifier `REFERENCES \`table\`` avant de supprimer une table.
