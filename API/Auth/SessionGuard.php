<?php
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
     * Connecte un utilisateur
     */
    public function login($user) {
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

        // Store establishment scope in session
        $etabId = $user['etablissement_id'] ?? ($_SESSION['etablissement_id'] ?? 1);
        $_SESSION['etablissement_id'] = (int) $etabId;

        // Set the global context
        \API\Core\EstablishmentContext::set((int) $etabId);

        $this->user = $safeUser;

        // Enregistrer la session active pour l'outil admin « Sessions actives »
        // (la table session_security n'était jamais alimentée → page toujours vide).
        // Best-effort : ne JAMAIS bloquer la connexion si l'écriture échoue.
        try {
            if (function_exists('getPDO') && session_status() === PHP_SESSION_ACTIVE) {
                $lifetime = 7200;
                getPDO()->prepare(
                    "REPLACE INTO session_security
                       (id, user_id, user_type, ip_address, user_agent, created_at, last_activity, expires_at, is_active)
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND), 1)"
                )->execute([
                    session_id(),
                    (int) $user['id'],
                    $user['type'],
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
