# Widget API — Documentation

Cette page décrit comment un module Fronote fournit des **widgets** au tableau de
bord d'accueil (`accueil/accueil.php`). Un widget = une carte de la grille
d'accueil, alimentée par un *data provider* PHP du module, rendue par le
contrôleur d'accueil.

> Public : développeurs de modules + administrateurs.
> Tous les chemins sont relatifs à la racine du dépôt.

---

## 1. Vue d'ensemble

Le dashboard d'accueil affiche une grille de cartes (« widget-grid »)
personnalisable par l'utilisateur (afficher/masquer, réordonner par
glisser-déposer, redimensionner). Chaque module peut **déclarer ses propres
widgets dans son `module.json`** et fournir une classe `*WidgetProvider`, sans
toucher au code de l'accueil.

Chaîne de bout en bout :

```
1. module.json  (widgets[])      → déclare le widget (key, type, roles, data_provider…)
2. ModuleSDK::syncAll()          → upsert dans la table dashboard_widgets
3. DashboardService              → liste les widgets de l'utilisateur (user_dashboard_config)
4. DashboardService::renderWidgetData() → résout le provider via le SDK, appelle getData(),
                                          normalise les clés vers $data['items']
5. accueil/accueil.php           → rend la carte selon $widget['type'] (render*Widget())
```

Fichiers clés :

| Rôle | Fichier |
|---|---|
| Contrôleur d'accueil + renderers HTML | `accueil/accueil.php` |
| Service métier (config, données) | `accueil/includes/DashboardService.php` |
| Endpoint AJAX (sauvegarde, toggle) | `accueil/ajax_dashboard.php` |
| Résolution du provider | `API/Services/ModuleSDK.php` (`resolveWidgetProvider`, `getAllWidgetConfigs`, `syncWidgets`) |
| Contrat | `API/Contracts/WidgetDataProvider.php` |
| Classe de base recommandée | `API/Contracts/AbstractWidgetProvider.php` |
| Tables | `dashboard_widgets`, `user_dashboard_config`, `dashboard_layouts` (`pronote.sql`) |

---

## 2. Déclaration dans `module.json`

Exemple réel — `modules/notes/module.json` :

```json
{
  "widgets": [
    {
      "key": "dernieres_notes",
      "name": { "fr": "Dernières notes", "en": "Latest grades" },
      "description": { "fr": "Notes les plus récentes", "en": "Most recent grades" },
      "type": "list",
      "icon": "fas fa-chart-bar",
      "roles": ["eleve", "parent", "professeur"],
      "default_size": { "width": 2, "height": 1 },
      "min_width": 1,
      "max_width": 4,
      "is_default": true,
      "sort_order": 30,
      "data_provider": "includes/NoteWidgetProvider.php",
      "template": "widgets/dernieres_notes.php"
    }
  ]
}
```

### Propriétés

