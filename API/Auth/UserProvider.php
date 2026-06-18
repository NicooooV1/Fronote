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
        // Rôles infrastructure : tables dédiées (colonnes différentes des 5 tables métier).
        if ($userType === 'super_admin') {
            $stmt = $this->pdo->prepare("SELECT id, nom, prenom, mail AS email, actif FROM super_admins WHERE id = ?");
            $stmt->execute([$userId]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u || (int) ($u['actif'] ?? 1) === 0) return null;
            $u['type'] = 'super_admin';
            $u['etablissement_id'] = null; // accès global, hors périmètre établissement
            return $u;
        }
        if ($userType === 'technicien') {
            $stmt = $this->pdo->prepare("SELECT id, nom, prenom, email AS email, actif FROM technicien_access WHERE id = ? AND actif = 1");
            $stmt->execute([$userId]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u) return null;
            $u['type'] = 'technicien';
            return $u;
        }

        $table = $this->getTableForUserType($userType);

        if (!$table) {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT id, nom, prenom, mail AS email, etablissement_id, actif
            FROM `{$table}` WHERE id = ?
        ");
        $stmt->execute([$userId]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return null;
        }
        // Compte désactivé par l'admin → invalider (révoque aussi les sessions déjà ouvertes).
        if ((int) ($user['actif'] ?? 1) === 0) {
            return null;
        }
        $user['type'] = $userType;
        return $user;
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
                "SELECT id, nom, prenom, mail AS email, mot_de_passe, etablissement_id, actif, locked_until
                 FROM `{$table}`
                 WHERE (mail = ? OR identifiant = ?) AND etablissement_id = ?
                 LIMIT 1"
            );
            $stmt->execute([$login, $login, $etabId]);
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT id, nom, prenom, mail AS email, mot_de_passe, etablissement_id, actif, locked_until
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

        if (!password_verify($password, $user['mot_de_passe'])) {
            return false;
        }

        // Refuser les comptes désactivés (actif=0) ou temporairement verrouillés.
        // Sans ce contrôle, la désactivation et le verrouillage côté admin sont décoratifs.
        return $this->accountUsable($user);
    }

    /**
     * Un compte est utilisable s'il est actif et non verrouillé temporairement.
     */
    protected function accountUsable(array $user): bool
    {
        if (array_key_exists('actif', $user) && (int) $user['actif'] === 0) {
            return false;
        }
        if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
            return false;
        }
        return true;
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
                        "SELECT id, nom, prenom, mail AS email, mot_de_passe, identifiant, etablissement_id, actif, locked_until
                         FROM `{$table}`
                         WHERE (mail = ? OR identifiant = ?) AND etablissement_id = ?
                         LIMIT 1"
                    );
                    $stmt->execute([$login, $login, $etabId]);
                } else {
                    $stmt = $this->pdo->prepare(
                        "SELECT id, nom, prenom, mail AS email, mot_de_passe, identifiant, etablissement_id, actif, locked_until
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

        // Rôles infrastructure (tables dédiées, colonnes spécifiques). Chaque requête est
        // isolée : une table absente ou un schéma différent ne casse pas le login métier.
        try {
            $stmt = $this->pdo->prepare("SELECT id, nom, prenom, mail AS email, mot_de_passe, identifiant, actif FROM super_admins WHERE (mail = ? OR identifiant = ?) LIMIT 1");
            $stmt->execute([$login, $login]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($u) { $u['type'] = 'super_admin'; $found[] = $u; }
        } catch (\PDOException $e) { /* table super_admins absente */ }
        try {
            $stmt = $this->pdo->prepare("SELECT id, nom, prenom, email AS email, mot_de_passe, identifiant, actif FROM technicien_access WHERE (email = ? OR identifiant = ?) AND actif = 1 LIMIT 1");
            $stmt->execute([$login, $login]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($u) { $u['type'] = 'technicien'; $found[] = $u; }
        } catch (\PDOException $e) { /* table technicien_access absente */ }

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
