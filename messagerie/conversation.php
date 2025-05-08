<?php
/**
 * /conversation.php - Affichage et gestion d'une conversation
 */

// Inclure les fichiers nécessaires
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/message_functions.php';
require_once __DIR__ . '/includes/auth.php';

// Vérifier l'authentification
if (!isLoggedIn()) {
    header('Location: ' . LOGIN_URL);
    exit;
}

$user = $_SESSION['user'];
// Adaptation: utiliser 'profil' comme 'type' si 'type' n'existe pas
if (!isset($user['type']) && isset($user['profil'])) {
    $user['type'] = $user['profil'];
}

// Vérifier que le type est défini
if (!isset($user['type'])) {
    die("Erreur: Type d'utilisateur non défini dans la session");
}

// Récupérer l'ID de la conversation
$convId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$convId) {
    redirect('index.php');
}

$error = '';
$success = '';
$messageContent = ''; // Pour conserver le contenu du message en cas d'erreur

try {
    // Vérifier l'accès à la conversation
    $conversation = getConversationInfo($convId);
    $messages = getMessages($convId, $user['id'], $user['type']);
    $participants = getParticipants($convId);
    
    // Vérifier si la conversation est dans la corbeille
    $participantInfo = getParticipantInfo($convId, $user['id'], $user['type']);
    $isDeleted = $participantInfo && $participantInfo['is_deleted'] == 1;
    $isAdmin = $participantInfo && $participantInfo['is_admin'] == 1;
    $isModerator = $participantInfo && ($participantInfo['is_moderator'] == 1 || $isAdmin);
    
    // Vérifier si l'utilisateur peut répondre à cette conversation
    $canReply = canReplyToAnnouncement($user['id'], $user['type'], $convId, $conversation['type']);
    
    // Définir le titre de la page
    $pageTitle = 'Conversation - ' . $conversation['titre'];
    
    // Traitement de l'envoi d'un nouveau message (uniquement si la conversation n'est pas dans la corbeille)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && !$isDeleted) {
        switch ($_POST['action']) {
            case 'send_message':
                if (!empty($_POST['contenu'])) {
                    // Conserver le contenu du message en cas d'erreur
                    $messageContent = $_POST['contenu'];
                    
                    try {
                        // Vérifier si l'utilisateur peut répondre à une annonce
                        if (!$canReply) {
                            throw new Exception("Vous n'êtes pas autorisé à répondre à cette annonce");
                        }
                        
                        $filesData = isset($_FILES['attachments']) ? $_FILES['attachments'] : [];
                        $importance = 'normal'; // Importance par défaut
                        
                        // Seuls certains utilisateurs peuvent définir une importance
                        if (canSetMessageImportance($user['type']) && isset($_POST['importance'])) {
                            $importance = $_POST['importance'];
                        }
                        
                        $parentMessageId = isset($_POST['parent_message_id']) && !empty($_POST['parent_message_id']) ? 
                                          (int)$_POST['parent_message_id'] : null;
                        
                        addMessage(
                            $convId, 
                            $user['id'], 
                            $user['type'], 
                            $_POST['contenu'],
                            $importance,
                            false, // Est annonce
                            false, // Notification obligatoire
                            false, // Accusé de réception
                            $parentMessageId, // Message parent
                            'standard',
                            $filesData
                        );
                        
                        // Redirection pour éviter les soumissions multiples
                        redirect("conversation.php?id=$convId");
                    } catch (Exception $e) {
                        $error = $e->getMessage();
                        // Ne pas rediriger en cas d'erreur pour conserver le formulaire
                    }
                }
                break;
                
            case 'add_participant':
                if (isset($_POST['participant_id']) && isset($_POST['participant_type'])) {
                    // Vérifier que l'utilisateur est admin ou modérateur
                    if (!$isModerator) {
                        throw new Exception("Vous n'êtes pas autorisé à ajouter des participants");
                    }
                    
                    addParticipantToConversation(
                        $convId, 
                        $_POST['participant_id'], 
                        $_POST['participant_type'], 
                        $user['id'], 
                        $user['type']
                    );
                    redirect("conversation.php?id=$convId");
                }
                break;
                
            case 'promote_moderator':
                if (isset($_POST['participant_id'])) {
                    // Vérifier que l'utilisateur est admin
                    if (!$isAdmin) {
                        throw new Exception("Vous n'êtes pas autorisé à promouvoir des modérateurs");
                    }
                    
                    promoteToModerator(
                        $_POST['participant_id'], 
                        $user['id'], 
                        $user['type'], 
                        $convId
                    );
                    redirect("conversation.php?id=$convId");
                }
                break;
                
            case 'demote_moderator':
                if (isset($_POST['participant_id'])) {
                    // Vérifier que l'utilisateur est admin
                    if (!$isAdmin) {
                        throw new Exception("Vous n'êtes pas autorisé à rétrograder des modérateurs");
                    }
                    
                    demoteFromModerator(
                        $_POST['participant_id'], 
                        $user['id'], 
                        $user['type'], 
                        $convId
                    );
                    redirect("conversation.php?id=$convId");
                }
                break;
                
            case 'remove_participant':
                if (isset($_POST['participant_id'])) {
                    // Vérifier que l'utilisateur est admin ou modérateur
                    if (!$isModerator) {
                        throw new Exception("Vous n'êtes pas autorisé à supprimer des participants");
                    }
                    
                    removeParticipant(
                        $_POST['participant_id'], 
                        $user['id'], 
                        $user['type'], 
                        $convId
                    );
                    redirect("conversation.php?id=$convId");
                }
                break;
                
            case 'archive_conversation':
                archiveConversation($convId, $user['id'], $user['type']);
                redirect("index.php?folder=archives");
                break;
                
            case 'delete_conversation':
                deleteConversation($convId, $user['id'], $user['type']);
                redirect("index.php?folder=corbeille");
                break;
                
            case 'restore_conversation':
                restoreConversation($convId, $user['id'], $user['type']);
                redirect("conversation.php?id=$convId");
                break;
        }
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Inclure l'en-tête
include 'templates/header.php';
?>

<div class="content conversation-page">
    <?php if (isset($error) && !empty($error)): ?>
    <div class="alert error"><?= htmlspecialchars($error) ?></div>
    <?php elseif (isset($success) && !empty($success)): ?>
    <div class="alert success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    
    <?php if (isset($conversation)): ?>
    
    <aside class="conversation-sidebar">
        <div class="conversation-info">
            <h3>
                Participants 
                <?php if (!$isDeleted && $isModerator): ?>
                <button id="add-participant-btn" class="btn-icon"><i class="fas fa-plus-circle"></i></button>
                <?php endif; ?>
            </h3>
            <?php include 'templates/participant-list.php'; ?>
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
    
    <main class="conversation-main">
        <div class="messages-container">
            <?php foreach ($messages as $message): ?>
                <?php include 'templates/message-item.php'; ?>
            <?php endforeach; ?>
        </div>
        
        <?php if ($isDeleted): ?>
        <div class="conversation-deleted">
            <p>Cette conversation a été déplacée dans la corbeille. Vous ne pouvez plus y répondre.</p>
            <form method="post" action="" id="restoreForm">
                <input type="hidden" name="action" value="restore_conversation">
                <button type="submit" class="btn primary">Restaurer la conversation</button>
            </form>
        </div>
        <?php elseif ($canReply): ?>
        <div class="reply-box">
            <form method="post" enctype="multipart/form-data" id="messageForm">
                <input type="hidden" name="action" value="send_message">
                <input type="hidden" name="parent_message_id" id="parent-message-id" value="">
                
                <!-- Interface de réponse (cachée par défaut) -->
                <div id="reply-interface" style="display: none;">
                    <div class="reply-header">
                        <span id="reply-to" class="reply-to"></span>
                        <button type="button" class="btn-icon" onclick="cancelReply()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                
                <?php if (canSetMessageImportance($user['type'])): ?>
                <div class="reply-options">
                    <select name="importance" class="importance-select">
                        <option value="normal">Normal</option>
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
                            <i class="fas fa-paperclip"></i> Pièces jointes
                        </label>
                        <div id="file-list"></div>
                    </div>
                    
                    <button type="submit" class="btn primary">
                        <i class="fas fa-paper-plane"></i> Envoyer
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
        <span class="close" onclick="closeAddParticipantModal()">&times;</span>
        <h3>Ajouter des participants</h3>
        <form method="post" id="addParticipantForm">
            <input type="hidden" name="action" value="add_participant">
            
            <div class="form-group">
                <label for="participant_type">Type de participant</label>
                <select name="participant_type" id="participant_type" onchange="loadParticipants()" required>
                    <option value="">Sélectionner un type</option>
                    <option value="eleve">Élève</option>
                    <option value="parent">Parent</option>
                    <option value="professeur">Professeur</option>
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
            
            <button type="submit" class="btn primary">Ajouter</button>
        </form>
    </div>
</div>

<!-- Formulaires cachés pour les actions -->
<form id="archiveForm" method="post" style="display: none;">
    <input type="hidden" name="action" value="archive_conversation">
</form>

<form id="deleteForm" method="post" style="display: none;">
    <input type="hidden" name="action" value="delete_conversation">
</form>

<form id="promoteForm" method="post" style="display: none;">
    <input type="hidden" name="action" value="promote_moderator">
    <input type="hidden" name="participant_id" id="promote_participant_id" value="">
</form>

<form id="demoteForm" method="post" style="display: none;">
    <input type="hidden" name="action" value="demote_moderator">
    <input type="hidden" name="participant_id" id="demote_participant_id" value="">
</form>

<form id="removeForm" method="post" style="display: none;">
    <input type="hidden" name="action" value="remove_participant">
    <input type="hidden" name="participant_id" id="remove_participant_id" value="">
</form>

<?php
// Inclure le pied de page
include 'templates/footer.php';
?>