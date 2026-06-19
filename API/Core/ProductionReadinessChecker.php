<?php
declare(strict_types=1);

namespace API\Core;

/**
 * ProductionReadinessChecker — garde-fou de configuration au démarrage.
 *
 * Vérifie que l'environnement n'expose pas de secrets faibles / placeholders /
 * configuration dangereuse AVANT de servir une requête en production. La classe est
 * PURE (aucune dépendance au container, aucun I/O) : elle reçoit un tableau de valeurs
 * d'environnement et retourne une liste de problèmes typés. Le câblage (lecture de
 * getenv, blocage du boot, rendu de la page d'erreur) est fait par bootstrap.php.
 *
 * Sévérités :
 *   CRITICAL — bloque le boot en production (secret absent/factice, debug ON, cookie
 *              de session en clair sur HTTPS). Ces conditions cassent l'auth ou exposent
 *              des données ; mieux vaut refuser de démarrer qu'opérer compromis.
 *   WARNING  — journalisé, jamais bloquant (http:// en LAN, health token vide, …).
 *              Volontairement non bloquant pour ne pas briser les petites instances
 *              (école sur réseau local en HTTP) — choix de déploiement assumé.
 */
final class ProductionReadinessChecker
{
    public const CRITICAL = 'critical';
    public const WARNING  = 'warning';

    /** Valeurs factices interdites (comparaison insensible à la casse). */
    private const PLACEHOLDERS = [
        'your_secure_secret_here', 'your_jwt_secret_here', 'changeme', 'change_me',
        'secret', 'password', 'todo', 'xxx', 'placeholder', 'example',
    ];

    /** Longueur minimale d'un secret cryptographique. */
    private const MIN_SECRET_LEN = 16;

    /** @var array<string,?string> */
    private array $env;
    private bool $isHttps;

    /** @var list<array{severity:string,key:string,message:string}>|null Résultat mémoïsé de check(). */
    private ?array $cachedIssues = null;

    /** @param array<string,?string> $env */
    public function __construct(array $env, bool $isHttps = false)
    {
        $this->env     = $env;
        $this->isHttps = $isHttps;
    }

    /** Construit l'instance depuis getenv() (clés pertinentes uniquement). */
    public static function fromEnvironment(bool $isHttps): self
    {
        $keys = [
            'APP_ENV', 'APP_DEBUG', 'APP_URL', 'APP_KEY', 'JWT_SECRET',
            'WEBSOCKET_ENABLED', 'WEBSOCKET_API_SECRET', 'WEBSOCKET_ALLOWED_ORIGINS',
            'SESSION_SECURE', 'HEALTH_TOKEN', 'DB_PASS',
        ];
        $env = [];
        foreach ($keys as $k) {
            $v = getenv($k);
            $env[$k] = $v === false ? null : (string) $v;
        }
        return new self($env, $isHttps);
    }

    public function isProduction(): bool
    {
        // strtolower + 'prod' accepté : aligné sur MarketplaceService::isProduction().
        // Sinon APP_ENV=prod ou Prod désactiverait silencieusement ce garde-fou.
        $env = strtolower($this->get('APP_ENV') ?: 'production');
        return in_array($env, ['production', 'prod'], true);
    }

