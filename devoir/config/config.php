<?php
/**
 * Configuration principale de l'application
 */

// Informations de base
define('APP_NAME', 'ENT Scolaire');
define('APP_VERSION', '1.0.0');
define('BASE_URL', 'http://localhost/ent-scolaire'); // À adapter selon votre environnement

// Chemins
define('ROOT_PATH', dirname(__DIR__)); // Répertoire racine du projet
define('UPLOADS_PATH', ROOT_PATH . '/uploads');
define('DEVOIRS_UPLOADS', UPLOADS_PATH . '/devoirs');
define('RENDUS_UPLOADS', UPLOADS_PATH . '/rendus');
define('RESSOURCES_UPLOADS', UPLOADS_PATH . '/ressources');

// Configuration de la base de données
define('DB_HOST', 'localhost');
define('DB_NAME', 'ent_scolaire');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Formats de date
define('DATE_FORMAT', 'd/m/Y');
define('DATETIME_FORMAT', 'd/m/Y H:i');
define('TIME_FORMAT', 'H:i');

// Statuts des devoirs
define('STATUT_A_FAIRE', 'AF');
define('STATUT_EN_COURS', 'EC');
define('STATUT_RENDU', 'RE');
define('STATUT_CORRIGE', 'CO');

// Statuts des séances
define('STATUT_PREVISIONNELLE', 'PREV');
define('STATUT_REALISEE', 'REAL');
define('STATUT_ANNULEE', 'ANNUL');

// Types d'utilisateurs
define('TYPE_ELEVE', 'eleve');
define('TYPE_PROFESSEUR', 'professeur');
define('TYPE_PARENT', 'parent');
define('TYPE_ADMIN', 'admin');

// Types de ressources
define('RESSOURCE_FILE', 'FILE');
define('RESSOURCE_LINK', 'LINK');
define('RESSOURCE_VIDEO', 'VIDEO');
define('RESSOURCE_QCM', 'QCM');
define('RESSOURCE_TEXT', 'TEXT');
define('RESSOURCE_GALLERY', 'GALLERY');

// Types de pièces jointes
define('PJ_PDF', 'PDF');
define('PJ_IMG', 'IMG');
define('PJ_DOC', 'DOC');
define('PJ_LINK', 'LINK');
define('PJ_OTHER', 'OTHER');

// Options de téléchargement de fichiers
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10 Mo
define('ALLOWED_EXTENSIONS', [
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    'jpg', 'jpeg', 'png', 'gif', 'zip', 'rar', 'txt'
]);

// Configuration du système de notification
define('ENABLE_EMAIL_NOTIFICATIONS', true);
define('NOTIFICATION_SENDER', 'no-reply@ent-scolaire.fr');
define('NOTIFICATION_ADMIN_EMAIL', 'admin@ent-scolaire.fr');

// Sécurité
define('SESSION_LIFETIME', 3600); // 1 heure en secondes
define('CSRF_TOKEN_LIFETIME', 1800); // 30 minutes en secondes

// Limites et pagination
define('ITEMS_PER_PAGE', 20);
define('MAX_SEARCH_RESULTS', 100);
define('MAX_FILE_UPLOADS_PER_DEVOIR', 5);

// Paramètres calendrier
define('CALENDAR_START_HOUR', 8); // 8h00
define('CALENDAR_END_HOUR', 18); // 18h00
define('DEFAULT_CALENDAR_VIEW', 'week'); // 'day', 'week', 'month'