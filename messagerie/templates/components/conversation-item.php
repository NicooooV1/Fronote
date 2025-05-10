<?php
/**
 * Item d'une conversation dans la liste
 * 
 * @param array $conversation La conversation à afficher
 * @param string $currentFolder Le dossier actuel
 */
?>
<div class="conversation-item <?= $conversation['non_lus'] > 0 ? 'unread' : '' ?> <?= $conversation['type'] === 'annonce' ? 'annonce' : '' ?>" data-is-read="<?= $conversation['non_lus'] == 0 ? '1' : '0' ?>">
    <label class="checkbox-container conversation-selector">
        <input type="checkbox" class="conversation-checkbox" value="<?= (int)$conversation['id'] ?>">
        <span class="checkmark"></span>
    </label>
    
    <a href="conversation.php?id=<?= (int)$conversation['id'] ?>" class="conversation-content">
        <div class="conversation-icon <?= $conversation['type'] === 'annonce' ? 'annonce' : '' ?>">
            <i class="fas fa-<?= getConversationIcon($conversation['type']) ?>"></i>
        </div>
        <div class="conversation-header">
            <h3><?= htmlspecialchars($conversation['titre'] ?: 'Conversation #'.$conversation['id']) ?></h3>
            <?php if ($conversation['non_lus'] > 0): ?>
            <span class="badge">
                <?php if ((int)$conversation['non_lus'] === 1): ?>
                    1 NOUVEAU
                <?php else: ?>
                    <?= (int)$conversation['non_lus'] ?> NOUVEAUX
                <?php endif; ?>
            </span>
            <?php endif; ?>
        </div>
        <div class="conversation-meta">
            <?php 
            $statusClass = '';
            $statusLabel = '';
            
            if ($conversation['type'] === 'annonce') {
                $statusClass = 'annonce';
                $statusLabel = 'Annonce';
            } elseif (isset($conversation['status'])) {
                $statusClass = $conversation['status'];
                $statusLabel = getMessageStatusLabel($conversation['status']);
            } else {
                $statusLabel = getConversationType($conversation['type']);
            }
            ?>
            <span class="message-status <?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
            <span class="date"><?= formatDate($conversation['dernier_message']) ?></span>
        </div>
    </a>
    
    <!-- Menu d'actions rapides -->
    <div class="quick-actions">
        <button class="quick-actions-btn" onclick="toggleQuickActions(<?= (int)$conversation['id'] ?>)">
            <i class="fas fa-ellipsis-v"></i>
        </button>
        <div class="quick-actions-menu" id="quick-actions-<?= (int)$conversation['id'] ?>">
            <?php if ($currentFolder === 'archives'): ?>
            <!-- Actions pour les archives -->
            <button type="button" onclick="performBulkAction('unarchive', [<?= (int)$conversation['id'] ?>])">
                <i class="fas fa-inbox"></i> Désarchiver
            </button>
            
            <button type="button" onclick="performBulkAction('delete', [<?= (int)$conversation['id'] ?>])" class="delete">
                <i class="fas fa-trash"></i> Supprimer
            </button>
            <?php elseif ($currentFolder === 'corbeille'): ?>
            <!-- Actions pour la corbeille -->
            <button type="button" onclick="performBulkAction('restore', [<?= (int)$conversation['id'] ?>])">
                <i class="fas fa-trash-restore"></i> Restaurer
            </button>
            
            <button type="button" onclick="performBulkAction('delete_permanently', [<?= (int)$conversation['id'] ?>])" class="delete">
                <i class="fas fa-trash-alt"></i> Supprimer définitivement
            </button>
            <?php else: ?>
            <!-- Actions pour les autres dossiers -->
            <?php if ($conversation['non_lus'] > 0): ?>
            <button type="button" onclick="markConversationAsRead(<?= (int)$conversation['id'] ?>)">
                <i class="fas fa-envelope-open"></i> Marquer comme lu
            </button>
            <?php else: ?>
            <button type="button" onclick="markConversationAsUnread(<?= (int)$conversation['id'] ?>)">
                <i class="fas fa-envelope"></i> Marquer comme non lu
            </button>
            <?php endif; ?>
            
            <?php if ($currentFolder !== 'archives'): ?>
            <button type="button" onclick="performBulkAction('archive', [<?= (int)$conversation['id'] ?>])">
                <i class="fas fa-archive"></i> Archiver
            </button>
            <?php endif; ?>
            
            <button type="button" onclick="performBulkAction('delete', [<?= (int)$conversation['id'] ?>])" class="delete">
                <i class="fas fa-trash"></i> Supprimer
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>