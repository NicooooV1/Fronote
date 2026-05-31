<?php
declare(strict_types=1);

// Idempotent guard to avoid double-loading
if (defined('PRONOTE_BOOTSTRAP_LOADED')) {
	return $app ?? null;
}
define('PRONOTE_BOOTSTRAP_LOADED', true);

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
$_appEnv = getenv('APP_ENV') ?: 'production';
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

// Définir BASE_URL si pas défini
if (!defined('BASE_URL')) {
	$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
	$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

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
				if ((ip2long($_maintIp) & (-1 << (32 - (int)$_b))) === (ip2long($_s) & (-1 << (32 - (int)$_b)))) {
					$_maintAllowed = true; break;
				}
			}
		}
		// Allow admin system pages through
		$_maintUri = $_SERVER['REQUEST_URI'] ?? '';
		$_maintIsAdmin = strpos($_maintUri, '/admin/systeme/maintenance') !== false;
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
	unset($_maintData, $_maintIp, $_maintAllowed, $_maintRule, $_s, $_b, $_maintUri, $_maintIsAdmin);
}
unset($_maintFile);

// Request ID unique pour traçabilité (J1)
$requestId = bin2hex(random_bytes(8));
$_SERVER['X_REQUEST_ID'] = $requestId;
if (!headers_sent()) {
	header('X-Request-Id: ' . $requestId);
}

// Démarrer la session si pas déjà démarrée
// Nom et path scopés par instance pour éviter les conflits multi-installation
if (session_status() !== PHP_SESSION_ACTIVE) {
	$_sessName = getenv('SESSION_NAME') ?: ('fronote_' . INSTANCE_ID);
	session_start([
		'cookie_httponly' => true,
		'cookie_secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
		'cookie_samesite' => 'Lax',
		'cookie_path'     => INSTANCE_COOKIE_PATH,
		'name'            => $_sessName,
	]);
	unset($_sessName);
}

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

return $app;