<?php
declare(strict_types=1);
namespace API\Core;

/**
 * Chargeur de variables d'environnement depuis .env
 */
class EnvLoader
{
    protected $path;
    protected $loaded = false;

    public function __construct($path)
    {
        $this->path = $path;
    }

    /**
     * Charge le fichier .env
     */
    public function load()
    {
        if ($this->loaded) {
            return true;
        }

        // Cherche .env HORS de la racine web d'ABORD (répertoire parent = non servi par Apache) :
        // permet de sortir les secrets de l'arborescence servie sans changer de code, éliminant la
        // dépendance à .htaccess (cf. finding « secrets protégés seulement par .htaccess »). Repli
        // sur .env à la racine de l'app (protégé par .htaccess) pour l'installation actuelle.
        $candidates = [
            \dirname($this->path) . '/.env',   // hors docroot (recommandé)
            $this->path . '/.env',             // racine app (repli)
        ];
        $envFile = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                $envFile = $candidate;
                break;
            }
        }

        if ($envFile === null) {
            throw new \RuntimeException("Impossible de charger la configuration environnement. Le fichier .env est introuvable.");
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Ignorer les commentaires
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            // Parser la ligne KEY=VALUE
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                
                $key = trim($key);
                $value = trim($value);

                // Supprimer les guillemets autour de la valeur
                $value = trim($value, '"\'');

                // Ne pas écraser les variables déjà définies
                if (!array_key_exists($key, $_ENV) && !getenv($key)) {
                    putenv("$key=$value");
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }
            }
        }

        $this->loaded = true;
        return true;
    }

    /**
     * Vérifie si les variables requises sont définies (et non vides).
     * Cohérent avec load() qui écrit dans getenv/$_ENV/$_SERVER.
     */
    public function validate(array $required)
    {
        $missing = [];

        foreach ($required as $key) {
            $val = getenv($key);
            if ($val === false || $val === '') {
                $val = $_ENV[$key] ?? $_SERVER[$key] ?? '';
            }
            if ($val === '' || $val === null) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            throw new \RuntimeException(
                "Impossible de charger la configuration environnement. Variables manquantes: " .
                implode(', ', $missing)
            );
        }

        return true;
    }
}
