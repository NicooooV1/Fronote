<?php
declare(strict_types=1);
namespace API\Services;

use PDO;

/**
 * Génère l'identifiant de connexion d'un utilisateur au format `nom.prenom`,
 * avec un suffixe numérique à deux chiffres (`01`, `02`, …) ajouté uniquement en
 * cas de collision. Exemple : `dupont.jean`, puis `dupont.jean01`, `dupont.jean02`.
 *
 * Source unique de vérité pour TOUS les points de création de comptes
 * (UserService::create, ImportExportService, BulkImporter) afin que les
 * conventions ne re-divergent pas.
 *
 * Unicité : vérifiée sur l'ensemble des tables d'utilisateurs métier du même
 * établissement. C'est volontairement plus strict que la contrainte SQL
 * (UNIQUE(identifiant, etablissement_id) par table) : le login
 * (UserProvider::findByLoginAllTypes) cherche l'identifiant dans CHAQUE table,
 * donc un même `nom.prenom` présent dans deux tables d'un établissement créerait
 * une ambiguïté de connexion. On l'évite à la génération.
 */
final class IdentifierGenerator
{
    /** Tables d'utilisateurs métier susceptibles de contenir un identifiant de connexion. */
    private const USER_TABLES = ['eleves', 'parents', 'professeurs', 'vie_scolaire', 'administrateurs'];

    /** Longueur max de la racine, pour que racine + suffixe tienne dans la colonne varchar(50). */
    private const MAX_BASE_LEN = 40;

    /**
     * Retourne un identifiant `nom.prenom` libre.
     *
     * @param int|null $etablissementId Restreint la recherche d'unicité à cet établissement.
     *                                  null = recherche sur tous les établissements (conservateur).
     */
    public static function generate(PDO $pdo, string $nom, string $prenom, ?int $etablissementId = null): string
    {
        $base     = self::base($nom, $prenom);
        $existing = self::existingWithPrefix($pdo, $base, $etablissementId);

        if (!in_array($base, $existing, true)) {
            return $base;
        }

        $i = 1;
        do {
            $candidate = $base . sprintf('%02d', $i++);
        } while (in_array($candidate, $existing, true));

        return $candidate;
    }

    /**
     * Vrai si $identifiant n'est encore pris dans aucune des tables d'utilisateurs
     * métier de l'établissement. Sert à valider un identifiant fourni explicitement
     * (CSV / admin) avec la même garantie de non-ambiguïté que generate().
     */
    public static function isAvailable(PDO $pdo, string $identifiant, ?int $etablissementId = null): bool
    {
        foreach (self::USER_TABLES as $table) {
            try {
                if ($etablissementId !== null) {
                    $stmt = $pdo->prepare(
                        "SELECT 1 FROM `{$table}` WHERE identifiant = ? AND etablissement_id = ? LIMIT 1"
                    );
                    $stmt->execute([$identifiant, $etablissementId]);
                } else {
                    $stmt = $pdo->prepare("SELECT 1 FROM `{$table}` WHERE identifiant = ? LIMIT 1");
                    $stmt->execute([$identifiant]);
                }
                if ($stmt->fetchColumn() !== false) {
                    return false;
                }
            } catch (\PDOException $e) {
                // Table absente ou sans colonne etablissement_id : on l'ignore.
                continue;
            }
        }
        return true;
    }

    /** Racine `nom.prenom` normalisée (minuscule, sans accent ni caractère spécial), bornée en longueur. */
    public static function base(string $nom, string $prenom): string
    {
        $base = trim(strtolower(self::normalize($nom) . '.' . self::normalize($prenom)), '.');
        if ($base === '') {
            return 'user';
        }
        return substr($base, 0, self::MAX_BASE_LEN);
    }

    /**
     * Tous les identifiants existants commençant par $base, sur l'ensemble des
     * tables d'utilisateurs du même établissement. $base ne contient que
     * [a-z0-9.] (cf. normalize) : aucun joker LIKE possible.
     *
     * @return string[]
     */
    private static function existingWithPrefix(PDO $pdo, string $base, ?int $etablissementId): array
    {
        $found = [];
        foreach (self::USER_TABLES as $table) {
            try {
                if ($etablissementId !== null) {
                    $stmt = $pdo->prepare(
                        "SELECT identifiant FROM `{$table}` WHERE identifiant LIKE ? AND etablissement_id = ?"
                    );
                    $stmt->execute([$base . '%', $etablissementId]);
                } else {
                    $stmt = $pdo->prepare(
                        "SELECT identifiant FROM `{$table}` WHERE identifiant LIKE ?"
                    );
                    $stmt->execute([$base . '%']);
                }
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    $found[] = (string) $id;
                }
            } catch (\PDOException $e) {
                // Table absente ou sans colonne etablissement_id : on l'ignore.
                continue;
            }
        }
        return $found;
    }

    /**
     * Translittère vers du latin ASCII puis ne conserve que [a-zA-Z0-9].
     *
     * L'extension intl (Transliterator), si présente, gère les écritures non
     * latines (CJK, cyrillique, arabe) là où iconv //TRANSLIT échoue (il renvoie
     * « ? » sous une locale C, ce qui réduirait tous ces noms à la base « user »).
     */
    private static function normalize(string $string): string
    {
        $string = trim($string);
        if ($string === '') {
            return '';
        }
        if (class_exists('\\Transliterator')) {
            $tr = \Transliterator::create('Any-Latin; Latin-ASCII');
            if ($tr !== null) {
                $converted = $tr->transliterate($string);
                if (is_string($converted)) {
                    $string = $converted;
                }
            }
        }
        // Complément pour les diacritiques latins éventuellement restants.
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $string);
        if ($ascii !== false) {
            $string = $ascii;
        }
        return (string) preg_replace('/[^a-zA-Z0-9]/', '', $string);
    }
}
