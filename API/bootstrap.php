<?php
declare(strict_types=1);

// Idempotent guard to avoid double-loading
if (defined('PRONOTE_BOOTSTRAP_LOADED')) {
	return $app ?? null;
}
define('PRONOTE_BOOTSTRAP_LOADED', true);

// ─── Output buffering (web) ─────────────────────────────────────────────────
// Beaucoup de pages incluent le header (qui émet du HTML) PUIS redirigent via
// header('Location: …') quand une ressource est introuvable / un paramètre manque.
// Sans tampon de sortie, ces redirections tardives échouent (« headers already
// sent ») et laissent une page cassée. Un ob_start() global rend header()/setcookie()
// utilisables jusqu'au flush final. Hors CLI (le cron ne doit pas tamponner stdout).
// NB : php.ini peut déjà avoir output_buffering=4096 (buffer auto qui se VIDE à 4 Ko
// et envoie alors les en-têtes en plein rendu). On empile notre propre buffer SANS
// limite de taille (chunk_size=0) pour qu'aucun flush automatique n'intervienne avant
// la fin du script — c'est ce qui garantit qu'un header('Location: …') tardif marche.
if (PHP_SAPI !== 'cli') {
	ob_start(null, 0);
}

// Définir les constantes de base
define('API_PATH', __DIR__);
define('BASE_PATH', dirname(__DIR__));

// ─── Instance fingerprint (multi-instance isolation) ────────────────────────
// Chaque installation Fronote sur un même serveur obtient un identifiant unique
// basé sur son chemin physique. Utilisé pour isoler sessions, cookies, cache Redis.
define('INSTANCE_ID', substr(md5(realpath(BASE_PATH) ?: BASE_PATH), 0, 8));

// Chemin web de l'installation (pour scoper les cookies)
$_instWebPath = '/';
$_instProjectRoot = str_replace('\\', '/', realpath(BASE_PATH) ?: BASE_PATH);
$_instDocRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '.') ?: '.');
if ($_instDocRoot && strpos($_instProjectRoot, $_instDocRoot) === 0) {
    $_instWebPath = substr($_instProjectRoot, strlen($_instDocRoot)) ?: '/';
    $_instWebPath = rtrim($_instWebPath, '/') . '/';
}
define('INSTANCE_COOKIE_PATH', $_instWebPath);
unset($_instWebPath, $_instProjectRoot, $_instDocRoot);

// Extension sanity check — log only, never crash.
// The marketplace + JWT code paths depend on these; failing early is friendlier
// than a confusing class-load failure deep into a request.
foreach (['sodium' => 'marketplace .fmod signing/verification', 'zip' => 'marketplace package handling'] as $_ext => $_use) {
    if (!extension_loaded($_ext)) {
        error_log("[bootstrap] PHP extension '{$_ext}' is missing — {$_use} will fail at first use.");
    }
}
unset($_ext, $_use);

// Priorité 1 : autoloader Composer (si vendor/ disponible)
$_vendor = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($_vendor)) {
	require_once $_vendor;
} else {
	// Fallback PSR-4 manuel (sans Composer)
	spl_autoload_register(function ($class) {
		$prefixes = ['API\\' => API_PATH . '/', 'Pronote\\' => API_PATH . '/'];
		foreach ($prefixes as $prefix => $baseDir) {
			if (strncmp($prefix, $class, strlen($prefix)) !== 0) continue;
			$file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
			if (file_exists($file)) { require $file; return; }
		}
	});
}
unset($_vendor);

// Autoloader Modules\\ — convertit PascalCase namespace → snake_case répertoire.
// Nécessaire sur Linux (case-sensitive) : Modules\Notes\* → modules/notes/
// Fonctionne avec ou sans Composer ; enregistré après l'autoloader Composer pour
// servir de fallback si le mapping PSR-4 standard échoue à cause de la casse.
spl_autoload_register(function (string $class) {
	if (strncmp('Modules\\', $class, 8) !== 0) {
		return;
	}
	$rest  = substr($class, 8); // ex: "Notes\Events\NoteCreated" ou "EmploiDuTemps\Services\Foo"
	$parts = explode('\\', $rest);
	if (count($parts) < 2) {
		return;
	}
	// PascalCase "EmploiDuTemps" → snake_case "emploi_du_temps"
	$moduleDir = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $parts[0]));
	$subPath   = implode('/', array_slice($parts, 1));
	$file      = BASE_PATH . '/modules/' . $moduleDir . '/' . $subPath . '.php';
	if (file_exists($file)) {
		require $file;
	}
});

