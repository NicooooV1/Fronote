<?php
declare(strict_types=1);
/**
 * Composant : un message dans une conversation (refonte 2026 — bulles groupées).
 *
 * Restructuré en bulle groupée par expéditeur + avatar-initiale coloré par palier
 * de rôle. TOUS les points d'ancrage JS (conversation.js / ws-global.js) sont
 * PRÉSERVÉS : classes .message/.message-content/.message-meta/.message-reactions/
 * .reaction-badge/.message-read-status/.message-dropdown/.attachments/.msg-inline-img,
 * attributs data-id/data-message-id/data-sender/data-sender-type/data-timestamp/
 * data-parent-id, id="message-{id}"/#msg-content-{id}, .msg-quote/.msg-jump-parent,
 * .msg-reply-count. Des alias .msg-* sont ajoutés pour le style ; rien n'est renommé.
 *
 * Variables attendues : $message, $user, (opt.) $isModerator, $replyCounts,
 *   $isGroupStart (bool — 1re bulle d'un groupe : affiche avatar + nom).
 */

$messageId   = $message['id'] ?? 0;
$senderId    = $message['sender_id'] ?? 0;
$senderType  = $message['sender_type'] ?? '';
$senderName  = $message['expediteur_nom'] ?? 'Inconnu';
$content     = $message['body'] ?? $message['contenu'] ?? '';
$timestamp   = $message['timestamp'] ?? time();
$status      = $message['status'] ?? 'normal';
$isRead      = isset($message['est_lu']) && $message['est_lu'] == 1;
$attachments = $message['pieces_jointes'] ?? [];
$editedAt    = $message['edited_at'] ?? null;
$deletedAt   = $message['deleted_at'] ?? null;
$isPinned    = !empty($message['is_pinned']);
$parentId    = $message['parent_message_id'] ?? null;
$reactions   = $message['reactions'] ?? [];
$parentMessage = $message['parent_message'] ?? null;
$replyCount  = isset($replyCounts, $replyCounts[$messageId]) ? (int) $replyCounts[$messageId] : 0;

// Première bulle d'un groupe (avatar + nom affichés) : vrai par défaut.
$isGroupStart = $isGroupStart ?? true;

/**
 * Détecte une pièce jointe image d'après son extension.
 */
if (!function_exists('msgAttachmentIsImage')) {
    function msgAttachmentIsImage(array $attachment): bool {
        $name = $attachment['nom_fichier'] ?? $attachment['file_name'] ?? $attachment['chemin'] ?? '';
        $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif'], true);
    }
}

/**
 * Palier de rôle (design partagé) d'un type d'expéditeur messagerie → classe couleur.
 * Réutilise les mêmes hex que RoleCatalog::tierColor (source autoritaire) sans coupler
 * la page au catalogue : mapping local sûr, repli « eleve » (ardoise).
 */
if (!function_exists('msgSenderTier')) {
    function msgSenderTier(string $type): string {
        static $map = [
            'super_admin'    => 'plateforme',
            'administrateur' => 'plateforme',
            'direction'      => 'direction',
            'administratif'  => 'administratif',
            'vie_scolaire'   => 'vie_scolaire',
            'professeur'     => 'enseignant',
            'enseignant'     => 'enseignant',
            'parent'         => 'famille',
            'eleve'          => 'eleve',
        ];
        return $map[$type] ?? 'eleve';
    }
}

/**
 * Initiales (1 à 2 lettres) d'un nom complet pour l'avatar.
 */
if (!function_exists('msgInitials')) {
    function msgInitials(string $name): string {
        $name = trim($name);
        if ($name === '') return '?';
        $parts = preg_split('/\s+/', $name);
        $first = mb_substr($parts[0], 0, 1);
        $last  = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
        return mb_strtoupper($first . $last);
    }
}

$isSelf = isCurrentUser($senderId, $senderType, $user);
$tier   = msgSenderTier((string) $senderType);
$initials = msgInitials((string) $senderName);

