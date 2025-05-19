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

// Ensure all keys we need are available with default values
$message['sender_id'] = $message['sender_id'] ?? ($message['expediteur_id'] ?? 0);
$message['sender_type'] = $message['sender_type'] ?? ($message['expediteur_type'] ?? '');
$message['body'] = $message['body'] ?? ($message['contenu'] ?? '');
$message['created_at'] = $message['created_at'] ?? ($message['date_envoi'] ?? '');
$message['expediteur_nom'] = $message['expediteur_nom'] ?? 'Utilisateur inconnu';

// Check if message is from current user
$isSelf = isCurrentUser($message['sender_id'], $message['sender_type'], $user);
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
$messageClassString = implode(' ', $messageClasses);

// Format timestamp for display
$timestamp = isset($message['timestamp']) ? (int)$message['timestamp'] : time();

// Check if formatTimeAgo function exists, otherwise use a simpler formatting
if (function_exists('formatTimeAgo')) {
    $formattedDate = formatTimeAgo($timestamp);
} else {
    $formattedDate = date('d/m/Y H:i', $timestamp);
}

// Prepare content safely
$messageContent = nl2br(htmlspecialchars($message['body']));
?>

<div class="message <?= $messageClassString ?>" data-id="<?= $message['id'] ?>" data-timestamp="<?= $timestamp ?>">
    <div class="message-header">
        <div class="sender">
            <strong><?= htmlspecialchars($message['expediteur_nom']) ?></strong>
            <span class="sender-type"><?= getParticipantType($message['sender_type']) ?></span>
        </div>
        <div class="message-meta">
            <?php if ($importance !== 'normal'): ?>
            <span class="importance-tag <?= $importance ?>"><?= ucfirst($importance) ?></span>
            <?php endif; ?>
            <span class="date" title="<?= date('d/m/Y H:i', $timestamp) ?>"><?= $formattedDate ?></span>
        </div>
    </div>
    
    <div class="message-content">
        <?= $messageContent ?>
        
        <?php if (!empty($message['pieces_jointes'])): ?>
        <div class="attachments">
            <?php foreach ($message['pieces_jointes'] as $piece): ?>
            <a href="<?= $piece['chemin'] ?>" class="attachment" target="_blank" download>
                <i class="fas fa-paperclip"></i> <?= htmlspecialchars($piece['nom_fichier']) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="message-footer">
        <?php if ($isSelf): ?>
        <!-- Pour ses propres messages: Status de lecture -->
        <div class="message-read-status">
            <?php if (isset($message['est_lu']) && $message['est_lu']): ?>
            <div class="all-read"><i class="fas fa-check-double"></i> Vu</div>
            <?php else: ?>
            <div class="partial-read"><i class="fas fa-check"></i></div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <!-- Pour les messages des autres: Actions -->
        <div class="message-actions">
            <?php if ($canReply): ?>
            <button class="btn-icon reply-btn" onclick="replyToMessage(<?= $message['id'] ?>, '<?= htmlspecialchars($message['expediteur_nom']) ?>')">
                <i class="fas fa-reply"></i> Répondre
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>