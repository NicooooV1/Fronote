<?php
// /config/constants.php
// Constantes de l'application

// Chemins
define('BASE_PATH', dirname(__DIR__) . '/');
define('UPLOAD_DIR', BASE_PATH . 'uploads/');
define('TEMPLATES_DIR', BASE_PATH . 'templates/');
define('ASSETS_DIR', BASE_PATH . 'assets/');

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

// Redirection login - exact path from original code
define('LOGIN_URL', '/~u22405372/SAE/Pronote/login/public/index.php');