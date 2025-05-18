<?php
/**
 * Outil de diagnostic des problèmes de chemin et de redirection
 * À placer dans le répertoire API/tools
 */

// Afficher les erreurs en mode développement
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Diagnostic des chemins Pronote</h1>";

echo "<h2>Informations sur le serveur</h2>";
echo "<pre>";
echo "DOCUMENT_ROOT: " . $_SERVER['DOCUMENT_ROOT'] . "\n";
echo "SCRIPT_FILENAME: " . $_SERVER['SCRIPT_FILENAME'] . "\n";
echo "SCRIPT_NAME: " . $_SERVER['SCRIPT_NAME'] . "\n";
echo "PHP_SELF: " . $_SERVER['PHP_SELF'] . "\n";
echo "REQUEST_URI: " . $_SERVER['REQUEST_URI'] . "\n";
echo "HTTP_HOST: " . $_SERVER['HTTP_HOST'] . "\n";
echo "</pre>";

echo "<h2>Détection du chemin de base</h2>";

// Méthode 1: Basée sur SCRIPT_NAME
$basePathMethod1 = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])));
echo "Méthode 1 (SCRIPT_NAME): $basePathMethod1<br>";

// Méthode 2: Basée sur PHP_SELF
$basePathMethod2 = dirname(dirname(dirname($_SERVER['PHP_SELF'])));
echo "Méthode 2 (PHP_SELF): $basePathMethod2<br>";

// Méthode 3: Extraction depuis REQUEST_URI
$uri = $_SERVER['REQUEST_URI'];
$parts = explode('/API/tools/path_debug.php', $uri);
$basePathMethod3 = $parts[0];
echo "Méthode 3 (REQUEST_URI): $basePathMethod3<br>";

echo "<h2>Configuration actuelle</h2>";

// Vérifier si les fichiers de configuration existent
$configFiles = [
    dirname(__DIR__) . '/config/env.php',
    dirname(__DIR__) . '/config/config.php'
];

foreach ($configFiles as $file) {
    if (file_exists($file)) {
        echo "Fichier trouvé: " . basename($file) . "<br>";
        
        // Inclure le fichier et capturer les constantes
        include_once $file;
        
        $constants = [
            'BASE_URL',
            'APP_URL',
            'APP_ROOT',
            'LOGIN_URL',
            'LOGOUT_URL',
            'HOME_URL'
        ];
        
        echo "<pre>";
        foreach ($constants as $const) {
            echo "$const: " . (defined($const) ? constant($const) : 'Non définie') . "\n";
        }
        echo "</pre>";
    } else {
        echo "Fichier non trouvé: " . basename($file) . "<br>";
    }
}

echo "<h2>Test de redirection</h2>";
echo "<p>Vous pouvez cliquer sur les liens suivants pour tester les redirections:</p>";

$homeUrl = defined('HOME_URL') ? HOME_URL : $basePathMethod1 . '/accueil/accueil.php';
$loginUrl = defined('LOGIN_URL') ? LOGIN_URL : $basePathMethod1 . '/login/public/index.php';
$logoutUrl = defined('LOGOUT_URL') ? LOGOUT_URL : $basePathMethod1 . '/login/public/logout.php';

echo "<ul>";
echo "<li><a href='$homeUrl'>Page d'accueil</a> ($homeUrl)</li>";
echo "<li><a href='$loginUrl'>Page de connexion</a> ($loginUrl)</li>";
echo "<li><a href='$logoutUrl'>Déconnexion</a> ($logoutUrl)</li>";
echo "</ul>";

echo "<h2>Solution recommandée</h2>";
echo "<p>Basé sur les informations ci-dessus, voici la configuration recommandée pour votre fichier env.php:</p>";

$recommendedBaseUrl = $basePathMethod1;
if (empty($recommendedBaseUrl) || $recommendedBaseUrl === '/') {
    $recommendedBaseUrl = '';
}

$recommendedAppUrl = 'https://' . $_SERVER['HTTP_HOST'] . $recommendedBaseUrl;

echo "<pre>";
echo "// Configuration des URLs et chemins
if (!defined('BASE_URL')) define('BASE_URL', '$recommendedBaseUrl');
if (!defined('APP_URL')) define('APP_URL', '$recommendedAppUrl');
if (!defined('APP_ROOT')) define('APP_ROOT', realpath(dirname(__FILE__) . '/../../'));

// URLs communes construites avec BASE_URL
if (!defined('LOGIN_URL')) define('LOGIN_URL', BASE_URL . '/login/public/index.php');
if (!defined('LOGOUT_URL')) define('LOGOUT_URL', BASE_URL . '/login/public/logout.php');
if (!defined('HOME_URL')) define('HOME_URL', BASE_URL . '/accueil/accueil.php');";
echo "</pre>";
