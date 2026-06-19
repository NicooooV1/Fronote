# Gestion des utilisateurs — Fronote

Documentation du modèle d'utilisateurs, des identifiants, des rôles/permissions, de la
dernière connexion et de la révocation de session.

---

## 1. Modèle : compte + rôle principal + sous-rôles

Un utilisateur = **un compte de base** (son *type*, qui est sa table de stockage) **+ un
ou plusieurs rôles fonctionnels** (catalogue) qui portent les permissions.

| Type de compte | Table | Rôles en général |
|---|---|---|
| `eleve` | `eleves` | **un seul** (son rôle de base) |
| `parent` | `parents` | **un seul** |
| `professeur` | `professeurs` | un **rôle principal** + éventuels sous-rôles (professeur principal, coordinateur…) |
| `vie_scolaire` | `vie_scolaire` | un principal + sous-rôles (CPE, infirmerie, AESH…) |
| `administrateur` | `administrateurs` | principal + sous-rôles (direction, DPO…) |
| `super_admin` | `super_admins` | **toutes les permissions** (god mode) |

- Le **rôle principal** affiché est le rôle de base du type de compte (★ dans le profil).
- Les **sous-rôles** sont des rôles du catalogue attribués via `user_roles`, chacun avec
  son **périmètre** (établissement, mes classes, élèves assignés, mes enfants, soi…).
- Source de vérité des rôles/permissions : `API/Security/RoleCatalog.php` (~110 rôles, 14
  *tiers*). Moteur d'autorisation : `API/Security/Authorization.php` (`can($perm, $ctx)`).

---

## 2. Identifiants = `nom.prenom`

L'**identifiant de connexion est au format `nom.prenom`** (nom de famille puis prénom),
généré automatiquement à la création : en minuscule, accents et caractères spéciaux retirés
(ex. *Jean Dupont* → `dupont.jean`). En cas de collision, un suffixe à deux chiffres est
ajouté : `dupont.jean01`, `dupont.jean02`, …

- Unicité vérifiée sur les **5 tables d'utilisateurs métier du même établissement**
  (élèves, parents, professeurs, vie scolaire, administrateurs) → **pas d'ambiguïté de
  login**, car le login cherche l'identifiant dans chacune de ces tables.
- Le login accepte l'identifiant **ou** l'e-mail (`API/Auth/UserProvider.php`).
- Code : `API/Services/IdentifierGenerator::generate()`, appelé par `UserService::create()`
  ainsi que par les imports CSV (`ImportExportService`, `BulkImporter`).
- Un admin peut toujours saisir un identifiant explicite (il court-circuite la génération).

---

## 3. Créer un utilisateur

Page **Admin → Utilisateurs → Ajouter** (`admin/users/create.php`).

1. Choisir le **type de compte** → les champs spécifiques s'affichent.
2. Pour le personnel, la section **Rôles fonctionnels** propose **uniquement les rôles
   compatibles avec le type de compte** (voir §4) : rôle principal + sous-rôles cochables.
3. Valider → le compte est créé avec un **identifiant** (`nom.prenom`) + un mot de passe généré, et les
   rôles choisis sont attribués (`RoleManagementService::assign`, avec garde anti-escalade).

> Les élèves et parents n'ont pas de section rôles (un seul rôle = leur type).

---

## 4. Restrictions d'attribution par type de compte

Un rôle ne peut être attribué qu'à un type de compte **compatible** — par exemple, **la
vie scolaire ne peut PAS recevoir de rôle d'administration / direction**.

Table de correspondance : `RoleCatalog::ACCOUNT_ALLOWED_TIERS`.

| Type de compte | Tiers de rôles autorisés |
|---|---|
| `eleve` / `parent` | `eleve_famille` |
| `professeur` | pédagogique, santé-social, organisation, communication, documents, stages, contrôle |
| `vie_scolaire` | vie scolaire, santé-social, communication, documents, contrôle, services |
| `administrateur` | plateforme, direction, administratif + tous les précédents |
| `super_admin` | **aucune limite** |

L'application est **double** : filtrage de l'UI (JS) **et** validation serveur dans
`RoleManagementService::assign()` (une tentative incompatible est refusée même par API).

---

## 5. Permissions & super_admin

- Les permissions d'un utilisateur = union des permissions de ses rôles effectifs
  (`RoleCatalog::effectivePermissions`), développant les jokers `*` et `domaine.*`.
- **super_admin = l'ensemble des permissions** (147/147) — pour les tests. À restreindre
  en production réelle.
- Visibles concrètement dans **Profil → Mes rôles et permissions**
  (`parametres/parametres.php?section=profil`) : rôle principal ★, sous-rôles (+ périmètre),
  et la liste des permissions groupées par catégorie (les sensibles marquées 🔒).
- Permissions de modules : visibilité gérée dans **Admin → Modules → Configurer** (rôles
  catalogue) ; accès CRUD résolu par le moteur catalogue (`RBAC::canModule`).

---

## 6. Dernière connexion

La date de **dernière connexion** est mise à jour à chaque connexion réussie (couvre 2FA
et « se souvenir de moi ») dans `SessionGuard::login()`, sur la table du compte. Affichée
dans la liste des utilisateurs et le profil.

---

## 7. Sessions & déconnexion forcée

Les sessions actives sont enregistrées dans `session_security`. Une **garde par requête**
(`API/bootstrap.php`) déconnecte l'utilisateur **dès sa requête suivante** quand :

- sa **session est fermée** par un admin (**Admin → Utilisateurs → Sessions actives** →
  *Fermer*), ou
- son **compte est désactivé** (`actif = 0`).

La désactivation révoque aussi automatiquement les sessions actives du compte. La garde
échoue *open* en cas d'indisponibilité base (ne déconnecte jamais tout le monde par erreur).

---

## 8. Comptes de démonstration (tests)

```bash
php scripts/seed_demo_users.php        # crée ~50 comptes fictifs (rôles variés)
php scripts/seed_demo_users.php clean  # les supprime
```

- E-mails `@demo.fronote.test`, **mot de passe commun** affiché en fin d'exécution.
- Connexion avec l'**identifiant** (affiché) + ce mot de passe.
- Récapitulatif : `/opt/pronote2-db/demo-users.txt`.

---

## 9. Migrations de schéma

Les changements de schéma versionnés passent par le runner de migrations :

```bash
php scripts/migrate.php status     # état
php scripts/migrate.php migrate    # applique les migrations en attente
php scripts/migrate.php rollback   # annule la dernière fournée
```

Migrations dans `database/migrations/` (up/down), journal `schema_migrations`.
`SchemaSyncService` reste un outil de réparation additif (création de tables/colonnes
manquantes), pas le système principal.
