<?php
/**
 * Liste des constantes de l'application Devoir et Cahier de Texte
 * Fichier mis à jour pour intégrer les correspondances avec le système de login
 */

// Types d'utilisateurs
define('TYPE_ELEVE', 'eleve');
define('TYPE_PARENT', 'parent');
define('TYPE_PROFESSEUR', 'professeur');
define('TYPE_ADMIN', 'administrateur');
define('TYPE_VIE_SCOLAIRE', 'vie_scolaire');

// Statuts de séance
define('STATUT_PREVISIONNELLE', 'PREV');
define('STATUT_REALISEE', 'REAL');
define('STATUT_ANNULEE', 'ANNUL');

// Statuts de devoir
define('STATUT_A_FAIRE', 'AF');
define('STATUT_EN_COURS', 'EC');
define('STATUT_RENDU', 'RE');
define('STATUT_CORRIGE', 'CO');

// Types de ressources
define('RESSOURCE_FILE', 'FILE');
define('RESSOURCE_LINK', 'LINK');
define('RESSOURCE_VIDEO', 'VIDEO');
define('RESSOURCE_TEXT', 'TEXT');
define('RESSOURCE_QCM', 'QCM');
define('RESSOURCE_GALLERY', 'GALLERY');

// Paramètres d'affichage
define('ITEMS_PER_PAGE', 20);
define('DATE_FORMAT', 'd/m/Y');
define('DATETIME_FORMAT', 'd/m/Y H:i');
define('TIME_FORMAT', 'H:i');
define('DEFAULT_CALENDAR_VIEW', 'month');

// Chemins d'uploads
define('UPLOADS_DIR', ROOT_PATH . '/uploads');
define('RESSOURCES_UPLOADS', UPLOADS_DIR . '/ressources');
define('DEVOIRS_UPLOADS', UPLOADS_DIR . '/devoirs');
define('RENDUS_UPLOADS', UPLOADS_DIR . '/rendus');

// Configuration des emails
define('NOTIFICATION_SENDER', 'no-reply@pronote.example.com');

// Correspondance des profils avec le système de login
$USER_TYPE_MAP = [
    'eleve' => TYPE_ELEVE,
    'parent' => TYPE_PARENT,
    'professeur' => TYPE_PROFESSEUR,
    'administrateur' => TYPE_ADMIN,
    'vie_scolaire' => TYPE_VIE_SCOLAIRE
];

// Nom de l'application
define('APP_NAME', 'Pronote - Cahier de texte et devoirs');

// URL de base
define('BASE_URL', '/~u22405372/SAE/Pronote/devoir');