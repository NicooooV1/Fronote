<?php
/**
 * Interface principale de messagerie
 */

// Inclure les fichiers nécessaires
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/core/utils.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/models/conversation.php';
require_once __DIR__ . '/models/notification.php';

// Vérifier l'authentification
$user = requireAuth();

// Définir le titre de la page
$pageTitle = 'Pronote - Messagerie';

// Traitement des actions rapides si demandé
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['conv_id'])) {
    $convId = (int)$_POST['conv_id'];
    
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
            redirect('index.php?folder=reception');
            break;
            
        case 'delete_permanently':
            deletePermanently($convId, $user['id'], $user['type']);
            redirect('index.php?folder=corbeille');
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

<div class="content">
    <!-- Barre latérale avec le menu -->
    <?php include 'templates/sidebar.php'; ?>

    <main>
        <h2><?= isset($folders[$currentFolder]) ? $folders[$currentFolder] : 'Messages' ?></h2>
        
        <!-- Section des actions en masse pour tous les dossiers -->
        <?php if (!empty($conversations)): ?>
        <div class="bulk-actions">
            <label class="checkbox-container">
                <input type="checkbox" id="select-all-conversations">
                <span class="checkmark"></span>
                <strong>Tout sélectionner</strong>
            </label>
            
            <div class="bulk-action-buttons">
                <?php 
                // Boutons d'actions spécifiques selon le dossier
                if ($currentFolder === 'archives'): ?>
                    <!-- Boutons pour les archives -->
                    <button data-action="unarchive" data-icon="inbox" data-action-text="Désarchiver" class="bulk-action-btn btn primary" disabled>
                        <i class="fas fa-inbox"></i> Désarchiver (0)
                    </button>
                <?php elseif ($currentFolder !== 'corbeille'): ?>
                    <!-- Bouton d'archivage pour les dossiers autres que archives et corbeille -->
                    <button data-action="archive" data-icon="archive" data-action-text="Archiver" class="bulk-action-btn btn secondary" disabled>
                        <i class="fas fa-archive"></i> Archiver (0)
                    </button>
                <?php endif; ?>
                
                <?php if ($currentFolder === 'corbeille'): ?>
                    <!-- Boutons pour la corbeille -->
                    <button data-action="restore" data-icon="trash-restore" data-action-text="Restaurer" class="bulk-action-btn btn primary" disabled>
                        <i class="fas fa-trash-restore"></i> Restaurer (0)
                    </button>
                    
                    <button data-action="delete_permanently" data-icon="trash-alt" data-action-text="Supprimer définitivement" class="bulk-action-btn btn warning" disabled>
                        <i class="fas fa-trash-alt"></i> Supprimer définitivement (0)
                    </button>
                <?php else: ?>
                    <!-- Bouton de suppression pour tous les dossiers sauf corbeille -->
                    <button data-action="delete" data-icon="trash" data-action-text="Supprimer" class="bulk-action-btn btn warning" disabled>
                        <i class="fas fa-trash"></i> Supprimer (0)
                    </button>
                <?php endif; ?>
                
                <?php if ($currentFolder !== 'corbeille'): ?>
                    <!-- Boutons de marquage communs à tous les dossiers sauf corbeille -->
                    <button data-action="mark_read" data-icon="envelope-open" data-action-text="Marquer comme lu" class="bulk-action-btn btn secondary" disabled>
                        <i class="fas fa-envelope-open"></i> Marquer comme lu (0)
                    </button>
                    
                    <button data-action="mark_unread" data-icon="envelope" data-action-text="Marquer comme non lu" class="bulk-action-btn btn secondary" disabled>
                        <i class="fas fa-envelope"></i> Marquer comme non lu (0)
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Liste des conversations -->
        <div class="conversation-list">
            <?php if (empty($conversations)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <p>Aucun message dans ce dossier</p>
            </div>
            <?php else: ?>
                <?php foreach ($conversations as $conversation): ?>
                    <?php include 'templates/components/conversation-item.php'; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php
// Inclure le pied de page
include 'templates/footer.php';
?>