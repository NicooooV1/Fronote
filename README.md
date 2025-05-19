# Pronote - Système de Gestion Scolaire

Bienvenue sur le projet Pronote, une application web de gestion scolaire inspirée du célèbre logiciel Pronote. Cette application permet de gérer les notes, absences, cahiers de textes, messagerie et agenda dans un établissement scolaire.

## Table des matières

1. [Prérequis](#prérequis)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [Structure du projet](#structure-du-projet)
5. [Modules](#modules)
   - [Accueil](#module-accueil)
   - [Notes](#module-notes)
   - [Absences](#module-absences)
   - [Cahier de textes](#module-cahier-de-textes)
   - [Agenda](#module-agenda)
   - [Messagerie](#module-messagerie)
6. [Rôles utilisateurs](#rôles-utilisateurs)
7. [API](#api)
8. [Dépannage](#dépannage)
9. [Contribution](#contribution)

## Prérequis

Pour installer et utiliser cette application, vous aurez besoin de :

- PHP 7.4 ou supérieur
- MySQL ou MariaDB
- Serveur web (Apache, Nginx, etc.)
- Extensions PHP requises : pdo, pdo_mysql, json, mbstring, session

## Installation

### Méthode 1 : Installation automatique (recommandée)

1. **Téléchargement** : Téléchargez l'archive du projet et décompressez-la dans le répertoire web de votre serveur.

2. **Accès à l'installation** : Accédez à `http://votre-serveur/pronote/install.php` depuis votre navigateur.

3. **Configuration** : Suivez les instructions pour configurer l'application :
   - Renseignez l'URL de base de l'application (par exemple, `/pronote`)
   - Sélectionnez l'environnement (`development`, `production` ou `test`)
   - Entrez les informations de connexion à votre base de données
   - Cliquez sur "Installer"

4. **Finalisation** : Une fois l'installation terminée, vous serez redirigé vers la page de connexion.

### Méthode 2 : Installation manuelle

1. **Téléchargement** : Téléchargez l'archive du projet et décompressez-la dans le répertoire web de votre serveur.

2. **Configuration** : Créez un fichier `env.php` dans le répertoire `API/config/` avec le contenu suivant :
   ```php
   <?php
   // Environnement (development, production, test)
   if (!defined('APP_ENV')) define('APP_ENV', 'production');

   // Configuration de base
   if (!defined('APP_NAME')) define('APP_NAME', 'Pronote');
   if (!defined('APP_VERSION')) define('APP_VERSION', '1.0.0');

   // Configuration des URLs et chemins
   if (!defined('BASE_URL')) define('BASE_URL', '/pronote'); // Ajustez selon votre installation
   if (!defined('APP_ROOT')) define('APP_ROOT', realpath(__DIR__ . '/../../'));

   // URLs communes construites avec BASE_URL
   if (!defined('LOGIN_URL')) define('LOGIN_URL', BASE_URL . '/login/public/index.php');
   if (!defined('LOGOUT_URL')) define('LOGOUT_URL', BASE_URL . '/login/public/logout.php');
   if (!defined('HOME_URL')) define('HOME_URL', BASE_URL . '/accueil/accueil.php');

   // Configuration de la base de données
   if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
   if (!defined('DB_NAME')) define('DB_NAME', 'votre_base_de_donnees');
   if (!defined('DB_USER')) define('DB_USER', 'votre_utilisateur');
   if (!defined('DB_PASS')) define('DB_PASS', 'votre_mot_de_passe');
   if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');
   ```

3. **Création des répertoires** : Assurez-vous que les répertoires suivants existent et sont accessibles en écriture :
   - `API/logs`
   - `uploads`
   - `temp`

4. **Base de données** : La configuration de la base de données sera ajoutée ultérieurement.

## Configuration

### Configuration du serveur web

#### Apache

Si vous utilisez Apache, assurez-vous que le module `mod_rewrite` est activé. Un fichier `.htaccess` est inclus dans le projet pour gérer les redirections.

Exemple de configuration VirtualHost :

```apache
<VirtualHost *:80>
    ServerName pronote.example.com
    DocumentRoot /chemin/vers/pronote

    <Directory "/chemin/vers/pronote">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/pronote_error.log
    CustomLog ${APACHE_LOG_DIR}/pronote_access.log combined
</VirtualHost>
```

#### Nginx

Pour Nginx, voici un exemple de configuration :

```nginx
server {
    listen 80;
    server_name pronote.example.com;
    root /chemin/vers/pronote;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
    }

    location ~ /\.ht {
        deny all;
    }
}
```

### Configuration des permissions

Certains répertoires doivent être accessibles en écriture par le serveur web :

```bash
chmod -R 755 .
chmod -R 775 API/logs
chmod -R 775 uploads
chmod -R 775 temp
```

## Structure du projet

L'application est organisée en modules distincts :

```
pronote/
├── accueil/           # Page d'accueil
├── API/               # API centralisée et système d'authentification
├── notes/             # Module de gestion des notes
├── absences/          # Module de gestion des absences
├── cahierdetextes/    # Module de cahier de textes et devoirs
├── agenda/            # Module d'agenda et d'événements
├── messagerie/        # Module de messagerie interne
├── login/             # Système d'authentification
├── uploads/           # Dossier pour les fichiers uploadés
└── temp/              # Dossier temporaire
```

## Modules

### Module Accueil

Le module d'accueil présente une vue d'ensemble des informations importantes pour l'utilisateur connecté :
- Pour les élèves : emploi du temps, dernières notes, devoirs à faire
- Pour les professeurs : emploi du temps, prochains cours, dernières actualités
- Pour les administrateurs : statistiques, alertes système

**Accès** : `/accueil/accueil.php`

### Module Notes

Ce module permet la gestion des notes des élèves :
- Ajout, modification et suppression de notes
- Consultation des moyennes
- Génération de bulletins
- Filtrage par classe, matière, trimestre

**Fonctionnalités principales** :
- **Ajout de notes** : `/notes/ajouter_note.php`
- **Consultation des notes** : `/notes/notes.php`
- **Modification d'une note** : `/notes/modifier_note.php?id=[ID_NOTE]`
- **Suppression d'une note** : `/notes/supprimer_note.php?id=[ID_NOTE]`

### Module Absences

Ce module gère les absences et retards des élèves :
- Saisie des absences et retards
- Justification des absences
- Statistiques d'absences
- Filtrage par élève, classe, période

**Fonctionnalités principales** :
- **Saisie d'absences** : `/absences/ajouter_absence.php`
- **Faire l'appel** : `/absences/appel.php`
- **Consultation des absences** : `/absences/absences.php`

### Module Cahier de textes

Ce module permet de gérer les devoirs et le contenu des cours :
- Ajout et modification de devoirs à faire
- Consultation du travail à faire
- Suivi du programme

**Fonctionnalités principales** :
- **Ajout de devoir** : `/cahierdetextes/ajouter_devoir.php`
- **Consultation des devoirs** : `/cahierdetextes/cahierdetextes.php`
- **Modification d'un devoir** : `/cahierdetextes/modifier_devoir.php?id=[ID_DEVOIR]`

### Module Agenda

Ce module gère les événements et l'agenda de l'établissement :
- Création d'événements
- Consultation de l'agenda
- Gestion des rendez-vous

**Fonctionnalités principales** :
- **Ajout d'événement** : `/agenda/ajouter_evenement.php`
- **Consultation de l'agenda** : `/agenda/agenda.php`
- **Modification d'un événement** : `/agenda/modifier_evenement.php?id=[ID_EVENEMENT]`

### Module Messagerie

Ce module permet la communication interne entre les différents acteurs :
- Envoi de messages
- Gestion des conversations
- Notifications

**Fonctionnalités principales** :
- **Consultation des messages** : `/messagerie/index.php`
- **Nouvelle conversation** : `/messagerie/nouvelle_conversation.php`
- **Lecture d'une conversation** : `/messagerie/conversation.php?id=[ID_CONVERSATION]`

## Rôles utilisateurs

L'application gère différents types d'utilisateurs avec des permissions spécifiques :

1. **Élève** (`eleve`) :
   - Consultation de ses propres notes, absences et devoirs
   - Accès à l'agenda et à la messagerie

2. **Parent** (`parent`) :
   - Consultation des notes, absences et devoirs de ses enfants
   - Accès à l'agenda et à la messagerie

3. **Professeur** (`professeur`) :
   - Gestion des notes pour ses classes et matières
   - Saisie des absences pour ses cours
   - Ajout de devoirs et gestion du cahier de textes
   - Création d'événements pour ses classes

4. **Vie scolaire** (`vie_scolaire`) :
   - Gestion complète des absences
   - Consultation des notes et devoirs
   - Communication avec les élèves, parents et professeurs

5. **Administrateur** (`administrateur`) :
   - Accès complet à toutes les fonctionnalités
   - Gestion des utilisateurs
   - Paramétrage de l'application

## API

L'application dispose d'une API centralisée pour gérer l'authentification, les sessions et les opérations communes.

Pour utiliser l'API dans un nouveau module :

```php
// Inclure le bootstrap de l'API
require_once __DIR__ . '/../API/bootstrap.php';

// Vérifier si l'utilisateur est connecté
if (!isLoggedIn()) {
    header('Location: ' . LOGIN_URL);
    exit;
}

// Accéder à la connexion PDO
$pdo = $GLOBALS['pdo'];

// Vérifier les permissions
if (canManageNotes()) {
    // Code pour la gestion des notes
}
```

## Dépannage

### Problèmes courants

1. **Erreur de connexion à la base de données**
   - Vérifiez les paramètres de connexion dans `API/config/env.php`
   - Assurez-vous que le serveur MySQL est en cours d'exécution
   - Vérifiez les droits de l'utilisateur de la base de données

2. **Erreur "Headers already sent"**
   - Vérifiez qu'il n'y a pas de sortie HTML avant les instructions `header()`
   - Ajoutez `ob_start();` au début de vos scripts

3. **Problèmes d'authentification**
   - Vérifiez que les sessions sont correctement configurées
   - Utilisez la page de diagnostic pour vérifier les chemins d'accès

### Page de diagnostic

Une page de diagnostic est disponible pour les administrateurs à l'adresse suivante :
`/diagnostic.php`

Cette page vérifie la configuration, les permissions et l'accessibilité des différents modules.

## Contribution

Pour contribuer au projet :

1. Familiarisez-vous avec la structure du code et les conventions
2. Suivez les normes de codage PHP PSR-12
3. Documentez vos modifications
4. Testez vos modifications sur les différents rôles d'utilisateurs

---

**Note** : Les instructions pour la configuration de la base de données seront ajoutées ultérieurement.

Pour toute question ou assistance, consultez la documentation ou contactez l'équipe de développement.