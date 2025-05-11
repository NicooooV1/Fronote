<?php
/**
 * Item d'un message dans une conversation
 * 
 * @param array $message Le message à afficher
 * @param array $user L'utilisateur connecté
 * @param bool $canReply Si l'utilisateur peut répondre
 */

// Classes CSS pour le message
$messageClasses = [];
$isSelf = isCurrentUser($message['expediteur_id'], $message['expediteur_type'], $user);
if ($isSelf) {
    $messageClasses[] = 'self';
}

// Importance/statut du message
$importance = isset($message['status']) ? $message['status'] : 'normal';
$messageClasses[] = $importance;

// Message lu/non lu
if (isset($message['est_lu']) && $message['est_lu']) {
    $messageClasses[] = 'read';
}

// Annonce
if (isset($conversation) && isset($conversation['type']) && $conversation['type'] === 'annonce') {
    $messageClasses[] = 'annonce';
}

// Filtrer les classes vides
$messageClasses = array_filter($messageClasses);
?>

<div class="message <?= implode(' ', $messageClasses) ?>" data-id="<?= (int)$message['id'] ?>" data-timestamp="<?= strtotime($message['date_envoi']) ?>">
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
        <!-- Affichage amélioré du statut de lecture -->
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