| Propriété | Type | Synchronisée en base ? | Description |
|---|---|---|---|
| `key` | string | oui (`widget_key`, UNIQUE) | **Identifiant global du widget.** Non préfixé automatiquement — il doit être unique sur toute l'app. |
| `name` | objet `{fr, en}` (ou string) | oui (`label`, fr) | Titre affiché sur la carte. **Obligatoire** (validé par `ModuleSDK::validate`). |
| `description` | objet `{fr, en}` (ou string) | oui (`description`, fr) | Sous-titre dans la modale de personnalisation. |
| `type` | string | oui | `stats`, `list`, `chart`, `calendar`, `shortcut`, `custom`. Pilote le renderer (voir §6). |
| `icon` | string | oui | Classe Font Awesome (déf. `fas fa-th`). |
| `roles` | array | oui (`roles_autorises`, JSON) | Rôles autorisés. **Absent / vide ⇒ `NULL` ⇒ tous les rôles.** |
| `default_size` | objet `{width, height}` | partiel : seul `width` ⇒ `default_width` | Taille initiale. **`height` n'est PAS synchronisé** (`default_height` reste à 1). |
| `min_width` / `max_width` | int | **non** | Présents en base (déf. 1 / 4) mais `syncWidgets()` ne les écrit pas. |
| `is_default` | bool | oui | `true` ⇒ ajouté automatiquement au dashboard des nouveaux utilisateurs du rôle. |
| `sort_order` | int | oui | Ordre d'apparition (déf. 50). |
| `data_provider` | string | non (lu à l'exécution) | Chemin **relatif au dossier du module** vers le fichier de la classe provider. |
| `template` | string | non | **Champ historique : ignoré par l'accueil.** Le rendu se fait par `type`, pas par template (voir §6). |

> ⚠️ **Clé globale, pas de préfixe.** Contrairement à ce qu'on pourrait croire,
> `key` n'est pas préfixé par le module : c'est l'identifiant brut stocké dans
> `dashboard_widgets.widget_key` (UNIQUE). Choisissez une clé non ambiguë
> (`dernieres_notes`, `reunions_a_venir`…).

> ⚠️ **`template` n'est plus utilisé.** Le contrôleur d'accueil ne `include` aucun
> template de module ; il rend chaque carte avec des fonctions internes
> (`renderListWidget`, `renderStatWidget`, …) choisies d'après `type`. Le champ
> reste accepté pour compat mais n'a aucun effet. Pour personnaliser le visuel,
> renvoyez les bonnes clés depuis `getData()` (voir §5/§6).

### Synchronisation en base

À chaque `app('module_sdk')->syncAll()` (déclenché notamment par le bouton de
mise à jour, cf. `admin/systeme/update.php`), `ModuleSDK::syncWidgets()` fait un
`INSERT … ON DUPLICATE KEY UPDATE` dans `dashboard_widgets` pour chaque widget
déclaré. Colonnes réellement écrites :
`widget_key, label, description, icon, type, module_key, roles_autorises,
default_width, is_default, sort_order`.

Les widgets « cœur » sont aussi semés directement dans `pronote.sql`
(`prochains_evenements`, `devoirs_a_faire`, `dernieres_notes`,
`messages_non_lus`, `absences_du_jour`, `stats_rapides`,
`emploi_du_temps_jour`, `raccourcis`, `annonces_recentes`, …) ; leur logique de
données vit dans `DashboardService` (renderers internes, §4).

---

## 3. Le data provider

Un provider implémente `API\Contracts\WidgetDataProvider`. **Recommandé :**
hériter de `API\Contracts\AbstractWidgetProvider`, qui fournit les helpers
`pdo()`, `etabId()` et `etabIdOrEmpty()` (scope multi-établissement par défaut).

```php
namespace API\Contracts;

interface WidgetDataProvider
{
    /**
     * @param int        $userId   ID de l'utilisateur connecté
     * @param string     $userType Rôle (eleve, parent, professeur, vie_scolaire, administrateur)
     * @param array|null $config   Config utilisateur (user_dashboard_config.config décodée), souvent null
     * @return array Données libres — voir §5 pour la convention de clés
     */
    public function getData(int $userId, string $userType, ?array $config = null): array;

    /**
     * Intervalle de rafraîchissement auto en secondes (0 = désactivé).
     * ⚠️ Actuellement NON consommé par l'UI d'accueil — voir §7.
     */
    public function getRefreshInterval(): int;
}
```

### Conventions de fichier / classe

`ModuleSDK::resolveWidgetProvider()` :

1. lit `data_provider` (ex. `includes/NoteWidgetProvider.php`) résolu depuis le
   dossier réel du module ;
2. extrait le `namespace …;` du fichier par regex et déduit le **nom de classe =
   nom du fichier** (`NoteWidgetProvider`) ;
3. `require_once` le fichier, instancie `Namespace\NomFichier` (fallback : classe
   sans namespace) ;
4. vérifie que l'instance est bien un `WidgetDataProvider`, sinon `null`.

Donc : **le nom du fichier doit être exactement le nom de la classe**, et le
`namespace` peut être quelconque (les providers réels utilisent
`Notes\Widgets`, `Reunions\Widgets`, `Intelligence\Widgets`…). L'autoload PSR-4
n'est pas requis pour les providers : ils sont chargés par `require_once`.

### Exemple réel — `modules/notes/includes/NoteWidgetProvider.php`

```php
namespace Notes\Widgets;

use API\Contracts\AbstractWidgetProvider;

class NoteWidgetProvider extends AbstractWidgetProvider
{
    public function getData(int $userId, string $userType, ?array $config = null): array
    {
        $pdo = app('db')?->getConnection();
        if (!$pdo) {
            return ['notes' => [], 'average' => null];
        }

        $limit = min(20, max(1, (int) ($config['limit'] ?? 5)));

        if ($userType === 'eleve') {
            $stmt = $pdo->prepare(
                'SELECT n.note, n.note_sur, n.coefficient, n.date_note, m.nom AS matiere
                 FROM notes n
                 LEFT JOIN matieres m ON m.id = n.id_matiere
                 WHERE n.id_eleve = ?
                 ORDER BY n.date_note DESC
                 LIMIT ?'
            );
            $stmt->execute([$userId, $limit]);
            return ['notes' => $stmt->fetchAll(\PDO::FETCH_ASSOC), 'average' => /* … */ null];
        }
        // … professeur …
        return ['notes' => [], 'average' => null];
    }

    public function getRefreshInterval(): int
    {
        return 600;
    }
}
```

Points à reprendre dans vos providers :

- **Récupérer le PDO** via `$this->pdo()` (depuis `AbstractWidgetProvider`) ou
  `app('db')?->getConnection()`. Ne pas utiliser `getPDO()` ici. Toujours gérer
  le cas `null`.
- **Scope multi-établissement.** Un widget global (qui agrège des chiffres
  d'établissement) DOIT filtrer `etablissement_id = ?` avec
  `$this->etabId()` / `$this->etabIdOrEmpty([...])`. Les widgets scopés par
  `user_id` (un user appartient à un seul établissement) sont implicitement
  scopés — voir l'annotation `@global-scope` en tête des providers.
- **Borner les volumes** (`LIMIT`), idéalement via `$config['limit']`.

---

## 4. Côté DashboardService

`accueil/includes/DashboardService.php` orchestre tout.

- `getUserWidgets($userId, $userType)` : renvoie la config widgets de
  l'utilisateur depuis `user_dashboard_config` (jointe à `dashboard_widgets`,
  filtrée `actif = 1`) ; **si l'utilisateur n'a aucune config**, renvoie les
  widgets `is_default = 1` autorisés pour son rôle
  (`getDefaultWidgetsForRole`). Résultat mis en cache session 5 min via
  `ClientCache` (invalidé par `saveWidgetLayout`).
- `getAvailableWidgets($role)` : tous les widgets `actif = 1` accessibles au
  rôle (pour la modale « Personnaliser »).
- `renderWidgetData($widgetKey, $userId, $userType)` : **le cœur de l'aiguillage.**
  1. Tente `app('module_sdk')->resolveWidgetProvider($widgetKey)` ; si trouvé,
     appelle `$provider->getData($userId, $userType)` et **normalise** (§5).
  2. Sinon, fallback sur des renderers internes hardcodés (rétro-compat des
     widgets cœur) : `renderDernieresNotes`, `renderDevoirsAFaire`,
     `renderProchainEvenements`, `renderMessagesNonLus`, `renderAbsencesDuJour`,
     `renderStatsRapides`, `renderEmploiDuTempsJour`, `renderRaccourcis`,
     `renderAnnoncesRecentes`, `renderReunionsAVenir`.
  3. Défaut : `['type' => 'empty', 'items' => []]`.

> Une erreur SQL `1146` (table absente = module fournisseur non activé, son
> `install.sql` n'étant provisionné qu'à l'activation) est **avalée
> silencieusement** : le widget s'affiche vide au lieu de planter ou de polluer
> les logs.

---

## 5. Convention de données : normalisation vers `items`

Les providers renvoient des **clés métier** (`notes`, `reunions`, `devoirs`,
`tickets`, `evenements`, `annonces`, `absences`, `menus`, `incidents`,
`competences`, `bulletins`, `cours`, `messages`…). Mais les renderers de
l'accueil de type `list` lisent `$data['items']`.

`renderWidgetData()` ajoute donc automatiquement un **alias `items`** s'il
manque : il prend la **première** de ces clés métier présente et non vide, sans
retirer les clés d'origine (les widgets `stats` peuvent encore lire leur clé).
Ordre testé :

```
devoirs, notes, reunions, tickets, evenements, annonces,
absences, menus, incidents, competences, bulletins, cours, messages
```

Conséquence pratique :

- Pour un widget **`list`** : renvoyez vos lignes dans une clé métier connue
  (ex. `['notes' => [...]]`) ou directement `['items' => [...]]`. Si votre clé
  n'est pas dans la liste ci-dessus, **renvoyez `items` explicitement**.
- Pour un widget **`chart`** : renvoyez `['items' => [['label' => ..., 'value' => N], …]]`
  (cf. `IntelligenceWidgetProvider`). `renderChartWidget` accepte aussi
  `nom/title/name` pour le label et `count/total/nb` pour la valeur.
- Pour un widget **`stats`** : renvoyez les clés `value`, `label`, `icon`,
  `color`, `trend` (stat unique) ; ou `['type' => 'stats_grid', 'items' => [...cartes...]]`.
- Clés optionnelles transverses lues par l'accueil : `link` et `link_label`
  (affichent le pied de carte « Voir plus → »).

### État vide compact

Une carte dont la charge normalisée n'a **ni `items`, ni `value`**, ni aucune des
clés `reunions/notes/devoirs/tickets`, reçoit la classe CSS `is-empty` côté
`accueil.php` : elle **se replie** (carte compacte) au lieu d'afficher une grande
boîte vide. Inutile de gérer ça dans le provider — il suffit de renvoyer une
charge vide cohérente (`['notes' => [], 'average' => null]`).

---

## 6. Rendu côté accueil (`accueil/accueil.php`)

Pour chaque widget visible, `accueil.php` pré-calcule les données
(`$widgetDataMap`) puis rend la carte. Le renderer est choisi **uniquement
d'après `type`** (pas de template de module) :

| `type` | Fonction de rendu | Attend dans `$data` |
|---|---|---|
| `stats` | `renderStatWidget` | stat unique (`value/label/icon/color/trend`) **ou** `type=stats_grid` + `items[]` (cartes `icon/value/label/color`) |
| `list` | `renderListWidget` | `items[]` ; rendu spécialisé selon `widget_key` (`dernieres_notes`, `devoirs_a_faire`, `prochains_evenements`/`reunions_a_venir`, `annonces_recentes`, `absences_du_jour`), sinon fallback générique (`titre`/`description`) |
| `chart` | `renderChartWidget` | `items[]` de `{label, value}` ⇒ barres horizontales |
| `calendar` | `renderCalendarWidget` | `items[]` de cours (`heure_debut`, `heure_fin`, `matiere`, `salle`/`classe`, `professeur`) |
| `shortcut` | `renderShortcutWidget` | `items[]` de `{href, icon, title}` |
| `custom` / défaut | `renderListWidget` | traité comme une liste |

Largeur → classe CSS : `width >= 4` ⇒ `widget-size-large`, `>= 2` ⇒
`widget-size-medium`, sinon `widget-size-small`. Le pied « Voir plus → »
n'apparaît que si `$data['link']` est présent.

> Le rendu spécialisé d'une liste est **codé en dur par `widget_key`** dans
> `renderListWidget`. Un nouveau widget `list` avec une `key` inconnue tombe sur
> le rendu générique (titre + sous-titre). Pour un visuel sur-mesure, soit vous
> alignez vos données sur le fallback générique (`titre` + `description`), soit
> il faut ajouter un cas dans `renderListWidget` (modif de l'accueil).

---

## 7. Personnalisation, persistance, endpoint AJAX

Côté UI (JS inline de `accueil.php`, sans dépendance externe) :

- **Glisser-déposer** des cartes dans la grille (HTML5 DnD) ⇒ `save_layout`.
- **Modale « Personnaliser »** : activer/désactiver chaque widget + réordonner ⇒
  `save_layout` (puis reload).
- **Réduire/agrandir** le corps d'une carte (purement local, non persisté).

Endpoint : **`accueil/ajax_dashboard.php`** (POST JSON). Actions :

| `action` | Effet |
|---|---|
| `save_layout` | Remplace toute la config de l'utilisateur (`DashboardService::saveWidgetLayout`) |
| `toggle_widget` | Affiche/masque un widget (`toggleWidget`) |
| `get_widgets` | Liste la config courante |
| `get_available` | Liste les widgets disponibles + flags `enabled/visible` |
| `get_widget_data` | Renvoie les données d'un widget (`renderWidgetData`) |

CSRF : `save_layout` et `toggle_widget` exigent `csrf_token` (comparé à
`$_SESSION['csrf_token']` via `hash_equals`). Le token est exposé au JS dans
`window.DASHBOARD_CSRF`.

> ⚠️ **`getRefreshInterval()` n'est pas consommé.** Le contrat l'expose et les
> providers le définissent (600 s, 1800 s, 3600 s…), mais aucun timer JS de
> l'accueil n'appelle `get_widget_data` en boucle aujourd'hui. Les widgets sont
> rendus côté serveur au chargement de la page ; un rafraîchissement = un reload.
> La valeur reste utile comme métadonnée et pour un éventuel polling futur.

---

## 8. Recette : ajouter un widget à un module

1. **Déclarer** dans `modules/<mod>/module.json` → tableau `widgets[]` :
   `key` (unique), `name.fr`, `type`, `icon`, `roles`, `default_size.width`,
   `is_default`, `sort_order`, `data_provider`.
2. **Créer** `modules/<mod>/includes/MonWidgetProvider.php` :
   `class MonWidgetProvider extends AbstractWidgetProvider` (nom de classe =
   nom de fichier), implémenter `getData()` (PDO via `$this->pdo()`, scope
   `etablissement_id` si global, `LIMIT`) et `getRefreshInterval()`.
3. **Renvoyer** depuis `getData()` une clé métier connue (ou `items`) selon le
   `type` (§5), plus optionnellement `link` / `link_label`.
4. **Synchroniser** : lancer la mise à jour (`admin/systeme/update.php`) ou
   `app('module_sdk')->syncAll()` → la ligne apparaît dans `dashboard_widgets`.
   Le widget est alors proposé aux rôles autorisés dans « Personnaliser »
   (et ajouté d'office si `is_default = true`).

### Pièges fréquents

- **Pas de rendu ?** Vérifiez que `type` correspond à un renderer (§6) et que
  vos données sont sous `items` (ou une clé métier normalisable, §5).
- **Liste avec un visuel générique inattendu :** normal si la `key` n'a pas de
  cas dédié dans `renderListWidget` — alignez-vous sur `titre`/`description`.
- **Classe non trouvée :** nom de fichier ≠ nom de classe, ou chemin
  `data_provider` incorrect (relatif au dossier du module).
- **Widget vide en prod :** souvent une table absente (module non activé,
  erreur SQL `1146` avalée) ou un scope `etablissement_id` manquant.
- **`template` ignoré :** attendu — le rendu passe par `type`, pas par template.

---

## 9. Schéma des tables

`dashboard_widgets` (catalogue global, source = `module.json` + seed `pronote.sql`) :

```sql
widget_key      VARCHAR(50)  -- UNIQUE, clé globale du widget
label           VARCHAR(100) -- name.fr
description      TEXT
icon            VARCHAR(50)
type            ENUM('stats','list','chart','calendar','shortcut','custom')
module_key      VARCHAR(50)  -- module fournisseur (NULL = cœur/global)
roles_autorises JSON         -- NULL = tous les rôles
default_config  JSON
min_width       INT DEFAULT 1
max_width       INT DEFAULT 4
default_width   INT DEFAULT 2
default_height  INT DEFAULT 1
is_default      TINYINT(1)
actif           TINYINT(1) DEFAULT 1
sort_order      INT DEFAULT 100
```

`user_dashboard_config` (config par utilisateur) : `user_id`, `user_type`,
`widget_key`, `position_x/y`, `width`, `height`, `config` (JSON spécifique
utilisateur, passé tel quel à `getData($…, $config)`), `visible`. Clé unique
`(user_id, user_type, widget_key)`.

`dashboard_layouts` (présets nommés, optionnels) : `name`, `columns`,
`widgets_config` (snapshot JSON), `is_active` — gérés par
`getLayouts/saveLayout/activateLayout/deleteLayout` de `DashboardService`.
