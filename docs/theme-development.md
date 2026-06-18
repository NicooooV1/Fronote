# Thèmes & UI — Guide de développement

Ce document décrit le système de thèmes de Fronote, le CSS unifié injecté par le
header partagé, la **topbar** horizontale (il n'y a plus de sidebar), et la façon
dont une page de module charge son propre CSS.

> Tous les chemins sont relatifs à la racine du projet. Les identifiants de code
> (variables CSS, services, classes) sont cités **verbatim** depuis le code réel.

---

## 1. Vue d'ensemble

Fronote n'utilise **pas** de framework CSS. L'UI repose sur :

- un fichier de **design tokens** (`assets/css/tokens.css`) — la source de vérité
  des couleurs, espacements, typographie, ombres et rayons (variables CSS `:root`) ;
- une **base** (`base.css`) et des **composants** (`components.css`) qui consomment
  ces tokens ;
- un thème CSS « visuel » toujours chargé (`theme-classic.css`) et, en surcouche
  optionnelle, `theme-glass.css` (glassmorphism) ou un thème custom ;
- un **dark mode** orthogonal au thème visuel, piloté par l'attribut
  `data-theme="dark"` sur `<html>` ;
- la **topbar** (`assets/css/topbar.css` + templates `shared_topbar*.php`).

Tout est assemblé par `templates/shared_header.php`, inclus par chaque page via le
`includes/header.php` du module.

Deux notions distinctes à ne pas confondre :

| Notion | Attribut HTML | Valeurs | Qui choisit | Effet |
|---|---|---|---|---|
| **Thème CSS** (visuel) | `data-css-theme` | `classic`, `glass`, `<custom>` | admin (défaut établissement) ou préférence utilisateur en base | charge `theme-classic` + éventuellement `theme-glass`/custom |
| **Dark mode** | `data-theme` | `light`, `dark` (préf. `auto`) | l'utilisateur (toggle topbar, page Paramètres) | bascule le bloc de tokens `[data-theme="dark"]` |

---

## 2. Arborescence CSS

```
assets/css/
├── tokens.css          ← Design tokens :root (+ bloc dark [data-theme="dark"])
├── base.css            ← Reset, layout, utilitaires
├── components.css      ← Styles de composants
├── topbar.css          ← Navigation horizontale (topbar)
├── theme-classic.css   ← Thème visuel par défaut (toujours chargé)
├── theme-glass.css     ← Surcouche glassmorphism (additive, optionnelle)
├── theme-<custom>.css  ← Thèmes uploadés via l'admin (ThemeService::uploadTheme)
├── rtl.css             ← Surcharges RTL (chargé si dir="rtl")
├── cookie-consent.css  ← Bannière cookies
├── admin.css           ← Pages d'administration
└── accueil.css         ← Tableau de bord d'accueil
```

### Ordre de chargement (défini dans `shared_header.php`)

Le header injecte les feuilles dans cet ordre exact (chaque `href` est préfixé par
`$rootPrefix` et suffixé d'un cache-buster `?v=<filemtime>`) :

```
cookie-consent → topbar → base → tokens → components → theme-classic
  → [theme-glass | theme-<custom>]      (si sélectionné)
  → <style id="establishment-branding"> (couleurs établissement, si définies)
  → <style id="theme-token-overrides">  (overrides du designer, si définis)
  → [rtl.css]                            (si locale RTL)
  → Font Awesome 6 (CDN)
  → $extraCss[] (CSS spécifique de la page)
```

Conséquence de cascade importante :

1. `tokens.css` pose les valeurs par défaut.
2. `theme-classic`/`theme-glass`/custom peuvent les surcharger.
3. Le **branding établissement** (couleurs primaire/secondaire) est injecté
   **avant** les overrides de thème — il est donc de priorité **basse** et
   surchargé par le thème/utilisateur (cf. commentaire en code).
4. Les **overrides de tokens** du « designer » admin (`theme-token-overrides`)
   passent en dernier dans `:root` et gagnent.
5. Le `$extraCss` de la page est chargé en tout dernier (hors `<style>` inline) et
   peut tout surcharger.

> ⚠️ `data-css-theme` est uniquement un **attribut** sur `<html>` (utile au JS ou
> au débogage). Il **n'est pas** utilisé comme sélecteur CSS : le choix du thème
> visuel se fait en chargeant ou non le bon `<link>`. N'écrivez pas de règles
> `[data-css-theme="glass"] { … }`, elles ne correspondent à aucune convention
> existante.

---

## 3. Design tokens (`assets/css/tokens.css`)

Toutes les valeurs personnalisables sont des variables CSS sur `:root`. **Ce sont
les noms réels du fichier** — utilisez-les tels quels.

### Palette principale & neutres

```css
:root {
  --primary-color: #0f4c81;
  --primary-light: #2d7dd2;
  --primary-dark:  #0a3962;

  --background-color: #f5f6fa;
  --text-color: #333;
  --text-light: #666;
  --text-muted: #999;
  --white: #fff;
  --border-color: #eee;
}
```

### Sémantique / feedback

```css
:root {
  --success-color: #34c759;
  --warning-color: #ff9500;
  --error-color:   #ff3b30;
  --info-color:    #007aff;
}
```

### Accents & pastels par module

```css
:root {
  --accent-notes: #ff9500;   --pastel-notes: rgba(255,149,0,.15);
  --accent-agenda: #007aff;  --pastel-agenda: rgba(0,122,255,.15);
  --accent-cahier: #34c759;  --pastel-cahier: rgba(52,199,89,.15);
  --accent-messagerie: #5856d6;
  --accent-absences: #ff3b30;
}
```

### Espacements, rayons, ombres

```css
:root {
  --space-xs: 5px;  --space-sm: 10px; --space-md: 20px;
  --space-lg: 30px; --space-xl: 40px;

  --radius-sm: 4px; --radius-md: 10px; --radius-lg: 20px; --radius-circle: 50%;

  --shadow-light:  0 2px 6px rgba(0,0,0,.05);
  --shadow-medium: 0 4px 12px rgba(0,0,0,.08);
  --shadow-hover:  0 8px 16px rgba(0,0,0,.1);
}
```

Une **grille 4px** dédiée aux classes utilitaires existe en parallèle :
`--sp-1: 4px … --sp-12: 48px`.

### Sous-système « Design System » (`--ds-*`)

`tokens.css` définit aussi une palette `--ds-*` (ex. `--ds-primary: #2563eb`,
`--ds-surface`, `--ds-border`, `--ds-radius`, `--ds-shadow`, `--ds-font`) et des
**alias sémantiques** que `components.css` consomme :

```css
--primary:      var(--ds-primary);
--bg-white:     var(--ds-surface);
--bg-light:     var(--ds-bg);
--text:         var(--ds-text);
--border-color: var(--ds-border);
```

### Typographie fluide

```css
--font-base: clamp(0.875rem, 0.83rem + 0.2vw, 1rem);  /* 14-16px */
/* … --font-xs … --font-2xl, toutes en clamp() responsive */
```

> Note : certaines variables sont **héritées** et non utilisées par le layout
> topbar — ex. `--sidebar-width: 260px` et `--header-height: 70px`. Ne vous y fiez
> pas pour positionner quoi que ce soit ; la topbar a sa propre hauteur fixe
> (`56px` dans `topbar.css`).

---

## 4. Dark mode

Le dark mode est **indépendant** du thème visuel. Il est porté par l'attribut
`data-theme` sur `<html>` et bascule le bloc dédié de `tokens.css` :

```css
[data-theme="dark"] {
  --background-color: #0f172a;
  --text-color: #e2e8f0;
  --white: #1e293b;       /* surfaces */
  --border-color: #334155;
  --ds-primary: #3b82f6;
  --ds-surface: #1e293b;
  /* … */
}
```

### Flux applicatif

- **Préférence stockée** : `user_settings.theme` peut valoir `light`, `dark` ou
  `auto` (énum exposé par `SettingsService::themes()` → `{light, dark, auto}`).
  `shared_header.php` lit cette valeur, la classe comme préférence dark
  (`data-theme-pref`) et applique `data-theme="light"` par défaut côté serveur.
- **Anti-flash** : un petit script inline dans le `<head>` relit
  `localStorage('fronote_dark_mode')` et `prefers-color-scheme` pour appliquer
  immédiatement `data-theme="dark"` avant le premier paint (`auto` → suit le
  système).
- **Toggle topbar** : le bouton `#topbar-theme-toggle` (icônes soleil/lune) bascule
  le mode ; la page **Paramètres** (`parametres/parametres.php`) propose aussi les
  trois choix via `previewTheme()` qui met à jour `data-theme` et
  `data-theme-pref` à la volée.

> Attention au double sens de la colonne `user_settings.theme` : si elle contient
> `classic`/`glass`/`<custom>`, c'est un **thème visuel** ; si elle contient
> `light`/`dark`/`auto`, c'est une **préférence dark**. `shared_header.php` gère les
> deux familles de valeurs (cf. le bloc « Theme loading » en tête du template).

---

## 5. Le service de thèmes — `app('themes')` (`API\Services\ThemeService`)

Enregistré dans `API/bootstrap.php` :

```php
$app->singleton('themes', function ($app) {
    return new \API\Services\ThemeService($app->make('db')->getConnection(), BASE_PATH);
});
```

Thèmes intégrés non supprimables : `classic`, `glass`
(`ThemeService::BUILTIN_THEMES`). Méthodes principales :

| Méthode | Rôle |
|---|---|
| `getAll()` | Liste les thèmes (les 2 built-in + ceux de la table `themes`). |
| `get($key)` | Un thème par sa clé, ou `null`. |
| `getDefault()` | Thème par défaut de l'établissement (clé `theme_default` dans `etablissement_info`), `classic` sinon. |
| `setDefault($key)` | Définit ce défaut (upsert dans `etablissement_info`). |
| `cssFileFor($key)` | Chemin CSS **validé** d'un thème installé (anti-traversée), ou `null`. |
| `uploadTheme($file, $key, $name, …)` | Installe un thème custom depuis un upload CSS (écrit `assets/css/theme-<key>.css`, insère en base). |
| `delete($key)` | Supprime un thème custom + ses fichiers, et réinitialise les utilisateurs à `classic`. |
| `getTokens()` | Parse les variables CSS de `tokens.css` (nom/valeur). |
| `getTokenOverrides($key)` / `saveTokenOverrides($key, $overrides)` | Lit/écrit les overrides de couleurs d'un thème (table `theme_token_overrides`, JSON). |
| `renderOverrideCss($key)` | Construit le bloc `:root{ … }` assaini injecté dans `theme-token-overrides`. |
| `contrastRatio()` / `contrastReport()` | Calcul de contraste WCAG sur les couleurs effectives. |

### Sécurité des thèmes uploadés

`uploadTheme()` impose : clé `^[a-z0-9_-]{2,30}$`, refus d'écraser un built-in, CSS
≤ 500 Ko, et un filtre `containsDangerousCSS()` qui rejette `javascript:`,
`expression(`, `eval(`, `<script`, `url(data:text/html`, `behavior:`,
`-moz-binding:`. À l'injection, `cssFileFor()` revalide le chemin
(`^assets/css/theme-[a-z0-9_-]+\.css$`) avant tout `<link>`.

### Page d'administration

`admin/themes/index.php` (rôle `administrateur`) regroupe : liste/activation des
thèmes, upload d'un thème custom, catalogue marketplace
(`app('marketplace')->getCatalog('theme')`), et le **« Designer de couleurs »**.

Le designer édite uniquement les `ThemeService::EDITABLE_TOKENS` :

```php
'--primary-color', '--primary-light', '--primary-dark', '--background-color',
'--text-color', '--success-color', '--warning-color', '--error-color'
```

Les valeurs (hex `#rrggbb`) sont enregistrées par thème via `saveTokenOverrides()`,
le **contraste WCAG AA** est vérifié à l'enregistrement (`contrastReport()`), puis
`shared_header.php` injecte le résultat dans `<style id="theme-token-overrides">`
(mise en cache `client_cache` 1 h, invalidée à la sauvegarde).

---

## 6. Branding établissement (couleurs)

Indépendamment des thèmes, chaque établissement peut imposer ses couleurs. Dans
`shared_header.php`, `app('etablissement')->getCurrent()` fournit
`couleur_primaire` et `couleur_secondaire` (validées `^#[0-9a-fA-F]{6}$`). Elles
sont mappées sur plusieurs variables pour couvrir tous les composants :

```php
'couleur_primaire'   => ['--primary-color', '--primary', '--ds-primary'],
'couleur_secondaire' => ['--secondary-color', '--secondary'],
```

Le bloc résultant est injecté dans `<style id="establishment-branding">`, **avant**
les overrides de thème (priorité basse, mis en cache 1 h via `client_cache` sous la
clé `etab_branding_<id>`).

---

## 7. Le header partagé et `$rootPrefix`

`templates/shared_header.php` ouvre le document (`<!DOCTYPE>`, `<head>`, `<body>`,
`.app-container`) et gère : chargement du thème (cache `ClientCache` → DB →
`classic`), CSRF, nonce CSP, config WebSocket, en-têtes de sécurité + CSP, et
l'injection de tout le CSS/JS.

### `$rootPrefix` — comment les chemins sont résolus

Chaque page est à une profondeur différente (racine, `admin/...`,
`modules/<m>/...`). `$rootPrefix` est le chemin relatif **vers la racine** du
projet, recalculé automatiquement dans `shared_header.php` :

1. en priorité depuis l'URI (`SCRIPT_NAME`, en retirant `BASE_URL` si défini) ;
2. fallback filesystem (`SCRIPT_FILENAME` vs `realpath(BASE_PATH)`).

Le résultat est `./`, `../`, `../../`, etc. Tous les `href`/`src` du header et de la
topbar sont préfixés par `$rootPrefix`. **Ne le calculez pas vous-même** : si vous
créez une page, laissez `shared_header.php` le faire (ou définissez-le avant
d'inclure le header seulement si vous savez ce que vous faites).

### Variables attendues / optionnelles du header

| Variable | Type | Rôle |
|---|---|---|
| `$pageTitle` | string | `<title>` + `<h1>` |
| `$user_initials` | string | avatar topbar |
| `$pageSubtitle` | string | sous-titre sous le `<h1>` |
| `$extraCss` | array | feuilles CSS supplémentaires (voir §9) |
| `$extraHeadHtml` | string | HTML brut ajouté au `<head>` |
| `$headerExtraActions` | string | boutons d'action dans `.header-actions` |
| `$user_fullname` | string | tooltip de l'avatar |
| `$activePage` | string | clé pour surligner l'entrée active de la topbar |
| `$isAdmin` | bool | affiche le lien Administration |
| `$pageBack` | string\|array | bouton « Retour » (voir §8) |

Côté footer (`templates/shared_footer.php`) : `$extraJs` (array de `<script src>`)
et `$extraScriptHtml` (bloc inline).

---

## 8. La topbar (`shared_topbar.php` + `shared_topbar_nav.php`)

Il n'y a **plus de sidebar**. La navigation est une barre horizontale fixe.

- `shared_topbar.php` : inclut `shared_topbar_nav.php`, ouvre `.main-content` puis
  le `.top-header` (titre, sous-titre, bouton retour, actions).
- `shared_topbar_nav.php` : la `<nav class="topbar-nav">` elle-même.

### Structure visuelle

```
[F FRONOTE] | [★ épingler] [★ Favoris ▾] [Pédagogie ▾] [Vie scolaire ▾] …
            | [🔍 Ctrl+K] [🔔 notifs] [☀/🌙 thème] [enfant ▾ parent] [Étab] [⚙ admin] [Avatar ▾]
```

### Catégories de modules

Les dropdowns sont alimentés par `app('modules')->getForTopbar($role)`, regroupant
les modules **activés et visibles pour le rôle** par catégorie. Les méta de
catégorie viennent de `ModuleService::categoryMeta()` :

| Clé | Libellé | Icône | Ordre |
|---|---|---|---|
| `navigation` | Accueil | `fas fa-home` | 0 |
| `scolaire` | Pédagogie | `fas fa-graduation-cap` | 1 |
| `vie_scolaire` | Vie scolaire | `fas fa-school` | 2 |
| `communication` | Communication | `fas fa-comments` | 3 |
| `sante` | Santé | `fas fa-heartbeat` | 4 |
| `etablissement` | Établissement | `fas fa-building` | 5 |
| `logistique` | Logistique | `fas fa-cogs` | 6 |
| `systeme` | Outils | `fas fa-tools` | 7 |

La catégorie effective d'un module est : `topbar_category` (colonne DB) →
`sidebarCategoryOverrides()` → `category` (DB). Quelques remaps fixes existent
(ex. `messagerie`/`notifications` → `communication`, `infirmerie` → `sante`,
`vie_associative` → `systeme`). Un **fallback DB direct** existe dans
`shared_topbar_nav.php` si la couche service ne renvoie rien.

### Favoris (épingles)

- `app('modules')->getFavorites($userId, $userType, $role)` renvoie les favoris ;
  ils s'affichent dans le dropdown « Favoris » (masqué si vide).
- Deux types : `module` (épingler un module) et `page` (épingler la page courante).
- Le bouton `#topbar-pin-page` (étoile, masqué sur l'accueil) épingle l'URL
  courante. Les boutons `.topbar-fav-toggle` ajoutent/retirent. Le tout passe par
  `API/endpoints/favorites.php` (POST JSON + CSRF, rotation de token via l'en-tête
  `X-Csrf-Token-Next`).

### Actions à droite

Recherche `#topbar-search-btn` (modale `#search-modal`, raccourci **Ctrl+K**),
notifications (badge = `COUNT(*)` non lus dans `notifications_globales`), toggle
thème, **sélecteur d'enfant** pour les parents (si > 1 enfant ; `switch_child`
vérifié contre `parent_eleve`), nom de l'établissement, lien Administration
(si admin), et le menu avatar (Profil / Paramètres / Déconnexion).

Un **panneau mobile** coulissant (`#topbar-mobile-panel`, hamburger
`#topbar-hamburger`) reprend les mêmes catégories. Le comportement JS vit dans
`assets/js/topbar.js`.

### `$pageBack` — bouton de retour

`shared_topbar.php` rend un bouton « Retour » dans le `.top-header` si la page
définit `$pageBack` **avant** d'inclure la topbar. Deux formes :

```php
// 1) Chemin RELATIF à la racine ($rootPrefix est préfixé automatiquement)
$pageBack = 'admin/modules/index.php';

// 2) Forme tableau (label custom, ou URL déjà résolue / externe)
$pageBack = [
    'url'   => 'admin/scolaire/notes.php',
    'label' => 'Retour aux notes',
    'raw'   => false, // true => 'url' utilisée telle quelle (pas de $rootPrefix)
];
```

> ⚠️ Ne passez **jamais** un chemin commençant par `/` sans `'raw' => true` : sous
> un sous-dossier de déploiement (ex. `/Pronote`) il pointerait vers la racine du
> domaine. Le label par défaut est `__('nav.back', ['default' => 'Retour'])`.

---

## 9. Styliser les pages d'un module

### Squelette d'une page de module

Chaque module fournit `includes/header.php` et `includes/footer.php`. Exemple réel
(`modules/notes/includes/header.php`) :

```php
require_once __DIR__ . '/../../../API/core.php';

$pageTitle     = $pageTitle ?? 'Notes';
$user_initials = $user_initials ?? getUserInitials();
$user_fullname = $user_fullname ?? getUserFullName();
$activePage    = 'notes';                          // surligne l'entrée topbar
$isAdmin       = (getUserRole() === 'administrateur');
$extraCss      = $extraCss ?? ['assets/css/notes.css'];   // CSS du module

include __DIR__ . '/../../../templates/shared_header.php';
include __DIR__ . '/../../../templates/shared_topbar.php';
?>
<div class="content-container">
```

Le `includes/footer.php` ferme `.content-container` puis inclut
`templates/shared_footer.php` :

```php
?></div><!-- /content-container -->
<?php include __DIR__ . '/../../../templates/shared_footer.php'; ?>
```

### `$extraCss` — comment ça résout

`$extraCss` est un **tableau de chemins** ; chaque entrée locale est :

1. nettoyée de ses `../` de tête (`preg_replace('#^(?:\.\./)+#', '', …)`),
2. résolue depuis la **racine du projet** pour le cache-buster (`?v=<filemtime>`),
3. injectée en dernier (après le thème, le branding et les overrides).

Autrement dit, le chemin est interprété **relatif à la racine du projet**, pas à la
page : pour le CSS du module Notes (`assets/css/notes.css`), écrivez simplement :

```php
$extraCss = ['assets/css/notes.css'];
```

Les pages d'admin, plus profondes, utilisent souvent la forme `../`-préfixée
(ex. `admin/themes/index.php` : `$extraCss = ['../../assets/css/admin.css'];`) ;
les `../` de tête sont retirés pour la résolution du cache-buster, mais le `<link>`
final conserve le chemin tel quel — donc la profondeur des `../` doit correspondre
à la page. **En cas de doute, partez de la racine** (`assets/css/...`) car le
header gère déjà `$rootPrefix` pour les assets cœur.

Une URL absolue (`https://…`) dans `$extraCss` est laissée telle quelle (pas de
cache-buster, pas de `htmlspecialchars` sur le chemin distant — réservez-la à des
CDN de confiance).

### Bonnes pratiques de style module

- **Consommez les tokens**, n'hardcodez pas les couleurs :
  `color: var(--text-color);`, `background: var(--white);`,
  `border: 1px solid var(--border-color);`.
- **Préfixez vos classes** par le nom du module (ou utilisez les composants
  existants de `components.css`) pour éviter les collisions globales.
- **Supportez le dark mode** : si vous fixez des couleurs en dur, ajoutez la
  variante `[data-theme="dark"] .ma-classe { … }`.
- **Pas de `<script>`/`<style>` inline non nécessaires** : la CSP autorise encore
  `'unsafe-inline'` mais l'objectif est de la durcir ; préférez `$extraCss` /
  `$extraJs`. Les scripts inline du cœur portent un `nonce` (`$_hdr_nonce`).

---

## 10. RTL (langues droite-à-gauche)

Quand la locale est RTL (`app('translator')->isRtl()`), `shared_header.php` pose
`<html dir="rtl">` et charge `assets/css/rtl.css`.

Pour un CSS compatible RTL :

1. **Propriétés logiques** plutôt que physiques :
   `margin-inline-start` / `padding-inline-end` plutôt que `…-left` / `…-right`.
2. **Flexbox** : la direction s'inverse automatiquement avec `dir="rtl"`.
3. **Icônes directionnelles** (flèches, chevrons) : prévoir
   `[dir="rtl"] .icone-fleche { transform: scaleX(-1); }`.
4. **Alignement texte** : `text-align: start/end` plutôt que `left/right`.
5. **Positionnement absolu** : `inset-inline-start/end` plutôt que `left/right`.

---

## 11. i18n des libellés UI

Les libellés de navigation/topbar passent par le helper `__('domaine.clé', $params)`
(`app('translator')`, fichiers `lang/<locale>/<domaine>.json`). Exemples réels :
`__('nav.favorites')`, `__('nav.notifications')`, `__('nav.profile')`,
`__('nav.back', ['default' => 'Retour'])`.

> ⚠️ Une clé absente **renvoie la clé elle-même**. Le 2ᵉ argument `$params` est
> l'**interpolation**, pas une valeur par défaut (la convention `['default' => …]`
> vue ci-dessus n'est qu'un paramètre nommé que certaines chaînes interpolent).
> Toute nouvelle chaîne UI doit donc avoir sa clé dans les fichiers `lang/`.

---

## 12. Créer un thème CSS custom

Deux voies, toutes deux côté **admin** (`admin/themes/index.php`) :

### A. Personnaliser les couleurs d'un thème existant (sans CSS)

Utilisez le **Designer de couleurs** : choisissez les 8 `EDITABLE_TOKENS`, le
contraste WCAG AA est vérifié, et les overrides sont injectés par-dessus le thème.
Aucun fichier à écrire.

### B. Uploader une feuille de thème complète

1. Préparez un fichier CSS qui surcharge les tokens et, au besoin, des composants :

   ```css
   /* ocean.css */
   :root {
     --primary-color: #0077b6;
     --primary-light: #48cae4;
     --primary-dark:  #023e8a;
     --background-color: #f0f8ff;
     --border-color: #caf0f8;
   }
   .ui-card { border: 1px solid var(--primary-light); }
   ```

2. Dans « Installer un thème personnalisé », renseignez clé
   (`^[a-z0-9_-]{2,30}$`), nom, CSS (≤ 500 Ko) et preview optionnelle.
   `uploadTheme()` écrit `assets/css/theme-<clé>.css` et enregistre le thème en
   base (table `themes`).
3. Activez-le comme **défaut établissement** (bouton « Activer », `setDefault()`).
   `shared_header.php` chargera alors votre CSS en surcouche de `theme-classic`,
   après validation du chemin par `cssFileFor()`.

> Le CSS uploadé est filtré (`containsDangerousCSS()`) : pas de `javascript:`,
> `expression(`, `eval(`, `<script`, `url(data:text/html`, `behavior:`,
> `-moz-binding:`.

---

## 13. Tester un thème / une page

1. Naviguez plusieurs modules (Pédagogie, Communication, Vie scolaire) pour
   vérifier la cohérence des composants.
2. Basculez le **dark mode** (toggle topbar) et vérifiez `[data-theme="dark"]`.
3. Vérifiez le **contraste** (le designer affiche le rapport WCAG AA ; cible 4.5:1
   texte, 3:1 grand texte/alertes).
4. Testez en **RTL** si votre CSS touche aux marges/positionnements.
5. Testez en **mobile** : panneau coulissant de la topbar, empilement des cartes.
6. Pensez au **cache-buster** : `?v=<filemtime>` force le rechargement après
   édition — pas besoin de vider le cache navigateur manuellement.
