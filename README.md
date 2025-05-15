# Pronote

## Structure du projet

Le projet Pronote est une application web modulaire pour la gestion scolaire. Il comporte plusieurs modules indépendants qui partagent un système d'authentification et une base de données commune.

## Architecture technique

### API centralisée

Le système utilise une API centralisée située dans le répertoire `/API` à la racine du projet. Cette API fournit:

- La connexion à la base de données (`core.php`)
- L'authentification utilisateur (`auth.php`)
- L'accès aux données communes (`data.php`)
- La résolution des chemins entre différents environnements (`path_helper.php`)

### Résolution des chemins

Le système utilise un mécanisme de résolution de chemins robuste pour fonctionner à la fois en développement local et sur le serveur de production. Chaque module utilise `path_helper.php` pour localiser correctement l'API.

### Modules

- **Accueil**: Page d'accueil utilisateur
- **Notes**: Gestion des notes des élèves
- **Agenda**: Calendrier et événements
- **Messagerie**: Système de messagerie interne
- **Devoirs**: Gestion des devoirs et exercices
- **Cahier de textes**: Suivi des cours et activités

## Installation

1. Cloner le dépôt
2. Configurer la base de données dans `login/config/database.php`
3. S'assurer que les chemins dans `API/path_helper.php` sont corrects pour votre environnement
4. Accéder à l'application via le point d'entrée principal

## Développement

Pour ajouter un nouveau module, assurez-vous d'inclure le path helper au début de votre fichier principal:

```php
// Locate and include the API path helper
$path_helper = null;
$possible_paths = [
    dirname(dirname(__DIR__)) . '/API/path_helper.php',
    dirname(__DIR__) . '/API/path_helper.php',
    dirname(dirname(dirname(__DIR__))) . '/API/path_helper.php',
];

foreach ($possible_paths as $path) {
    if (file_exists($path)) {
        $path_helper = $path;
        break;
    }
}

if ($path_helper) {
    if (!defined('ABSPATH')) define('ABSPATH', dirname(__FILE__));
    require_once $path_helper;
    require_once API_CORE_PATH;
    require_once API_AUTH_PATH;
    require_once API_DATA_PATH;
}
```

Cela garantira que votre module pourra accéder à l'API partagée indépendamment de l'environnement d'exécution.