// Classes de la bulle (msg-grouped = rendu serveur ; le JS injecte sans cette classe).
$messageClasses = ['message', 'msg-bubble', 'msg-grouped'];
$messageClasses[] = $isGroupStart ? 'is-group-start' : 'is-group-cont';
if ($isSelf) { $messageClasses[] = 'self'; $messageClasses[] = 'is-self'; }
if ($isRead) $messageClasses[] = 'read';
if ($isPinned) $messageClasses[] = 'pinned';
if ($deletedAt) $messageClasses[] = 'deleted';
if ($status && $status !== 'normal') $messageClasses[] = $status;

$dateFormatted = date('d/m/Y H:i', $timestamp);

$canEdit   = $isSelf && !$deletedAt && (time() - $timestamp < 300);
$canDelete = ($isSelf || (isset($isModerator) && $isModerator)) && !$deletedAt;
$canPin    = isset($isModerator) && $isModerator && !$deletedAt;
?>

<div class="<?= implode(' ', $messageClasses) ?>" data-id="<?= $messageId ?>" data-message-id="<?= (int) $messageId ?>" data-sender="<?= (int) $senderId ?>" data-sender-type="<?= h($senderType) ?>" data-timestamp="<?= $timestamp ?>"<?= $parentId ? ' data-parent-id="' . (int) $parentId . '"' : '' ?> id="message-<?= $messageId ?>">

    <div class="msg-avatar msg-avatar--<?= h($tier) ?>" title="<?= h($senderName) ?>" aria-hidden="true"><?= h($initials) ?></div>

    <div class="msg-col">
        <?php if ($isPinned): ?>
        <div class="pinned-badge"><i class="fas fa-thumbtack"></i> Épinglé</div>
        <?php endif; ?>

        <div class="message-header">
            <div class="sender">
                <strong class="msg-sender"><?= h($senderName) ?></strong>
                <span class="sender-type msg-role msg-role--<?= h($tier) ?>"><?= getParticipantType($senderType) ?></span>
            </div>
            <div class="message-meta msg-meta">
                <?php if ($status && $status !== 'normal'): ?>
                <span class="importance-tag <?= $status ?>"><?= ucfirst($status) ?></span>
                <?php endif; ?>
                <?php if ($editedAt): ?>
                <span class="edited-tag" title="Modifié le <?= date('d/m/Y H:i', strtotime($editedAt)) ?>">
                    <i class="fas fa-pencil-alt"></i> modifié
                </span>
                <?php endif; ?>
                <span class="date" title="<?= $dateFormatted ?>"><?= formatTimeAgo($timestamp) ?></span>

                <?php if (!$deletedAt): ?>
                <div class="message-dropdown">
                    <button class="btn-icon message-menu-btn" title="<?= __('label.actions') ?>"><i class="fas fa-ellipsis-v"></i></button>
                    <div class="message-dropdown-content">
                        <?php if ($canEdit): ?>
                        <button data-fr-click="editMessage" data-fr-args='[<?= (int)$messageId ?>]'><i class="fas fa-edit"></i> <?= __('btn.edit') ?></button>
                        <?php endif; ?>
                        <?php if ($canDelete): ?>
                        <button data-fr-click="deleteMessage" data-fr-args='[<?= (int)$messageId ?>]'><i class="fas fa-trash"></i> <?= __('btn.delete') ?></button>
                        <?php endif; ?>
                        <?php if ($canPin): ?>
                        <button data-fr-click="togglePinMessage" data-fr-args='[<?= (int)$messageId ?>]'>
                            <i class="fas fa-thumbtack"></i> <?= $isPinned ? 'Désépingler' : 'Épingler' ?>
                        </button>
                        <?php endif; ?>
                        <?php if (!$isSelf): ?>
                        <button class="js-reply" data-message-id="<?= (int)$messageId ?>" data-sender="<?= h($senderName) ?>">
                            <i class="fas fa-reply"></i> <?= __('messagerie.reply') ?>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($parentId): ?>
        <?php
            $quoteAuthor = $parentMessage['expediteur_nom'] ?? 'Message';
            $quoteBody = $parentMessage['body'] ?? null;
            if ($quoteBody === null || $quoteBody === '') {
                $quoteSnippet = 'Message indisponible';
            } else {
                $quoteSnippet = mb_substr($quoteBody, 0, 120);
                if (mb_strlen($quoteBody) > 120) {
                    $quoteSnippet .= '…';
                }
            }
        ?>
        <a href="#message-<?= (int)$parentId ?>" class="msg-jump-parent" data-parent-id="<?= (int)$parentId ?>"
           data-fr-click="scrollToMessage" data-fr-args='[<?= (int)$parentId ?>]' data-fr-prevent="1">
            <blockquote class="msg-quote">
                <span class="msg-quote-author"><i class="fas fa-reply"></i> <?= h($quoteAuthor) ?></span>
                <span class="msg-quote-snippet"><?= h($quoteSnippet) ?></span>
            </blockquote>
        </a>
        <?php endif; ?>

        <div class="message-content msg-body" id="msg-content-<?= $messageId ?>">
            <?= nl2br(linkify(h($content))) ?>
        </div>

        <?php if (!empty($attachments) && !$deletedAt): ?>
        <div class="attachments">
            <div class="attachments-header">
                <i class="fas fa-paperclip"></i> Pièces jointes (<?= count($attachments) ?>)
            </div>
            <?php foreach ($attachments as $attachment): ?>
            <?php
                $attId = (int)($attachment['id'] ?? 0);
                $attName = $attachment['nom_fichier'] ?? $attachment['file_name'] ?? 'Fichier';
                $attUrl = 'download.php?id=' . $attId;
            ?>
            <?php if (msgAttachmentIsImage($attachment)): ?>
            <div class="attachment-item attachment-image">
                <img class="msg-inline-img" src="<?= h($attUrl) ?>" data-full="<?= h($attUrl) ?>"
                     alt="<?= h($attName) ?>" title="<?= h($attName) ?>" loading="lazy">
            </div>
            <?php else: ?>
            <div class="attachment-item">
                <a href="<?= h($attUrl) ?>" target="_blank" class="attachment-link">
                    <i class="fas fa-file"></i>
                    <?= h($attName) ?>
                </a>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!$deletedAt && !empty($reactions)): ?>
        <div class="message-reactions msg-reactions">
            <?php foreach ($reactions as $r): ?>
            <button class="reaction-badge reaction-chip js-reaction <?= $r['user_reacted'] ? 'active is-mine' : '' ?>"
                    data-message-id="<?= (int)$messageId ?>" data-emoji="<?= h($r['emoji']) ?>">
                <?= h($r['emoji']) ?> <span class="reaction-count"><?= (int)$r['count'] ?></span>
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!$deletedAt): ?>
        <div class="message-reactions-add">
            <button class="btn-icon reaction-add-btn" data-fr-click="showReactionPicker" data-fr-args='[<?= (int)$messageId ?>]' title="Ajouter une réaction">
                <i class="far fa-smile"></i>
            </button>
        </div>
        <?php endif; ?>

        <?php if ($replyCount > 0): ?>
        <div class="msg-reply-count-wrap">
            <button type="button" class="msg-reply-count" data-message-id="<?= (int)$messageId ?>"
                    title="<?= $replyCount ?> réponse<?= $replyCount > 1 ? 's' : '' ?>">
                <i class="fas fa-comments"></i> <?= $replyCount ?> réponse<?= $replyCount > 1 ? 's' : '' ?>
            </button>
        </div>
        <?php endif; ?>

        <div class="message-footer">
            <?php if ($isSelf): ?>
            <div class="message-status">
                <div class="message-read-status msg-receipt" data-message-id="<?= $messageId ?>">
                    <?php if ($isRead): ?>
                    <div class="all-read">
                        <i class="fas fa-check-double"></i> Vu
                    </div>
                    <?php else: ?>
                    <div class="partial-read">
                        <i class="fas fa-check"></i> <span class="read-count">0/<?= count($participants ?? []) - 1 ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="message-actions">
                <button class="btn-icon js-reply" data-message-id="<?= (int)$messageId ?>" data-sender="<?= h($senderName) ?>">
                    <i class="fas fa-reply"></i> <?= __('messagerie.reply') ?>
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