// Helpers (app(), env(), ...)
require_once API_PATH . '/Core/helpers.php';

// Charger l'environnement via EnvLoader (met dans getenv/$_ENV/$_SERVER)
$envLoader = new \API\Core\EnvLoader(BASE_PATH);
$envLoadError = null;
try {
	$envLoader->load();
} catch (\Throwable $e) {
	// Conserver la cause racine pour la surfacer au boot de ConfigServiceProvider.
	// Sans cela, l'erreur "Variables manquantes" masque la vraie raison (.env absent / illisible).
	$envLoadError = $e;
	error_log('[bootstrap] EnvLoader failed: ' . $e->getMessage() . ' (file=' . BASE_PATH . '/.env)');

	// Si .env est manquant et qu'on n'est PAS sur install.php, rediriger vers l'installateur.
	$_scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
	$_isInstallCtx = (strpos($_scriptName, '/install.php') !== false)
	              || defined('FRONOTE_WEBHOOK_ENDPOINT')
	              || php_sapi_name() === 'cli';
	if (!$_isInstallCtx && !file_exists(BASE_PATH . '/.env') && file_exists(BASE_PATH . '/install.php')) {
		// Construire l'URL de install.php relative à la requête courante
		$_dir = rtrim(dirname($_scriptName), '/\\');
		$_installUrl = '/install.php';
		if ($_dir && $_dir !== '/' && $_dir !== '\\') {
			// remonter jusqu'à la racine du projet (au cas où on est dans un sous-dossier)
			$_segments = explode('/', trim($_dir, '/'));
			// heuristique : si le segment courant n'est pas la racine du projet,
			// on construit une URL absolue à partir de BASE_URL si dispo plus tard.
			$_installUrl = '/' . $_segments[0] . '/install.php';
		}
		if (!headers_sent()) {
			header('Location: ' . $_installUrl);
			exit;
		}
	}
	unset($_scriptName, $_isInstallCtx, $_dir, $_installUrl, $_segments);
}

// Sécurité : forcer display_errors off en production
$_appEnv = strtolower(trim(getenv('APP_ENV') ?: 'production'));
if ($_appEnv === 'prod') { $_appEnv = 'production'; } // 'prod' ≡ 'production' (normalisation)
// Propager la valeur normalisée à TOUS les lecteurs : health.php, Environment.php et
// Database.php comparent getenv('APP_ENV') === 'production' en dur. Sans cette propagation,
// APP_ENV=prod devient « production » ici (display_errors off) mais « non-production »
// ailleurs → le health endpoint sert ses diagnostics détaillés sans authentification.
if (getenv('APP_ENV') !== $_appEnv) {
    putenv('APP_ENV=' . $_appEnv);
    $_ENV['APP_ENV'] = $_appEnv;
    $_SERVER['APP_ENV'] = $_appEnv;
}
$_isDebug = $_appEnv !== 'production' || getenv('APP_DEBUG') === 'true';
if ($_appEnv === 'production') {
	ini_set('display_errors', '0');
	ini_set('display_startup_errors', '0');
	error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
} else {
	ini_set('display_errors', '1');
	error_reporting(E_ALL);
}

// Register global error handler (friendly pages in prod, traces in dev)
$_errorHandler = new \API\Core\ErrorHandler(BASE_PATH, $_isDebug);
$_errorHandler->register();
unset($_appEnv, $_isDebug, $_errorHandler);

// Fuseau horaire applicatif. Sans cela, toutes les dates/heures (absences, retards,
// devoirs rendus « à temps », audit) utilisent le fuseau de php.ini (souvent UTC).
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Europe/Paris');

