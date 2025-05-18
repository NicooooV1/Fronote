<?php
// Chemins
define('BASE_PATH', dirname(__DIR__) . '/');
define('UPLOAD_DIR', BASE_PATH . 'assets/uploads/');
define('TEMPLATES_DIR', BASE_PATH . 'templates/');
define('ASSETS_DIR', BASE_PATH . 'assets/');
define('LOGS_DIR', BASE_PATH . 'logs/');

// Types d'utilisateurs
define('USER_TYPE_ELEVE', 'eleve');
define('USER_TYPE_PARENT', 'parent');
define('USER_TYPE_PROFESSEUR', 'professeur');
define('USER_TYPE_VIE_SCOLAIRE', 'vie_scolaire');
define('USER_TYPE_ADMINISTRATEUR', 'administrateur');

// Types de messages
define('MESSAGE_TYPE_STANDARD', 'standard');
define('MESSAGE_TYPE_ANNONCE', 'annonce');
define('MESSAGE_TYPE_INFORMATION', 'information');

// Niveaux d'importance
define('IMPORTANCE_NORMAL', 'normal');
define('IMPORTANCE_IMPORTANT', 'important');
define('IMPORTANCE_URGENT', 'urgent');

// Dossiers de messagerie
define('FOLDER_RECEPTION', 'reception');
define('FOLDER_ENVOYES', 'envoyes');
define('FOLDER_ARCHIVES', 'archives');
define('FOLDER_INFORMATION', 'information');
define('FOLDER_CORBEILLE', 'corbeille');

// Redirection login - suppression du préfixe
define('LOGIN_URL', '/login/public/index.php');

// Créer les répertoires importants s'ils n'existent pas
$directories = [
    UPLOAD_DIR, 
    LOGS_DIR
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}