<?php
namespace API\Auth;

use PDO;

/**
 * Fournisseur d'utilisateurs
 */
class UserProvider
{
    protected $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Récupère un utilisateur par son ID
     * @note Utilise 'type' comme clé standard (plus 'profil')
     */
    public function retrieveById($userId, $userType)
    {
        $table = $this->getTableForUserType($userType);

        if (!$table) {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT id, nom, prenom, mail AS email, etablissement_id
            FROM `{$table}` WHERE id = ?
        ");
        $stmt->execute([$userId]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $user['type'] = $userType;
        }
        return $user ?: null;
    }

    /**
     * Récupère un utilisateur par ses identifiants
     */
    public function retrieveByCredentials($credentials)
    {
        // Accept either 'login' or 'email' as input field
        $login = $credentials['login'] ?? $credentials['email'] ?? null;
        $userType = $credentials['type'] ?? null;

        if (!$login || !$userType) {
            return null;
        }

        $table = $this->getTableForUserType($userType);
        if (!$table) {
            return null;
        }

        // Lookup by email OR identifiant. Au moment du login l'établissement
        // courant n'est pas encore résolu (chicken-and-egg) → si EstablishmentContext::id()
        // refuse de défaut (multi-établissement, pas de session), on cherche cross-établissement
        // puis le contexte sera fixé après login depuis user.etablissement_id.
        //
        // Si l'appelant fournit explicitement un `etablissement_id` (résolution d'ambiguïté
        // par le sélecteur de profil), il prime sur le contexte ambiant.
        $etabId = null;
        if (isset($credentials['etablissement_id'])) {
            $etabId = (int) $credentials['etablissement_id'];
            if ($etabId <= 0) $etabId = null;
        }
        if ($etabId === null) {
            try { $etabId = \API\Core\EstablishmentContext::id(); } catch (\Throwable $e) { $etabId = null; }
        }

        if ($etabId !== null) {
            $stmt = $this->pdo->prepare(
                "SELECT id, nom, prenom, mail AS email, mot_de_passe, etablissement_id
                 FROM `{$table}`
                 WHERE (mail = ? OR identifiant = ?) AND etablissement_id = ?
                 LIMIT 1"
            );
            $stmt->execute([$login, $login, $etabId]);
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT id, nom, prenom, mail AS email, mot_de_passe, etablissement_id
                 FROM `{$table}`
                 WHERE (mail = ? OR identifiant = ?)
                 LIMIT 1"
            );
            $stmt->execute([$login, $login]);
        }

        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $user['type'] = $userType;
        }
        return $user ?: null;
    }

    /**
     * Valide les identifiants d'un utilisateur
     */
    public function validateCredentials($user, $credentials)
    {
        $password = $credentials['password'] ?? null;
        
        if (!$password || !isset($user['mot_de_passe'])) {
            return false;
        }

        return password_verify($password, $user['mot_de_passe']);
    }

    /**
     * Recherche un utilisateur dans TOUTES les tables par login/email.
     * Retourne un tableau de candidats (peut en avoir plusieurs si même identifiant dans tables différentes).
     * Chaque entrée contient mot_de_passe pour validation.
     */
    public function findByLoginAllTypes(string $login): array
    {
        $types = [
            'administrateur' => 'administrateurs',
            'vie_scolaire'   => 'vie_scolaire',
            'professeur'     => 'professeurs',
            'eleve'          => 'eleves',
            'parent'         => 'parents',
        ];

        // Au login le contexte d'établissement n'est pas encore fixé : si
        // EstablishmentContext::id() refuse de défaut (multi-établissement, pas de
        // session), on cherche cross-établissement — AuthManager fixera le contexte
        // ensuite à partir de user.etablissement_id.
        $etabId = null;
        try { $etabId = \API\Core\EstablishmentContext::id(); } catch (\Throwable $e) { $etabId = null; }

        $found = [];
        foreach ($types as $type => $table) {
            try {
                if ($etabId !== null) {
                    $stmt = $this->pdo->prepare(
                        "SELECT id, nom, prenom, mail AS email, mot_de_passe, identifiant, etablissement_id
                         FROM `{$table}`
                         WHERE (mail = ? OR identifiant = ?) AND etablissement_id = ?
                         LIMIT 1"
                    );
                    $stmt->execute([$login, $login, $etabId]);
                } else {
                    $stmt = $this->pdo->prepare(
                        "SELECT id, nom, prenom, mail AS email, mot_de_passe, identifiant, etablissement_id
                         FROM `{$table}`
                         WHERE (mail = ? OR identifiant = ?)
                         LIMIT 1"
                    );
                    $stmt->execute([$login, $login]);
                }
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user) {
                    $user['type'] = $type;
                    $found[] = $user;
                }
            } catch (\PDOException $e) {
                continue;
            }
        }

        return $found;
    }

    /**
     * Retourne la table correspondant au type d'utilisateur
     */
    protected function getTableForUserType($userType)
    {
        $tables = [
            'eleve' => 'eleves',
            'parent' => 'parents',
            'professeur' => 'professeurs',
            'vie_scolaire' => 'vie_scolaire',
            'administrateur' => 'administrateurs'
        ];

        return $tables[$userType] ?? null;
    }
}