// Définir BASE_URL si pas défini
if (!defined('BASE_URL')) {
	$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
	// HTTP_HOST est contrôlable par le client : on n'autorise que les caractères valides
	// d'un nom d'hôte (+ port / IPv6). Neutralise toute injection HTML/attribut via BASE_URL
	// (utilisé dans des href/action à travers l'app).
	$host = preg_replace('/[^A-Za-z0-9.:\[\]_-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';

	// Calculer le chemin web du projet à partir du système de fichiers
	// __DIR__ = <projet>/API  →  dirname(__DIR__) = racine projet
	$projectRoot = str_replace('\\', '/', realpath(dirname(__DIR__)));
	$docRoot     = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '.'));

	if ($docRoot && strpos($projectRoot, $docRoot) === 0) {
		$webPath = substr($projectRoot, strlen($docRoot));
	} else {
		// Fallback : déduire du SCRIPT_NAME en remontant d'un niveau par segment connu
		$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
		// Remonter autant de niveaux que nécessaire pour atteindre la racine projet
		$relToRoot = str_replace('\\', '/', substr(realpath(dirname($_SERVER['SCRIPT_FILENAME'] ?? __DIR__)), strlen($projectRoot)));
		$depth = $relToRoot ? substr_count(ltrim($relToRoot, '/'), '/') + 1 : 0;
		$webPath = $scriptDir;
		for ($i = 0; $i < $depth; $i++) {
			$webPath = dirname($webPath);
		}
	}

	$baseUrl = $protocol . '://' . $host . rtrim($webPath, '/');
	define('BASE_URL', $baseUrl);
}

// ─── Maintenance mode check (file-based, no DB needed) ────────────────────
$_maintFile = BASE_PATH . '/storage/maintenance.json';
if (file_exists($_maintFile) && php_sapi_name() !== 'cli') {
	$_maintData = json_decode(file_get_contents($_maintFile), true);
	if (($_maintData['active'] ?? false) === true) {
		$_maintIp = $_SERVER['REMOTE_ADDR'] ?? '';
		$_maintAllowed = false;
		foreach ($_maintData['allowed_ips'] ?? [] as $_maintRule) {
			if ($_maintRule === $_maintIp) { $_maintAllowed = true; break; }
			if (strpos($_maintRule, '/') !== false) {
				[$_s, $_b] = explode('/', $_maintRule);
				$_b = (int) $_b;
				// Bornes OBLIGATOIRES : sans elles un « /99 » ou « /-1 » provoque « -1 << négatif »
				// (ArithmeticError fatale à CHAQUE requête → DoS de tout le site pendant la maintenance).
				if ($_b >= 0 && $_b <= 32 && ip2long($_maintIp) !== false && ip2long($_s) !== false
					&& (ip2long($_maintIp) & (-1 << (32 - $_b))) === (ip2long($_s) & (-1 << (32 - $_b)))) {
					$_maintAllowed = true; break;
				}
			}
		}
		// Allow admin system pages through
		$_maintUri = $_SERVER['REQUEST_URI'] ?? '';
		// Exemption évaluée sur le CHEMIN seul (pas la query) : sinon « ?x=/platform/ »
		// suffirait à contourner la maintenance.
		$_maintPath = strtolower(parse_url($_maintUri, PHP_URL_PATH) ?: $_maintUri);
		// Retire le préfixe de déploiement (ex. /Pronote) pour matcher par PRÉFIXE de segment :
		// un simple « contient /platform/ » exempterait à tort /admin/platform/… ou /x/platform/.
		$_maintBase = strtolower(rtrim((string) (parse_url(defined('BASE_URL') ? BASE_URL : '', PHP_URL_PATH) ?: ''), '/'));
		if ($_maintBase !== '' && strpos($_maintPath, $_maintBase) === 0) { $_maintPath = substr($_maintPath, strlen($_maintBase)); }
		// La console PLATEFORME (opérateur) reste toujours accessible : la maintenance
		// verrouille l'app établissement, jamais le poste de pilotage qui l'a déclenchée.
		$_maintIsAdmin = strpos($_maintPath, '/admin/systeme/maintenance') === 0
		              || strpos($_maintPath, '/platform/') === 0;
		if (!$_maintAllowed && !$_maintIsAdmin) {
			// API requests get JSON 503
			if (strpos($_maintUri, '/API/') !== false || (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
				http_response_code(503);
				header('Content-Type: application/json');
				echo json_encode(['error' => 'maintenance', 'message' => $_maintData['message'] ?? 'Maintenance']);
				exit;
			}
			require BASE_PATH . '/templates/maintenance.php';
			exit;
		}
	}
	unset($_maintData, $_maintIp, $_maintAllowed, $_maintRule, $_s, $_b, $_maintUri, $_maintPath, $_maintBase, $_maintIsAdmin);
}
unset($_maintFile);

// Request ID unique pour traçabilité (J1)
$requestId = bin2hex(random_bytes(8));
$_SERVER['X_REQUEST_ID'] = $requestId;
if (!headers_sent()) {
	header('X-Request-Id: ' . $requestId);
}

// Détection HTTPS robuste, y compris derrière un reverse-proxy de confiance qui
// termine TLS (honore X-Forwarded-Proto / X-Forwarded-SSL UNIQUEMENT si
// TRUST_PROXY_HEADERS est activé — sinon ces en-têtes sont spoofables).
if (!function_exists('request_is_https')) {
	function request_is_https(): bool {
		if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
			return true;
		}
		$trust = in_array(strtolower((string) getenv('TRUST_PROXY_HEADERS')), ['1', 'true', 'yes', 'on'], true);
		if ($trust) {
			$xfp = (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
			if (strtolower(trim(explode(',', $xfp)[0])) === 'https') {
				return true;
			}
			if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') {
				return true;
			}
		}
		return false;
	}
}

