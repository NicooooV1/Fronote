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
            <a href="<?= htmlspecialchars($attachment['chemin']) ?>" class="attachment" target="_blank">
                <i class="fas fa-paperclip"></i> <?= htmlspecialchars($attachment['nom_fichier']) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    
<!-- Dans le footer du message -->
    <div class="message-status">
        <?php if ($isSelf && isset($message['est_lu']) && $message['est_lu']): ?>
        <div class="message-read">
            <i class="fas fa-check"></i> Vu
        </div>
        <?php endif; ?>
    </div>
        
        <?php if (isset($canReply) && $canReply && !$isSelf): ?>
        <div class="message-actions">
            <button class="btn-icon" onclick="replyToMessage(<?= (int)$message['id'] ?>, '<?= htmlspecialchars(addslashes($message['expediteur_nom'])) ?>')">
                <i class="fas fa-reply"></i> Répondre
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>