    /**
     * @return list<array{severity:string,key:string,message:string}>
     */
    public function check(): array
    {
        if ($this->cachedIssues !== null) {
            return $this->cachedIssues;
        }
        $issues = [];

        // ── Clé maître : APP_KEY OU JWT_SECRET requis (l'un retombe sur l'autre). ──
        $appKey = $this->get('APP_KEY');
        $jwt    = $this->get('JWT_SECRET');
        if ($appKey === '' && $jwt === '') {
            $issues[] = $this->issue(self::CRITICAL, 'APP_KEY',
                'APP_KEY et JWT_SECRET sont tous deux vides : cookies signés (HMAC), '
                . 'chiffrement at-rest et sauvegardes chiffrées sont inopérants.');
        }
        foreach (['APP_KEY' => $appKey, 'JWT_SECRET' => $jwt] as $k => $v) {
            if ($v !== '' && $this->isWeakSecret($v)) {
                $issues[] = $this->issue(self::CRITICAL, $k,
                    "$k est une valeur factice ou trop courte (< " . self::MIN_SECRET_LEN . ' caractères).');
            }
        }

        // ── WebSocket : secret obligatoire + placeholders interdits si activé. ──
        // Défaut false : aligné sur install.php (WEBSOCKET_ENABLED=false) et INSTALL.md.
        // Sinon un .env dérivé de .env.example (ENABLED=true + secret placeholder) bloque
        // le boot en production alors que le WebSocket n'est pas utilisé.
        if ($this->boolish($this->get('WEBSOCKET_ENABLED'), false)) {
            $wsSecret = $this->get('WEBSOCKET_API_SECRET');
            if ($wsSecret === '' || $this->isWeakSecret($wsSecret)) {
                $issues[] = $this->issue(self::CRITICAL, 'WEBSOCKET_API_SECRET',
                    'WEBSOCKET_API_SECRET est vide, factice ou trop court alors que le WebSocket est activé.');
            }
            // JWT_SECRET faible NON vide est déjà signalé par la boucle clé-maître ci-dessus ;
            // ici on ne couvre que le cas VIDE (sinon double critique pour la même clé).
            if ($jwt === '') {
                // Le token WS client est signé avec JWT_SECRET (cf. WebSocket::generateToken).
                $issues[] = $this->issue(self::CRITICAL, 'JWT_SECRET',
                    'JWT_SECRET est vide alors que le WebSocket signe ses tokens avec.');
            }
            if ($this->get('WEBSOCKET_ALLOWED_ORIGINS') === '') {
                $issues[] = $this->issue(self::WARNING, 'WEBSOCKET_ALLOWED_ORIGINS',
                    'Aucune origine CORS WebSocket autorisée : le client navigateur ne pourra pas se connecter.');
            }
        }

        // ── Cookie de session en clair sur HTTPS = vol de session. ──
        if ($this->isHttps && !$this->boolish($this->get('SESSION_SECURE'), false)) {
            $issues[] = $this->issue(self::CRITICAL, 'SESSION_SECURE',
                'La requête est servie en HTTPS mais SESSION_SECURE=false : '
                . 'le cookie de session peut fuiter sur une connexion HTTP dégradée.');
        }

        // ── Debug actif en production : traces exposées. ──
        if ($this->boolish($this->get('APP_DEBUG'), false)) {
            $issues[] = $this->issue(self::CRITICAL, 'APP_DEBUG',
                "APP_DEBUG est actif : messages d'erreur détaillés et traces exposés aux utilisateurs.");
        }

        // ── APP_URL en http:// : à proscrire pour un accès Internet (non bloquant). ──
        $appUrl = $this->get('APP_URL');
        if ($appUrl !== '' && stripos($appUrl, 'http://') === 0) {
            $issues[] = $this->issue(self::WARNING, 'APP_URL',
                'APP_URL utilise http:// : préférer https:// pour un établissement accessible depuis Internet.');
        }

        // ── Health token vide : monitoring détaillé impossible (endpoint reste binaire). ──
        if ($this->get('HEALTH_TOKEN') === '') {
            $issues[] = $this->issue(self::WARNING, 'HEALTH_TOKEN',
                "HEALTH_TOKEN vide : /API/endpoints/health.php ne renverra qu'un statut binaire.");
        }

        // ── Mot de passe DB vide. ──
        if ($this->get('DB_PASS') === '') {
            $issues[] = $this->issue(self::WARNING, 'DB_PASS',
                'DB_PASS est vide : compte MySQL sans mot de passe.');
        }

        return $this->cachedIssues = $issues;
    }

    /** @return list<array{severity:string,key:string,message:string}> */
    public function criticalIssues(): array
    {
        return array_values(array_filter($this->check(), fn($i) => $i['severity'] === self::CRITICAL));
    }

    /** @return list<array{severity:string,key:string,message:string}> */
    public function warnings(): array
    {
        return array_values(array_filter($this->check(), fn($i) => $i['severity'] === self::WARNING));
    }

    /** Le boot doit-il être refusé ? (production + au moins un problème critique) */
    public function shouldBlockBoot(): bool
    {
        return $this->isProduction() && $this->criticalIssues() !== [];
    }

    // ───────────────────────── interne ─────────────────────────

    private function get(string $key): string
    {
        return trim((string) ($this->env[$key] ?? ''));
    }

    private function isWeakSecret(string $value): bool
    {
        $v = trim($value);
        if (strlen($v) < self::MIN_SECRET_LEN) {
            return true;
        }
        return in_array(strtolower($v), self::PLACEHOLDERS, true);
    }

    private function boolish(string $value, bool $default): bool
    {
        $v = strtolower(trim($value));
        if ($v === '') {
            return $default;
        }
        return in_array($v, ['1', 'true', 'on', 'yes'], true);
    }

    /** @return array{severity:string,key:string,message:string} */
    private function issue(string $severity, string $key, string $message): array
    {
        return ['severity' => $severity, 'key' => $key, 'message' => $message];
    }
}