// Nonce CSP unique par requête, exposé globalement (constante + helper csp_nonce())
// pour que TOUTE page/template puisse noncer ses <script> — condition du retrait de
// 'unsafe-inline' de script-src.
if (!defined('CSP_NONCE')) {
	define('CSP_NONCE', base64_encode(random_bytes(16)));
}

// En-têtes de sécurité de base émis sur TOUS les points d'entrée (et pas seulement les
// pages HTML incluant shared_header). La CSP dynamique à nonce reste gérée par shared_header.
if (!headers_sent()) {
	header('X-Frame-Options: DENY');
	header('X-Content-Type-Options: nosniff');
	header('Referrer-Policy: strict-origin-when-cross-origin');
	header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
	if (request_is_https()) {
		header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
	}
}

// ─── Vérification de configuration production (fail-fast) ────────────────────
// En production, refuser de servir si un secret est absent/factice (APP_KEY/JWT/WS)
// ou si la config expose des données (APP_DEBUG ON, cookie de session en clair sur
// HTTPS). Les WARNING sont seulement journalisés. Ignoré en CLI, pendant
// l'installation et si .env a échoué (déjà redirigé vers l'installateur ci-dessus).
if (php_sapi_name() !== 'cli'
	&& !isset($envLoadError) // isset(null) === false → on n'entre PAS si EnvLoader a échoué
	&& strpos($_SERVER['SCRIPT_NAME'] ?? '', '/install.php') === false) {
	$_readiness = \API\Core\ProductionReadinessChecker::fromEnvironment(request_is_https());

	// WARNING : journalisés au plus une fois par heure. Sans throttle, une instance LAN en
	// HTTP (où APP_URL=http://, HEALTH_TOKEN/DB_PASS vides sont des warnings permanents)
	// inonderait error_log de plusieurs lignes À CHAQUE requête.
	$_readinessStamp = BASE_PATH . '/storage/cache/.readiness_warned';
	$_readinessWarnedAt = @filemtime($_readinessStamp); // false si jamais journalisé
	if ($_readinessWarnedAt === false || (time() - $_readinessWarnedAt) > 3600) {
		$_readinessWarns = $_readiness->warnings();
		if ($_readinessWarns !== []) {
			foreach ($_readinessWarns as $_rw) {
				error_log("[readiness][warn] {$_rw['key']}: {$_rw['message']}");
			}
			@touch($_readinessStamp);
		}
		unset($_readinessWarns, $_rw);
	}

	if ($_readiness->shouldBlockBoot()) {
		// CRITICAL rares (uniquement quand on bloque le boot) : toujours journalisés.
		foreach ($_readiness->criticalIssues() as $_rc) {
			error_log("[readiness][CRITICAL] {$_rc['key']}: {$_rc['message']}");
		}
		// La page d'erreur pose elle-même ses en-têtes 503 ; le fallback inline ne les pose
		// que s'il n'y a pas de template (évite de dupliquer status + headers).
		$_readinessPage = BASE_PATH . '/templates/readiness_error.php';
		if (is_file($_readinessPage)) {
			require $_readinessPage;
		} else {
			if (!headers_sent()) {
				http_response_code(503);
				header('Content-Type: text/html; charset=utf-8');
				header('Retry-After: 3600');
			}
			echo '<!doctype html><meta charset="utf-8"><title>Configuration incomplète</title>'
			   . '<h1>503 — Configuration incomplète</h1>'
			   . '<p>Le service ne peut pas démarrer : la configuration de production est '
			   . 'incomplète ou non sécurisée. Consultez les journaux serveur (motif '
			   . '« [readiness] ») pour le détail.</p>';
		}
		exit;
	}
	unset($_readiness, $_readinessStamp, $_readinessWarnedAt);
}

