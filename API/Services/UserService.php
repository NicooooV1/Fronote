<?php
namespace API\Services;

use PDO;

/**
 * Service de gestion des utilisateurs
 *
 * Centralise : CRUD, authentification, remember-me, rate limiting,
 * réinitialisation de mot de passe, génération d'identifiants.
 */
class UserService
{
    protected $pdo;

    protected $tableMap = [
        'eleve'          => 'eleves',
        'parent'         => 'parents',
        'professeur'     => 'professeurs',
        'vie_scolaire'   => 'vie_scolaire',
        'administrateur' => 'administrateurs',
    ];

    private const VALID_PROFILES = ['eleve', 'parent', 'professeur', 'vie_scolaire', 'administrateur'];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /* ================================================================
     *  CRUD
     * ================================================================ */

    /**
     * Crée un nouvel utilisateur.
     */
    public function create($profil, $userData)
    {
        if (!isset($this->tableMap[$profil])) {
            return ['success' => false, 'message' => 'Type de profil invalide.'];
        }

        $table = $this->tableMap[$profil];

        // Établissement de rattachement : périmètre d'unicité de l'identifiant nom.prenom
        // (et colonne etablissement_id de l'INSERT plus bas).
        $etabId = 1;
        if (class_exists('\\API\\Core\\EstablishmentContext')) {
            try { $etabId = (int) \API\Core\EstablishmentContext::id(); } catch (\Throwable $e) { $etabId = 1; }
        }
        if ($etabId <= 0) { $etabId = 1; }

        // Identifiant de connexion = nom.prenom (suffixe 01, 02… en cas de collision),
        // sauf si l'admin fournit explicitement un identifiant.
        $identifiant = !empty($userData['identifiant'])
            ? $userData['identifiant']
            : IdentifierGenerator::generate($this->pdo, $userData['nom'] ?? '', $userData['prenom'] ?? '', $etabId);

        // Vérifier unicité
        $stmt = $this->pdo->prepare(
            "SELECT id, identifiant, mail FROM `{$table}` WHERE identifiant = ? OR mail = ? LIMIT 1"
        );
        $stmt->execute([$identifiant, $userData['mail'] ?? '']);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ($existing['identifiant'] === $identifiant) {
                return ['success' => false, 'message' => "L'identifiant '{$identifiant}' est déjà utilisé."];
            }
            return ['success' => false, 'message' => "L'adresse email est déjà utilisée."];
        }

        // Mot de passe sécurisé
        $password       = self::generatePassword();
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        // Données de base
        $data = [
            'identifiant'  => $identifiant,
            'nom'          => $userData['nom'],
            'prenom'       => $userData['prenom'],
            'mail'         => $userData['mail'],
            'mot_de_passe' => $hashedPassword,
        ];

        // Rattachement à l'établissement courant (déjà résolu plus haut pour la génération de l'identifiant).
        $data['etablissement_id'] = $etabId;

        // Adresse : colonne NOT NULL sur parents/professeurs (présente, nullable, sur
        // administrateurs ; absente sur vie_scolaire) → la fournir (défaut '') sinon l'INSERT
        // échoue en mode SQL strict (« Field 'adresse' doesn't have a default value »).
        if (in_array($profil, ['parent', 'professeur', 'administrateur'], true)) {
            $data['adresse'] = $userData['adresse'] ?? '';
        }
        // Téléphone (optionnel) là où la colonne existe (pas sur administrateurs).
        if (isset($userData['telephone']) && $userData['telephone'] !== '' && $profil !== 'administrateur') {
            $data['telephone'] = $userData['telephone'];
        }

        // Champs spécifiques par profil
        if ($profil === 'eleve') {
            // Colonnes NOT NULL sans défaut sur eleves → toujours fournies (placeholder éditable).
            $data['date_naissance'] = !empty($userData['date_naissance']) ? $userData['date_naissance'] : '2000-01-01';
            $data['lieu_naissance'] = $userData['lieu_naissance'] ?? '';
            $data['classe']         = $userData['classe'] ?? '';
            $data['adresse']        = $userData['adresse'] ?? '';
        } elseif ($profil === 'professeur') {
            $data['matiere']               = $userData['matiere'] ?? '';
            $data['professeur_principal']   = $userData['professeur_principal'] ?? $userData['est_pp'] ?? 'non';
        } elseif ($profil === 'parent') {
            $data['metier']           = $userData['metier'] ?? null;
            $data['est_parent_eleve'] = $userData['est_parent_eleve'] ?? 'non';
        } elseif ($profil === 'vie_scolaire') {
            $data['est_CPE']        = $userData['est_CPE'] ?? 0;
            $data['est_infirmerie'] = $userData['est_infirmerie'] ?? 0;
        }

