<?php
/**
 * Fonctions utilitaires
 */
require_once __DIR__ . '/../config/config.php';

/**
 * Redirige vers une URL
 * @param string $url
 */
function redirect($url) {
    if (!headers_sent()) {
        header("Location: $url");
    } else {
        echo "<script>location.href='$url';</script>";
    }
    exit;
}

/**
 * Génère un jeton CSRF et le stocke en session
 * @return string
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

/**
 * Vérifie si le jeton CSRF est valide
 * @param string $token
 * @return bool
 */
function validateCSRFToken($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        error_log("CSRF validation failed: empty token");
        return false;
    }
    
    // Ajouter une expiration au token
    if (isset($_SESSION['csrf_token_time']) && (time() - $_SESSION['csrf_token_time']) > 3600) {
        // Token expiré après 1h
        $_SESSION['csrf_token'] = null;
        error_log("CSRF validation failed: token expired");
        return false;
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Crée un champ caché de jeton CSRF pour les formulaires
 * @return string
 */
function csrfField() {
    $token = generateCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Construit une requête SQL sécurisée avec des clauses IN
 * @param PDO $pdo Instance PDO
 * @param string $baseQuery Requête SQL de base
 * @param string $field Nom du champ pour la clause IN
 * @param array $values Valeurs pour la clause IN
 * @param array $additionalParams Paramètres supplémentaires pour la requête
 * @return array Tableau contenant la requête préparée et le tableau de paramètres
 */
function buildSafeInQuery($pdo, $baseQuery, $field, $values, $additionalParams = []) {
    // Si aucune valeur, retourner une requête qui ne renvoie rien
    if (empty($values)) {
        return [
            'query' => $baseQuery . " WHERE 1=0",
            'params' => []
        ];
    }
    
    // Assainir les valeurs (s'assurer qu'elles sont toutes du bon type)
    $sanitizedValues = [];
    foreach ($values as $value) {
        if (is_numeric($value)) {
            $sanitizedValues[] = (int)$value;
        } elseif (is_string($value)) {
            $sanitizedValues[] = $value;
        }
    }
    
    // Créer des placeholders nommés uniques pour chaque valeur
    $placeholders = [];
    $params = [];
    
    foreach ($sanitizedValues as $index => $value) {
        $paramName = "in_param_" . $index;
        $placeholders[] = ":" . $paramName;
        $params[$paramName] = $value;
    }
    
    // Ajouter les paramètres supplémentaires
    if (!empty($additionalParams)) {
        $params = array_merge($params, $additionalParams);
    }
    
    // Construire la requête finale
    $query = $baseQuery . " WHERE " . $field . " IN (" . implode(", ", $placeholders) . ")";
    
    return [
        'query' => $query,
        'params' => $params
    ];
}

/**
 * Valide et nettoie une entrée d'utilisateur
 * @param mixed $input
 * @param string $type Type de données attendu (string, int, email, etc.)
 * @return mixed
 */
function cleanInput($input, $type = 'string') {
    switch ($type) {
        case 'int':
            return filter_var($input, FILTER_SANITIZE_NUMBER_INT);
        case 'float':
            return filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        case 'email':
            return filter_var($input, FILTER_SANITIZE_EMAIL);
        case 'url':
            return filter_var($input, FILTER_SANITIZE_URL);
        case 'string':
        default:
            if (is_string($input)) {
                // Supprimer les caractères invisibles du début et de la fin
                $input = trim($input);
                // Convertir les caractères HTML spéciaux en entités
                return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
            }
            return $input;
    }
}

/**
 * Formatte une date de façon conviviale
 * @param string $date
 * @return string
 */
function formatDate($date) {
    if (!$date) return 'Jamais';
    
    $timestamp = strtotime($date);
    $now = time();
    $diff = $now - $timestamp;
    
    if ($diff < 60) {
        return 'À l\'instant';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return "Il y a $minutes minute" . ($minutes > 1 ? 's' : '');
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return "Il y a $hours heure" . ($hours > 1 ? 's' : '');
    } elseif ($diff < 172800) {
        return 'Hier à ' . date('H:i', $timestamp);
    } else {
        return date('d/m/Y à H:i', $timestamp);
    }
}

/**
 * Vérifie si un utilisateur est l'utilisateur courant
 * @param int $id
 * @param string $type
 * @param array $user
 * @return bool
 */
function isCurrentUser($id, $type, $user) {
    return $id == $user['id'] && $type == $user['type'];
}

/**
 * Échappe le texte pour l'affichage HTML
 * @param string $text
 * @return string
 */
function h($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/**
 * Transforme les URLs en liens cliquables
 * @param string $text
 * @return string
 */
function linkify($text) {
    $pattern = '~(https?://[^\s<]+)~i';
    return preg_replace(
        $pattern,
        '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
        $text
    );
}

/**
 * Retourne l'icône pour un dossier
 * @param string $folder
 * @return string
 */
function getFolderIcon($folder) {
    $icons = [
        'reception' => 'inbox',
        'envoyes' => 'paper-plane',
        'archives' => 'archive',
        'information' => 'info-circle',
        'corbeille' => 'trash'
    ];
    return $icons[$folder] ?? 'folder';
}

/**
 * Retourne l'icône pour une conversation
 * @param string $type
 * @return string
 */
function getConversationIcon($type) {
    $icons = [
        'individuelle' => 'comment',
        'groupe' => 'users',
        'information' => 'info-circle',
        'annonce' => 'bullhorn',
        'sondage' => 'poll-h',
        'classe' => 'graduation-cap',
        'standard' => 'comment'
    ];
    return $icons[$type] ?? 'comment';
}

/**
 * Retourne le type de conversation
 * @param string $type
 * @return string
 */
function getConversationType($type) {
    $types = [
        'individuelle' => 'Message individuel',
        'groupe' => 'Conversation de groupe',
        'information' => 'Information',
        'annonce' => 'Annonce importante',
        'sondage' => 'Sondage',
        'classe' => 'Message à la classe',
        'standard' => 'Message standard'
    ];
    return $types[$type] ?? ucfirst($type);
}

/**
 * Retourne le libellé d'un statut de message
 * @param string $status
 * @return string
 */
function getMessageStatusLabel($status) {
    $statuses = [
        'normal' => 'Message normal',
        'important' => 'Message important',
        'urgent' => 'Message urgent',
        'annonce' => 'Annonce'
    ];
    return $statuses[$status] ?? 'Message normal';
}

/**
 * Retourne l'icône d'un type de participant
 * @param string $type
 * @return string
 */
function getParticipantIcon($type) {
    $icons = [
        'eleve' => 'graduate',
        'parent' => 'users',
        'professeur' => 'tie',
        'vie_scolaire' => 'clipboard',
        'administrateur' => 'cog'
    ];
    return $icons[$type] ?? 'user';
}

/**
 * Retourne le libellé du type de participant
 * @param string $type
 * @return string
 */
function getParticipantType($type) {
    $types = [
        'eleve' => 'Élève',
        'parent' => 'Parent',
        'professeur' => 'Professeur',
        'vie_scolaire' => 'Vie scolaire',
        'administrateur' => 'Administrateur'
    ];
    return $types[$type] ?? ucfirst($type);
}