// Démarrer la session si pas déjà démarrée
// Nom et path scopés par instance pour éviter les conflits multi-installation
if (session_status() !== PHP_SESSION_ACTIVE) {
	$_sessName     = getenv('SESSION_NAME') ?: ('fronote_' . INSTANCE_ID);
	$_sessSameSite = getenv('SESSION_SAMESITE') ?: 'Lax';
	session_start([
		'use_strict_mode' => true,   // rejette un ID de session non émis par le serveur (anti-fixation)
		'use_only_cookies' => true,  // jamais d'ID de session propagé dans l'URL (OWASP)
		'cookie_httponly' => true,
		'cookie_secure'   => request_is_https(),
		'cookie_samesite' => $_sessSameSite,
		'cookie_path'     => INSTANCE_COOKIE_PATH,
		'name'            => $_sessName,
	]);
	unset($_sessName, $_sessSameSite);
}

// Expiration de session : inactivité (SESSION_LIFETIME) + plafond absolu. Sinon la durée
// réelle ne dépend que de gc_maxlifetime de php.ini. On purge l'identité sans rediriger
// (les guards requireAuth()/endpoints renvoient ensuite une redirection HTML ou un 401 JSON).
// Le cycle de vie de session est keyé sur la PRÉSENCE d'une identité, pas sur sa forme :
// couvre les 3 mondes (établissement `user_id`, plateforme `platform.account_id`,
// tenant `tenant.account_id`). Auparavant seul le monde établissement était protégé,
// laissant les sessions plateforme/tenant sans expiration ni révocation.
$_hasIdentity = !empty($_SESSION['user_id'])
	|| !empty($_SESSION['platform']['account_id'])
	|| !empty($_SESSION['tenant']['account_id']);
if (php_sapi_name() !== 'cli' && $_hasIdentity) {
	$_sessTtl = (int) (getenv('SESSION_LIFETIME') ?: 7200);
	$_now     = time();
	$_idle    = $_now - (int) ($_SESSION['last_activity'] ?? $_now);
	$_age     = $_now - (int) ($_SESSION['session_started'] ?? $_now);
	if ($_idle > $_sessTtl || $_age > ($_sessTtl * 12)) {
		unset($_SESSION['user_id'], $_SESSION['user_type'], $_SESSION['user'], $_SESSION['logged_in'],
			$_SESSION['platform'], $_SESSION['tenant']);
		$_SESSION['session_expired'] = true;
	} else {
		$_SESSION['last_activity'] = $_now;
		if (empty($_SESSION['session_started'])) {
			$_SESSION['session_started'] = $_now;
		}
	}
	unset($_sessTtl, $_now, $_idle, $_age);
}
unset($_hasIdentity);

// Créer l'application et enregistrer les providers
$app = new \API\Core\Application(BASE_PATH);

// Exposer l'env loader dans le container
$app->instance('env.loader', $envLoader ?? null);
$app->instance('env.load_error', $envLoadError ?? null);

// Enregistrer les providers
$app->register(new \API\Providers\ConfigServiceProvider($app));
$app->register(new \API\Providers\DatabaseServiceProvider($app));
$app->register(new \API\Providers\AuthServiceProvider($app));
$app->register(new \API\Providers\SecurityServiceProvider($app));

// Moteur d'autorisation RBAC/ABAC (rôle → permission → périmètre). Résolution paresseuse
// de l'utilisateur courant pour ne pas forcer la session avant son démarrage.
$app->singleton('authz', function($app) {
	$u = null;
	try { $u = $app->make('auth')->user(); } catch (\Throwable $e) { error_log('[bootstrap.php] ' . $e->getMessage()); }
	return new \API\Security\Authorization($app->make('db')->getConnection(), $u);
});
$app->register(new \API\Providers\EtablissementServiceProvider($app));
$app->register(new \API\Providers\TranslationServiceProvider($app));
// Hook Manager (système d'événements pour les modules)
$app->singleton('hooks', function($app) {
	return new \API\Core\HookManager();
});

