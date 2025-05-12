<?php
/**
 * Fonctions utilitaires pour l'application ENT Scolaire
 */

/**
 * Sécurise une donnée de formulaire
 * @param string $data Donnée à sécuriser
 * @return string Donnée sécurisée
 */
function securize($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Vérifie si l'utilisateur actuel a un rôle spécifique
 * @param string $role Rôle à vérifier (eleve, professeur, parent, admin)
 * @return bool Vrai si l'utilisateur a le rôle spécifié
 */
function hasRole($role) {
    if (!isset($_SESSION['user_type'])) {
        return false;
    }
    return $_SESSION['user_type'] === $role;
}

/**
 * Vérifie si l'utilisateur est connecté
 * @return bool Vrai si l'utilisateur est connecté
 */
function isAuthenticated() {
    return isset($_SESSION['user_id']);
}

/**
 * Vérifie si l'utilisateur actuel est un administrateur
 * @return bool Vrai si l'utilisateur est administrateur
 */
function isAdmin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

/**
 * Génère un token CSRF
 * @return string Token CSRF
 */
function generateCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Vérifie la validité d'un token CSRF
 * @param string $token Token à vérifier
 * @return bool Vrai si le token est valide
 */
function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Formate une date selon le format spécifié dans la configuration
 * @param string $date Date au format SQL (Y-m-d H:i:s)
 * @param string $format Format désiré (date, datetime, time)
 * @return string Date formatée
 */
function formatDate($date, $format = 'date') {
    if (empty($date)) {
        return '';
    }
    
    $timestamp = strtotime($date);
    
    switch ($format) {
        case 'datetime':
            return date(DATETIME_FORMAT, $timestamp);
            
        case 'time':
            return date(TIME_FORMAT, $timestamp);
            
        case 'date':
        default:
            return date(DATE_FORMAT, $timestamp);
    }
}

/**
 * Génère une chaîne de requête URL à partir d'un tableau de paramètres
 * @param array $params Tableau des paramètres
 * @param array $exclude Paramètres à exclure
 * @return string Chaîne de requête URL
 */
function buildQueryString($params = [], $exclude = []) {
    // Fusionner avec les paramètres GET actuels
    $params = array_merge($_GET, $params);
    
    // Exclure certains paramètres
    foreach ($exclude as $param) {
        if (isset($params[$param])) {
            unset($params[$param]);
        }
    }
    
    // Construire la chaîne de requête
    $query = http_build_query($params);
    
    return !empty($query) ? '?' . $query : '';
}

/**
 * Tronque un texte à une longueur spécifiée
 * @param string $text Texte à tronquer
 * @param int $length Longueur maximale
 * @param string $suffix Suffixe à ajouter si le texte est tronqué
 * @return string Texte tronqué
 */
function truncateText($text, $length = 100, $suffix = '...') {
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    
    return mb_substr($text, 0, $length) . $suffix;
}

/**
 * Génère un identifiant unique aléatoire
 * @param int $length Longueur de l'identifiant
 * @return string Identifiant généré
 */
function generateRandomId($length = 10) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Génère une pagination HTML
 * @param int $currentPage Page actuelle
 * @param int $totalPages Nombre total de pages
 * @param string $baseUrl URL de base
 * @param array $queryParams Paramètres de requête supplémentaires
 * @return string HTML de la pagination
 */
function generatePagination($currentPage, $totalPages, $baseUrl, $queryParams = []) {
    if ($totalPages <= 1) {
        return '';
    }
    
    $html = '<ul class="pagination">';
    
    // Bouton précédent
    if ($currentPage > 1) {
        $params = array_merge($queryParams, ['page' => $currentPage - 1]);
        $queryString = buildQueryString($params);
        $html .= '<li><a href="' . $baseUrl . $queryString . '"><i class="material-icons">chevron_left</i> Précédent</a></li>';
    } else {
        $html .= '<li class="disabled"><span><i class="material-icons">chevron_left</i> Précédent</span></li>';
    }
    
    // Pages
    $range = 2; // Nombre de pages à afficher avant et après la page courante
    
    // Pages initiales
    for ($i = 1; $i <= min(3, $totalPages); $i++) {
        $params = array_merge($queryParams, ['page' => $i]);
        $queryString = buildQueryString($params);
        $active = ($i === $currentPage) ? ' class="active"' : '';
        $html .= '<li' . $active . '><a href="' . $baseUrl . $queryString . '">' . $i . '</a></li>';
    }
    
    // Ellipsis si nécessaire
    if ($currentPage - $range > 3) {
        $html .= '<li class="disabled"><span>...</span></li>';
    }
    
    // Pages autour de la page courante
    for ($i = max(4, $currentPage - $range); $i <= min($totalPages - 2, $currentPage + $range); $i++) {
        $params = array_merge($queryParams, ['page' => $i]);
        $queryString = buildQueryString($params);
        $active = ($i === $currentPage) ? ' class="active"' : '';
        $html .= '<li' . $active . '><a href="' . $baseUrl . $queryString . '">' . $i . '</a></li>';
    }
    
    // Ellipsis si nécessaire
    if ($currentPage + $range < $totalPages - 2) {
        $html .= '<li class="disabled"><span>...</span></li>';
    }
    
    // Pages finales
    for ($i = max($totalPages - 2, 1); $i <= $totalPages; $i++) {
        if ($i > 3 && $i > $currentPage + $range) {
            $params = array_merge($queryParams, ['page' => $i]);
            $queryString = buildQueryString($params);
            $active = ($i === $currentPage) ? ' class="active"' : '';
            $html .= '<li' . $active . '><a href="' . $baseUrl . $queryString . '">' . $i . '</a></li>';
        }
    }
    
    // Bouton suivant
    if ($currentPage < $totalPages) {
        $params = array_merge($queryParams, ['page' => $currentPage + 1]);
        $queryString = buildQueryString($params);
        $html .= '<li><a href="' . $baseUrl . $queryString . '">Suivant <i class="material-icons">chevron_right</i></a></li>';
    } else {
        $html .= '<li class="disabled"><span>Suivant <i class="material-icons">chevron_right</i></span></li>';
    }
    
    $html .= '</ul>';
    
    return $html;
}

/**
 * Détermine l'extension d'un fichier
 * @param string $filename Nom du fichier
 * @return string Extension du fichier
 */
function getFileExtension($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * Détermine si un fichier est une image
 * @param string $filename Nom du fichier
 * @return bool Vrai si le fichier est une image
 */
function isImage($filename) {
    $ext = getFileExtension($filename);
    $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    return in_array($ext, $imageExtensions);
}

/**
 * Détermine si un fichier est un document PDF
 * @param string $filename Nom du fichier
 * @return bool Vrai si le fichier est un PDF
 */
function isPdf($filename) {
    return getFileExtension($filename) === 'pdf';
}

/**
 * Détermine si un fichier est un document office (Word, Excel, PowerPoint)
 * @param string $filename Nom du fichier
 * @return bool Vrai si le fichier est un document office
 */
function isOfficeDocument($filename) {
    $ext = getFileExtension($filename);
    $officeExtensions = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
    return in_array($ext, $officeExtensions);
}

/**
 * Formate une taille de fichier en bytes pour l'affichage (KB, MB, etc.)
 * @param int $bytes Taille en bytes
 * @param int $decimals Nombre de décimales
 * @return string Taille formatée
 */
function formatBytes($bytes, $decimals = 2) {
    if ($bytes === 0) {
        return '0 Bytes';
    }
    
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $factor = floor(log($bytes) / log($k));
    
    return sprintf("%.{$decimals}f", $bytes / pow($k, $factor)) . ' ' . $sizes[$factor];
}

/**
 * Convertir une durée en minutes en format heures:minutes
 * @param int $minutes Durée en minutes
 * @return string Durée au format heures:minutes
 */
function formatDuration($minutes) {
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    
    return sprintf('%02d:%02d', $hours, $mins);
}

/**
 * Récupère le statut d'un devoir sous forme lisible
 * @param string $statut Code du statut
 * @return string Libellé du statut
 */
function getStatutDevoir($statut) {
    switch ($statut) {
        case STATUT_A_FAIRE:
            return 'À faire';
        case STATUT_EN_COURS:
            return 'En cours';
        case STATUT_RENDU:
            return 'Rendu';
        case STATUT_CORRIGE:
            return 'Corrigé';
        default:
            return 'Inconnu';
    }
}

/**
 * Récupère le statut d'une séance sous forme lisible
 * @param string $statut Code du statut
 * @return string Libellé du statut
 */
function getStatutSeance($statut) {
    switch ($statut) {
        case STATUT_PREVISIONNELLE:
            return 'Prévisionnelle';
        case STATUT_REALISEE:
            return 'Réalisée';
        case STATUT_ANNULEE:
            return 'Annulée';
        default:
            return 'Inconnu';
    }
}

/**
 * Génère une couleur aléatoire au format hexadécimal
 * @return string Couleur hexadécimale
 */
function generateRandomColor() {
    return sprintf('#%06X', mt_rand(0, 0xFFFFFF));
}

/**
 * Récupère le nom du jour de la semaine en français
 * @param int $dayNumber Numéro du jour (0 = dimanche, 1 = lundi, etc.)
 * @return string Nom du jour en français
 */
function getJourSemaine($dayNumber) {
    $jours = [
        0 => 'Dimanche',
        1 => 'Lundi',
        2 => 'Mardi',
        3 => 'Mercredi',
        4 => 'Jeudi',
        5 => 'Vendredi',
        6 => 'Samedi'
    ];
    
    return $jours[$dayNumber] ?? 'Inconnu';
}

/**
 * Récupère le nom du mois en français
 * @param int $monthNumber Numéro du mois (1 = janvier, etc.)
 * @return string Nom du mois en français
 */
function getMoisAnnee($monthNumber) {
    $mois = [
        1 => 'Janvier',
        2 => 'Février',
        3 => 'Mars',
        4 => 'Avril',
        5 => 'Mai',
        6 => 'Juin',
        7 => 'Juillet',
        8 => 'Août',
        9 => 'Septembre',
        10 => 'Octobre',
        11 => 'Novembre',
        12 => 'Décembre'
    ];
    
    return $mois[$monthNumber] ?? 'Inconnu';
}

/**
 * Convertit une date anglaise en date française
 * @param string $dateEn Date au format anglais (Y-m-d)
 * @return string Date au format français (d/m/Y)
 */
function dateEnToFr($dateEn) {
    if (empty($dateEn)) {
        return '';
    }
    
    $timestamp = strtotime($dateEn);
    return date('d/m/Y', $timestamp);
}

/**
 * Convertit une date française en date anglaise
 * @param string $dateFr Date au format français (d/m/Y)
 * @return string Date au format anglais (Y-m-d)
 */
function dateFrToEn($dateFr) {
    if (empty($dateFr)) {
        return '';
    }
    
    list($jour, $mois, $annee) = explode('/', $dateFr);
    return "$annee-$mois-$jour";
}

/**
 * Protège contre les attaques XSS pour l'affichage d'un contenu HTML
 * @param string $html Contenu HTML
 * @return string Contenu HTML sécurisé
 */
function purifyHtml($html) {
    // Configuration simple pour sécuriser l'HTML
    $allowedTags = '<p><br><br/><a><strong><em><u><h1><h2><h3><h4><h5><h6><img><li><ol><ul><span><div><table><tr><td><th><tbody><thead>';
    
    // Nettoyer l'HTML
    $purified = strip_tags($html, $allowedTags);
    
    // Traiter les attributs des balises (notamment href et src)
    $purified = preg_replace_callback('/<(a|img)([^>]*)>/i', function($matches) {
        $tag = $matches[1];
        $attributes = $matches[2];
        
        // Nettoyer les attributs
        $cleanAttributes = '';
        
        if ($tag === 'a') {
            // Extraire href
            if (preg_match('/href=(["\'])(.*?)\1/i', $attributes, $hrefMatch)) {
                $url = $hrefMatch[2];
                // Sécuriser l'URL
                if (substr($url, 0, 7) === 'http://' || substr($url, 0, 8) === 'https://' || substr($url, 0, 1) === '/') {
                    $cleanAttributes .= ' href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"';
                }
            }
        } elseif ($tag === 'img') {
            // Extraire src
            if (preg_match('/src=(["\'])(.*?)\1/i', $attributes, $srcMatch)) {
                $src = $srcMatch[2];
                // Sécuriser la source
                if (substr($src, 0, 7) === 'http://' || substr($src, 0, 8) === 'https://' || substr($src, 0, 1) === '/') {
                    $cleanAttributes .= ' src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"';
                }
            }
            
            // Extraire alt
            if (preg_match('/alt=(["\'])(.*?)\1/i', $attributes, $altMatch)) {
                $alt = $altMatch[2];
                $cleanAttributes .= ' alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '"';
            }
        }
        
        return "<$tag$cleanAttributes>";
    }, $purified);
    
    return $purified;
}