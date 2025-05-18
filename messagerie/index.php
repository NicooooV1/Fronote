<?php
/**
 * Interface principale de messagerie
 */

// Inclure les fichiers nécessaires avec des chemins relatifs
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/core/utils.php';
require_once __DIR__ . '/core/auth.php';

// Assurer que les fonctions d'authentification sont disponibles
if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['user']) && !empty($_SESSION['user']);
    }
}

// Vérifier l'authentification
if (!isLoggedIn()) {
    $loginPage = defined('BASE_URL') ? BASE_URL . '/login/public/index.php' : '../login/public/index.php';
    header('Location: ' . $loginPage);
    exit;
}

$user = $_SESSION['user'];

// S'assurer que la propriété 'type' existe dans $user, sinon définir une valeur par défaut
if (!isset($user['type']) && isset($user['profil'])) {
    $user['type'] = $user['profil']; // Utiliser 'profil' si 'type' n'existe pas
} elseif (!isset($user['type'])) {
    $user['type'] = 'eleve'; // Valeur par défaut
}

// Charger les modèles nécessaires 
require_once __DIR__ . '/models/conversation.php';
require_once __DIR__ . '/models/notification.php';

// Définir le titre de la page
$pageTitle = 'Pronote - Messagerie';

// Traitement des actions rapides si demandé
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['conv_id'])) {
    $convId = (int)$_POST['conv_id'];
    
    // Vérifier que ces fonctions existent
    if (!function_exists('archiveConversation') || !function_exists('deleteConversation')) {
        // Inclure le fichier qui définit ces fonctions
        require_once __DIR__ . '/models/action_handlers.php';
    }
    
    switch ($_POST['action']) {
        case 'archive':
            archiveConversation($convId, $user['id'], $user['type']);
            redirect('index.php?folder=archives');
            break;
            
        case 'delete':
            deleteConversation($convId, $user['id'], $user['type']);
            redirect('index.php?folder=corbeille');
            break;
            
        case 'restore':
            restoreConversation($convId, $user['id'], $user['type']);
            redirect('index.php');
            break;
            
        case 'leave':
            leaveConversation($convId, $user['id'], $user['type']);
            redirect('index.php');
            break;
    }
}

// Récupérer le dossier courant
$currentFolder = isset($_GET['folder']) ? $_GET['folder'] : 'reception';

// Récupérer les conversations
$conversations = getConversations($user['id'], $user['type'], $currentFolder);

// Si c'est une requête AJAX, renvoyer seulement le contenu partiel
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    // Inclure uniquement le template de la liste des conversations
    foreach ($conversations as $conversation) {
        include 'templates/components/conversation-item.php';
    }
    exit;
}

// Inclure l'en-tête
include 'templates/header.php';
?>

<!-- Contenu principal -->
<div class="conversation-list-header">
    <div class="bulk-actions">
        <label class="checkbox-container select-all">
            <input type="checkbox" id="select-all-conversations">
            <span class="checkmark"></span>
            <span class="label-text">Tout sélectionner</span>
        </label>
        
        <?php if ($currentFolder !== 'corbeille'): ?>
        <button class="bulk-action-btn" data-action="mark_read" data-action-text="Marquer comme lu" data-icon="envelope-open" disabled>
            <i class="fas fa-envelope-open"></i> Marquer comme lu (0)
        </button>
        <button class="bulk-action-btn" data-action="mark_unread" data-action-text="Marquer comme non lu" data-icon="envelope" disabled>
            <i class="fas fa-envelope"></i> Marquer comme non lu (0)
        </button>
        
        <button class="bulk-action-btn danger" data-action="delete" data-action-text="Supprimer" data-icon="trash" disabled>
            <i class="fas fa-trash"></i> Supprimer (0)
        </button>
        
        <?php if ($currentFolder !== 'archives'): ?>
        <button class="bulk-action-btn" data-action="archive" data-action-text="Archiver" data-icon="archive" disabled>
            <i class="fas fa-archive"></i> Archiver (0)
        </button>
        <?php else: ?>
        <button class="bulk-action-btn" data-action="unarchive" data-action-text="Désarchiver" data-icon="inbox" disabled>
            <i class="fas fa-inbox"></i> Désarchiver (0)
        </button>
        <?php endif; ?>
        
        <?php else: ?>
        <button class="bulk-action-btn" data-action="restore" data-action-text="Restaurer" data-icon="trash-restore" disabled>
            <i class="fas fa-trash-restore"></i> Restaurer (0)
        </button>
        <button class="bulk-action-btn danger" data-action="delete_permanently" data-action-text="Supprimer définitivement" data-icon="trash-alt" disabled>
            <i class="fas fa-trash-alt"></i> Supprimer définitivement (0)
        </button>
        <?php endif; ?>
    </div>
    
    <div class="conversation-search">
        <input type="text" id="search-conversations" placeholder="Rechercher...">
    </div>
</div>

<?php if (empty($conversations)): ?>
<div class="empty-state">
    <i class="fas fa-inbox"></i>
    <p>Aucun message dans ce dossier.</p>
</div>
<?php else: ?>
<div class="conversation-list">
    <?php foreach ($conversations as $conversation): ?>
        <?php include 'templates/components/conversation-item.php'; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
// Inclure le pied de page
include 'templates/footer.php';
?>