// Core event listeners (UserCreated, UserPasswordChanged — events core uniquement)
$app->register(new \API\Providers\EventServiceProvider($app));

// Module SDK (découverte et gestion des modules via module.json)
$app->singleton('module_sdk', function($app) {
	return new \API\Services\ModuleSDK($app->make('db')->getConnection(), BASE_PATH);
});

// Environnement applicatif (dev/staging/prod) — requis par dev_toolbar.php (footer,
// chargé sur chaque page) et admin/systeme/monitoring.php. Sans ce binding,
// app('environment') lève "Target class [environment] does not exist" partout.
$app->singleton('environment', function($app) {
	return new \API\Core\Environment();
});

// Feature Flags (fonctionnalités par type d'établissement — core transversal)
$app->singleton('features', function($app) {
	return new \API\Services\FeatureFlagService($app->make('db')->getConnection());
});

// Generic job queue (core — not module-specific)
$app->singleton('queue', function($app) {
	return new \API\Services\QueueService($app->make('db')->getConnection());
});

// Logger structuré avec rotation de fichiers
$app->singleton('log', function($app) {
	$logDir = getenv('LOGS_PATH') ?: (BASE_PATH . '/logs');
	return new \API\Core\Logger($logDir, 'app', 30);
});

$app->singleton('audit', function($app) {
	return new \API\Services\AuditService($app->make('db')->getConnection());
});

// Cache Manager (file / redis) — préfixe scopé par instance
$app->singleton('cache', function($app) {
	return new \API\Core\CacheManager(null, BASE_PATH);
});

// Client Cache (session + cookies signés HMAC, scopé par instance)
$app->singleton('client_cache', function($app) {
	return new \API\Core\ClientCache();
});

// Marketplace Service (core — gestion des modules)
$app->singleton('marketplace', function($app) {
	return new \API\Services\MarketplaceService($app->make('db')->getConnection(), BASE_PATH);
});

// Scolaire services encore sans module.json — enregistrés directement le temps
// que leurs modules soient configurés (tableau_de_bord, etc.)
$app->singleton('admin_dashboard', function($app) {
	return new \API\Services\Scolaire\AdminDashboardService($app->make('db')->getConnection());
});
$app->singleton('classes', function($app) {
	return new \API\Services\Scolaire\ClasseService($app->make('db')->getConnection());
});

// Theme Service (core — theming applicatif)
$app->singleton('themes', function($app) {
	return new \API\Services\ThemeService($app->make('db')->getConnection(), BASE_PATH);
});

// IP Firewall (core — sécurité transversale)
$app->singleton('firewall', function($app) {
	return new \API\Security\IpFirewall($app->make('db')->getConnection());
});

// Encryption Service (core — AES-256-GCM)
$app->singleton('encryption', function($app) {
	try {
		return new \API\Core\Encryption();
	} catch (\Throwable $e) {
		return null; // APP_KEY non configuré
	}
});

// Backup Service (core)
$app->singleton('backup', function($app) {
	return new \API\Services\BackupService($app->make('db')->getConnection(), BASE_PATH);
});

// Update Service (core — auto-update)
$app->singleton('updates', function($app) {
	return new \API\Services\UpdateService(BASE_PATH);
});

// Maintenance Service (core — file-based)
$app->singleton('maintenance', function($app) {
	return new \API\Services\MaintenanceService(BASE_PATH);
});

// Health Check Service (core)
$app->singleton('health', function($app) {
	return new \API\Services\HealthCheckService($app->make('db')->getConnection(), BASE_PATH);
});

// Quarantine Service (core — marketplace security)
$app->singleton('quarantine', function($app) {
	return new \API\Services\QuarantineService(BASE_PATH);
});

// Lier l'application aux Facades
\API\Core\Facade::setApplication($app);

// Démarrer les services core
$app->boot();

// Charger les ServiceProviders des modules actifs (services module chargés à la demande)
// Remplace ScolaireServiceProvider + les 15 singletons module retirés ci-dessus.
try {
	$app->make('module_sdk')->bootActiveModuleProviders($app);
} catch (\Throwable $e) {
	error_log('[bootstrap] bootActiveModuleProviders failed: ' . $e->getMessage());
}

// Establishment context (multi-establishment scoping)
\API\Middleware\EstablishmentScope::handle();

// Legacy bridge (compat helpers)
require_once API_PATH . '/Legacy/Bridge.php';

