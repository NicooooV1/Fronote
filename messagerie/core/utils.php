<?php
/**
 * Fonctions utilitaires
 */
require_once __DIR__ . '/../config/config.php';

/**
 * Crée un identifiant aléatoire
 * @param int $length Longueur de l'identifiant
 * @return string Identifiant généré
 */
function generateRandomId($length = 10) {
    return substr(str_shuffle("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"), 0, $length);
}

/**
 * Redirige vers une URL
 * @param string $url URL de destination
 */
function redirect($url) {
    header("Location: $url");
    exit;
}

/**
 * Tronque un texte à une longueur donnée
 * @param string $text Texte à tronquer
 * @param int $length Longueur maximale
 * @param string $suffix Suffixe à ajouter si le texte est tronqué
 * @return string Texte tronqué
 */
function truncate($text, $length = 100, $suffix = '...') {
    if (strlen($text) <= $length) {
        return $text;
    }
    
    return substr($text, 0, $length) . $suffix;
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
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return "Il y a $days jour" . ($days > 1 ? 's' : '');
    } elseif ($diff < 2592000) {
        $weeks = floor($diff / 604800);
        return "Il y a $weeks semaine" . ($weeks > 1 ? 's' : '');
    } elseif ($diff < 31536000) {
        $months = floor($diff / 2592000);
        return "Il y a $months mois";
    } else {
        $years = floor($diff / 31536000);
        return "Il y a $years an" . ($years > 1 ? 's' : '');
    }
}

/**
 * Convertit un timestamp en texte relatif (temps écoulé)
 * @param int|string $timestamp Timestamp UNIX ou chaîne de date à convertir
 * @return string Texte affichant le temps écoulé
 */
function getTimeAgo($timestamp) {
    if (!$timestamp) return 'Date inconnue';
    
    // Handle different timestamp formats
    if (!is_numeric($timestamp)) {
        // Try to parse date string
        $parsedTime = strtotime($timestamp);
        if ($parsedTime === false) {
            return 'Date invalide';
        }
        $timestamp = $parsedTime;
    }
    
    $current = time();
    $diff = $current - $timestamp;
    
    // Ajuster les conditions pour éviter "à l'instant" pour les messages anciens
    if ($diff < 60) {
        return "à l'instant";
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return "il y a $minutes min" . ($minutes > 1 ? 's' : '');
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return "il y a $hours h" . ($hours > 1 ? '' : '');
    } elseif ($diff < 172800) {
        return 'hier à ' . date('H:i', $timestamp);
    } elseif ($diff < 604800) {
        return date('l', $timestamp) . ' à ' . date('H:i', $timestamp);
    } else {
        return date('d/m/Y', $timestamp);
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
 * Renvoie l'icône correspondant à un dossier
 * @param string $folder Identifiant du dossier
 * @return string Nom de l'icône Font Awesome
 */
function getFolderIcon($folder) {
    $icons = [
        'information' => 'info-circle',
        'reception' => 'inbox',
        'envoyes' => 'paper-plane',
        'archives' => 'archive',
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

/**
 * Détermine si l'utilisateur peut définir l'importance d'un message
 * @param string $userType Type d'utilisateur
 * @return bool True si l'utilisateur peut définir l'importance
 */
function canSetMessageImportance($userType) {
    return in_array($userType, ['professeur', 'vie_scolaire', 'administrateur']);
}

/**
 * Détermine si l'utilisateur peut répondre à une annonce
 * @param int $userId ID de l'utilisateur
 * @param string $userType Type d'utilisateur
 * @param int $convId ID de la conversation
 * @param string $convType Type de conversation
 * @return bool True si l'utilisateur peut répondre
 */
function canReplyToAnnouncement($userId, $userType, $convId, $convType = 'standard') {
    // Si ce n'est pas une annonce, tout le monde peut répondre
    if ($convType !== 'annonce') {
        return true;
    }
    
    // Certains profils peuvent toujours répondre aux annonces
    if (in_array($userType, ['vie_scolaire', 'administrateur'])) {
        return true;
    }
    
    // Pour les autres profils, vérifier si l'option est activée
    global $pdo;
    if (!isset($pdo) || !$pdo) {
        require_once __DIR__ . '/../config/database.php';
    }
    
    try {
        // Vérifier si l'annonce permet les réponses
        $stmt = $pdo->prepare("
            SELECT allow_replies FROM conversations 
            WHERE id = ? AND type = 'annonce'
        ");
        $stmt->execute([$convId]);
        
        return $stmt->fetchColumn() == 1;
    } catch (Exception $e) {
        // En cas d'erreur, ne pas autoriser la réponse par sécurité
        return false;
    }
}