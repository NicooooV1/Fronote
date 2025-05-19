# Pronote - Système de Gestion Scolaire

Bienvenue dans le projet Pronote, une application web complète de gestion scolaire inspirée du célèbre logiciel Pronote. Cette application permet de gérer les notes, absences, cahiers de textes, messagerie et agenda dans un établissement scolaire de manière sécurisée et centralisée.

## Table des matières

1. [Prérequis](#prérequis)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [Structure du projet](#structure-du-projet)
5. [Sécurité](#sécurité)
6. [Modules](#modules)
7. [Utilisation](#utilisation)
8. [Maintenance](#maintenance)
9. [Dépannage](#dépannage)
10. [Contribution](#contribution)

## Prérequis

Pour installer et utiliser cette application, vous aurez besoin de :

- PHP 7.4 ou supérieur
- MySQL 5.7+ ou MariaDB 10.3+
- Serveur web (Apache, Nginx)
- Extensions PHP requises : pdo, pdo_mysql, json, mbstring, session
- Recommandé : Extension intl pour la gestion des dates/formats internationaux

## Installation

### Méthode 1 : Installation automatique (recommandée)

1. **Téléchargement** : Téléchargez l'archive du projet et décompressez-la dans le répertoire web de votre serveur.

2. **Préparation** : Assurez-vous que votre serveur web est correctement configuré et que PHP a les autorisations d'écriture sur les dossiers:
   - `API/logs`
   - `API/config`
   - `uploads`
   - `temp`

3. **Accès à l'installation** : Accédez à `http://votre-serveur/pronote/install.php` depuis votre navigateur.

4. **Configuration** : Suivez les instructions pour configurer l'application :
   - Renseignez l'URL de base de l'application (par exemple, `/pronote` ou laisser vide si à la racine)
   - Sélectionnez l'environnement (`development`, `production` ou `test`)
   - Entrez les informations de connexion à votre base de données
   - Cliquez sur "Installer"

5. **Finalisation** : Une fois l'installation terminée, vous serez redirigé vers la page de connexion.

### Méthode 2 : Installation manuelle (pour utilisateurs avancés)

1. **Téléchargement et déploiement** : Décompressez l'archive dans le répertoire web de votre serveur.

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

3. **Création des répertoires** : Assurez-vous que ces répertoires existent et sont accessibles en écriture :
   - `API/logs`
   - `uploads`
   - `temp`

4. **Importation de la base de données** : Importez le fichier SQL `API/schema.sql` dans votre base de données.

5. **Finition** : Créez un fichier `install.lock` à la racine du projet pour désactiver l'installation.

## Configuration

### Configuration du serveur web

#### Apache

Voici un exemple de configuration pour Apache avec un VirtualHost :

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

Pour assurer un fonctionnement optimal et sécurisé :

```bash
# Permissions de base
chmod -R 755 .

# Dossiers nécessitant des permissions d'écriture
chmod -R 775 API/logs
chmod -R 775 uploads
chmod -R 775 temp

# Protéger les fichiers de configuration
chmod 640 API/config/env.php
```

## Structure du projet

L'application est organisée en modules distincts suivant une architecture modulaire :

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

## Sécurité

La sécurité est une priorité dans le développement de cette application. Voici quelques-unes des mesures mises en place :

- **Validation et assainissement des entrées** : Toutes les données utilisateur sont systématiquement validées et assainies pour prévenir les injections SQL et les attaques XSS.
- **Gestion des sessions** : Les sessions sont gérées de manière sécurisée, avec des identifiants de session uniques et des protections contre le détournement de session.
- **Chiffrement des données sensibles** : Les mots de passe et autres données sensibles sont chiffrés à l'aide d'algorithmes robustes.
- **Contrôle d'accès** : Des contrôles d'accès stricts sont appliqués pour s'assurer que les utilisateurs n'ont accès qu'aux fonctionnalités et données qui les concernent.

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

## Utilisation

Après l'installation et la configuration, voici comment utiliser l'application :

1. **Connexion** : Accédez à la page de connexion à l'URL configurée (par exemple, `http://votre-serveur/pronote/login/public/index.php`).
2. **Tableau de bord** : Après connexion, vous serez redirigé vers le tableau de bord adapté à votre rôle (élève, professeur, administrateur, etc.).
3. **Navigation** : Utilisez le menu pour naviguer entre les différents modules (notes, absences, cahier de textes, agenda, messagerie).
4. **Déconnexion** : Pour vous déconnecter, cliquez sur le lien de déconnexion dans le menu.

## Maintenance

Pour assurer le bon fonctionnement de l'application, voici quelques tâches de maintenance régulières :

- **Sauvegardes** : Effectuez des sauvegardes régulières de la base de données et des fichiers importants.
- **Mises à jour** : Tenez l'application à jour avec les dernières versions pour bénéficier des améliorations et correctifs de sécurité.
- **Surveillance** : Surveillez les journaux d'erreurs et d'accès pour détecter d'éventuels problèmes ou tentatives d'intrusion.

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