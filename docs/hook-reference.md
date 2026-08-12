# Hook Reference — Système d'événements

Fronote n'a pas de framework : le système d'événements est un **`HookManager` maison**
(`API/Core/HookManager.php`), exposé dans le conteneur DI sous la clé `hooks`
(`app('hooks')`). Il sert à deux choses :

1. **Découpler les modules** : un module émet un événement, d'autres y réagissent
   sans dépendance directe (audit, notifications temps réel, emails…).
2. **Étendre le core sans le modifier** : on s'abonne à un point d'extension plutôt
   que de patcher le code central.

Il supporte trois styles d'API : les **événements-objets** (`dispatch()` —
le mode principal aujourd'hui), les **hooks nommés** (`fire()` — actions par
nom de chaîne) et les **filtres** (`filter()` — transformation d'une valeur en
chaîne).

> Source : `API/Core/HookManager.php`. Enregistrement du service :
> `API/bootstrap.php` (`$app->singleton('hooks', fn() => new \API\Core\HookManager())`).

---

## 1. L'API du HookManager

Toutes les méthodes sont sur l'objet renvoyé par `app('hooks')`.

| Méthode | Signature | Rôle |
|---|---|---|
| `register` | `register(string $event, callable $callback, int $priority = 10): void` | Abonne un callback à un événement (ou à une classe d'événement). |
| `fire` | `fire(string $event, mixed ...$args): void` | Déclenche un hook nommé ; passe `...$args` à chaque callback. Ne retourne rien. |
| `filter` | `filter(string $event, mixed $value, mixed ...$args): mixed` | Fait transiter `$value` dans la chaîne de callbacks ; chaque callback **retourne** la valeur modifiée. |
| `dispatch` | `dispatch(object $event): void` | Dispatche un **objet** événement vers les listeners abonnés à sa classe (et à ses parents/interfaces). |
| `has` | `has(string $event): bool` | Vrai si l'événement a au moins un callback. |
| `clear` | `clear(string $event): void` | Supprime les callbacks d'un événement. |
| `clearAll` | `clearAll(): void` | Vide tous les hooks. |

### Priorités

`register()` accepte une **priorité** (défaut `10`). Les callbacks s'exécutent par
ordre **croissant** (`ksort` interne) : `5` avant `10` avant `20`.

```php
$hooks->register('event', $callbackA, 5);   // exécuté en premier
$hooks->register('event', $callbackB, 10);  // ensuite
$hooks->register('event', $callbackC, 20);  // en dernier
```

### Isolation des erreurs

Un callback qui lève une exception **n'interrompt pas** la chaîne : `fire`,
`filter` et (via `fire`) `dispatch` enrobent chaque appel dans un `try/catch`
qui logge avec `error_log("HookManager: Error in hook '...': ...")` puis continue.
Ne comptez donc jamais sur un événement pour faire échouer une opération métier.

---

## 2. Le mode principal : événements-objets (`dispatch`)

C'est le mécanisme utilisé en production par les modules métier. Le flux est :

```
Service métier  ──dispatch(new XxxEvent(...))──▶  HookManager  ──▶  Listeners (handle())
```

### Comment `dispatch` route un événement

`dispatch(object $event)` appelle `fire()` sur le **nom de classe complet** de
l'objet, **puis** sur chacun de ses parents et interfaces :

```php
// API/Core/HookManager.php
public function dispatch(object $event): void
{
    $class = get_class($event);
    $this->fire($class, $event);                 // listeners sur la classe exacte
    foreach (array_merge(class_parents($event) ?: [],
                         class_implements($event) ?: []) as $parent) {
        $this->fire($parent, $event);            // + classes/interfaces parentes
    }
}
```

Concrètement : on s'abonne avec `register(NoteCreated::class, [$listener, 'handle'])`,
et `dispatch(new NoteCreated(...))` invoque `$listener->handle($event)`.

### Les classes d'événements

Un événement est un **simple DTO immuable** (constructeur à propriétés promues
`public readonly`). Exemples réels :

```php
// modules/notes/Events/NoteCreated.php
namespace Modules\Notes\Events;
class NoteCreated {
    public function __construct(
        public readonly int $noteId,
        public readonly array $data,   // ligne insérée (id_eleve, note, id_matiere…)
    ) {}
}

// modules/notes/Events/NoteDeleted.php  →  seulement l'id
class NoteDeleted {
    public function __construct(public readonly int $noteId) {}
}

// modules/messagerie/Events/MessageSent.php
class MessageSent {
    public function __construct(
        public readonly int $messageId,
        public readonly int $senderId,
        public readonly string $senderType,
    ) {}
}
```

> Les vraies classes vivent dans **`modules/<module>/Events/`** sous le namespace
> `Modules\<Pascal>\Events\`. Le namespace est en PascalCase même si la clé du
> module est en snake_case (ex. `emploi_du_temps` → `Modules\EmploiDuTemps\Events\`).

### Les alias `API\Events\*` (compat / core)

Le dossier `API/Events/*.php` ne contient (pour les événements métier) **que des
alias** vers les classes des modules, p. ex. :

```php
// API/Events/NoteCreated.php
class_alias(\Modules\Notes\Events\NoteCreated::class, 'API\Events\NoteCreated');
```

Conséquence : `\API\Events\NoteCreated` et `\Modules\Notes\Events\NoteCreated`
**désignent la même classe**. On peut donc dispatcher ou écouter via l'un ou
l'autre indifféremment. C'est exactement ce que fait `ClasseService` du core, qui
émet `new \API\Events\ClasseCreated(...)` alors que le module `tableau_de_bord`
s'abonne à `\Modules\TableauDeBord\Events\ClasseCreated::class` — le `class_alias`
garantit que le listener est bien déclenché.

Seuls deux événements **ne sont pas** des alias mais de vraies classes core :
`API\Events\UserCreated` et `API\Events\UserPasswordChanged`.

---

## 3. Émettre un événement depuis un service

Pattern réel (issu de `modules/notes/Services/NoteService.php`) : on dispatche
**après** l'écriture réussie, en utilisant l'opérateur `?->` car `app('hooks')`
peut être absent dans certains contextes hors-web.

```php
public function create(array $data): int
{
    // … INSERT … ;
    $id = (int) $this->pdo->lastInsertId();
    app('hooks')?->dispatch(new \Modules\Notes\Events\NoteCreated($id, $data));
    return $id;
}

public function update(int $id, array $data): bool
{
    // … UPDATE … ;
    if ($updated) {
        app('hooks')?->dispatch(new \Modules\Notes\Events\NoteUpdated($id, $data));
    }
    return $updated;
}

public function delete(int $id): bool
{
    // … DELETE … ;
    if ($deleted) {
        app('hooks')?->dispatch(new \Modules\Notes\Events\NoteDeleted($id));
    }
    return $deleted;
}
```

Conventions observées dans tout le code :

- on ne dispatche **que si l'opération a réussi** (`if ($updated)` / `if ($deleted)`) ;
- les événements `…Deleted` ne portent que l'`id` (la ligne n'existe plus) ;
- les événements `…Created` / `…Updated` portent l'`id` + le tableau `$data`.

---

## 4. Écrire et brancher un listener

Un listener est une classe avec une méthode publique (`handle()` par convention)
qui reçoit l'objet événement.

```php
namespace Modules\MonModule\Listeners;

class MonListener
{
    public function handle(object $event): void
    {
        // … réagir à l'événement …
    }
}
```

### Branchement : dans le `boot()` du ServiceProvider du module

Chaque module métier branche ses listeners dans le `boot()` de son
`ServiceProvider` (`modules/<module>/Providers/<Pascal>ServiceProvider.php`).
Exemple complet (`modules/absences/Providers/AbsencesServiceProvider.php`) :

```php
class AbsencesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('absences', fn($app) =>
            new \Modules\Absences\Services\AbsenceService($app->make('db')->getConnection()));
    }

    public function boot(): void
    {
        $hooks  = $this->app->make('hooks');
        $audit  = new \API\Events\Listeners\AuditListener();
        $ws     = new \API\Events\Listeners\WebSocketListener();
        $notify = new \API\Events\Listeners\NotifyParentAbsenceListener();

        $hooks->register(\Modules\Absences\Events\AbsenceCreated::class, [$audit,  'handle']);
        $hooks->register(\Modules\Absences\Events\AbsenceCreated::class, [$ws,     'handle']);
        $hooks->register(\Modules\Absences\Events\AbsenceCreated::class, [$notify, 'handle']);
        $hooks->register(\Modules\Absences\Events\AbsenceDeleted::class, [$audit,  'handle']);
        // … RetardCreated/Deleted, JustificatifApproved/Rejected → AuditListener
    }
}
```

Un même événement peut avoir **plusieurs listeners** (ici 3 sur `AbsenceCreated`).
Ils se déclenchent dans l'ordre d'enregistrement (priorité par défaut `10`).

### Quand le `boot()` est-il appelé ?

L'ordre est défini dans `API/bootstrap.php` :

1. `$app->register(...)` des providers core (config, db, auth, sécurité,
   établissement, traduction), puis du singleton `hooks`, puis de
   `EventServiceProvider` (listeners **core**).
2. `$app->boot()` — appelle `boot()` sur tous les providers déjà enregistrés.
3. `app('module_sdk')->bootActiveModuleProviders($app)` — découvre chaque module
   **actif** (`enabled`), charge `modules/<key>/Providers/<Pascal>ServiceProvider.php`
   (clé snake_case → namespace PascalCase) et fait `$app->register(new $provider(...))`.

> Subtilité importante (`API/Core/Application.php::register`) : une fois
> `$app->boot()` passé (`$this->booted === true`), tout `register()` ultérieur
> appelle **immédiatement** `boot()` sur le provider. Les providers de modules
> sont donc registered+booted à la volée, ce qui branche leurs listeners aussitôt.
> Conséquence pratique : **un module désactivé ne branche aucun listener**, et ses
> événements (s'ils étaient dispatchés) seraient sans effet.

---

## 5. Les listeners livrés

### `AuditListener` (`API/Events/Listeners/AuditListener.php`)

Listener générique branché sur **quasiment tous** les événements domaine. Il écrit
une ligne d'audit via `app('audit')->log($action, $modelId, ['new' => $props])`,
remplaçant les anciens appels `logAudit()` dispersés.

Fonctionnement :

- il déduit le **nom court** de la classe (`get_class` puis `strrpos('\\')`),
  ce qui le rend insensible au namespace (alias `API\Events\*` **ou**
  `Modules\…\Events\*`) ;
- il mappe ce nom court vers un libellé d'action via `$actionMap`
  (`'NoteCreated' => 'note.created'`, `'AbsenceDeleted' => 'absence.deleted'`,
  `'MessageSent' => 'message.sent'`, …) ; en l'absence d'entrée, fallback
  CamelCase → `dotted.lower` ;
- il extrait l'`id` du modèle en cherchant la première propriété entière dont le
  nom finit par `Id` (`noteId`, `absenceId`, `evenementId`…), et journalise
  l'ensemble des propriétés sous `['new' => …]`.

### `WebSocketListener` (`API/Events/Listeners/WebSocketListener.php`)

Pousse des notifications temps réel via `API\Core\WebSocket` pour quelques
événements critiques uniquement. Sa méthode `handle()` fait un `match` sur le type
concret et ignore le reste :

| Événement | Action WebSocket |
|---|---|
| `NoteCreated` | `WebSocket::notifyNewGrade($data['id_eleve'], …)` |
| `AbsenceCreated` | `WebSocket::notifyNewAbsence($data['id_eleve'], …)` |
| `MessageSent` | `WebSocket::notifyUser($senderId, …)` |
| `EvenementCreated` | `WebSocket::notifyNewEvent('all', 0, …)` |

Il n'est branché que là où c'est utile : `NoteCreated`, `AbsenceCreated`,
`MessageSent`, `EvenementCreated` (cf. les providers correspondants).

### `NotifyParentAbsenceListener` (`API/Events/Listeners/NotifyParentAbsenceListener.php`)

Branché sur `AbsenceCreated`. Notifie **in-app** les parents de l'élève absent — de
façon **synchrone** (il n'y a ni file de jobs ni relais e-mail sur ce déploiement).
Il :

1. vérifie le feature flag opt-in `absences.notify_parents` (`app('features')->isEnabled(...)`) ;
2. récupère les parents (`parent_eleve`) et crée une notification via `\NotificationService::creer()` ;
3. est **fail-safe** : toute erreur est journalisée sans jamais casser la création de l'absence.

> Bon exemple de listener best-effort : il ne doit **jamais** faire échouer l'action
> métier qui l'a déclenché (tout est enveloppé dans un `try/catch`).

---

## 6. Événements domaine disponibles (catalogue réel)

Tous portent un `id` (+ généralement un tableau `$data` reflétant la ligne).
Le namespace réel est `Modules\<Pascal>\Events\` ; l'alias `API\Events\<Nom>`
existe aussi pour chacun.

### Notes — `modules/notes/` · émis par `NoteService`

| Classe | Constructeur | Action audit |
|---|---|---|
| `NoteCreated` | `(int $noteId, array $data)` | `note.created` |
| `NoteUpdated` | `(int $noteId, array $data)` | `note.updated` |
| `NoteDeleted` | `(int $noteId)` | `note.deleted` |

`NoteCreated` est aussi écouté par `WebSocketListener`.

### Absences — `modules/absences/` · émis par `AbsenceService`

| Classe | Constructeur | Action audit |
|---|---|---|
| `AbsenceCreated` | `(int $absenceId, array $data)` | `absence.created` |
| `AbsenceDeleted` | `(int $absenceId)` | `absence.deleted` |
| `RetardCreated` | `(int $retardId, array $data)` | `retard.created` |
| `RetardDeleted` | `(int $retardId)` | `retard.deleted` |
| `JustificatifApproved` | `(int $justificatifId, int $adminId, string $comment)` | `justificatif.approved` |
| `JustificatifRejected` | `(int $justificatifId, int $adminId, string $comment)` | `justificatif.rejected` |

`AbsenceCreated` est aussi écouté par `WebSocketListener` **et**
`NotifyParentAbsenceListener`.

### Devoirs — `modules/devoirs/` · émis par `DevoirService`

| Classe | Constructeur | Action audit |
|---|---|---|
| `DevoirCreated` | `(int $devoirId, array $data)` | `devoir.created` |
| `DevoirUpdated` | `(int $devoirId, array $data)` | `devoir.updated` |
| `DevoirDeleted` | `(int $devoirId)` | `devoir.deleted` |

### Agenda — `modules/agenda/` · émis par `EvenementService`

| Classe | Constructeur | Action audit |
|---|---|---|
| `EvenementCreated` | `(int $evenementId, array $data)` | `evenement.created` |
| `EvenementUpdated` | `(int $evenementId, array $data)` | `evenement.updated` |
| `EvenementDeleted` | `(int $evenementId)` | `evenement.deleted` |

`EvenementCreated` est aussi écouté par `WebSocketListener`.

### Emploi du temps — `modules/emploi_du_temps/` · émis par `MatiereService` / `PeriodeService`

| Classe | Constructeur | Action audit |
|---|---|---|
| `MatiereCreated` / `MatiereUpdated` | `(int $matiereId, array $data)` | `matiere.created` / `matiere.updated` |
| `MatiereDeleted` | `(int $matiereId)` | `matiere.deleted` |
| `PeriodeCreated` / `PeriodeUpdated` | `(int $periodeId, array $data)` | `periode.created` / `periode.updated` |
| `PeriodeDeleted` | `(int $periodeId)` | `periode.deleted` |

### Tableau de bord / Classes — `modules/tableau_de_bord/` · émis par les `ClasseService`

| Classe | Constructeur | Action audit |
|---|---|---|
| `ClasseCreated` / `ClasseUpdated` | `(int $classeId, array $data)` | `classe.created` / `classe.updated` |
| `ClasseDeleted` | `(int $classeId)` | `classe.deleted` |

> Deux services émettent des événements `Classe…` : `API/Services/Scolaire/ClasseService.php`
> (via l'alias `\API\Events\ClasseCreated`) et le service homonyme du module.
> Grâce au `class_alias`, le listener d'audit du module les capte dans les deux cas.

### Messagerie — `modules/messagerie/` · émis depuis l'envoi de message

| Classe | Constructeur | Action audit |
|---|---|---|
| `MessageSent` | `(int $messageId, int $senderId, string $senderType)` | `message.sent` |

Aussi écouté par `WebSocketListener`.

### Événements core (transversaux)

Vraies classes (pas des alias), branchées sur `AuditListener` dans
`API/Providers/EventServiceProvider.php` :

| Classe | Constructeur | Action audit |
|---|---|---|
| `API\Events\UserCreated` | `(int $userId, string $userType, array $data)` | `user.created` |
| `API\Events\UserPasswordChanged` | `(int $userId, string $userType)` | `user.password_changed` |

> État actuel : ces deux événements sont **déclarés et leurs listeners branchés**,
> mais **aucun service ne les dispatche encore** (points d'extension prêts à
> l'emploi). Si vous créez/maj un utilisateur, c'est l'endroit naturel pour
> ajouter `app('hooks')?->dispatch(new \API\Events\UserCreated($id, $type, $data))`.

---

## 7. Hooks nommés (`fire`) et filtres (`filter`)

En plus des événements-objets, le `HookManager` fournit deux API par **nom de
chaîne**. Elles sont disponibles et documentées, mais **peu utilisées** dans le
code actuel (le mode objet domine) — réservez-les à vos propres points
d'extension.

### Hook nommé — `fire` / `register`

```php
// Émettre une action nommée
app('hooks')->fire('mon_module.import_done', $rapport);

// Y réagir (dans un boot() de provider)
app('hooks')->register('mon_module.import_done', function(array $rapport) {
    app('log')->info('Import terminé', $rapport);
});
```

`fire()` ne retourne rien : c'est une notification « fire-and-forget ».

### Filtre — `filter` / `register`

Un filtre transforme une valeur en la faisant passer dans chaque callback ; chaque
callback **reçoit la valeur courante et doit la retourner** :

```php
// Brancher un filtre
app('hooks')->register('mon_module.export_columns', function(array $cols) {
    $cols[] = 'moyenne';
    return $cols;          // ⚠️ toujours retourner la valeur
});

// Appliquer le filtre
$colonnes = app('hooks')->filter('mon_module.export_columns', $colonnesParDefaut);
```

`register()` est la **même méthode** pour les hooks et les filtres : c'est l'appel
côté émetteur (`fire` vs `filter`) qui détermine la sémantique. Un callback de
filtre doit retourner une valeur ; un callback de hook n'a pas besoin de retourner.

---

## 8. Recette : ajouter un événement à votre module

1. **Créer la classe d'événement** dans `modules/<module>/Events/MonEvenement.php` :

   ```php
   namespace Modules\<Pascal>\Events;
   class MonEvenement {
       public function __construct(
           public readonly int $monId,
           public readonly array $data,
       ) {}
   }
   ```

2. **Dispatcher** depuis le service, après succès de l'opération :

   ```php
   app('hooks')?->dispatch(new \Modules\<Pascal>\Events\MonEvenement($id, $data));
   ```

3. **Brancher les listeners** dans le `boot()` du ServiceProvider du module :

   ```php
   public function boot(): void
   {
       $hooks = $this->app->make('hooks');
       $audit = new \API\Events\Listeners\AuditListener();   // audit « gratuit »
       $hooks->register(\Modules\<Pascal>\Events\MonEvenement::class, [$audit, 'handle']);
   }
   ```

   Pour que l'`AuditListener` produise un libellé propre, ajoutez l'entrée
   correspondante dans `$actionMap` (`API/Events/Listeners/AuditListener.php`),
   p. ex. `'MonEvenement' => 'mon.evenement'` ; sinon il génère
   `mon.evenement` par défaut via le fallback CamelCase.

---

## Bonnes pratiques

1. **Dispatcher après succès** : émettez l'événement seulement si l'écriture a
   abouti (`if ($updated)` / `if ($deleted)`), jamais avant.
2. **Événements immuables** : DTO `public readonly`, pas de logique dedans.
3. **Listeners légers** : déléguez le travail lourd (emails, exports) à
   `app('queue')`, comme `NotifyParentAbsenceListener`.
4. **Ne dépendez pas de l'effet** : les erreurs de listeners sont avalées et
   loggées — un listener ne doit pas être le seul garant d'une règle métier.
5. **Brancher dans `boot()`, pas `register()`** : `register()` du provider sert
   à déclarer des singletons ; les abonnements aux hooks vont dans `boot()`.
6. **Nommage** : pour les hooks/filtres nommés, dot-notation préfixée par la clé
   du module (`mon_module.action`) pour éviter les collisions.
7. **Utilisez `?->`** sur `app('hooks')` dans les services : le hook manager peut
   être absent dans certains contextes (CLI, scripts).
