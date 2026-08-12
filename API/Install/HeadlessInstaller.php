<?php
declare(strict_types=1);

namespace API\Install;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Installateur HEADLESS (sans UI) de Fronote.
 *
 * Rejoue fidèlement la séquence de provisioning applicatif du wizard web
 * (install.php étape 5) mais pilotable en CLI, de façon IDEMPOTENTE :
 *   1. écrit .env (atomique ; préserve les secrets existants sur ré-exécution)
 *   2. importe pronote.sql (idempotent : codes d'erreur bénins tolérés, erreur dure = abandon)
 *   3. crée le compte administrateur (bcrypt cost 12) — ignoré s'il existe déjà
 *   4. bootstrap l'app puis : réconciliation de schéma (SchemaSyncService),
 *      synchronisation + provisioning de TOUS les modules (ModuleSDK),
 *      cohérence 3-mondes (PlatformRoleSync / TenantRoleSync / miroirs), miroir accounts
 *   5. journalise l'installation (audit_log) et écrit install.lock (atomique)
 *
 * La création de la base et de l'utilisateur DB (privilège minimal) est faite EN AMONT
 * par install.sh (root, via le client mariadb) : cet installateur se connecte avec
 * l'utilisateur applicatif à privilège restreint et ne touche jamais au serveur DB.
 */
final class HeadlessInstaller
{
    private string $baseDir;
    /** @var array<string,mixed> */
    private array $cfg;
    /** @var array<int,array{level:string,msg:string}> */
    private array $log = [];

    /** @param array<string,mixed> $config */
    public function __construct(string $baseDir, array $config)
    {
        $this->baseDir = rtrim($baseDir, '/');
        $this->cfg = $config;
    }

    /**
     * Exécute l'installation. Lève RuntimeException sur erreur dure ; sinon retourne le journal.
     *
     * @return array<int,array{level:string,msg:string}>
     */
    public function run(): array
    {
        $this->requireConfig(['db_host', 'db_port', 'db_name', 'db_user', 'db_pass', 'app_url', 'admin_mail', 'admin_pw']);

        // 1) .env
        $envPath = $this->baseDir . '/.env';
        $this->writeEnvFile($envPath);
        @chmod($envPath, ($this->cfg['app_env'] ?? 'production') === 'production' ? 0640 : 0660);
        $this->ok('.env écrit et sécurisé (0640)');

        // 2) Connexion applicative (la base + l'utilisateur existent déjà — créés par install.sh)
        $pdo = $this->connect();

        // 3) Import du socle pronote.sql
        $this->importSchema($pdo);

        // 4) Validation des tables critiques
        $this->validateCriticalTables($pdo);

        // 5) Compte administrateur (idempotent)
        $adminId = $this->ensureAdmin($pdo);

        // 6) Provisioning applicatif via le conteneur (bootstrap lit le .env fraîchement écrit)
        $this->provisionViaBootstrap($pdo, $adminId);

        // 7) Audit
        $this->writeAudit($pdo, $adminId);

        // 8) Verrou d'installation (atomique)
        $this->writeLock($envPath);

        return $this->log;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @param string[] $keys */
    private function requireConfig(array $keys): void
    {
        $missing = [];
        foreach ($keys as $k) {
            if (!isset($this->cfg[$k]) || $this->cfg[$k] === '') {
                $missing[] = $k;
            }
        }
        if ($missing) {
            throw new RuntimeException('Configuration incomplète — clés manquantes : ' . implode(', ', $missing));
        }
    }

    private function connect(): PDO
    {
        $host = strtolower((string) $this->cfg['db_host']) === 'localhost' ? '127.0.0.1' : (string) $this->cfg['db_host'];
        $charset = (string) ($this->cfg['db_charset'] ?? 'utf8mb4');
        $dsn = "mysql:host={$host};port=" . (int) $this->cfg['db_port'] . ";dbname=" . (string) $this->cfg['db_name'] . ";charset={$charset}";
        try {
            $pdo = new PDO($dsn, (string) $this->cfg['db_user'], (string) $this->cfg['db_pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $this->ok('Connexion à la base « ' . $this->cfg['db_name'] . ' » établie (utilisateur applicatif)');
            return $pdo;
        } catch (PDOException $e) {
            throw new RuntimeException(
                "Connexion à la base impossible avec l'utilisateur applicatif « {$this->cfg['db_user']} ». "
                . "La base et l'utilisateur doivent être créés au préalable (install.sh s'en charge). Détail : " . $e->getMessage()
            );
        }
    }

    private function importSchema(PDO $pdo): void
    {
        $sqlFile = $this->baseDir . '/pronote.sql';
        if (!is_file($sqlFile)) {
            throw new RuntimeException('Fichier pronote.sql introuvable à la racine.');
        }
        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            throw new RuntimeException('Impossible de lire pronote.sql.');
        }

        // Codes bénins d'un import idempotent (ré-exécution / colonne déjà présente) :
        // 1050 table existe · 1060 colonne dupliquée · 1061 index dupliqué · 1062 doublon
        // 1091 colonne/clé absente à DROP · 1022 clé dupliquée · 1826 nom FK dupliqué
        $benign = [1050, 1060, 1061, 1062, 1091, 1022, 1826];
        $tableCount = 0;
        $fatal = [];
        $benignCount = 0;

        // Sécurité : par défaut on NE joue PAS les DROP TABLE de pronote.sql. Le socle est
        // un dump « drop+create » : rejouer l'import sur une base peuplée effacerait les
        // données. En sautant les DROP, le CREATE suivant devient une erreur bénigne (1050
        // « table existe ») → table ET données préservées. Sur une install neuve, les DROP
        // sont de toute façon des no-op. Un vrai reset se fait en amont (install.sh --reset
        // recrée la base) ou en passant la config wipe=true.
        $wipe = !empty($this->cfg['wipe']);
        try { $pdo->exec('SET FOREIGN_KEY_CHECKS=0'); } catch (PDOException $e) {}
        foreach ($this->splitSql($sql) as $q) {
            $q = trim($q);
            if ($q === '' || preg_match('/^\s*(CREATE\s+DATABASE|USE\s+)/i', $q)) {
                continue;
            }
            if (!$wipe && preg_match('/^\s*DROP\s+TABLE\b/i', $q)) {
                continue; // import non destructif : on préserve les tables/données existantes
            }
            try {
                $pdo->exec($q);
                if (stripos($q, 'CREATE TABLE') !== false) {
                    $tableCount++;
                }
            } catch (PDOException $e) {
                $code = (int) ($e->errorInfo[1] ?? 0);
                if (in_array($code, $benign, true)) {
                    $benignCount++;
                } else {
                    $fatal[] = "[{$code}] " . $e->getMessage();
                }
            }
        }
        try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (PDOException $e) {}

        if ($fatal) {
            $shown = array_slice($fatal, 0, 5);
            throw new RuntimeException(
                count($fatal) . " erreur(s) SQL bloquante(s) pendant l'import du schéma :\n• " . implode("\n• ", $shown)
                . (count($fatal) > 5 ? "\n• … (+" . (count($fatal) - 5) . ' autres)' : '')
            );
        }
        $this->ok("Structure importée ({$tableCount} tables créées)"
            . ($benignCount > 0 ? " ; {$benignCount} instruction(s) déjà appliquée(s) (idempotent)" : ''));
    }

    private function validateCriticalTables(PDO $pdo): void
    {
        $critical = ['administrateurs', 'eleves', 'professeurs', 'parents', 'classes', 'matieres', 'periodes', 'modules_config'];
        $missing = [];
        foreach ($critical as $t) {
            if ($pdo->query('SHOW TABLES LIKE ' . $pdo->quote($t))->rowCount() === 0) {
                $missing[] = $t;
            }
        }
        if ($missing) {
            throw new RuntimeException('Tables critiques manquantes après import : ' . implode(', ', $missing));
        }
        $this->ok('Toutes les tables critiques vérifiées');
    }

    private function ensureAdmin(PDO $pdo): int
    {
        // Idempotent : réutilise l'admin « admin » s'il existe déjà.
        $existing = $pdo->query("SELECT id FROM administrateurs WHERE identifiant = 'admin' LIMIT 1")->fetchColumn();
        if ($existing) {
            $this->ok("Administrateur « admin » déjà présent (ID {$existing}) — conservé");
            return (int) $existing;
        }
        $hash = password_hash((string) $this->cfg['admin_pw'], PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $pdo->prepare(
            "INSERT INTO administrateurs (nom, prenom, mail, identifiant, mot_de_passe, role, actif)
             VALUES (?, ?, ?, 'admin', ?, 'administrateur', 1)"
        );
        $stmt->execute([
            (string) ($this->cfg['admin_nom'] ?? 'Admin'),
            (string) ($this->cfg['admin_prenom'] ?? 'Fronote'),
            (string) $this->cfg['admin_mail'],
            $hash,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->ok("Administrateur créé (ID {$id}) — identifiant : admin");
        return $id;
    }

    private function provisionViaBootstrap(PDO $pdo, int $adminId): void
    {
        $bootstrap = $this->baseDir . '/API/bootstrap.php';
        if (!is_file($bootstrap)) {
            $this->warn('API/bootstrap.php introuvable — provisioning des modules ignoré');
            return;
        }

        try {
            /** @var \API\Core\Application $app */
            $app = require $bootstrap;

            // Réconciliation de schéma AVANT provisioning (union additive socle ⊕ modules),
            // sinon les INSERT de données de référence des modules échouent sur colonnes absentes.
            $this->safe('Réconciliation schéma (pré)', function () use ($pdo) {
                (new \API\Services\SchemaSyncService($pdo, $this->baseDir))->sync();
            });

            // Synchronisation des manifestes + provisioning du schéma de CHAQUE module
            // (les tables doivent exister même si le module reste désactivé).
            $this->safe('Synchronisation des modules', function () use ($app) {
                $sdk = $app->make('module_sdk');
                $sync = $sdk->syncAll();
                $provDone = 0;
                $errs = $sync['errors'] ?? [];
                foreach (array_keys($sdk->discover()) as $mk) {
                    $r = $sdk->provisionSql($mk);
                    if (!empty($r['success'])) {
                        $provDone++;
                    }
                    $errs = array_merge($errs, $r['errors'] ?? []);
                }
                $this->ok("Modules synchronisés ({$sync['synced']}), schémas provisionnés ({$provDone})"
                    . ($errs ? ' ; ' . count($errs) . ' avertissement(s) non bloquant(s)' : ''));
            });

            // Réconciliation de schéma APRÈS provisioning (colonnes divergentes socle/module).
            $this->safe('Réconciliation schéma (post)', function () use ($pdo) {
                (new \API\Services\SchemaSyncService($pdo, $this->baseDir))->sync();
            });

            // Cohérence 3-mondes : catalogues de rôles, établissement, miroir tenant, super-admin plateforme.
            $this->safe('Cohérence 3-mondes', function () use ($pdo, $adminId) {
                (new \API\Platform\PlatformRoleSync($pdo))->sync();
                (new \API\Tenant\TenantRoleSync($pdo))->sync();
                $pdo->exec("UPDATE etablissements SET slug = COALESCE(NULLIF(slug, ''), code, 'default'), status = COALESCE(NULLIF(status, ''), 'active') WHERE id = 1");

                $hash = (string) $pdo->query("SELECT mot_de_passe FROM administrateurs WHERE id = " . (int) $adminId)->fetchColumn();
                $mail = (string) $this->cfg['admin_mail'];
                $prenom = (string) ($this->cfg['admin_prenom'] ?? 'Fronote');
                $nom = (string) ($this->cfg['admin_nom'] ?? 'Admin');

                // Miroir TENANT de l'administrateur.
                $tas = new \API\Tenant\TenantAccountService($pdo);
                $tExist = $tas->findByLegacy('administrateur', (int) $adminId);
                $tId = $tExist ? (int) $tExist['id'] : $tas->createAccount([
                    'account_type' => 'staff', 'username' => 'admin', 'email' => $mail,
                    'password_hash' => $hash, 'first_name' => $prenom, 'last_name' => $nom,
                    'status' => 'active', 'must_change_password' => 0,
                    'legacy_type' => 'administrateur', 'legacy_id' => (int) $adminId,
                ]);
                $mId = (new \API\Tenant\TenantMembershipService($pdo))->ensure(1, $tId);
                $admRid = (int) ($pdo->query("SELECT id FROM tenant_roles WHERE role_key='administration' LIMIT 1")->fetchColumn() ?: 0);
                if ($admRid > 0) {
                    $chk = $pdo->prepare("SELECT 1 FROM tenant_membership_roles WHERE membership_id=? AND tenant_role_id=? LIMIT 1");
                    $chk->execute([$mId, $admRid]);
                    if (!$chk->fetchColumn()) {
                        $pdo->prepare("INSERT INTO tenant_membership_roles (membership_id, tenant_role_id, scope_type, is_active) VALUES (?, ?, 'establishment', 1)")->execute([$mId, $admRid]);
                    }
                }

                // Super-admin PLATEFORME (« superadmin », mêmes identifiants).
                $pas = new \API\Platform\PlatformAccountService($pdo);
                if (!$pas->findActiveByLogin('superadmin')) {
                    $paId = $pas->createAccount([
                        'email' => $mail, 'username' => 'superadmin', 'password_hash' => $hash,
                        'first_name' => $prenom, 'last_name' => $nom, 'status' => 'active',
                    ]);
                    $srid = (int) ($pdo->query("SELECT id FROM platform_roles WHERE role_key='super_admin' LIMIT 1")->fetchColumn() ?: 0);
                    if ($srid > 0) {
                        $pdo->prepare("INSERT INTO platform_account_roles (platform_account_id, platform_role_id, scope_type, is_active) VALUES (?, ?, 'global', 1)")->execute([$paId, $srid]);
                    }
                }
            });

            // Migrations de données (journal schema_migrations), si un runner est présent.
            $this->safe('Migrations de données', function () use ($pdo) {
                $runnerFile = $this->baseDir . '/API/Services/MigrationRunner.php';
                if (is_file($runnerFile) && class_exists('\\API\\Services\\MigrationRunner')) {
                    $runner = new \API\Services\MigrationRunner($pdo, $this->baseDir);
                    if (method_exists($runner, 'run')) {
                        $runner->run();
                    }
                }
            });

            // Miroir d'identité `accounts` (additif).
            $this->safe('Miroir accounts', function () use ($pdo) {
                (new \API\Services\AccountService($pdo))->syncFromLegacy();
            });
        } catch (Throwable $e) {
            $this->warn('Bootstrap/provisioning : ' . $e->getMessage());
        }
    }

    private function writeAudit(PDO $pdo, int $adminId): void
    {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO audit_log (action, model, user_id, user_type, new_values, ip_address, user_agent, created_at)
                 VALUES ('system.installed', 'system', ?, 'administrateur', ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $adminId,
                json_encode(['channel' => 'cli', 'php' => PHP_VERSION, 'date' => date('c')]),
                'cli',
                'HeadlessInstaller',
            ]);
            $this->ok('Événement d\'installation enregistré (audit_log)');
        } catch (Throwable $e) {
            $this->warn('Audit : ' . $e->getMessage());
        }
    }

    private function writeLock(string $envPath): void
    {
        clearstatcache(true, $envPath);
        if (!is_file($envPath) || (int) filesize($envPath) <= 10) {
            throw new RuntimeException('.env introuvable/vide au moment de verrouiller — refus de créer install.lock.');
        }
        $lockFile = $this->baseDir . '/install.lock';
        $tmp = $lockFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $payload = json_encode([
            'installed_at' => date('c'),
            'channel'      => 'cli',
            'php'          => PHP_VERSION,
        ], JSON_PRETTY_PRINT);

        $fh = fopen($tmp, 'wb');
        if (!is_resource($fh) || fwrite($fh, (string) $payload) === false) {
            if (is_resource($fh)) { fclose($fh); }
            @unlink($tmp);
            throw new RuntimeException('Impossible d\'écrire install.lock.');
        }
        @fflush($fh);
        if (function_exists('fdatasync')) { @fdatasync($fh); } else { @fsync($fh); }
        fclose($fh);
        if (!@rename($tmp, $lockFile)) {
            @unlink($tmp);
            throw new RuntimeException('Impossible de finaliser install.lock (rename).');
        }
        $this->ok('install.lock créé');
    }

    // ─── .env ────────────────────────────────────────────────────────────────

    private function writeEnvFile(string $path): void
    {
        $host = strtolower((string) $this->cfg['db_host']) === 'localhost' ? '127.0.0.1' : (string) $this->cfg['db_host'];
        $protocol = (string) ($this->cfg['protocol'] ?? (str_starts_with((string) $this->cfg['app_url'], 'https') ? 'https' : 'http'));

        // Idempotence : préserver les secrets déjà présents (ne JAMAIS régénérer APP_KEY sur
        // une base existante — cela rendrait illisibles les données chiffrées at-rest).
        $existing = is_file($path) ? $this->parseEnv((string) @file_get_contents($path)) : [];
        $secret = function (string $key, int $bytes) use ($existing): string {
            $v = $existing[$key] ?? '';
            return ($v !== '' && $v !== 'your_secure_secret_here' && $v !== 'your_jwt_secret_here') ? $v : bin2hex(random_bytes($bytes));
        };

        $appEnv   = (string) ($this->cfg['app_env'] ?? 'production');
        $appDebug = !empty($this->cfg['app_debug']) ? 'true' : 'false';
        $wsEnabled = !empty($this->cfg['websocket_enabled']) ? 'true' : 'false';

        $lines = [
            '# Fronote — généré le ' . date('Y-m-d H:i:s') . ' (HeadlessInstaller)',
            '# NE PAS PARTAGER CE FICHIER', '',
            '# Base de données',
            "DB_HOST={$host}",
            'DB_PORT=' . (int) $this->cfg['db_port'],
            'DB_NAME=' . $this->cfg['db_name'],
            'DB_USER=' . $this->cfg['db_user'],
            'DB_PASS=' . $this->cfg['db_pass'],
            'DB_CHARSET=' . (string) ($this->cfg['db_charset'] ?? 'utf8mb4'), '',
            '# Application',
            'APP_NAME=' . ($this->cfg['app_name'] ?? 'Fronote'),
            "APP_ENV={$appEnv}",
            "APP_DEBUG={$appDebug}",
            'APP_URL=' . $this->cfg['app_url'],
            'APP_BASE_PATH=' . $this->baseDir,
            'BASE_URL=' . ($this->cfg['base_url'] ?? ''), '',
            '# Sécurité — clé maître (HMAC cookies, chiffrement at-rest). NE JAMAIS régénérer sur une base existante.',
            'APP_KEY=' . $secret('APP_KEY', 32),
            'TRUST_PROXY_HEADERS=' . (!empty($this->cfg['trust_proxy']) ? 'true' : 'false'),
            'CSRF_LIFETIME=' . (int) ($this->cfg['csrf_lifetime'] ?? 3600),
            'CSRF_MAX_TOKENS=' . (int) ($this->cfg['csrf_max_tokens'] ?? 10),
            'SESSION_NAME=' . ($this->cfg['session_name'] ?? 'pronote_session'),
            'SESSION_LIFETIME=' . (int) ($this->cfg['session_lifetime'] ?? 7200),
            'SESSION_SECURE=' . ($protocol === 'https' ? 'true' : 'false'),
            'SESSION_HTTPONLY=true',
            'SESSION_SAMESITE=Lax',
            'MAX_LOGIN_ATTEMPTS=' . (int) ($this->cfg['max_login_attempts'] ?? 5),
            'LOGIN_LOCKOUT_TIME=900',
            'RATE_LIMIT_ATTEMPTS=' . (int) ($this->cfg['rate_limit_attempts'] ?? 5),
            'RATE_LIMIT_DECAY=' . (int) ($this->cfg['rate_limit_decay'] ?? 1), '',
            '# Chemins',
            'LOGS_PATH=' . $this->baseDir . '/API/logs',
            'UPLOADS_PATH=' . $this->baseDir . '/uploads',
            'TEMP_PATH=' . $this->baseDir . '/temp', '',
            '# Mail (à configurer depuis l\'administration)',
            'MAIL_MAILER=smtp', 'MAIL_HOST=', 'MAIL_PORT=587',
            'MAIL_USERNAME=', 'MAIL_PASSWORD=', 'MAIL_ENCRYPTION=tls',
            'MAIL_FROM_ADDRESS=' . $this->cfg['admin_mail'],
            'MAIL_FROM_NAME=' . ($this->cfg['app_name'] ?? 'Fronote'), '',
            '# Divers',
            'APP_TIMEZONE=' . ($this->cfg['app_timezone'] ?? 'Europe/Paris'),
            'ALLOWED_INSTALL_IP=' . ($this->cfg['allowed_install_ip'] ?? ''),
            'JWT_SECRET=' . $secret('JWT_SECRET', 32), '',
            '# Audit',
            'AUDIT_ENABLED=true',
            'AUDIT_RETENTION_DAYS=' . (int) ($this->cfg['audit_retention_days'] ?? 180), '',
            '# Monitoring — protège /API/endpoints/health.php',
            'HEALTH_TOKEN=' . $secret('HEALTH_TOKEN', 16), '',
            '# WebSocket',
            "WEBSOCKET_ENABLED={$wsEnabled}",
            'WEBSOCKET_URL=' . ($this->cfg['websocket_url'] ?? 'http://127.0.0.1:3000'),
            'WEBSOCKET_CLIENT_URL=' . ($this->cfg['websocket_client_url'] ?? 'http://127.0.0.1:3000'),
            'WEBSOCKET_API_SECRET=' . $secret('WEBSOCKET_API_SECRET', 32),
            'WEBSOCKET_ALLOWED_ORIGINS=' . ($this->cfg['websocket_allowed_origins'] ?? ''), '',
            '# Mise à jour GitHub (optionnel)',
            'GITHUB_WEBHOOK_SECRET=', 'GITHUB_REPO=', 'GITHUB_BRANCH=main', '',
            '# Internationalisation',
            'APP_LOCALE=fr', 'APP_FALLBACK_LOCALE=fr', '',
            '# API Rate limiting',
            'API_RATE_LIMIT=60', 'API_RATE_LIMIT_WINDOW=60', '',
            '# Cache',
            'CACHE_DRIVER=file', 'REDIS_HOST=127.0.0.1', 'REDIS_PORT=6379', '',
            '# Sauvegardes',
            'BACKUP_RETENTION=30',
        ];

        $content = implode("\n", $lines) . "\n";
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $fh = fopen($tmp, 'wb');
        if (!is_resource($fh)) {
            throw new RuntimeException("Impossible d'ouvrir {$tmp} en écriture (droits du répertoire ?).");
        }
        if (fwrite($fh, $content) === false) {
            fclose($fh);
            @unlink($tmp);
            throw new RuntimeException("Échec d'écriture de .env.");
        }
        @fflush($fh);
        if (function_exists('fdatasync')) { @fdatasync($fh); } else { @fsync($fh); }
        fclose($fh);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Impossible de finaliser .env (rename).');
        }
    }

    /** @return array<string,string> */
    private function parseEnv(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $out[trim($k)] = trim($v);
        }
        return $out;
    }

    /** @return string[] */
    private function splitSql(string $sql): array
    {
        $statements = [];
        $buf = '';
        $len = strlen($sql);
        $i = 0;
        $inS = $inD = $inB = $inLine = $inBlock = false;
        while ($i < $len) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';
            if ($inLine) { $buf .= $ch; if ($ch === "\n") { $inLine = false; } $i++; continue; }
            if ($inBlock) { $buf .= $ch; if ($ch === '*' && $next === '/') { $buf .= $next; $i += 2; $inBlock = false; continue; } $i++; continue; }
            if (!$inS && !$inD && !$inB) {
                if ($ch === '-' && $next === '-' && ($i + 2 >= $len || in_array($sql[$i + 2], [' ', "\t", "\n", "\r"], true))) { $inLine = true; $buf .= $ch; $i++; continue; }
                if ($ch === '#') { $inLine = true; $buf .= $ch; $i++; continue; }
                if ($ch === '/' && $next === '*') { $inBlock = true; $buf .= $ch . $next; $i += 2; continue; }
                if ($ch === ';') { $s = trim($buf); if ($s !== '') { $statements[] = $s; } $buf = ''; $i++; continue; }
            }
            if ($inS) {
                if ($ch === '\\' && $next !== '') { $buf .= $ch . $next; $i += 2; continue; }
                if ($ch === "'" && $next === "'") { $buf .= "''"; $i += 2; continue; }
                if ($ch === "'") { $inS = false; }
            } elseif ($inD) {
                if ($ch === '\\' && $next !== '') { $buf .= $ch . $next; $i += 2; continue; }
                if ($ch === '"' && $next === '"') { $buf .= '""'; $i += 2; continue; }
                if ($ch === '"') { $inD = false; }
            } elseif ($inB) {
                if ($ch === '`') { $inB = false; }
            } else {
                if ($ch === "'") { $inS = true; }
                elseif ($ch === '"') { $inD = true; }
                elseif ($ch === '`') { $inB = true; }
            }
            $buf .= $ch;
            $i++;
        }
        $s = trim($buf);
        if ($s !== '') { $statements[] = $s; }
        return $statements;
    }

    private function safe(string $label, callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            $this->warn($label . ' : ' . $e->getMessage());
        }
    }

    private function ok(string $msg): void { $this->log[] = ['level' => 'ok', 'msg' => $msg]; }
    private function warn(string $msg): void { $this->log[] = ['level' => 'warn', 'msg' => $msg]; }
}
