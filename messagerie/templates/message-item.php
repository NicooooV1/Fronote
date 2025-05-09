<?php
/**
 * /templates/message-item.php - Élément de message individuel
 * 
 * Variables attendues:
 * - $message: Informations sur le message
 * - $user: Utilisateur courant
 * - $conversation: Informations sur la conversation
 * - $canReply: Si l'utilisateur peut répondre
 */

// Classes CSS pour le message
$messageClasses = [];
$isSelf = isCurrentUser($message['expediteur_id'], $message['expediteur_type'], $user);
if ($isSelf) {
    $messageClasses[] = 'self';
}

// Vérification de l'existence de l'importance et définition d'une valeur par défaut
$importance = isset($message['status']) ? $message['status'] : 'normal';
$messageClasses[] = $importance;

$messageClasses[] = isset($message['est_lu']) && $message['est_lu'] ? 'read' : '';
$messageClasses[] = isset($conversation['type']) && $conversation['type'] === 'annonce' ? 'annonce' : '';

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
            <?php 
            // Afficher le statut correct - MODIFIÉ pour prioritiser le type annonce
            $statusClass = '';
            $statusLabel = '';
            
            if ($importance !== 'normal'): ?>
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
            <a href="<?= htmlspecialchars($attachment['chemin']) ?>" class="attachment" target="_blank">
                <i class="fas fa-paperclip"></i> <?= htmlspecialchars($attachment['nom_fichier']) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="message-footer">
        <div class="message-status">
            <?php if ($isSelf && isset($message['est_lu']) && ($message['est_lu'] === 1 || $message['est_lu'] === true)): ?>
                <div class="message-read">
                    <i class="fas fa-check"></i> Vu
                </div>
            <?php endif; ?>
        </div>
        
        <?php if (isset($canReply) && $canReply && !$isSelf): ?>
        <div class="message-actions">
            <?php if (!isset($isConversationView) || !$isConversationView): ?>
                <?php if (isset($message['est_lu']) && $message['est_lu']): ?>
                    <button class="btn-icon mark-unread-btn" data-message-id="<?= (int)$message['id'] ?>">
                        <i class="fas fa-envelope"></i> Marquer comme non lu
                    </button>
                <?php else: ?>
                    <button class="btn-icon mark-read-btn" data-message-id="<?= (int)$message['id'] ?>">
                        <i class="fas fa-envelope-open"></i> Marquer comme lu
                    </button>
                <?php endif; ?>
            <?php endif; ?>
            
            <button class="btn-icon" onclick="replyToMessage(<?= (int)$message['id'] ?>, '<?= htmlspecialchars(addslashes($message['expediteur_nom'])) ?>')">
                <i class="fas fa-reply"></i> Répondre
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>