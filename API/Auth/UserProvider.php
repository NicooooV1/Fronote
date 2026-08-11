<?php
declare(strict_types=1);
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
        if (!$password) {
            return false;
        }

        // Bascule complète : on vérifie D'ABORD contre le hash du compte unifié
        // (accounts.password_hash, présent sur les candidats résolus via `accounts`),
        // puis on retombe sur le hash hérité (mot_de_passe) — filet anti-verrouillage
        // si le miroir manquait ou différait. Les candidats issus du scan hérité n'ont
        // que mot_de_passe → comportement inchangé.
        $ok = false;
        if (!empty($user['account_password_hash']) && password_verify($password, (string) $user['account_password_hash'])) {
            $ok = true;
        } elseif (!empty($user['mot_de_passe']) && password_verify($password, (string) $user['mot_de_passe'])) {
            $ok = true;
        }
        if (!$ok) {
            // Mot de passe erroné : incrémenter le compteur d'échecs et verrouiller au-delà
            // du seuil. Sans cela accountUsable() (locked_until/failed_login_attempts) ne se
            // déclenche jamais → verrouillage automatique inopérant.
            $this->registerFailedAttempt($user);
            return false;
        }

        // Refuser les comptes désactivés (actif=0) ou temporairement verrouillés.
        // Sans ce contrôle, la désactivation et le verrouillage côté admin sont décoratifs.
        if (!$this->accountUsable($user)) {
            return false;
        }

        // Succès complet : réinitialiser le compteur d'échecs et lever un éventuel verrou expiré.
        $this->resetFailedAttempts($user);
        return true;
    }

    /**
     * Incrémente failed_login_attempts pour le compte et pose un verrou temporaire
     * (locked_until) au-delà du seuil. Best-effort, par compte, sur les 5 tables métier
     * (les tables infrastructure super_admins/technicien_access n'ont pas ces colonnes).
     */
    protected function registerFailedAttempt(array $user): void
    {
        $table = $this->getTableForUserType($user['type'] ?? '');
        $id    = (int) ($user['id'] ?? 0);
        if (!$table || $id <= 0) {
            return;
        }
        try {
            $this->pdo->prepare(
                "UPDATE `{$table}` SET failed_login_attempts = failed_login_attempts + 1 WHERE id = ?"
            )->execute([$id]);
            // Au 5e échec : verrou de 15 min (aligné sur le rate-limit du login). Ne ré-arme pas
            // un verrou déjà actif (évite un allongement perpétuel sous martèlement).
            $this->pdo->prepare(
                "UPDATE `{$table}`
                    SET locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                  WHERE id = ? AND failed_login_attempts >= 5
                    AND (locked_until IS NULL OR locked_until < NOW())"
            )->execute([$id]);
        } catch (\PDOException $e) {
            error_log('[UserProvider] registerFailedAttempt: ' . $e->getMessage());
        }
    }

    /** Réinitialise le compteur d'échecs et lève le verrou après une connexion réussie. */
    protected function resetFailedAttempts(array $user): void
    {
        $table = $this->getTableForUserType($user['type'] ?? '');
        $id    = (int) ($user['id'] ?? 0);
        if (!$table || $id <= 0) {
            return;
        }
        try {
            $this->pdo->prepare(
                "UPDATE `{$table}` SET failed_login_attempts = 0, locked_until = NULL
                  WHERE id = ? AND (failed_login_attempts <> 0 OR locked_until IS NOT NULL)"
            )->execute([$id]);
        } catch (\PDOException $e) {
            error_log('[UserProvider] resetFailedAttempts: ' . $e->getMessage());
        }
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
        // Voie COMPTES UNIFIÉS (si FEATURE_ACCOUNTS activé) : on résout l'identité via la
        // table `accounts` (username/email → legacy_type/legacy_id), puis on vérifie le mot
        // de passe sur la table HÉRITÉE — source de vérité des credentials, donc jamais de
        // hash périmé ni de verrouillage accidentel.
        //   - compte ACTIF trouvé           → on l'utilise (login piloté par `accounts`) ;
        //   - compte présent mais INACTIF    → blocage, pas de repli (désactivation effective) ;
        //   - AUCUN compte pour ce login     → repli sur le scan hérité (utilisateur non encore reflété).
        if (\API\Services\AccountService::isEnabled()) {
            $res = $this->findViaAccounts($login);
            if (!empty($res['candidates'])) {
                return $res['candidates'];
            }
            if ($res['matched']) {
                return []; // un compte existe mais n'est pas actif → connexion refusée
            }
            // aucun compte ne correspond → repli sur le scan des tables héritées ↓
        }

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
     * Résout un login via la table `accounts` (comptes unifiés).
     * @return array{candidates: array, matched: bool} candidates = lignes héritées prêtes
     *         pour la vérification ; matched = vrai si au moins un compte (tout statut) matche.
     */
    private function findViaAccounts(string $login): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT username, legacy_type, legacy_id, status, password_hash FROM accounts
                  WHERE (username = ? OR email = ?)
                    AND legacy_type IS NOT NULL AND legacy_id IS NOT NULL"
            );
            $stmt->execute([$login, $login]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return ['candidates' => [], 'matched' => false]; // table absente → repli hérité
        }

        // Désambiguïsation username/email : le username (unique par établissement, miroir de
        // l'identifiant hérité) est l'identité PRIMAIRE et fait autorité. On ne retient les
        // correspondances par email QUE si aucun username ne correspond — sinon un compte dont
        // l'email égale le username d'un AUTRE compte coupterait des identités sans lien
        // (statut d'autrui bloquant le login, hash d'autrui vérifié).
        $byUsername = array_values(array_filter($rows, fn($r) => ($r['username'] ?? null) === $login));
        $effective  = $byUsername !== [] ? $byUsername : $rows;

        $candidates = [];
        foreach ($effective as $r) {
            if (($r['status'] ?? '') !== 'active') {
                continue;
            }
            $u = $this->loadLegacyForAuth((string) $r['legacy_type'], (int) $r['legacy_id']);
            if ($u !== null) {
                // Hash du compte unifié = source de vérité des credentials (bascule complète) ;
                // mot_de_passe (hérité) reste attaché comme filet de repli.
                if (!empty($r['password_hash'])) {
                    $u['account_password_hash'] = $r['password_hash'];
                }
                $candidates[] = $u;
            }
        }
        // Le verrou « compte présent mais inactif → pas de repli hérité » n'est autoritaire que
        // pour une correspondance par USERNAME (identifiant canonique). Une correspondance seulement
        // par email ne supprime pas le repli (le scan hérité cherche aussi par mail) — évite qu'un
        // compte homonyme inactif verrouille un utilisateur non encore reflété dans accounts.
        return ['candidates' => $candidates, 'matched' => $byUsername !== []];
    }

    /** Charge une ligne héritée (avec mot_de_passe) par (type, id), pour vérifier le login. */
    private function loadLegacyForAuth(string $type, int $id): ?array
    {
        try {
            if ($type === 'super_admin') {
                $stmt = $this->pdo->prepare("SELECT id, nom, prenom, mail AS email, mot_de_passe, identifiant, actif FROM super_admins WHERE id = ? AND actif = 1 LIMIT 1");
                $stmt->execute([$id]);
                $u = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($u) { $u['type'] = 'super_admin'; }
                return $u ?: null;
            }
            if ($type === 'technicien') {
                $stmt = $this->pdo->prepare("SELECT id, nom, prenom, email AS email, mot_de_passe, identifiant, actif FROM technicien_access WHERE id = ? AND actif = 1 LIMIT 1");
                $stmt->execute([$id]);
                $u = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($u) { $u['type'] = 'technicien'; }
                return $u ?: null;
            }
            $table = $this->getTableForUserType($type);
            if (!$table) {
                return null;
            }
            $stmt = $this->pdo->prepare("SELECT id, nom, prenom, mail AS email, mot_de_passe, identifiant, etablissement_id, actif, locked_until FROM `{$table}` WHERE id = ? AND actif = 1 LIMIT 1");
            $stmt->execute([$id]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($u) { $u['type'] = $type; }
            return $u ?: null;
        } catch (\PDOException $e) {
            return null;
        }
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
