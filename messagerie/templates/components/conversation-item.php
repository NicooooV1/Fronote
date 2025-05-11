<?php
/**
 * Item for conversation list or message display
 * 
 * @param array $conversation The conversation to display in listing view
 * @param array $message The message to display in conversation view
 * @param array $user Current logged-in user
 */

// First, determine which type of view we're in
$inConversationView = isset($message) && !empty($message);
$inListingView = isset($conversation) && !empty($conversation);

// Classes CSS for the item
$itemClasses = [];

if ($inConversationView) {
    // CONVERSATION VIEW - displaying a message
    $isSelf = isset($message['expediteur_id'], $message['expediteur_type']) && 
              isCurrentUser($message['expediteur_id'], $message['expediteur_type'], $user);
    if ($isSelf) {
        $itemClasses[] = 'self';
    }

    // Importance/status of the message
    $importance = isset($message['status']) ? $message['status'] : 'normal';
    $itemClasses[] = $importance;

    // Read/unread message
    if (isset($message['est_lu']) && $message['est_lu']) {
        $itemClasses[] = 'read';
    }

    // Display a message
    ?>
    <div class="message <?= implode(' ', $itemClasses) ?>" data-id="<?= (int)$message['id'] ?>" data-timestamp="<?= strtotime($message['date_envoi']) ?>">
        <!-- Message display for conversation view -->
        <div class="message-header">
            <div class="sender">
                <strong><?= htmlspecialchars($message['expediteur_nom']) ?></strong>
                <span class="sender-type"><?= getParticipantType($message['expediteur_type']) ?></span>
            </div>
            <div class="message-meta">
                <?php if ($importance !== 'normal'): ?>
                <span class="importance-tag <?= htmlspecialchars($importance) ?>">
                    <?= htmlspecialchars($importance) ?>
                </span>
                <?php endif; ?>
                <span class="date"><?= formatDate($message['date_envoi']) ?></span>
            </div>
        </div>
        
        <div class="message-content">
            <?= nl2br(linkify(htmlspecialchars($message['contenu']))) ?>
            
            <?php if (!empty($message['pieces_jointes'])): ?>
            <div class="attachments">
                <?php foreach ($message['pieces_jointes'] as $attachment): ?>
                <a href="<?= isset($baseUrl) ? $baseUrl : '' ?><?= htmlspecialchars($attachment['chemin']) ?>" class="attachment" target="_blank">
                    <i class="fas fa-paperclip"></i> <?= htmlspecialchars($attachment['nom_fichier']) ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="message-footer">
            <!-- Read status display -->
            <div class="message-status">
                <?php if ($isSelf): ?>
                    <div class="message-read-status" data-message-id="<?= (int)$message['id'] ?>">
                        <?php if (isset($message['read_status']) && $message['read_status']['all_read']): ?>
                            <div class="all-read">
                                <i class="fas fa-check-double"></i> Vu
                            </div>
                        <?php elseif (isset($message['read_status']) && $message['read_status']['read_by_count'] > 0): ?>
                            <div class="partial-read">
                                <i class="fas fa-check"></i> 
                                <span class="read-count"><?= $message['read_status']['read_by_count'] ?>/<?= $message['read_status']['total_participants'] - 1 ?></span>
                                <span class="read-tooltip" title="<?= implode(', ', array_column($message['read_status']['readers'], 'nom_complet')) ?>">
                                    <i class="fas fa-info-circle"></i>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
                
            <?php if (isset($canReply) && $canReply && !$isSelf): ?>
            <div class="message-actions">
                <?php if (isset($message['est_lu']) && $message['est_lu']): ?>
                    <button class="btn-icon mark-unread-btn" data-message-id="<?= (int)$message['id'] ?>">
                        <i class="fas fa-envelope"></i> Marquer comme non lu
                    </button>
                <?php else: ?>
                    <button class="btn-icon mark-read-btn" data-message-id="<?= (int)$message['id'] ?>">
                        <i class="fas fa-envelope-open"></i> Marquer comme lu
                    </button>
                <?php endif; ?>
                <button class="btn-icon" onclick="replyToMessage(<?= (int)$message['id'] ?>, '<?= htmlspecialchars(addslashes($message['expediteur_nom'])) ?>')">
                    <i class="fas fa-reply"></i> Répondre
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
} elseif ($inListingView) {
    // LISTING VIEW - displaying a conversation item in the list
    // Add unread class if needed
    if (isset($conversation['non_lus']) && $conversation['non_lus'] > 0) {
        $itemClasses[] = 'unread';
    }
    
    // Add type-specific class (if it's an announcement)
    if (isset($conversation['type']) && $conversation['type'] === 'annonce') {
        $itemClasses[] = 'annonce';
    }
    
    // Get the conversation ID
    $convId = isset($conversation['id']) ? (int)$conversation['id'] : 0;
    
    // This is specifically for the conversation list view
    ?>
    <a href="conversation.php?id=<?= $convId ?>" class="conversation-item <?= implode(' ', $itemClasses) ?>">
        <!-- Checkbox for bulk actions -->
        <label class="checkbox-container conversation-selector">
            <input type="checkbox" class="conversation-checkbox" value="<?= $convId ?>">
            <span class="checkmark"></span>
        </label>
        
        <!-- Conversation icon based on type -->
        <div class="conversation-icon <?= isset($conversation['type']) ? $conversation['type'] : '' ?>">
            <i class="fas fa-<?= getConversationIcon(isset($conversation['type']) ? $conversation['type'] : 'standard') ?>"></i>
        </div>
        
        <div class="conversation-content">
            <div class="conversation-header">
                <h3><?= isset($conversation['titre']) ? htmlspecialchars($conversation['titre']) : 'Sans titre' ?></h3>
                
                <!-- Quick actions menu -->
                <div class="quick-actions">
                    <button class="quick-actions-btn" onclick="toggleQuickActions(<?= $convId ?>); return false;">
                        <i class="fas fa-ellipsis-v"></i>
                    </button>
                    <div class="quick-actions-menu" id="quick-actions-<?= $convId ?>">
                        <?php if ($currentFolder !== 'corbeille'): ?>
                            <?php if ($currentFolder !== 'archives'): ?>
                            <form method="post" action="">
                                <input type="hidden" name="action" value="archive">
                                <input type="hidden" name="conv_id" value="<?= $convId ?>">
                                <button type="submit"><i class="fas fa-archive"></i> Archiver</button>
                            </form>
                            <?php endif; ?>
                            
                            <form method="post" action="">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="conv_id" value="<?= $convId ?>">
                                <button type="submit" class="delete"><i class="fas fa-trash"></i> Supprimer</button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="">
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="conv_id" value="<?= $convId ?>">
                                <button type="submit"><i class="fas fa-trash-restore"></i> Restaurer</button>
                            </form>
                            
                            <form method="post" action="">
                                <input type="hidden" name="action" value="delete_permanently">
                                <input type="hidden" name="conv_id" value="<?= $convId ?>">
                                <button type="submit" class="delete"><i class="fas fa-trash-alt"></i> Supprimer définitivement</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="conversation-meta">
                <span class="type"><?= isset($conversation['type']) ? getConversationType($conversation['type']) : 'Message' ?></span>
                <span class="date"><?= isset($conversation['dernier_message']) ? formatDate($conversation['dernier_message']) : formatDate($conversation['date_creation']) ?></span>
                <?php if (isset($conversation['status']) && $conversation['status'] !== 'normal'): ?>
                    <span class="message-status <?= $conversation['status'] ?>"><?= ucfirst($conversation['status']) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </a>
    <?php
} else {
    // Fallback display if neither condition is met
    echo '<div class="error-item">Données indisponibles</div>';
}
?>