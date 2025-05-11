<?php
/**
 * Item d'un message dans une conversation
 * 
 * @param array $message or $conversation The message/conversation to display
 * @param array $user Current logged-in user
 * @param bool $canReply Whether the user can reply
 */

// First, determine which variable to use (message or conversation)
$item = isset($message) ? $message : (isset($conversation) ? $conversation : []);

// Classes CSS for the message
$messageClasses = [];
$isSelf = isset($item['expediteur_id']) && isset($item['expediteur_type']) && isset($user) && 
          isCurrentUser($item['expediteur_id'], $item['expediteur_type'], $user);
if ($isSelf) {
    $messageClasses[] = 'self';
}

// Importance/status of the message
$importance = isset($item['status']) ? $item['status'] : 'normal';
$messageClasses[] = $importance;

// Read/unread message
if (isset($item['est_lu']) && $item['est_lu']) {
    $messageClasses[] = 'read';
}

// Announcement
if (isset($conversation) && isset($conversation['type']) && $conversation['type'] === 'annonce') {
    $messageClasses[] = 'annonce';
}

// Filter empty classes
$messageClasses = array_filter($messageClasses);
?>

<div class="message <?= implode(' ', $messageClasses) ?>" data-id="<?= isset($item['id']) ? (int)$item['id'] : 0 ?>" data-timestamp="<?= isset($item['date_envoi']) ? strtotime($item['date_envoi']) : 0 ?>">
    <div class="message-header">
        <div class="sender">
            <strong><?= isset($item['expediteur_nom']) ? htmlspecialchars($item['expediteur_nom']) : 'Unknown' ?></strong>
            <span class="sender-type"><?= isset($item['expediteur_type']) ? getParticipantType($item['expediteur_type']) : '' ?></span>
        </div>
        <div class="message-meta">
            <?php if ($importance !== 'normal'): ?>
            <span class="importance-tag <?= htmlspecialchars($importance) ?>">
                <?= htmlspecialchars($importance) ?>
            </span>
            <?php endif; ?>
            <span class="date"><?= isset($item['date_envoi']) ? formatDate($item['date_envoi']) : 'Jamais' ?></span>
        </div>
    </div>
    
    <div class="message-content">
        <?= isset($item['contenu']) ? nl2br(linkify(htmlspecialchars($item['contenu']))) : '' ?>
        
        <?php if (isset($item['pieces_jointes']) && !empty($item['pieces_jointes'])): ?>
        <div class="attachments">
            <?php foreach ($item['pieces_jointes'] as $attachment): ?>
            <a href="<?= isset($baseUrl) ? $baseUrl : '' ?><?= htmlspecialchars($attachment['chemin']) ?>" class="attachment" target="_blank">
                <i class="fas fa-paperclip"></i> <?= htmlspecialchars($attachment['nom_fichier']) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="message-footer">
        <!-- Improved display of read status -->
        <div class="message-status">
            <?php if ($isSelf): ?>
                <div class="message-read-status" data-message-id="<?= isset($item['id']) ? (int)$item['id'] : 0 ?>">
                    <?php if (isset($item['read_status']) && $item['read_status']['all_read']): ?>
                        <div class="all-read">
                            <i class="fas fa-check-double"></i> Vu
                        </div>
                    <?php elseif (isset($item['read_status']) && $item['read_status']['read_by_count'] > 0): ?>
                        <div class="partial-read">
                            <i class="fas fa-check"></i> 
                            <span class="read-count"><?= $item['read_status']['read_by_count'] ?>/<?= $item['read_status']['total_participants'] - 1 ?></span>
                            <span class="read-tooltip" title="<?= implode(', ', array_column($item['read_status']['readers'], 'nom_complet')) ?>">
                                <i class="fas fa-info-circle"></i>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
            
        <?php if (isset($canReply) && $canReply && !$isSelf): ?>
        <div class="message-actions">
            <?php if (isset($item['est_lu']) && $item['est_lu']): ?>
                <button class="btn-icon mark-unread-btn" data-message-id="<?= isset($item['id']) ? (int)$item['id'] : 0 ?>">
                    <i class="fas fa-envelope"></i> Marquer comme non lu
                </button>
            <?php else: ?>
                <button class="btn-icon mark-read-btn" data-message-id="<?= isset($item['id']) ? (int)$item['id'] : 0 ?>">
                    <i class="fas fa-envelope-open"></i> Marquer comme lu
                </button>
            <?php endif; ?>
            <button class="btn-icon" onclick="replyToMessage(<?= isset($item['id']) ? (int)$item['id'] : 0 ?>, '<?= isset($item['expediteur_nom']) ? htmlspecialchars(addslashes($item['expediteur_nom'])) : '' ?>')">
                <i class="fas fa-reply"></i> Répondre
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>