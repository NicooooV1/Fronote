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