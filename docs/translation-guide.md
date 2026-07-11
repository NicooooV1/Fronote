# Guide d'internationalisation (i18n)

Ce guide décrit le système de traduction de Fronote : le service `TranslationService`
(exposé via `app('translator')`), l'organisation des fichiers de langue, les helpers
`__()` / `_n()`, la résolution de locale, le support RTL et la page d'administration
des traductions.

> **À lire absolument** : la section [« Le 2ᵉ argument est l'interpolation, PAS un défaut »](#-piège-le-2e-argument-nest-pas-un-défaut)
> avant d'écrire la moindre chaîne traduite. C'est le piège n°1 du système.

Code source de référence :
- `API/Services/TranslationService.php` — le service
- `API/Providers/TranslationServiceProvider.php` — enregistrement du singleton `translator`
- `API/Legacy/Bridge.php` — helpers globaux `__()`, `_n()`, `currentLocale()`
- `admin/systeme/translations.php` — page d'administration
- `templates/shared_header.php` — direction RTL/LTR, `<html dir>`, chargement `rtl.css`

---

## Vue d'ensemble

Fronote gère **8 locales** déclarées dans `TranslationService::$supportedLocales` :

| Code | Langue        | RTL | Drapeau (login) |
|------|---------------|-----|-----------------|
| `fr` | Français      | non | 🇫🇷 |
| `en` | English       | non | 🇬🇧 |
| `es` | Español       | non | 🇪🇸 |
| `de` | Deutsch       | non | 🇩🇪 |
| `ru` | Русский       | non | 🇷🇺 |
| `nl` | Nederlands    | non | 🇳🇱 |
| `ar` | العربية       | **oui** | 🇸🇦 |
| `th` | ไทย           | non | 🇹🇭 |

Les noms d'affichage proviennent de `TranslationService::getLocaleNames()` (statique).
**`fr` est la langue source** : c'est aussi la locale de fallback par défaut.

Le service est un singleton enregistré dans `TranslationServiceProvider` :

```php
// API/Providers/TranslationServiceProvider.php
$this->app->singleton('translator', function ($app) {
    $basePath       = env('APP_BASE_PATH', dirname(dirname(__DIR__)));
    $langPath       = $basePath . '/lang';
    $defaultLocale  = env('APP_LOCALE', 'fr');
    $fallbackLocale = env('APP_FALLBACK_LOCALE', 'fr');
    return new TranslationService($langPath, $defaultLocale, $fallbackLocale);
});
```

Variables `.env` correspondantes :

```ini
APP_BASE_PATH=          # racine projet (auto-détectée si vide)
APP_LOCALE=fr           # locale par défaut
APP_FALLBACK_LOCALE=fr  # locale de repli
```

---

## Organisation des fichiers de langue

Il existe **deux emplacements** distincts, et c'est important de ne pas les confondre :

### 1. Domaines globaux — `lang/<locale>/<domaine>.json`

```
lang/
├── fr/
│   ├── common.json    ← chaînes partagées (boutons, nav, rôles, cookies…)
│   ├── auth.json      ← login, mot de passe, 2FA, register…
│   └── admin.json     ← panneau d'administration
├── en/  (même structure)
├── es/  de/  ru/  nl/  ar/  th/
```

Seuls **trois domaines globaux** existent aujourd'hui : `common`, `auth`, `admin`.

### 2. Traductions par module — `modules/<clé>/lang/<locale>.json`

> ⚠️ **Changement de structure.** Les traductions de module ne sont **PAS** dans
> `lang/<locale>/modules/<clé>.json`. Elles vivent **à l'intérieur du module** :

```
modules/
├── absences/
│   └── lang/
│       ├── fr.json   ← { "absences.title": "Absences", … }
│       ├── en.json
│       └── … (ar, de, es, nl, ru, th)
├── agenda/lang/…
└── …  (≈ 44 modules disposent d'un dossier lang/)
```

C'est `TranslationService::loadDomain()` qui implémente cette résolution : pour un
domaine `modules/<clé>`, il charge d'abord `BASE_PATH/<clé>/lang/<locale>.json`
(via la constante `BASE_PATH`, sinon `dirname($langPath)`), puis retombe sur le
chemin global si le fichier module est absent.

> **Note pour les modules métier** : depuis la restructuration, les modules sont sous
> `modules/<clé>/`. Le domaine i18n d'un module reste `modules/<clé>` mais le fichier
> physique est bien `modules/<clé>/lang/<locale>.json`.

### Format JSON

Paires clé → valeur à plat, **clés en dot-notation** :

```json
{
  "absences.title": "Absences",
  "absences.justified": "Justifiée",
  "welcome_message": "Bienvenue :name",
  "items.count": "Aucun élément|:count élément|:count éléments"
}
```

- Les paramètres d'interpolation s'écrivent `:nom` dans la valeur.
- La pluralisation utilise le séparateur `|` (voir plus bas).
- L'encodage est UTF-8 ; la page admin écrit avec `JSON_UNESCAPED_UNICODE`.

---

## Utiliser les traductions en PHP

### Traduction simple

```php
echo __('btn.save');              // common.json → "Enregistrer"
echo __('login.title');           // auth.json (préfixe mappé) → "Connexion - FRONOTE"
echo __('modules/absences.absences.title'); // modules/absences/lang/<locale>.json
```

### Avec interpolation de paramètres

```php
echo __('welcome_message', ['name' => 'Jean']);
// la chaîne "Bienvenue :name" → "Bienvenue Jean"
```

L'interpolation remplace `:clé` par la valeur (`str_replace(':' . $k, $v, …)`).

### Pluralisation — `_n()`

```php
echo _n('items.count', 0);   // "Aucun élément"
echo _n('items.count', 1);   // ":count élément" → "1 élément"
echo _n('items.count', 5);   // ":count éléments" → "5 éléments"
```

`_n()` (alias de `TranslationService::choice()`) :
- injecte automatiquement `:count` = le nombre passé ;
- découpe la valeur sur `|` ;
- `count == 0` → variante `[0]` ; `count == 1` → variante `[1]` (sinon `[0]`) ;
  `count > 1` → **dernière** variante.
- Si la valeur ne contient pas de `|`, elle est renvoyée telle quelle (interpolée).

### Locale courante

```php
echo currentLocale();           // ex: "fr"  (alias de $translator->locale())
```

### Accès direct au service

```php
$t = app('translator');
$t->get('btn.save', [], 'en');  // forcer la locale en 3e argument
$t->locale();                   // locale active
$t->setLocale('en');            // change + persiste en session
$t->isRtl();                    // true pour 'ar' (et 'he', 'fa')
$t->clearCache();               // vide le cache après édition d'un fichier
```

Les helpers `__()`, `_n()`, `currentLocale()` enveloppent ces appels dans un
`try/catch` : si le service échoue, ils **retournent la clé** (ou `'fr'` pour la locale).

---

## ⚠️ PIÈGE : le 2ᵉ argument n'est PAS un défaut

C'est l'erreur la plus fréquente. **Une clé absente renvoie la clé elle-même**, jamais
un texte de repli.

```php
// La signature réelle :
__(string $key, array $params = [], ?string $locale = null): string
```

Le 2ᵉ argument `$params` sert **uniquement à l'interpolation** (`:clé` → valeur).
Il n'y a **aucune** notion de « valeur par défaut ». Si la clé n'existe dans aucune
locale (ni la locale active, ni le fallback `fr`, ni `common`), `get()` retourne… la clé.

```php
// La clé existe → OK
echo __('btn.save');                      // "Enregistrer"

// La clé N'EXISTE PAS → on voit la clé brute à l'écran, PAS un défaut
echo __('btn.does_not_exist');            // "btn.does_not_exist"

// PIÈGE FRÉQUENT (vu dans le code) : croire que 'default' est un fallback
echo __('nav.favorite_add', ['default' => 'Épingler cette page']);
//  → si 'nav.favorite_add' existe : sa traduction (le 'default' est IGNORÉ)
//  → si elle n'existe pas : "nav.favorite_add" (le 'default' n'est JAMAIS affiché)
```

`['default' => '…']` ne « marche » dans certaines pages que par accident, parce que la
clé existe réellement avec ce même texte. Ce paramètre n'est interpolé que si la chaîne
contient littéralement `:default` — ce qui n'est jamais le cas. **Ne vous reposez pas
dessus.**

**Conséquence pratique : toute nouvelle chaîne affichée DOIT avoir sa clé dans
`lang/fr/…` (au minimum), sinon l'utilisateur voit le nom de la clé.**

---

## Résolution du domaine à partir de la clé

`TranslationService::get()` déduit le fichier à charger depuis la forme de la clé :

1. **Clé avec slash** (`modules/notes.title`) → domaine = la partie avant le 1ᵉʳ point
   (`modules/notes`), reste = clé. Charge `modules/notes/lang/<locale>.json`.
2. **Préfixe mappé** via `$prefixToDomain` → pointe vers `auth.json` sans créer de
   fichier `login.json` :
   `login`, `logout`, `register`, `reset`, `password`, `2fa` → domaine `auth`,
   et la **clé complète** est conservée (ex. `login.title`).
3. **Préfixe = nom de fichier existant** (`admin.foo` si `admin.json` existe) → domaine =
   le préfixe, reste = ce qui suit le 1ᵉʳ point.
4. **Sinon** → domaine `common`.

Ordre de repli (fallback) à l'intérieur de `get()` :
1. domaine demandé, locale active ;
2. domaine demandé, locale de fallback (`fr`) ;
3. domaine `common`, locale active puis fallback ;
4. faute de tout cela → **la clé brute**.

Les fichiers chargés sont mis en cache en mémoire (`$loaded`) pour la durée de la
requête ; `clearCache()` le réinitialise.

> **État d'adoption.** Les fichiers JSON existent pour de nombreux modules, mais
> l'appel `__()` n'est aujourd'hui réellement câblé que dans le tronc commun
> (`login/index.php`, `templates/shared_topbar*.php`, `templates/cookie_consent.php`,
> `admin/etablissement/*`). La plupart des pages de module affichent encore du
> français en dur. Migrer une page = remplacer ses chaînes par `__('modules/<clé>.…')`
> et s'assurer que les clés existent dans `modules/<clé>/lang/fr.json`.

---

## Sélection de la locale active

`resolveLocale()` choisit la locale dans cet ordre de priorité (1ʳᵉ trouvée gagne) :

1. **Paramètre URL `?lang=xx`** — s'il fait partie des locales supportées, il est
   stocké en session (`$_SESSION['locale']`) et devient « sticky ».
2. **Session déjà définie** (`$_SESSION['locale']`).
3. **Préférence utilisateur en base** — colonne `user_settings.langue` (lue par
   `user_id` + `user_type`). Échec silencieux si la colonne n'existe pas.
4. **En-tête navigateur `Accept-Language`** — parsé avec pondération `q`.
5. **Défaut établissement** — colonne `etablissements.default_locale` (silencieux si
   absente).
6. **Fallback** — la locale par défaut (`APP_LOCALE`, soit `fr`).

Le sélecteur de la **page de connexion** (`login/index.php`) liste
`getSupportedLocales()` et pointe sur `?lang=<code>` ; chaque entrée a un drapeau pris
dans `$localeFlags` (tableau local au fichier) et un libellé issu de `getLocaleNames()`.

La **préférence durable** d'un utilisateur connecté se règle dans
`parametres/parametres.php` (champ `langue` du formulaire), persistée par
`parametres/includes/SettingsService.php` dans `user_settings.langue`. C'est la priorité
n°3 ci-dessus.

---

## Support RTL (droite-à-gauche)

`isRtl()` renvoie `true` pour `ar` (et accepte aussi `he`, `fa` si on les ajoute).

Le rendu est géré dans `templates/shared_header.php` :

```php
$_hdr_dir = $translator->isRtl() ? 'rtl' : 'ltr';
// …
<html lang="<?= $_hdr_locale ?>" dir="<?= $_hdr_dir ?>" …>
// …
<?php if ($_hdr_dir === 'rtl'): ?>
  <link rel="stylesheet" href="assets/css/rtl.css">
<?php endif; ?>
```

Donc en RTL : `<html dir="rtl">` et chargement de `assets/css/rtl.css` (qui inverse la
mise en page). La page de connexion fait de même (`$_loginDir` dans `login/index.php`).

**Ajouter une langue RTL** : il suffit de l'inclure dans le tableau de `isRtl()` au sein
de `TranslationService.php` (le `<html dir>` et `rtl.css` suivent automatiquement). La
maquette étant en **topbar horizontale** (plus de sidebar), `rtl.css` mirroir la topbar
et les conteneurs.

---

## Formatage localisé (dates, nombres, devises)

`TranslationService` fournit des helpers de formatage qui s'appuient sur l'extension
PHP `intl` (`IntlDateFormatter`, `NumberFormatter`) avec repli si absente :

```php
$t = app('translator');

$t->formatDate($timestamp);          // style 'medium' par défaut
$t->formatDate($date, 'short');      // 'short' | 'medium' | 'long' | 'full'
$t->formatNumber(1234.56);           // "1 234,56" (fr) / "1,234.56" (en)
$t->formatNumber(3.14159, 2);        // 2 décimales
$t->formatCurrency(99.99, 'EUR');    // selon la locale active
```

Sans `intl`, `formatDate` retombe sur `date('d/m/Y')`, `formatNumber`/`formatCurrency`
sur `number_format(...)`.

---

## Ajouter une clé de traduction

1. **Choisir le bon fichier** :
   - chaîne partagée → `lang/fr/common.json`
   - login / mot de passe / 2FA → `lang/fr/auth.json`
   - back-office → `lang/fr/admin.json`
   - propre à un module → `modules/<clé>/lang/fr.json`
2. **Ajouter la clé en `fr` d'abord** (langue source). Sans elle, l'écran affichera le
   nom de la clé.
3. **Répliquer la clé dans les autres locales** (`en`, `es`, …). Une clé absente d'une
   locale retombe sur `fr`.
4. **Appeler** `__('domaine.clé')` (ou `__('modules/<clé>.clé')`) côté code.
5. Si vous éditez un fichier à chaud dans un script long, appelez
   `app('translator')->clearCache()`.

---

## Ajouter une nouvelle langue

Exemple : ajouter le portugais (`pt`).

1. **Enregistrer la locale** dans `API/Services/TranslationService.php` :

   ```php
   private array $supportedLocales = ['fr','en','es','de','ru','nl','ar','th','pt'];
   ```

2. **Ajouter son nom d'affichage** dans `getLocaleNames()` :

   ```php
   'pt' => 'Português',
   ```

3. **Créer les domaines globaux** en copiant le `fr` puis en traduisant les *valeurs*
   (garder les clés) :

   ```bash
   mkdir -p lang/pt
   cp lang/fr/common.json lang/pt/common.json
   cp lang/fr/auth.json   lang/pt/auth.json
   cp lang/fr/admin.json  lang/pt/admin.json
   ```

4. **Créer les fichiers par module** au fur et à mesure :

   ```bash
   cp modules/absences/lang/fr.json modules/absences/lang/pt.json
   # …répéter pour chaque modules/<clé>/lang/
   ```

   Priorisez les modules à fort trafic (`accueil`, `notes`, `absences`, `messagerie`,
   `emploi_du_temps`).

5. **Ajouter le drapeau** dans `$localeFlags` de `login/index.php` (le libellé vient déjà
   de `getLocaleNames()`).

6. **(RTL uniquement)** si la langue s'écrit de droite à gauche, l'ajouter au tableau de
   `isRtl()`.

7. **Tester** : `/<page>?lang=pt`, parcourir l'app, repérer les clés non traduites
   (elles s'affichent en clair sous forme de nom de clé).

---

## Page d'administration des traductions

`admin/systeme/translations.php` (réservée au rôle `administrateur`,
protégée CSRF) offre :

- **Matrice de couverture** — pourcentage de clés traduites par locale et par domaine,
  calculé par rapport au nombre de clés `fr`. Affiché via `ui_card`/`ui_table` avec un
  badge `success` (100 %), `warning` (≥ 50 %) ou `danger` (< 50 %).
- **Éditeur en ligne** — on choisit une locale + un domaine, on clique « Charger » :
  l'éditeur affiche, pour chaque clé, la référence `fr` à gauche et un champ éditable
  pour la locale cible. Bouton « Sauver » par ligne → POST AJAX `save_translation`.

L'enregistrement écrit **directement dans le fichier JSON**
(`lang/<locale>/<domaine>.json`), avec `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE`.
Une whitelist anti-traversée valide le domaine
(`^(modules/)?[a-z0-9_-]+$`, refus de `..`).

> **⚠️ Limite connue (dérive de structure).** La matrice de couverture et l'éditeur
> énumèrent les domaines de module via `glob(lang/fr/modules/*.json)` — un emplacement
> **hérité** qui ne contient plus rien depuis que les traductions de module sont passées
> sous `modules/<clé>/lang/`. En l'état, la page admin **ne liste donc que les domaines
> globaux** (`common`, `auth`, `admin`) et n'édite pas les fichiers de module via
> l'interface. Pour traduire un module, éditez directement
> `modules/<clé>/lang/<locale>.json`.

---

## Bonnes pratiques

1. **Ne jamais coder le texte en dur** dans une page destinée à être traduite — passer
   par `__()`.
2. **`fr` est la source de vérité** : ajouter d'abord la clé `fr`, puis traduire.
3. **Le 2ᵉ argument est de l'interpolation**, pas un défaut : pas de clé `fr` = clé brute
   à l'écran. (Voir le piège plus haut.)
4. **Clés descriptives** : `grade_saved_success`, pas `msg1`. Préfixe par module/contexte
   (`absences.title`).
5. **Tester en RTL** (`?lang=ar`) pour vérifier que la mise en page tient.
6. Après édition manuelle de fichiers de langue dans un process long, appeler
   `clearCache()`.
