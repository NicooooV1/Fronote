<?php
/**
 * Composant d'élément de conversation dans la liste
 */

// Extraire les données de la conversation
$id = $conversation['id'];
$title = !empty($conversation['title']) ? $conversation['title'] : '(Sans titre)';
$preview = !empty($conversation['last_message']) ? $conversation['last_message'] : '(Pas de message)';
$dateCreation = isset($conversation['created_at']) ? strtotime($conversation['created_at']) : time();
$lastActivity = isset($conversation['updated_at']) ? strtotime($conversation['updated_at']) : time();
$isRead = isset($conversation['is_read']) ? $conversation['is_read'] : true;
$participants = $conversation['participants'] ?? [];
$type = $conversation['type'] ?? 'standard';

// Déterminer l'icône et la classe CSS selon le type
$typeClasses = [
    'standard' => '',
    'annonce' => 'announcement',
    'information' => 'info',
    'urgent' => 'urgent'
];

$typeIcons = [
    'standard' => 'comments',
    'annonce' => 'bullhorn',
    'information' => 'info-circle',
    'urgent' => 'exclamation-circle'
];

$icon = $typeIcons[$type] ?? 'comments';
$typeClass = $typeClasses[$type] ?? '';

// Formater la liste des participants
$participantsText = '';
if (!empty($participants)) {
    // Limiter à 3 participants affichés
    $displayParticipants = array_slice($participants, 0, 3);
    $participantsNames = array_map(function($p) {
        return $p['prenom'] . ' ' . $p['nom'];
    }, $displayParticipants);
    
    $participantsText = implode(', ', $participantsNames);
    
    // Ajouter indicateur si plus de participants
    if (count($participants) > 3) {
        $participantsText .= ' et ' . (count($participants) - 3) . ' autres...';
    }
}

// Formater la date relative
$relativeDate = getTimeAgo($lastActivity);

// Classes CSS pour la conversation
$conversationClasses = ['conversation-item'];
if (!$isRead) {
    $conversationClasses[] = 'unread';
}
if (!empty($typeClass)) {
    $conversationClasses[] = $typeClass;
}

$conversationClassesStr = implode(' ', $conversationClasses);
?>

<div class="<?= $conversationClassesStr ?>" data-id="<?= $id ?>">
    <div class="conversation-checkbox">
        <input type="checkbox" class="conversation-select" data-id="<?= $id ?>" data-read="<?= $isRead ? '1' : '0' ?>">
    </div>
    
    <div class="conversation-content" onclick="window.location.href='conversation.php?id=<?= $id ?>'">
        <div class="conversation-header">
            <div class="conversation-info">
                <div class="conversation-title">
                    <?php if (!$isRead): ?>
                    <span class="unread-indicator"></span>
                    <?php endif; ?>
                    <?= htmlspecialchars($title) ?>
                </div>
                <div class="conversation-participants"><?= htmlspecialchars($participantsText) ?></div>
            </div>
            <div class="conversation-timestamp" title="<?= date('d/m/Y H:i', $lastActivity) ?>">
                <?= $relativeDate ?>
            </div>
        </div>
        
        <div class="conversation-body">
            <div class="conversation-preview"><?= htmlspecialchars(strip_tags($preview)) ?></div>
        </div>
        
        <div class="conversation-footer">
            <div class="conversation-status">
                <span class="conversation-type">
                    <i class="fas fa-<?= $icon ?>"></i>
                    <?= ucfirst($type) ?>
                </span>
            </div>
            
            <div class="conversation-actions">
                <?php if ($currentFolder === 'corbeille'): ?>
                <button class="conversation-action" title="Restaurer" onclick="restoreConversation(<?= $id ?>); event.stopPropagation();">
                    <i class="fas fa-trash-restore"></i>
                </button>
                <button class="conversation-action danger" title="Supprimer définitivement" onclick="confirmDeletePermanently(<?= $id ?>); event.stopPropagation();">
                    <i class="fas fa-trash-alt"></i>
                </button>
                <?php else: ?>
                <?php if ($currentFolder !== 'archives'): ?>
                <button class="conversation-action" title="Archiver" onclick="archiveConversation(<?= $id ?>); event.stopPropagation();">
                    <i class="fas fa-archive"></i>
                </button>
                <?php else: ?>
                <button class="conversation-action" title="Désarchiver" onclick="unarchiveConversation(<?= $id ?>); event.stopPropagation();">
                    <i class="fas fa-inbox"></i>
                </button>
                <?php endif; ?>
                <button class="conversation-action danger" title="Supprimer" onclick="confirmDelete(<?= $id ?>); event.stopPropagation();">
                    <i class="fas fa-trash"></i>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>