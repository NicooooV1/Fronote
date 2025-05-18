<?php
/**
 * Système d'autoloading pour éviter les redéclarations de fonctions
 * 
 * Ce fichier sert de point d'entrée pour charger les fonctionnalités
 * communes à tous les modules de l'application.
 */

// Vérifier si le système a déjà été chargé pour éviter des redéclarations
if (defined('PRONOTE_AUTOLOAD_LOADED')) {
    return;
}
define('PRONOTE_AUTOLOAD_LOADED', true);

// Chemins des fonctionnalités principales
$core_paths = [
    __DIR__ . '/core/functions.php',
    __DIR__ . '/core/auth.php',
    __DIR__ . '/core/session.php',
    __DIR__ . '/core/security.php',
    __DIR__ . '/core/database.php',
    __DIR__ . '/core/logging.php',
];

// Charger les fichiers principaux
foreach ($core_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
    }
}

/**
 * Fonction pour initialiser l'application
 * @param array $options Options d'initialisation
 */
function bootstrap($options = []) {
    // Définir les constantes de base si elles ne sont pas déjà définies
    if (!defined('APP_ROOT')) {
        define('APP_ROOT', dirname(__DIR__));
    }
    
    // Charger la configuration
    $configFile = __DIR__ . '/config/config.php';
    if (file_exists($configFile)) {
        require_once $configFile;
    }
    
    // Initialiser la session de manière sécurisée
    if (function_exists('Pronote\Session\init')) {
        Pronote\Session\init();
    } else {
        // Fallback si la fonction spécialisée n'est pas disponible
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    // Vérifier si la base de données est configurée et se connecter
    if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ];
            
            $GLOBALS['pdo'] = new PDO($dsn, DB_USER, DB_PASS, $options);
            
            // Rendre la connexion disponible via une fonction
            function getPDO() {
                return $GLOBALS['pdo'] ?? null;
            }
        } catch (PDOException $e) {
            // Logger l'erreur mais ne pas l'afficher directement (sécurité)
            error_log("Erreur de connexion à la base de données: " . $e->getMessage());
        }
    }
}

/**
 * Fonction pour exporter les fonctions du namespace vers le scope global
 * uniquement si elles n'existent pas déjà
 */
function export_namespace_functions() {
    // Liste des fonctions d'authentification à exporter
    $auth_functions = [
        'isLoggedIn',
        'getCurrentUser',
        'getUserRole',
        'isAdmin',
        'isTeacher',
        'isStudent',
        'isParent',
        'isVieScolaire',
        'getUserFullName',
        'canManageNotes',
        'canManageAbsences',
        'canManageCahierTextes',
        'canManageDevoirs',
        'requireLogin'
    ];
    
    // Exporter les fonctions d'authentification si elles n'existent pas déjà
    foreach ($auth_functions as $function) {
        $namespace_function = "\\Pronote\\Auth\\{$function}";
        if (function_exists($namespace_function) && !function_exists($function)) {
            eval("function {$function}() { return call_user_func_array('{$namespace_function}', func_get_args()); }");
        }
    }
}

// Exporter les fonctions pour la compatibilité descendante
export_namespace_functions();
