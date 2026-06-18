# Tests unitaires (Fronote)

Tests PHPUnit ciblant les correctifs de sécurité critiques. Ils s'appuient
uniquement sur SQLite en mémoire et la réflexion : aucune base MySQL ni
serveur n'est requis.

## Lancer les tests

```bash
composer install
composer test
```

`composer test` exécute `phpunit`, qui charge `phpunit.xml` (suite « unit »
sur `tests/Unit`, bootstrap `vendor/autoload.php`).

## Suite actuelle (`tests/Unit/`)

- **ChangePasswordIsolationTest** — `UserService::changePassword()` ne modifie
  que la table du profil ciblé (isolation eleves / professeurs sur un id partagé).
- **UploadValidationTest** — la whitelist `DocumentService::ALLOWED_EXT` exclut
  `php`/`phtml`/`phar` et inclut `pdf`/`jpg`.
- **AccountUsableTest** — `UserProvider::accountUsable()` refuse les comptes
  désactivés (`actif=0`) ou verrouillés (`locked_until` futur).

Certains tests s'auto-marquent « skipped » si une dépendance applicative
(ex. `app()`) est indisponible hors runtime, plutôt que d'échouer.
