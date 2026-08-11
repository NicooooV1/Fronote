<?php
declare(strict_types=1);
/**
 * Session Guard - Authentification par session
 */

namespace API\Auth;

class SessionGuard {
    protected $user;
    protected $userProvider;

    public function __construct(UserProvider $userProvider) {
        $this->userProvider = $userProvider;
    }

    /**
     * Vérifie si un utilisateur est authentifié
     */
    public function check() {
        return !is_null($this->user());
    }

    /**
     * Retourne l'utilisateur actuel
     */
    public function user() {
        if (!is_null($this->user)) {
            return $this->user;
        }

        $userId = $_SESSION['user_id'] ?? null;
        $userType = $_SESSION['user_type'] ?? null;

        if ($userId && $userType) {
            $this->user = $this->userProvider->retrieveById($userId, $userType);
        }

        return $this->user;
    }

    /**
     * Connecte un utilisateur.
     *
     * @param array $options Options de connexion :
     *   - 'impersonating' (bool) : connexion par « agir en tant que » (Support). Dans ce cas
     *     on NE met PAS à jour last_login de la cible (l'usurpation ne doit pas apparaître comme
     *     une vraie connexion) et la session active est attribuée à l'acteur Support.
     *   - 'actor_type' / 'actor_id' : identité réelle (Support) sous laquelle enregistrer la
     *     session active (session_security), au lieu de la cible impersonifiée.
     */
    public function login($user, array $options = []) {
        $impersonating = !empty($options['impersonating']);
        // Régénérer l'ID de session pour prévenir la fixation de session
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        // Ne jamais stocker le hash du mot de passe en session
        $safeUser = $user;
        unset($safeUser['mot_de_passe']);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_type'] = $user['type'];
        $_SESSION['user'] = $safeUser;

        // Store establishment scope in session — PAS de retombée silencieuse sur l'établissement 1.
        $etabId = $user['etablissement_id'] ?? ($_SESSION['etablissement_id'] ?? null);
        if ($etabId === null) {
            // Résout l'unique établissement s'il n'y en a qu'un ; sinon lève une exception
            // (refus de cloisonnement plutôt que rattachement implicite à l'établissement 1).
            $etabId = \API\Core\EstablishmentContext::id();
        }
        $_SESSION['etablissement_id'] = (int) $etabId;

        // Set the global context
        \API\Core\EstablishmentContext::set((int) $etabId);

        $this->user = $safeUser;

        // Date de dernière connexion sur la table de l'utilisateur. Point de passage
        // unique (couvre 2FA, sans-2FA, remember-me). Best-effort. Avant : jamais écrit
        // pour les 5 types → la colonne restait NULL (« dernière connexion ne marche pas »).
        // En impersonation, on NE touche PAS last_login de la cible (ce n'est pas une vraie
        // connexion de l'utilisateur usurpé).
        if (!$impersonating) {
            try {
                $llMap = [
                    'eleve' => 'eleves', 'parent' => 'parents', 'professeur' => 'professeurs',
                    'vie_scolaire' => 'vie_scolaire', 'administrateur' => 'administrateurs',
                    'super_admin' => 'super_admins',
                ];
                $llTbl = $llMap[$user['type']] ?? null;
                if ($llTbl !== null && function_exists('getPDO')) {
                    getPDO()->prepare("UPDATE `{$llTbl}` SET last_login = NOW() WHERE id = ?")
                        ->execute([(int) $user['id']]);
                }
            } catch (\Throwable $e) {
                error_log('last_login update failed: ' . $e->getMessage());
            }
        }

        // Enregistrer la session active (session_security) — mutualisé avec les mondes
        // plateforme/tenant via recordActiveSession(). Best-effort. En impersonation, la
        // session est attribuée à l'ACTEUR Support (actor_type/actor_id) et non à la cible,
        // pour ne pas simuler une connexion de l'utilisateur usurpé.
        if ($impersonating && !empty($options['actor_type']) && isset($options['actor_id'])) {
            self::recordActiveSession((string) $options['actor_type'], (int) $options['actor_id']);
        } else {
            self::recordActiveSession($user['type'], (int) $user['id']);
        }
    }

    /**
     * Enregistre/rafraîchit la session courante dans session_security : alimente l'outil admin
     * « Sessions actives » ET permet la révocation côté serveur (bootstrap la relit par session_id()).
     * Mutualisé par les 3 mondes (établissement, plateforme, tenant) pour ne pas dupliquer le REPLACE.
     * Best-effort : ne JAMAIS bloquer une connexion si l'écriture échoue.
     */
    public static function recordActiveSession(string $userType, int $userId, int $lifetime = 7200): void
    {
        try {
            if (function_exists('getPDO') && session_status() === PHP_SESSION_ACTIVE) {
                getPDO()->prepare(
                    "REPLACE INTO session_security
                       (id, user_id, user_type, ip_address, user_agent, created_at, last_activity, expires_at, is_active)
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND), 1)"
                )->execute([
                    session_id(),
                    $userId,
                    $userType,
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 1000),
                    $lifetime,
                ]);
            }
        } catch (\Throwable $e) {
            error_log('session_security record failed: ' . $e->getMessage());
        }
    }

    /**
     * Déconnecte l'utilisateur
     */
    public function logout() {
        // Invalider le "Se souvenir de moi" AVANT de vider la session, sinon
        // login/index.php restaure automatiquement la session via le cookie
        // remember_<INSTANCE_ID> au prochain chargement et l'utilisateur se
        // retrouve immédiatement reconnecté (logout "ne fonctionne pas").
        $rememberId   = $_SESSION['user_id'] ?? null;
        $rememberType = $_SESSION['user_type'] ?? null;
        if ($rememberId !== null && function_exists('app')) {
            try {
                app('API\\Services\\UserService')->clearRememberToken((int) $rememberId, $rememberType);
            } catch (\Throwable $e) {
                // best-effort : ne jamais bloquer la déconnexion
                error_log('logout: clearRememberToken failed: ' . $e->getMessage());
            }
        }

        // Marquer la session inactive dans l'outil admin « Sessions actives »
        // AVANT de détruire la session (besoin de session_id()). Best-effort.
        try {
            if (function_exists('getPDO') && session_status() === PHP_SESSION_ACTIVE) {
                getPDO()->prepare("UPDATE session_security SET is_active = 0, expires_at = NOW() WHERE id = ?")
                    ->execute([session_id()]);
            }
        } catch (\Throwable $e) { /* best-effort */ }

        $this->user = null;

        // Vider toutes les données de session
        $_SESSION = [];

        // Supprimer le cookie de session
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        // Détruire la session côté serveur
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