// Contrôle d'accès centralisé (front controller de sécurité) : impose authentification
// + rôle minimal selon le chemin du point d'entrée appelé, en fail-closed. Complète
// (sans les remplacer) les gardes requireAuth()/requireRole() par page.
// ─── Garde de session par requête : déconnexion forcée si la session a été révoquée
//     (fermée par un admin via « Sessions actives ») OU si le compte a été désactivé
//     (actif=0). Sans cela, « kill session » ne faisait que masquer la ligne en base et
//     la désactivation n'agissait que sur les pages appelant requireAuth(). ─────────────
// Impersonation Support : couper l'impersonation si la session support n'est plus active,
// AVANT le bloc ci-dessous (qui met app('auth')->user() en cache) — sinon l'utilisateur
// impersonifié resterait en cache pour la requête après une révocation.
\API\Support\SupportImpersonation::enforce();

$_revokeIdentity = !empty($_SESSION['user_id'])
	|| !empty($_SESSION['platform']['account_id'])
	|| !empty($_SESSION['tenant']['account_id']);
if (php_sapi_name() !== 'cli' && $_revokeIdentity) {
	$_forceLogout = false;
	// (1) Session révoquée côté serveur (session_security). Keyé sur session_id() → couvre les
	//     3 mondes (établissement/plateforme/tenant). Fail-OPEN sur erreur DB : une
	//     indisponibilité base ne doit JAMAIS déconnecter tout le monde.
	try {
		// Comparaison expires_at <= NOW() faite DANS MySQL (même fuseau que NOW()) :
		// comparer en PHP via strtotime() casserait sur le décalage UTC/Europe-Paris.
		// PDO via le container : getPDO() (Bridge legacy) N'EST PAS encore chargé à ce stade
		// du bootstrap → l'appeler ici lançait "Call to undefined function getPDO()", avalée
		// fail-open, DÉSACTIVANT silencieusement la révocation de session côté serveur
		// (kill-session + expiration) à chaque requête. app('db') est déjà prêt ici.
		$_ssPdo = $app->make('db')->getConnection();
		$_ss = $_ssPdo->prepare("SELECT is_active, (expires_at IS NOT NULL AND expires_at <= NOW()) AS expired FROM session_security WHERE id = ? LIMIT 1");
		$_ss->execute([session_id()]);
		$_ssRow = $_ss->fetch(\PDO::FETCH_ASSOC);
		if ($_ssRow !== false && ((int) $_ssRow['is_active'] === 0 || (int) $_ssRow['expired'] === 1)) {
			$_forceLogout = true;
		} elseif ($_ssRow !== false) {
			// Throttle : au plus une écriture par minute (au lieu d'un UPDATE à CHAQUE requête).
			// On PROLONGE aussi expires_at (fenêtre GLISSANTE = SESSION_LIFETIME) : sans ça,
			// expires_at restait figé à login+2h et révoquait une session ACTIVE au bout de 2h,
			// en contradiction avec le garde d'inactivité (qui tolère l'activité jusqu'au plafond
			// absolu). Le plafond absolu (24h) reste imposé par le bloc d'expiration d'inactivité.
			$_ssTtl = (int) (getenv('SESSION_LIFETIME') ?: 7200);
			$_ssPdo->prepare("UPDATE session_security SET last_activity = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL {$_ssTtl} SECOND) WHERE id = ? AND (last_activity IS NULL OR last_activity < (NOW() - INTERVAL 60 SECOND))")->execute([session_id()]);
		}
	} catch (\Throwable $e) { /* fail-open */ error_log('[bootstrap.php] ' . $e->getMessage()); }

	// (2) Compte désactivé/verrouillé : UserProvider::retrieveById() renvoie null si
	//     actif=0. Spécifique au monde ÉTABLISSEMENT (app('auth') ne connaît que celui-ci) ;
	//     les mondes plateforme/tenant s'appuient sur la révocation session_security ci-dessus.
	if (!$_forceLogout && !empty($_SESSION['user_id'])) {
		try { if (app('auth')->user() === null) { $_forceLogout = true; } }
		catch (\Throwable $e) { /* fail-open */ error_log('[bootstrap.php] ' . $e->getMessage()); }
	}

	// (2b) Mondes PLATEFORME/TENANT (auth propre, hors app('auth')) : compte desactive ou
	//      verrouille -> logout immediat, sans attendre l'expiration de session_security.
	//      Fail-open sur erreur DB, fail-closed si le compte a disparu. Table = litteral sur.
	if (!$_forceLogout) {
		$_pw = !empty($_SESSION['platform']['account_id']) ? ['platform_accounts', (int) $_SESSION['platform']['account_id']]
			: (!empty($_SESSION['tenant']['account_id']) ? ['tenant_accounts', (int) $_SESSION['tenant']['account_id']] : null);
		if ($_pw !== null) {
			try {
				$_pwStmt = $app->make('db')->getConnection()->prepare(
					"SELECT status, (locked_until IS NOT NULL AND locked_until > NOW()) AS locked FROM `{$_pw[0]}` WHERE id = ? LIMIT 1"
				);
				$_pwStmt->execute([$_pw[1]]);
				$_pwRow = $_pwStmt->fetch(\PDO::FETCH_ASSOC);
				if ($_pwRow === false || ($_pwRow['status'] ?? '') !== 'active' || (int) ($_pwRow['locked'] ?? 0) === 1) {
					$_forceLogout = true;
				}
				unset($_pwStmt, $_pwRow);
			} catch (\Throwable $e) { /* fail-open */ error_log('[bootstrap.php] ' . $e->getMessage()); }
		}
		unset($_pw);
	}

	if ($_forceLogout) {
		// app('auth')->logout() détruit la session (session_id-based) → couvre les 3 mondes ;
		// on purge en plus les clés plateforme/tenant par sécurité si logout() échoue.
		try { app('auth')->logout(); }
		catch (\Throwable $e) { unset($_SESSION['user_id'], $_SESSION['user_type'], $_SESSION['user'], $_SESSION['logged_in'], $_SESSION['platform'], $_SESSION['tenant']); }
		$_SESSION['session_expired'] = true;
	}
	unset($_forceLogout, $_ss, $_ssRow);
}
unset($_revokeIdentity);

