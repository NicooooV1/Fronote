<?php
// /includes/functions.php - Fonctions générales

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/constants.php';

// ==========================================
// FONCTIONS UTILITAIRES
// ==========================================

/**
 * Vérifie si un utilisateur est l'utilisateur courant
 * @param int $id ID de l'utilisateur à vérifier
 * @param string $type Type de l'utilisateur à vérifier
 * @param array $user Informations de l'utilisateur courant
 * @return bool True si c'est l'utilisateur courant
 */
function isCurrentUser($id, $type, $user) {
    return $id == $user['id'] && $type == $user['type'];
}

/**
 * Fonction utilitaire pour formater une date 
 * @param string $date Date à formater
 * @return string Date formatée
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
 * Retourne l'icône correspondante au type de participant
 * @param string $type Type de participant
 * @return string Nom de l'icône
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
 * Renvoie le libellé du type de participant
 * @param string $type Type de participant
 * @return string Libellé du type
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
 * Retourne l'icône pour un dossier
 * @param string $folder Nom du dossier
 * @return string Nom de l'icône
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
 * @param string $type Type de conversation
 * @return string Nom de l'icône
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
 * @param string $type Type de conversation
 * @return string Libellé du type
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
 * @param string $status Statut du message
 * @return string Libellé du statut
 */
if (!function_exists('getMessageStatusLabel')) {
    function getMessageStatusLabel($status) {
    $statuses = [
            'normal' => 'Message normal',
            'important' => 'Message important',
            'urgent' => 'Message urgent',
            'annonce' => 'Annonce'
        ];
        return $statuses[$status] ?? 'Message normal';
    }
}

/**
 * Transforme les URLs en liens cliquables
 * @param string $text Texte à traiter
 * @return string Texte avec liens cliquables
 */
function linkify($text) {
    // Expression régulière pour détecter les URL
    $pattern = '~(https?://[^\s<]+)~i';
    
    // Remplacer les URLs par des liens HTML
    return preg_replace(
        $pattern,
        '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
        $text
    );
}

/**
 * Vérifie si la session utilisateur est valide
 * Redirige vers la page de login si non authentifié
 * @return array Données de l'utilisateur
 */
function checkUserSession() {
    if (!isset($_SESSION['user'])) {
        header('Location: ' . LOGIN_URL);
        exit;
    }

    $user = $_SESSION['user'];
    // Adaptation: utiliser 'profil' comme 'type' si 'type' n'existe pas
    if (!isset($user['type']) && isset($user['profil'])) {
        $user['type'] = $user['profil'];
    }

    // Vérifier que le type est défini
    if (!isset($user['type'])) {
        die("Erreur: Type d'utilisateur non défini dans la session");
    }
    
    return $user;
}

/**
 * Redirige vers une URL en évitant les problèmes de buffer
 * @param string $url URL de redirection
 */
function redirect($url) {
    if (!headers_sent()) {
        header("Location: $url");
    } else {
        echo "<script>location.href='$url';</script>";
    }
    exit;
}

// Compatibilité avec le code original qui pourrait utiliser header() directement
function redirectTo($url) {
    redirect($url);
}

/**
 * Sécurise les données pour affichage HTML
 * @param string $data Données à sécuriser
 * @return string Données sécurisées
 */
function h($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

/**
 * Vérifie si un chemin d'upload existe, le crée sinon
 * @param string $path Chemin à vérifier/créer
 * @return bool Succès de l'opération
 */
function checkUploadPath($path) {
    if (!is_dir($path)) {
        return mkdir($path, 0755, true);
    }
    return true;
}