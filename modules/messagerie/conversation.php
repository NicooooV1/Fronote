<?php
declare(strict_types=1);
/**
 * Affichage et gestion d'une conversation
 */

// Charger la configuration
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/core/utils.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/csrf.php';
require_once __DIR__ . '/core/validator.php';
require_once __DIR__ . '/models/conversation.php';
require_once __DIR__ . '/models/message.php';
require_once __DIR__ . '/models/participant.php';
require_once __DIR__ . '/controllers/conversation.php';
require_once __DIR__ . '/controllers/message.php';
require_once __DIR__ . '/controllers/participant.php';

// Vérifier l'authentification
$user = requireAuth();

// Récupérer l'ID de la conversation
$convId = Validator::id($_GET['id'] ?? null);

if (!$convId) {
    redirect('index.php');
}

$error = '';
$success = '';
$messageContent = '';

try {
    // Vérifier l'accès à la conversation
    $conversation = getConversationInfo($convId);
    
    if (!$conversation) {
        throw new Exception("La conversation n'existe pas");
    }
    
    // Vérifier les permissions
    $participantInfo = getParticipantInfo($convId, $user['id'], $user['type']);
    
    if (!$participantInfo) {
        throw new Exception("Vous n'êtes pas autorisé à accéder à cette conversation");
    }
    
    $isDeleted = $participantInfo['is_deleted'] == 1;
    $isAdmin = $participantInfo['is_admin'] == 1;
    $isModerator = $participantInfo['is_moderator'] == 1 || $isAdmin;
    
    // Récupérer les messages et participants
    if (!$isDeleted) {
        // Capturer le dernier message lu AVANT le marquage comme lu, afin de positionner
        // le séparateur « nouveaux messages ». markConversationAsRead() ci-dessous fait
        // avancer last_read_message_id ; on lit donc sa valeur pré-ouverture ici.
        // Dernier message lu AVANT le marquage comme lu (séparateur « nouveaux messages »).
        // Passe par le modèle (pas de SQL brut en page — cloisonnement/ratchet).
        $lastReadMessageId = getLastReadMessageId($convId, $user['id'], $user['type']);

        $messagesResult = getMessages($convId, $user['id'], $user['type']);
        $messages = $messagesResult['messages'];
        $pinnedMessages = $messagesResult['pinned'] ?? [];
        $hasMoreMessages = $messagesResult['has_more'];
        $participants = getParticipants($convId);

        // Nombre de réponses par message parent (badge .msg-reply-count).
        $replyCounts = getReplyCounts($convId);

        // Séparateur « nouveaux messages » : repérer le premier message (non émis par soi)
        // dont l'id dépasse last_read_message_id, et compter les nouveaux messages.
        $firstUnreadId = null;
        $newMessagesCount = 0;
        foreach ($messages as $m) {
            $isSelfMsg = (int) ($m['is_self'] ?? 0) === 1;
            if (!$isSelfMsg && (int) $m['id'] > $lastReadMessageId) {
                if ($firstUnreadId === null) {
                    $firstUnreadId = (int) $m['id'];
                }
                $newMessagesCount++;
            }
        }

        // Ouvrir la conversation (page complète) = intention de lecture : marquage
        // explicite ici (getMessages n'a plus cet effet de bord — cf. audit M5).
        markConversationAsRead($convId, $user['id'], $user['type']);
    } else {
        $messagesResult = getMessagesEvenIfDeleted($convId, $user['id'], $user['type']);
        $messages = $messagesResult['messages'];
        $pinnedMessages = [];
        $hasMoreMessages = $messagesResult['has_more'];
        $participants = getParticipants($convId);
        $replyCounts = [];
        $firstUnreadId = null;
        $newMessagesCount = 0;
    }
    
    $canReply = !$isDeleted && canReplyToAnnouncement($user['id'], $user['type'], $convId, $conversation['type']);
    
    $pageTitle = 'Conversation - ' . $conversation['titre'];
    
    // Traitement des actions POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && !$isDeleted) {
        csrf_verify();
        
        switch ($_POST['action']) {
            case 'send_message':
                $result = handleSendMessage(
                    $convId,
                    $user,
                    $_POST['contenu'],
                    isset($_POST['importance']) ? $_POST['importance'] : 'normal',
                    isset($_POST['parent_message_id']) && !empty($_POST['parent_message_id']) ? (int)$_POST['parent_message_id'] : null,
                    isset($_FILES['attachments']) ? $_FILES['attachments'] : []
                );
                
                if ($result['success']) {
                    // Redirection pour éviter les soumissions multiples
                    redirect("conversation.php?id=$convId");
                } else {
                    $error = $result['message'];
                    $messageContent = $_POST['contenu']; // Conserver le contenu en cas d'erreur
                }
                break;
                
            case 'add_participant':
                $result = handleAddParticipant(
                    $convId,
                    $_POST['participant_id'],
                    $_POST['participant_type'],
                    $user
                );
                
                if ($result['success']) {
                    $success = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;
                
            case 'promote_moderator':
                $result = handlePromoteToModerator(
                    $convId,
                    $_POST['participant_id'],
                    $user
                );
                
                if ($result['success']) {
                    $success = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;
                
            case 'demote_moderator':
                $result = handleDemoteFromModerator(
                    $convId,
                    $_POST['participant_id'],
                    $user
                );
                
                if ($result['success']) {
                    $success = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;
                
            case 'remove_participant':
                $result = handleRemoveParticipant(
                    $convId,
                    $_POST['participant_id'],
                    $user
                );
                
                if ($result['success']) {
                    $success = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;
                
            case 'archive_conversation':
                $result = handleArchiveConversation($convId, $user);
                
                if ($result['success']) {
                    redirect($result['redirect']);
                } else {
                    $error = $result['message'];
                }
                break;
                
            case 'delete_conversation':
                $result = handleDeleteConversation($convId, $user);
                
                if ($result['success']) {
                    redirect($result['redirect']);
                } else {
                    $error = $result['message'];
                }
                break;
                
            case 'restore_conversation':
                $result = handleRestoreConversation($convId, $user);
                
                if ($result['success']) {
                    redirect($result['redirect']);
                } else {
                    $error = $result['message'];
                }
                break;
                
            case 'unarchive':
                $result = handleUnarchiveConversation($convId, $user);
                
                if ($result['success']) {
                    redirect($result['redirect']);
                } else {
                    $error = $result['message'];
                }
                break;
        }
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
    $conversation = null;
    $messages = [];
    $participants = [];
    $isDeleted = false;
    $isAdmin = false;
    $isModerator = false;
    $canReply = false;
    $replyCounts = [];
    $firstUnreadId = null;
    $newMessagesCount = 0;
}

/**
 * Helpers d'avatar (palier de rôle + initiales), partagés par l'en-tête slim et
 * message-item.php (défini ici en 1er → message-item saute sa propre définition).
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

include 'templates/header.php';
?>

<div class="content conversation-page" data-sidebar-collapsed="false">
    <?php if (isset($error) && !empty($error)): ?>
    <div class="alert error"><?= htmlspecialchars($error) ?></div>
    <?php elseif (isset($success) && !empty($success)): ?>
    <div class="alert success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    
    <?php if (isset($conversation)): ?>
    
    <aside class="conversation-sidebar">
        <div class="conversation-info">
            <?php // Le titre « Participants » + bouton d'ajout sont rendus par participant-list.php
            include 'templates/components/participant-list.php'; ?>
        </div>
        
        <div class="conversation-actions">
            <?php if ($isDeleted): ?>
            <a href="#" class="action-button" id="restore-btn">
                <i class="fas fa-trash-restore"></i> Restaurer la conversation
            </a>
            <?php else: ?>
            <a href="#" class="action-button" id="archive-btn">
                <i class="fas fa-archive"></i> Archiver la conversation
            </a>
            <a href="#" class="action-button" id="delete-btn">
                <i class="fas fa-trash"></i> Supprimer la conversation
            </a>
            <?php endif; ?>
        </div>
    </aside>
    
    <main class="conversation-main msg-thread">
        <header class="conv-thread-header">
            <div class="conv-thread-head-main">
                <h1 class="conv-thread-title"><?= h($conversation['titre'] ?? 'Conversation') ?></h1>
                <div class="conv-thread-sub">
                    <?php
                    $activeParticipants = array_values(array_filter($participants ?? [], static function ($p) {
                        return empty($p['a_quitte']);
                    }));
                    $partCount = count($activeParticipants);
                    ?>
                    <?php if ($partCount > 0): ?>
                    <span class="conv-thread-avatars" aria-hidden="true">
                        <?php foreach (array_slice($activeParticipants, 0, 5) as $p):
                            $pTier = msgSenderTier((string) ($p['utilisateur_type'] ?? ''));
                        ?>
                        <span class="msg-avatar msg-avatar--<?= h($pTier) ?>" title="<?= h($p['nom_complet'] ?? '') ?>"><?= h(msgInitials((string) ($p['nom_complet'] ?? ''))) ?></span>
                        <?php endforeach; ?>
                    </span>
                    <span><?= $partCount ?> participant<?= $partCount > 1 ? 's' : '' ?></span>
                    <?php endif; ?>
                    <?php if (($conversation['type'] ?? '') === 'annonce'): ?>
                    <span class="conv-thread-badge is-annonce"><i class="fas fa-bullhorn"></i> Annonce</span>
                    <?php endif; ?>
                </div>
            </div>
        </header>
        <?php if (!empty($pinnedMessages)): ?>
        <div class="pinned-messages-bar">
            <div class="pinned-header">
                <i class="fas fa-thumbtack"></i> Messages épinglés (<?= count($pinnedMessages) ?>)
                <button class="btn-icon toggle-pinned" data-fr-click="toggleClass" data-fr-args='[".pinned-messages-list","collapsed"]'>
                    <i class="fas fa-chevron-down"></i>
                </button>
            </div>
            <div class="pinned-messages-list">
                <?php foreach ($pinnedMessages as $pinned): ?>
                <div class="pinned-message-preview" data-message-id="<?= $pinned['id'] ?>">
                    <strong><?= h($pinned['expediteur_nom']) ?></strong>: 
                    <?= h(mb_substr($pinned['body'], 0, 100)) ?><?= mb_strlen($pinned['body']) > 100 ? '…' : '' ?>
                    <a href="#" data-fr-click="scrollToMessage" data-fr-args='[<?= $pinned['id'] ?>]' data-fr-prevent="1" class="jump-to-msg">
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($hasMoreMessages): ?>
        <div class="load-more-messages" id="load-more-messages">
            <button class="btn secondary btn-sm" data-fr-click="loadOlderMessages">
                <i class="fas fa-history"></i> Charger les messages précédents
            </button>
        </div>
        <?php endif; ?>
        
        <div class="messages-container" data-conv-id="<?= $convId ?>" data-has-more="<?= $hasMoreMessages ? '1' : '0' ?>" role="log" aria-live="polite" aria-relevant="additions" aria-atomic="false">
            <?php
            // Regroupement visuel : une bulle démarre un groupe si l'expéditeur change,
            // si l'écart de temps dépasse 10 min, ou si le séparateur « non lus » l'introduit.
            $prevSenderKey = null;
            $prevTs = null;
            foreach ($messages as $message):
                $isUnreadStart = ($firstUnreadId !== null && (int) $message['id'] === $firstUnreadId);
                $curSenderKey = ($message['sender_type'] ?? '') . ':' . ($message['sender_id'] ?? '');
                $curTs = (int) ($message['timestamp'] ?? 0);
                $isGroupStart = $isUnreadStart
                    || $curSenderKey !== $prevSenderKey
                    || ($prevTs !== null && ($curTs - $prevTs) > 600)
                    || !empty($message['deleted_at']);
                $prevSenderKey = $curSenderKey;
                $prevTs = $curTs;
            ?>
                <?php if ($isUnreadStart): ?>
                <div class="unread-divider" data-first-unread-id="<?= $firstUnreadId ?>" role="separator">
                    <span class="unread-divider-label">
                        <?= $newMessagesCount ?> nouveau<?= $newMessagesCount > 1 ? 'x' : '' ?> message<?= $newMessagesCount > 1 ? 's' : '' ?>
                    </span>
                </div>
                <?php endif; ?>
                <?php include 'templates/components/message-item.php'; ?>
            <?php endforeach; ?>
        </div>
        
<?php if ($isDeleted): ?>
<div class="conversation-deleted">
    <p>Cette conversation a été déplacée dans la corbeille. Vous ne pouvez plus y répondre.</p>
    <form method="post" action="" id="restoreForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="restore_conversation">
        <button type="submit" class="btn primary">Restaurer la conversation</button>
    </form>
</div>
<?php elseif ($canReply): ?>
<div class="reply-box msg-composer">
    <form method="post" enctype="multipart/form-data" id="messageForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send_message">
        <input type="hidden" name="parent_message_id" id="parent-message-id" value="">
        
        <!-- Interface de réponse (cachée par défaut) -->
        <div id="reply-interface" style="display: none;">
            <div class="reply-header">
                <span id="reply-to" class="reply-to"></span>
                <button type="button" class="btn-icon" data-fr-click="cancelReply">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        
        <?php if (canSetMessageImportance($user['type'])): ?>
        <div class="reply-options">
            <select name="importance" class="importance-select">
                <option value="normal"><?= __('status.normal') ?></option>
                <option value="important">Important</option>
                <option value="urgent">Urgent</option>
            </select>
        </div>
        <?php endif; ?>
        
        <textarea name="contenu" rows="4" placeholder="Envoyer un message..." required><?= htmlspecialchars($messageContent) ?></textarea>
        
        <div class="form-footer">
            <div class="file-upload">
                <input type="file" name="attachments[]" id="attachments" multiple>
                <label for="attachments">
                    <i class="fas fa-paperclip"></i> Sélect. fichiers
                </label>
                <div id="file-list"></div>
            </div>
            
            <button type="submit" class="btn primary">
                <i class="fas fa-paper-plane"></i> <?= __('messagerie.send') ?>
            </button>
        </div>
    </form>
</div>
<?php elseif (!$isDeleted && $conversation['type'] === 'annonce'): ?>
<div class="conversation-deleted">
    <p>Cette annonce est en lecture seule. Vous ne pouvez pas y répondre.</p>
</div>
<?php endif; ?>
    </main>
    <?php endif; ?>
</div>

<!-- Modal pour ajouter des participants -->
<div class="modal" id="addParticipantModal">
    <div class="modal-content">
        <span class="close" data-fr-click="closeAddParticipantModal">&times;</span>
        <h3>Ajouter des participants</h3>
        <form method="post" id="addParticipantForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_participant">
            
            <div class="form-group">
                <label for="participant_type">Type de participant</label>
                <select name="participant_type" id="participant_type" data-fr-change="loadParticipants" required>
                    <option value="">Sélectionner un type</option>
                    <option value="eleve">Élève</option>
                    <option value="parent">Parent</option>
                    <option value="professeur"><?= __('label.professeur') ?></option>
                    <option value="vie_scolaire">Vie scolaire</option>
                    <option value="administrateur">Administrateur</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="participant_id">Participant</label>
                <select name="participant_id" id="participant_id" required>
                    <option value="">Choisissez d'abord un type</option>
                </select>
            </div>
            
            <button type="submit" class="btn primary"><?= __('btn.add') ?></button>
        </form>
    </div>
</div>

<!-- Formulaires cachés pour les actions -->
<form id="archiveForm" method="post" style="display: none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="archive_conversation">
</form>

<form id="deleteForm" method="post" style="display: none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_conversation">
</form>

<form id="promoteForm" method="post" style="display: none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="promote_moderator">
    <input type="hidden" name="participant_id" id="promote_participant_id" value="">
</form>

<form id="demoteForm" method="post" style="display: none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="demote_moderator">
    <input type="hidden" name="participant_id" id="demote_participant_id" value="">
</form>

<form id="removeForm" method="post" style="display: none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="remove_participant">
    <input type="hidden" name="participant_id" id="remove_participant_id" value="">
</form>

<?php
// Fermer la coquille 2 volets .msg-app ouverte dans templates/header.php.
if (in_array($currentPage ?? '', ['index', 'conversation'], true)) {
    echo "</div><!-- /.msg-app -->\n";
}
// Inclure le pied de page
include 'templates/footer.php';
?>