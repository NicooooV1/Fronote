<?php
/**
 * Constantes de l'application
 */

// Types d'utilisateurs
if (!defined('USER_TYPE_ADMIN')) define('USER_TYPE_ADMIN', 'administrateur');
if (!defined('USER_TYPE_TEACHER')) define('USER_TYPE_TEACHER', 'professeur');
if (!defined('USER_TYPE_STUDENT')) define('USER_TYPE_STUDENT', 'eleve');
if (!defined('USER_TYPE_PARENT')) define('USER_TYPE_PARENT', 'parent');
if (!defined('USER_TYPE_STAFF')) define('USER_TYPE_STAFF', 'vie_scolaire');

// Types d'absence
if (!defined('ABSENCE_TYPE_COURSE')) define('ABSENCE_TYPE_COURSE', 'cours');
if (!defined('ABSENCE_TYPE_HALF_DAY')) define('ABSENCE_TYPE_HALF_DAY', 'demi-journee');
if (!defined('ABSENCE_TYPE_FULL_DAY')) define('ABSENCE_TYPE_FULL_DAY', 'journee');

// Types de retard
if (!defined('DELAY_JUSTIFIED')) define('DELAY_JUSTIFIED', 'justified');
if (!defined('DELAY_UNJUSTIFIED')) define('DELAY_UNJUSTIFIED', 'unjustified');

// Types de messages
if (!defined('MESSAGE_TYPE_STANDARD')) define('MESSAGE_TYPE_STANDARD', 'standard');
if (!defined('MESSAGE_TYPE_ANNOUNCEMENT')) define('MESSAGE_TYPE_ANNOUNCEMENT', 'annonce');
if (!defined('MESSAGE_TYPE_INFORMATION')) define('MESSAGE_TYPE_INFORMATION', 'information');

// Statuts de justificatif
if (!defined('JUSTIFICATION_PENDING')) define('JUSTIFICATION_PENDING', 'pending');
if (!defined('JUSTIFICATION_APPROVED')) define('JUSTIFICATION_APPROVED', 'approved');
if (!defined('JUSTIFICATION_REJECTED')) define('JUSTIFICATION_REJECTED', 'rejected');

// Autres constantes métier
if (!defined('GRADES_SCALE')) define('GRADES_SCALE', 20); // Notes sur 20
if (!defined('SCHOOL_START_TIME')) define('SCHOOL_START_TIME', '08:00');
if (!defined('SCHOOL_END_TIME')) define('SCHOOL_END_TIME', '18:00');

// URLs communes - Utiliser BASE_URL au lieu de APP_URL
if (!defined('LOGIN_URL')) define('LOGIN_URL', BASE_URL . '/login/public/index.php');
if (!defined('LOGOUT_URL')) define('LOGOUT_URL', BASE_URL . '/login/public/logout.php');
if (!defined('HOME_URL')) define('HOME_URL', BASE_URL . '/accueil/accueil.php');
