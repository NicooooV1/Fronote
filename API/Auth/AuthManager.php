<?php
declare(strict_types=1);
namespace API\Auth;

/**
 * Gestionnaire d'authentification
 */
class AuthManager
{
    protected $guard;
    protected $userProvider;

    public function __construct(SessionGuard $guard, UserProvider $userProvider)
    {
        $this->guard = $guard;
        $this->userProvider = $userProvider;
    }

    /**
     * Vérifie si l'utilisateur est authentifié
     */
    public function check()
    {
        return $this->guard->check();
    }

    /**
     * Retourne l'utilisateur actuel
     */
    public function user()
    {
        return $this->guard->user();
    }

    /**
     * Authentifie un utilisateur
     */
    public function login($userId, $userType)
    {
        $user = $this->userProvider->retrieveById($userId, $userType);
        
        if ($user) {
            $this->guard->login($user);
            return true;
        }
        
        return false;
    }

    /**
     * Déconnecte l'utilisateur
     */
    public function logout()
    {
        $this->guard->logout();
    }

    /**
     * Tente une authentification avec identifiants
     */
    public function attempt($credentials)
    {
        $user = $this->userProvider->retrieveByCredentials($credentials);

        if ($user && $this->userProvider->validateCredentials($user, $credentials)) {
            $this->guard->login($user);
            return true;
        }

        return false;
    }

    /**
     * Valide des identifiants SANS créer de session (utilisé avant vérification 2FA).
     * Si $credentials contient 'type', cherche dans la table correspondante uniquement.
     * Sinon, essaie toutes les tables dans l'ordre.
     * Retourne l'utilisateur si valide, null sinon.
     * Si plusieurs comptes correspondent au login (identifiant partagé entre tables), retourne un tableau.
     */
    public function attemptAndGetUser(array $credentials): array|null
    {
        $login    = $credentials['login'] ?? $credentials['email'] ?? null;
        $password = $credentials['password'] ?? null;
        $type     = $credentials['type'] ?? null;

        if (!$login || !$password) {
            return null;
        }

        if ($type) {
            $user = $this->userProvider->retrieveByCredentials($credentials);
            if ($user && $this->userProvider->validateCredentials($user, $credentials)) {
                if (!$this->demoAllowed() && $this->isDemoAccount($user)) { $this->denyDemo($login); return null; }
                return $user;
            }
            if (!$user) {
                $this->dummyVerify(); // identifiant inconnu : équilibrer le timing
            }
            return null;
        }

        // Multi-type : chercher dans toutes les tables
        $candidates = $this->userProvider->findByLoginAllTypes($login);
        if (empty($candidates)) {
            $this->dummyVerify(); // identifiant inconnu : équilibrer le timing
            return null;
        }
        $valid = [];
        foreach ($candidates as $user) {
            if ($this->userProvider->validateCredentials($user, $credentials)) {
                $valid[] = $user;
            }
        }

        // Comptes de démonstration : refusés sauf si explicitement autorisés (dev/test).
        if (!$this->demoAllowed()) {
            $valid = array_values(array_filter($valid, fn($u) => !$this->isDemoAccount($u)));
            if (empty($valid)) { $this->denyDemo($login); return null; }
        }

        if (count($valid) === 1) {
            return $valid[0];
        }
        if (count($valid) > 1) {
            // Plusieurs comptes → retourner le tableau pour que le login gère le choix
            return $valid;
        }

        return null;
    }

    /** Les comptes de démonstration ne sont autorisés que si ALLOW_DEMO_ACCOUNTS=true (dev/test). */
    private function demoAllowed(): bool
    {
        return filter_var(getenv('ALLOW_DEMO_ACCOUNTS') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    }

    /** Compte de démonstration = e-mail @demo.fronote.test (cf. scripts/seed_demo_*). */
    private function isDemoAccount(array $user): bool
    {
        $mail = strtolower((string) ($user['email'] ?? $user['mail'] ?? ''));
        return $mail !== '' && str_ends_with($mail, '@demo.fronote.test');
    }

    /** Journalise un refus de connexion d'un compte de démonstration. */
    private function denyDemo(string $login): void
    {
        error_log("[auth] connexion refusée : compte de démonstration '{$login}' (ALLOW_DEMO_ACCOUNTS != true)");
        try {
            if (function_exists('app') && ($a = app('audit'))) { $a->logAuth('demo_blocked', $login, false); }
        } catch (\Throwable $e) {
            error_log('[auth] audit demo_blocked: ' . $e->getMessage());
        }
    }

    /**
     * Vérification de mot de passe factice (bcrypt cost 12) exécutée lorsqu'aucun
     * compte ne correspond, afin que le temps de réponse soit comparable à celui
     * d'un mot de passe erroné sur un compte existant. Empêche l'énumération de
     * comptes par canal temporel (AUTH-03).
     */
    private function dummyVerify(): void
    {
        // Hash bcrypt valide (cost 12) → password_verify exécute réellement le KDF.
        password_verify('dummy_password', '$2y$12$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy');
    }

    /**
     * Crée la session pour un utilisateur déjà validé (appelé après 2FA ou après credential check).
     */
    public function loginUser(array $user): void
    {
        $this->guard->login($user);
    }
}
