<?php
/**
 * Constantes de l'application
 */

// Types d'utilisateurs
define('USER_TYPE_ADMIN', 'administrateur');
define('USER_TYPE_TEACHER', 'professeur');
define('USER_TYPE_STUDENT', 'eleve');
define('USER_TYPE_PARENT', 'parent');
define('USER_TYPE_STAFF', 'vie_scolaire');

// Types d'absence
define('ABSENCE_TYPE_COURSE', 'cours');
define('ABSENCE_TYPE_HALF_DAY', 'demi-journee');
define('ABSENCE_TYPE_FULL_DAY', 'journee');

// Types de retard
define('DELAY_JUSTIFIED', 'justified');
define('DELAY_UNJUSTIFIED', 'unjustified');

// Types de messages
define('MESSAGE_TYPE_STANDARD', 'standard');
define('MESSAGE_TYPE_ANNOUNCEMENT', 'annonce');
define('MESSAGE_TYPE_INFORMATION', 'information');

// Statuts de justificatif
define('JUSTIFICATION_PENDING', 'pending');
define('JUSTIFICATION_APPROVED', 'approved');
define('JUSTIFICATION_REJECTED', 'rejected');

// Autres constantes métier
define('GRADES_SCALE', 20); // Notes sur 20
define('SCHOOL_START_TIME', '08:00');
define('SCHOOL_END_TIME', '18:00');

// URLs communes
define('LOGIN_URL', APP_URL . '/login/public/index.php');
define('LOGOUT_URL', APP_URL . '/login/public/logout.php');
define('HOME_URL', APP_URL . '/accueil/accueil.php');