        $columns      = array_keys($data);
        $placeholders = array_fill(0, count($data), '?');

        $sql = sprintf(
            "INSERT INTO `%s` (%s) VALUES (%s)",
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_values($data));
            $legacyId = (int) $this->pdo->lastInsertId();

            // Bascule comptes unifiés : créer le compte miroir (login désormais piloté par
            // `accounts`). N'échoue JAMAIS la création de l'utilisateur (filet : sans miroir,
            // le login retombe sur le scan hérité).
            try {
                $accountType = ['eleve' => 'student', 'parent' => 'family'][$profil] ?? 'personnel';
                (new AccountService($this->pdo))->createAccount([
                    'account_type'         => $accountType,
                    'username'             => $identifiant,
                    'email'                => $userData['mail'] ?? null,
                    'password_hash'        => $hashedPassword,
                    'first_name'           => $userData['prenom'] ?? null,
                    'last_name'            => $userData['nom'] ?? null,
                    'etablissement_id'     => $etabId,
                    'status'               => 'active',
                    'legacy_type'          => $profil,
                    'legacy_id'            => $legacyId,
                    'must_change_password' => 1,
                ]);
            } catch (\Throwable $e) {
                error_log('[create] mirror account: ' . $e->getMessage());
            }

            return [
                'success'     => true,
                'identifiant' => $identifiant,
                'password'    => $password,
                'message'     => 'Utilisateur créé avec succès.',
            ];
        } catch (\PDOException $e) {
            error_log('Erreur création utilisateur: ' . $e->getMessage());
            return ['success' => false, 'message' => "Erreur lors de l'enregistrement."];
        }
    }

    /**
     * Trouve un utilisateur par son ID.
     * Si $userType est fourni, ne cherche que dans la table correspondante.
     * Sinon, recherche dans toutes les tables (attention : IDs non-uniques entre tables).
     */
    public function findById(int $id, ?string $userType = null): ?array
    {
        // Si le type est connu, recherche ciblée (sûre)
        if ($userType !== null && isset($this->tableMap[$userType])) {
            $table = $this->tableMap[$userType];
            try {
                $stmt = $this->pdo->prepare("SELECT * FROM `{$table}` WHERE id = ? LIMIT 1");
                $stmt->execute([$id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user) {
                    $user['type']   = $userType;
                    $user['profil'] = $userType;
                    return $user;
                }
            } catch (\PDOException $e) {
                error_log('findById: ' . $e->getMessage());
            }
            return null;
        }

        // Fallback : recherche dans toutes les tables (legacy)
        foreach ($this->tableMap as $profil => $table) {
            try {
                $stmt = $this->pdo->prepare("SELECT * FROM `{$table}` WHERE id = ? LIMIT 1");
                $stmt->execute([$id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user) {
                    $user['type']   = $profil;
                    $user['profil'] = $profil;
                    return $user;
                }
            } catch (\PDOException $e) {
                continue;
            }
        }
        return null;
    }

    /* ================================================================
     *  MOT DE PASSE
     * ================================================================ */

    /**
     * Change le mot de passe d'un utilisateur.
     * Si $userType est fourni, ne cherche que dans la table correspondante.
     */
    public function changePassword($userId, $newPassword, ?string $userType = null)
    {
        // Validation via PasswordPolicy si disponible (instance configurée via le binding)
        if (class_exists('\API\Security\PasswordPolicy')) {
            $policy = function_exists('app') ? app('password_policy') : new \API\Security\PasswordPolicy();
            $policyResult = $policy->validate($newPassword);
            if (!$policyResult['valid']) {
                return ['success' => false, 'message' => implode(' ', $policyResult['errors'])];
            }
        }

        $hash = \API\Security\PasswordPolicy::hash($newPassword);

        $tables = ($userType && isset($this->tableMap[$userType]))
            ? [$userType => $this->tableMap[$userType]]
            : $this->tableMap;

        foreach ($tables as $profil => $table) {
            try {
                $stmt = $this->pdo->prepare("SELECT id FROM `{$table}` WHERE id = ? LIMIT 1");
                $stmt->execute([$userId]);
                if ($stmt->fetch()) {
                    $stmt2 = $this->pdo->prepare("
                        UPDATE `{$table}` 
                        SET mot_de_passe = ?, password_changed_at = NOW() 
                        WHERE id = ?
                    ");
                    $stmt2->execute([$hash, $userId]);
                    // Synchronise le miroir accounts.password_hash (basculement complet).
                    try { (new AccountService($this->pdo))->syncPassword($profil, (int) $userId, $hash); }
                    catch (\Throwable $e) { error_log('[changePassword] sync accounts: ' . $e->getMessage()); }
                    return ['success' => true, 'message' => 'Mot de passe changé avec succès.'];
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return ['success' => false, 'message' => 'Utilisateur non trouvé.'];
    }

    /**
     * Génère un mot de passe aléatoire sécurisé (cryptographiquement sûr).
     */
    public static function generatePassword(int $length = 12): string
    {
        $length = max($length, 12);

        $upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower   = 'abcdefghijkmnopqrstuvwxyz';
        $digits  = '23456789';
        $special = '!@#$%^&*_-+=';

        $password  = $upper[random_int(0, strlen($upper) - 1)];
        $password .= $lower[random_int(0, strlen($lower) - 1)];
        $password .= $digits[random_int(0, strlen($digits) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];

        $all = $upper . $lower . $digits . $special;
        for ($i = 4; $i < $length; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }

        // Fisher-Yates with CSPRNG — str_shuffle uses libc rand(), which is predictable
        $chars = str_split($password);
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }

    /* ================================================================
     *  RÉINITIALISATION
     * ================================================================ */

    /**
     * Trouve un utilisateur par identifiant + email + téléphone.
     */
    public function findByCredentials($username, $email, $phone, $userType)
    {
        if (!isset($this->tableMap[$userType])) {
            return null;
        }

        $table = $this->tableMap[$userType];

        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM `{$table}` WHERE identifiant = ? AND mail = ? AND telephone = ? LIMIT 1"
            );
            $stmt->execute([$username, $email, $phone]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\PDOException $e) {
            error_log('findByCredentials: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Crée une demande de réinitialisation de mot de passe.
     */
    public function createResetRequest($userId, $userType)
    {
        try {
            // Vérifier s'il y a déjà une demande en attente
            $stmt = $this->pdo->prepare(
                "SELECT id FROM demandes_reinitialisation WHERE user_id = ? AND user_type = ? AND status = 'pending' LIMIT 1"
            );
            $stmt->execute([$userId, $userType]);
            if ($stmt->fetch()) {
                return false; // demande déjà en attente
            }

            $stmt = $this->pdo->prepare(
                "INSERT INTO demandes_reinitialisation (user_id, user_type, date_demande, status) VALUES (?, ?, NOW(), 'pending')"
            );
            return $stmt->execute([$userId, $userType]);
        } catch (\PDOException $e) {
            error_log('createResetRequest: ' . $e->getMessage());
            return false;
        }
    }

    /* ================================================================
     *  REMEMBER ME
     * ================================================================ */

    /**
     * Crée un token "Remember Me" pour un utilisateur.
     * Stocke aussi le user_type pour éviter l'ambiguïté d'ID entre tables.
     */
    public function createRememberToken(int $userId, ?string $userType = null): ?string
    {
        try {
            // Résoudre le user_type si non fourni
            if ($userType === null) {
                $user = $this->findById($userId);
                $userType = $user['type'] ?? null;
            }

            $token     = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);

            $this->pdo->prepare("DELETE FROM remember_tokens WHERE user_id = ? AND user_type = ?")->execute([$userId, $userType]);

            $stmt = $this->pdo->prepare(
                "INSERT INTO remember_tokens (user_id, user_type, token_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))"
            );
            $stmt->execute([$userId, $userType, $tokenHash]);

            $cookieName = 'remember_' . (defined('INSTANCE_ID') ? INSTANCE_ID : 'token');
            $cookiePath = defined('INSTANCE_COOKIE_PATH') ? INSTANCE_COOKIE_PATH : '/';
            setcookie($cookieName, $token, [
                'expires'  => time() + 30 * 86400,
                'path'     => $cookiePath,
                'secure'   => function_exists('request_is_https') ? request_is_https() : !empty($_SERVER['HTTPS']),
                'httponly'  => true,
                'samesite' => 'Lax',
            ]);

            return $token;
        } catch (\Throwable $e) {
            error_log('createRememberToken: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Vérifie un token "Remember Me" et restaure la session.
     * Utilise user_type stocké pour une résolution déterministe.
     */
    public function validateRememberToken(string $token): ?array
    {
        try {
            $tokenHash = hash('sha256', $token);

            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare(
                "SELECT user_id, user_type FROM remember_tokens WHERE token_hash = ? AND expires_at > NOW() LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$tokenHash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->pdo->rollBack();
                return null;
            }

            // Consume the token atomically before releasing the lock
            $this->pdo->prepare("DELETE FROM remember_tokens WHERE token_hash = ?")->execute([$tokenHash]);
            $this->pdo->commit();

            $user = $this->findById((int) $row['user_id'], $row['user_type'] ?? null);
            if ($user) {
                $this->createRememberToken($user['id'], $user['type'] ?? $row['user_type'] ?? null);
            }
            return $user;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('validateRememberToken: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Supprime un token "Remember Me".
     */
    public function clearRememberToken(int $userId, ?string $userType = null): void
    {
        try {
            if ($userType !== null) {
                $this->pdo->prepare("DELETE FROM remember_tokens WHERE user_id = ? AND user_type = ?")->execute([$userId, $userType]);
            } else {
                $this->pdo->prepare("DELETE FROM remember_tokens WHERE user_id = ?")->execute([$userId]);
            }
        } catch (\Throwable $e) { /* silencieux */ }

        $cookieName = 'remember_' . (defined('INSTANCE_ID') ? INSTANCE_ID : 'token');
        $cookiePath = defined('INSTANCE_COOKIE_PATH') ? INSTANCE_COOKIE_PATH : '/';
        setcookie($cookieName, '', [
            'expires'  => time() - 3600,
            'path'     => $cookiePath,
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);
    }

    /* ================================================================
     *  RATE LIMITING (login)
     * ================================================================ */

    /**
     * Vérifie le rate limiting pour les tentatives de connexion (lockout progressif).
     * Retourne le nombre de minutes restantes avant déblocage, ou 0 si autorisé.
     *
     * Seuils :  5 tentatives → 15 min
     *          10 tentatives →  1 h
     *          20 tentatives → 24 h
     */
    public function checkLoginRateLimit(string $ip, ?string $identifier = null): int
    {
        // Limitation par IP (toujours) ET par identifiant (anti brute-force ciblé
        // distribué sur plusieurs IP — AUTH-04). On renvoie le délai le plus long.
        $wait = $this->checkRateTier('ip', $ip);
        if ($identifier !== null && $identifier !== '') {
            $wait = max($wait, $this->checkRateTier('identifier', $identifier));
        }
        return $wait;
    }

    /**
     * Calcule le délai d'attente (minutes) pour une dimension donnée.
     *
     * @param string $column 'ip' ou 'identifier' (littéral contrôlé — jamais une entrée utilisateur).
     */
    private function checkRateTier(string $column, string $value): int
    {
        // Paliers décroissants : vérifié du plus restrictif au moins restrictif
        $tiers = [
            ['threshold' => 20, 'window_min' => 24 * 60, 'lock_min' => 24 * 60],
            ['threshold' => 10, 'window_min' => 60,       'lock_min' => 60],
            ['threshold' => 5,  'window_min' => 15,       'lock_min' => 15],
        ];

        try {
            foreach ($tiers as $tier) {
                $stmt = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM login_attempts
                     WHERE `{$column}` = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
                );
                $stmt->execute([$value, $tier['window_min']]);
                $attempts = (int) $stmt->fetchColumn();

                if ($attempts >= $tier['threshold']) {
                    $stmt2 = $this->pdo->prepare(
                        "SELECT MIN(attempted_at) FROM login_attempts
                         WHERE `{$column}` = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
                    );
                    $stmt2->execute([$value, $tier['window_min']]);
                    $first = $stmt2->fetchColumn();

                    if ($first) {
                        $unlocksAt = strtotime($first) + $tier['lock_min'] * 60;
                        return max(1, (int) ceil(($unlocksAt - time()) / 60));
                    }
                    return $tier['lock_min'];
                }
            }
            return 0;
        } catch (\Throwable $e) {
            // Colonne identifier absente (ancienne base) ou erreur DB : ne pas bloquer.
            return 0;
        }
    }

    /**
     * Enregistre une tentative de connexion échouée (par IP et, si fourni, par identifiant).
     */
    public function recordFailedAttempt(string $ip, ?string $identifier = null): void
    {
        try {
            $this->pdo->prepare("INSERT INTO login_attempts (ip, identifier, attempted_at) VALUES (?, ?, NOW())")
                      ->execute([$ip, $identifier]);
        } catch (\Throwable $e) {
            // Repli si la colonne `identifier` n'existe pas encore (ancienne base).
            try {
                $this->pdo->prepare("INSERT INTO login_attempts (ip, attempted_at) VALUES (?, NOW())")->execute([$ip]);
            } catch (\Throwable $e2) { /* table peut ne pas exister */ }
        }
    }

    /**
     * Nettoie les tentatives expirées.
     */
    public function cleanOldAttempts(): void
    {
        try {
            $this->pdo->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        } catch (\Throwable $e) { /* silencieux */ }
    }

    /* ================================================================
     *  UTILITAIRES
     * ================================================================ */

    /**
     * Retourne le nom de la table pour un profil donné.
     */
    public static function getTableName(string $profil): ?string
    {
        $map = [
            'eleve'          => 'eleves',
            'parent'         => 'parents',
            'professeur'     => 'professeurs',
            'vie_scolaire'   => 'vie_scolaire',
            'administrateur' => 'administrateurs',
        ];
        return $map[$profil] ?? null;
    }
}