\API\Core\ReadOnlyGuard::enforce();
\API\Core\AccessControl::enforce(BASE_PATH);

// ─── Filet de maintenance best-effort (tick quotidien) ───────────────────────
// Si aucun cron n'est configuré, on déclenche une maintenance minimale au plus une
// fois par 24h via un fichier sentinelle. Strictement NON bloquant et silencieux :
// on se limite à un check de mtime ; le verrou (rename atomique) évite que deux
// requêtes simultanées lancent le travail en double. Jamais en CLI (le vrai cron
// passe par cron/daily_maintenance.php).
if (php_sapi_name() !== 'cli') {
	try {
		$_maintSentinel = BASE_PATH . '/storage/.last_maintenance';
		$_maintMtime    = @filemtime($_maintSentinel); // false si jamais exécuté
		if ($_maintMtime === false || (time() - $_maintMtime) > 86400) {
			// Pose le verrou AVANT le travail : si une autre requête a déjà touché la
			// sentinelle dans l'intervalle, on n'entre pas (rename concurrent → mtime frais).
			@touch($_maintSentinel);
			// Re-vérifie après touch que c'est bien NOUS qui venons de la dater
			// (fenêtre de course résiduelle acceptable : pire cas, double purge idempotente).

			// (a) Purge d'audit (> AUDIT_RETENTION_DAYS)
			try { app('audit')->cleanup(); }
			catch (\Throwable $e) { error_log('[bootstrap][maint] audit: ' . $e->getMessage()); }

			// (b) Nettoyage de storage/tmp (fichiers > 24h) — léger, borné, jamais récursif.
			try {
				$_tmpDir = BASE_PATH . '/storage/tmp';
				if (is_dir($_tmpDir)) {
					$_cut = time() - 86400;
					foreach (new \DirectoryIterator($_tmpDir) as $_f) {
						if ($_f->isDot() || $_f->isDir()) continue;
						if ($_f->getMTime() < $_cut) { @unlink($_f->getPathname()); }
					}
					unset($_cut, $_f);
				}
				unset($_tmpDir);
			} catch (\Throwable $e) { error_log('[bootstrap][maint] tmp: ' . $e->getMessage()); }
		}
		unset($_maintSentinel, $_maintMtime);
	} catch (\Throwable $e) {
		// Filet best-effort : ne JAMAIS faire échouer une requête à cause de la maintenance.
		error_log('[bootstrap][maint] ' . $e->getMessage());
	}
}

return $app;