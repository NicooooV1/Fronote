<?php
declare(strict_types=1);
/**
 * Modèle pour la gestion des messages
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/utils.php';

/**
 * Marque une conversation comme lue pour un utilisateur
 */
function markConversationAsRead($convId, $userId, $userType) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            UPDATE conversation_participants
            SET last_read_at = NOW(),
                unread_count = 0,
                last_read_message_id = GREATEST(
                    COALESCE(last_read_message_id, 0),
                    COALESCE((SELECT MAX(m.id) FROM messages m WHERE m.conversation_id = ? AND m.deleted_at IS NULL), 0)
                )
            WHERE conversation_id = ? AND user_id = ? AND user_type = ?
        ");
        $stmt->execute([$convId, $convId, $userId, $userType]);
        return $stmt->rowCount() > 0;
    } catch (Exception $ex) {
        error_log("Erreur markConversationAsRead: " . $ex->getMessage());
        return false;
    }
}

/**
 * Charge en un seul lot les préférences de notification des participants fournis.
 * Retourne une map indexée par "user_id|user_type" → ligne user_notification_preferences.
 * Un participant sans ligne n'est pas présent dans la map (→ valeurs par défaut = notifier).
 *
 * @param array $participants Lignes contenant au moins user_id + user_type
 * @return array<string,array>
 */
function loadNotificationPreferences(array $participants): array {
    global $pdo;
    if (empty($participants)) {
        return [];
    }

    // Construire un IN (…) sur les couples (user_id, user_type).
    $conds = [];
    $params = [];
    foreach ($participants as $p) {
        $conds[] = '(user_id = ? AND user_type = ?)';
        $params[] = $p['user_id'];
        $params[] = $p['user_type'];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT user_id, user_type, browser_notifications,
                   mention_notifications, reply_notifications, important_notifications
            FROM user_notification_preferences
            WHERE " . implode(' OR ', $conds));
        $stmt->execute($params);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[$row['user_id'] . '|' . $row['user_type']] = $row;
        }
        return $map;
    } catch (\Throwable $e) {
        // Fail-open sûr : en cas d'erreur (table absente en dev…), on notifie
        // normalement plutôt que de perdre silencieusement les notifications.
        error_log('[messagerie] loadNotificationPreferences failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Décide si un participant doit recevoir une notification push/desktop pour ce
 * message, d'après ses préférences. Sans préférences enregistrées → défaut = oui.
 *
 * @param array  $prefsByUser  Map produite par loadNotificationPreferences()
 * @param array  $participant  Ligne participant (user_id + user_type)
 * @param string $notifType    Type de notification : unread|broadcast|important|reply|mention
 * @return bool
 */
function shouldNotifyParticipant(array $prefsByUser, array $participant, string $notifType): bool {
    $key = $participant['user_id'] . '|' . $participant['user_type'];
    $prefs = $prefsByUser[$key] ?? null;

    // Aucune préférence enregistrée : comportement par défaut = notifier.
    if ($prefs === null) {
        return true;
    }

    // Interrupteur maître : navigateur/desktop coupé → aucune notification.
    if ((int) $prefs['browser_notifications'] === 0) {
        return false;
    }

    // Bascules par type : respecter le drapeau spécifique quand il s'applique.
    switch ($notifType) {
        case 'reply':
            return (int) $prefs['reply_notifications'] !== 0;
        case 'important':
            return (int) $prefs['important_notifications'] !== 0;
        case 'mention':
            return (int) $prefs['mention_notifications'] !== 0;
        default:
            // 'unread' / 'broadcast' : couverts par l'interrupteur maître.
            return true;
    }
}

/**
 * Diffuse un événement de MISE À JOUR de message vers la room temps réel de la
 * conversation. Le serveur WS (server.js) rebroadcast 'message:updated' avec
 * {conversationId, messageId, kind, data} à la room 'conversation:<id>'.
 *
 * Best-effort et hors transaction : un échec réseau ne doit jamais faire échouer
 * la mutation déjà persistée. Complète WebSocket::notifyNewMessage (nouveaux
 * messages) pour les cas edit / delete / reaction / pin.
 *
 * @param int    $convId
 * @param int    $messageId
 * @param string $kind  'edit'|'delete'|'reaction'|'pin'
 * @param array  $data  Charge utile spécifique au kind (nouveau corps, état épinglé, réactions…)
 */
function dispatchMessageUpdate($convId, $messageId, string $kind, array $data = []): void {
    try {
        if (!file_exists(__DIR__ . '/../../../API/Core/WebSocket.php')) {
            return;
        }
        require_once __DIR__ . '/../../../API/Core/WebSocket.php';
        if (!method_exists('\API\Core\WebSocket', 'dispatch')) {
            return;
        }
        \API\Core\WebSocket::dispatch('/notify/message-updated', [
            'conversationId' => (int) $convId,
            'messageId'      => (int) $messageId,
            'kind'           => $kind,
            'data'           => $data,
        ]);
    } catch (\Throwable $e) {
        error_log('[messagerie] dispatchMessageUpdate failed (non-fatal): ' . $e->getMessage());
    }
}

/**
 * Dernier message lu par un participant (positionne le séparateur « nouveaux messages »).
 */
function getLastReadMessageId($convId, $userId, $userType): int {
    global $pdo;
    $stmt = $pdo->prepare("SELECT last_read_message_id FROM conversation_participants
                           WHERE conversation_id = ? AND user_id = ? AND user_type = ?");
    $stmt->execute([$convId, $userId, $userType]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * Nombre de réponses par message parent d'une conversation (badge .msg-reply-count).
 * @return array<int,int> parent_message_id => nombre de réponses
 */
function getReplyCounts($convId): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT parent_message_id, COUNT(*) AS c FROM messages
                           WHERE conversation_id = ? AND parent_message_id IS NOT NULL AND deleted_at IS NULL
                           GROUP BY parent_message_id");
    $stmt->execute([$convId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rc) { $out[(int) $rc['parent_message_id']] = (int) $rc['c']; }
    return $out;
}

/**
 * Récupère les messages d'une conversation avec pagination
 * Corrige le problème N+1 en batch-chargeant les pièces jointes
 *
 * @param int $convId
 * @param int $userId
 * @param string $userType
 * @param int $limit   Nombre de messages à charger
 * @param int $before  Charger les messages avant cet ID (0 = depuis la fin)
 * @return array ['messages' => [...], 'has_more' => bool, 'pinned' => [...]]
 */
function getMessages($convId, $userId, $userType, $limit = 50, $before = 0) {
    global $pdo;
    
    // Vérifier que l'utilisateur est participant ET que la conversation appartient à son
    // établissement (défense en profondeur du cloisonnement tenant — cohérent avec getConversationInfo).
    $checkParticipant = $pdo->prepare("
        SELECT cp.id FROM conversation_participants cp
        JOIN conversations c ON c.id = cp.conversation_id
        WHERE cp.conversation_id = ? AND cp.user_id = ? AND cp.user_type = ? AND cp.is_deleted = 0
          AND c.etablissement_id = ?
    ");
    $checkParticipant->execute([$convId, $userId, $userType, \API\Core\EstablishmentContext::id()]);
    if (!$checkParticipant->fetch()) {
        throw new \RuntimeException("Vous n'êtes pas autorisé à accéder à cette conversation");
    }
    
    $nameCase = getUserNameCaseSQL('m.sender_id', 'm.sender_type');
    
    // Construire la requête avec pagination "avant ID"
    $whereClause = "m.conversation_id = ? AND (m.deleted_at IS NULL)";
    $params = [$userId, $userType, $userId, $userType];
    
    if ($before > 0) {
        $whereClause .= " AND m.id < ?";
        $params[] = $before;
    }
    
    $params = array_merge([$convId], $params);
    
    // Charger limit+1 pour savoir s'il y a encore des messages avant
    $stmt = $pdo->prepare("
        SELECT m.id, m.conversation_id, m.sender_id, m.sender_type, m.body, 
               m.original_body, m.status, m.parent_message_id,
               m.created_at, m.updated_at, m.edited_at, m.deleted_at,
               m.is_pinned, m.pinned_at, m.pinned_by_id, m.pinned_by_type,
               CASE
                   WHEN cp.last_read_message_id IS NULL OR m.id > cp.last_read_message_id THEN 0
                   ELSE 1
               END as est_lu,
               CASE 
                   WHEN m.sender_id = ? AND m.sender_type = ? THEN 1
                   ELSE 0
               END as is_self,
               {$nameCase} as expediteur_nom,
               UNIX_TIMESTAMP(m.created_at) as timestamp
        FROM messages m
        LEFT JOIN conversation_participants cp ON (
            m.conversation_id = cp.conversation_id AND cp.user_id = ? AND cp.user_type = ?
        )
        WHERE {$whereClause}
        ORDER BY m.created_at DESC
        LIMIT ?
    ");
    $allParams = [$userId, $userType, $userId, $userType, $convId];
    if ($before > 0) {
        $allParams[] = $before;
    }
    $allParams[] = $limit + 1;
    
    $stmt->execute($allParams);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $hasMore = count($messages) > $limit;
    if ($hasMore) {
        array_pop($messages); // Retirer le message supplémentaire
    }
    
    // Inverser pour obtenir l'ordre chronologique
    $messages = array_reverse($messages);
    
    // ── FIX N+1 : batch-charger les pièces jointes ──
    if (!empty($messages)) {
        $msgIds = array_column($messages, 'id');
        $placeholders = implode(',', array_fill(0, count($msgIds), '?'));
        
        $attachStmt = $pdo->prepare("
            SELECT id, message_id, file_name as nom_fichier, file_path as chemin
            FROM message_attachments WHERE message_id IN ({$placeholders})
        ");
        $attachStmt->execute($msgIds);
        $allAttachments = $attachStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $attachByMsg = [];
        foreach ($allAttachments as $a) {
            $attachByMsg[$a['message_id']][] = $a;
        }
        
        // Batch-charger les réactions.
        // Le drapeau user_reacted est calculé par agrégat sur l'égalité exacte du
        // couple (user_id, user_type) : on évite ainsi les faux positifs de sous-chaîne
        // (ex. id « 12 » matchant « 123 ») et la troncature silencieuse de GROUP_CONCAT
        // au-delà de group_concat_max_len que produisait l'ancien parsing de chaîne.
        $reactStmt = $pdo->prepare("
            SELECT message_id, reaction, COUNT(*) as count,
                   MAX(CASE WHEN user_id = ? AND user_type = ? THEN 1 ELSE 0 END) as user_reacted
            FROM message_reactions
            WHERE message_id IN ({$placeholders})
            GROUP BY message_id, reaction
        ");
        $reactStmt->execute(array_merge([$userId, $userType], $msgIds));
        $allReactions = $reactStmt->fetchAll(PDO::FETCH_ASSOC);

        $reactionsByMsg = [];
        foreach ($allReactions as $r) {
            $reactionsByMsg[$r['message_id']][] = [
                'emoji'        => $r['reaction'],
                'count'        => (int) $r['count'],
                'user_reacted' => (bool) $r['user_reacted'],
            ];
        }
        
        // Batch-charger les messages parents pour les réponses
        $parentIds = array_filter(array_unique(array_column($messages, 'parent_message_id')));
        $parentMessages = [];
        if (!empty($parentIds)) {
            $pPlaceholders = implode(',', array_fill(0, count($parentIds), '?'));
            $parentNameCase = getUserNameCaseSQL('pm.sender_id', 'pm.sender_type');
            $pStmt = $pdo->prepare("
                SELECT pm.id, pm.body, pm.sender_id, pm.sender_type,
                       {$parentNameCase} as expediteur_nom
                FROM messages pm WHERE pm.id IN ({$pPlaceholders})
            ");
            $pStmt->execute(array_values($parentIds));
            foreach ($pStmt->fetchAll(PDO::FETCH_ASSOC) as $pm) {
                $parentMessages[$pm['id']] = $pm;
            }
        }
        
        foreach ($messages as &$message) {
            $message['pieces_jointes'] = $attachByMsg[$message['id']] ?? [];
            $message['reactions'] = $reactionsByMsg[$message['id']] ?? [];
            $message['parent_message'] = $parentMessages[$message['parent_message_id']] ?? null;
        }
        unset($message);
    }
    
    // Récupérer les messages épinglés séparément
    $pinnedStmt = $pdo->prepare("
        SELECT m.id, m.body, m.sender_id, m.sender_type, m.pinned_at,
               " . getUserNameCaseSQL('m.sender_id', 'm.sender_type') . " as expediteur_nom,
               UNIX_TIMESTAMP(m.created_at) as timestamp
        FROM messages m
        WHERE m.conversation_id = ? AND m.is_pinned = 1 AND m.deleted_at IS NULL
        ORDER BY m.pinned_at DESC
    ");
    $pinnedStmt->execute([$convId]);
    $pinnedMessages = $pinnedStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // NB : le marquage « lu » n'est PLUS un effet de bord de la lecture. Lire des
    // messages (page, pagination via action=list, polling) ne doit pas modifier
    // l'état. Le marquage est explicite : à l'ouverture de la conversation
    // (conversation.php) et au fil de la lecture via l'IntersectionObserver
    // (action mark_read). Cf. audit M5.
    return [
        'messages' => $messages,
        'has_more' => $hasMore,
        'pinned' => $pinnedMessages
    ];
}

/**
 * Récupère les messages même pour les conversations supprimées (corbeille)
 */
function getMessagesEvenIfDeleted($convId, $userId, $userType, $limit = 50, $before = 0) {
    global $pdo;
    
    // Vérifier que l'utilisateur est participant (même supprimé)
    $checkParticipant = $pdo->prepare("
        SELECT id FROM conversation_participants 
        WHERE conversation_id = ? AND user_id = ? AND user_type = ?
    ");
    $checkParticipant->execute([$convId, $userId, $userType]);
    if (!$checkParticipant->fetch()) {
        throw new \RuntimeException("Vous n'êtes pas autorisé à accéder à cette conversation");
    }

    $nameCase = getUserNameCaseSQL('m.sender_id', 'm.sender_type');

    $whereClause = "m.conversation_id = ?";
    $allParams = [$userId, $userType, $userId, $userType, $convId];
    
    if ($before > 0) {
        $whereClause .= " AND m.id < ?";
        $allParams[] = $before;
    }
    $allParams[] = $limit + 1;
    
    $stmt = $pdo->prepare("
        SELECT m.id, m.conversation_id, m.sender_id, m.sender_type, m.body, 
               m.original_body, m.status, m.parent_message_id,
               m.created_at, m.updated_at, m.edited_at, m.deleted_at,
               m.is_pinned, m.pinned_at,
               CASE WHEN cp.last_read_message_id IS NULL OR m.id > cp.last_read_message_id THEN 0 ELSE 1 END as est_lu,
               CASE WHEN m.sender_id = ? AND m.sender_type = ? THEN 1 ELSE 0 END as is_self,
               {$nameCase} as expediteur_nom,
               UNIX_TIMESTAMP(m.created_at) as timestamp
        FROM messages m
        LEFT JOIN conversation_participants cp ON (m.conversation_id = cp.conversation_id AND cp.user_id = ? AND cp.user_type = ?)
        WHERE {$whereClause}
        ORDER BY m.created_at DESC LIMIT ?
    ");
    $stmt->execute($allParams);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $hasMore = count($messages) > $limit;
    if ($hasMore) array_pop($messages);
    $messages = array_reverse($messages);
    
    // Batch pièces jointes
    if (!empty($messages)) {
        $msgIds = array_column($messages, 'id');
        $ph = implode(',', array_fill(0, count($msgIds), '?'));
        $aStmt = $pdo->prepare("SELECT id, message_id, file_name as nom_fichier, file_path as chemin FROM message_attachments WHERE message_id IN ($ph)");
        $aStmt->execute($msgIds);
        $byMsg = [];
        foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $a) $byMsg[$a['message_id']][] = $a;
        foreach ($messages as &$msg) $msg['pieces_jointes'] = $byMsg[$msg['id']] ?? [];
        unset($msg);
    }
    
    return ['messages' => $messages, 'has_more' => $hasMore, 'pinned' => []];
}

/**
 * Récupère un message par son ID
 * @param int $messageId
 * @return array|false
 */
function getMessageById($messageId) {
    global $pdo;
    
    $sql = "
        SELECT m.*,
               0 as is_self,
               CASE
                   WHEN m.sender_type = 'eleve' THEN 
                       (SELECT CONCAT(e.prenom, ' ', e.nom) FROM eleves e WHERE e.id = m.sender_id)
                   WHEN m.sender_type = 'parent' THEN 
                       (SELECT CONCAT(p.prenom, ' ', p.nom) FROM parents p WHERE p.id = m.sender_id)
                   WHEN m.sender_type = 'professeur' THEN 
                       (SELECT CONCAT(p.prenom, ' ', p.nom) FROM professeurs p WHERE p.id = m.sender_id)
                   WHEN m.sender_type = 'vie_scolaire' THEN 
                       (SELECT CONCAT(v.prenom, ' ', v.nom) FROM vie_scolaire v WHERE v.id = m.sender_id)
                   WHEN m.sender_type = 'administrateur' THEN 
                       (SELECT CONCAT(a.prenom, ' ', a.nom) FROM administrateurs a WHERE a.id = m.sender_id)
                   ELSE 'Inconnu'
               END as expediteur_nom,
               m.sender_id as expediteur_id, 
               m.sender_type as expediteur_type,
               m.body as contenu,
               m.status as status,
               m.created_at as date_envoi,
               UNIX_TIMESTAMP(m.created_at) as timestamp
        FROM messages m
        WHERE m.id = ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$messageId]);
    $message = $stmt->fetch();
    
    if ($message) {
        $attachmentStmt = $pdo->prepare("
            SELECT id, message_id, file_name as nom_fichier, file_path as chemin
            FROM message_attachments 
            WHERE message_id = ?
        ");
        $attachmentStmt->execute([$messageId]);
        $message['pieces_jointes'] = $attachmentStmt->fetchAll();
        
        // Récupérer les informations de lecture pour ce message
        $readInfoStmt = $pdo->prepare("
            SELECT COUNT(*) as total_participants,
                   SUM(CASE WHEN cp.last_read_message_id >= ? THEN 1 ELSE 0 END) as read_count
            FROM conversation_participants cp
            WHERE cp.conversation_id = ? AND cp.is_deleted = 0
        ");
        $readInfoStmt->execute([$messageId, $message['conversation_id']]);
        $readInfo = $readInfoStmt->fetch();
        
        $message['read_status'] = [
            'message_id' => $message['id'],
            'total_participants' => (int)$readInfo['total_participants'],
            'read_by_count' => (int)$readInfo['read_count'],
            'all_read' => (int)$readInfo['read_count'] === (int)$readInfo['total_participants'],
            'percentage' => $readInfo['total_participants'] > 0 ? 
                          round(($readInfo['read_count'] / $readInfo['total_participants']) * 100) : 0
        ];
    }
    
    return $message;
}

/**
 * Ajoute un nouveau message
 * @param int $convId
 * @param int $senderId
 * @param string $senderType
 * @param string $content
 * @param string $importance
 * @param bool $estAnnonce
 * @param bool $notificationObligatoire
 * @param int|null $parentMessageId
 * @param string $typeMessage
 * @param array $filesData
 * @return int
 */
function addMessage($convId, $senderId, $senderType, $content, $importance = 'normal', 
                   $estAnnonce = false, $notificationObligatoire = false,
                   $parentMessageId = null, $typeMessage = 'standard', $filesData = []) {
    global $pdo;
    
    // Vérifier que l'expéditeur est participant à la conversation
    $checkParticipant = $pdo->prepare("
        SELECT id FROM conversation_participants 
        WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
    ");
    $checkParticipant->execute([$convId, $senderId, $senderType]);
    if (!$checkParticipant->fetch()) {
        throw new \RuntimeException("Vous n'êtes pas autorisé à envoyer des messages dans cette conversation");
    }

    // Annonce en lecture seule : appliquer la règle côté serveur (et non plus
    // uniquement dans la vue). Pour une conversation de type 'annonce', seuls les
    // profils habilités peuvent répondre — vie scolaire / administrateurs toujours,
    // les autres uniquement si l'annonce autorise explicitement les réponses
    // (allow_replies). Logique identique à canReplyToAnnouncement() utilisée à
    // l'affichage. NB : la création de l'annonce elle-même passe par un auteur
    // vie_scolaire/administrateur, donc le premier message n'est jamais bloqué.
    $convTypeStmt = $pdo->prepare("SELECT type FROM conversations WHERE id = ?");
    $convTypeStmt->execute([$convId]);
    $convType = $convTypeStmt->fetchColumn();
    if ($convType === 'annonce' && !canReplyToAnnouncement($senderId, $senderType, $convId, 'annonce')) {
        throw new \RuntimeException("Cette annonce est en lecture seule : vous n'êtes pas autorisé à y répondre");
    }

    // Vérification de la longueur maximale
    $maxLength = 10000;
    if (mb_strlen($content) > $maxLength) {
        throw new \InvalidArgumentException("Votre message est trop long (maximum $maxLength caractères)");
    }
    
    // Transaction nesting-aware : addMessage est appelé soit seul, soit depuis un
    // handler qui a déjà ouvert une transaction (handleSendMessage, handleSendAnnouncement,
    // sendMessageToClass…). PDO ne supporte pas les transactions imbriquées : appeler
    // beginTransaction() deux fois lève "There is already an active transaction".
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        // Déterminer le statut du message
        $validStatuses = ['normal', 'important', 'urgent', 'annonce'];
        $status = $estAnnonce ? 'annonce' : (in_array($importance, $validStatuses) ? $importance : 'normal');

        // Insérer le message
        $sql = "INSERT INTO messages (conversation_id, sender_id, sender_type, body, original_body, parent_message_id, created_at, updated_at, status)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$convId, $senderId, $senderType, $content, $content, $parentMessageId, $status]);
        $messageId = $pdo->lastInsertId();
        
        // Mettre à jour la date du dernier message et last_message_id
        $upd = $pdo->prepare("UPDATE conversations SET updated_at = NOW(), last_message_id = ? WHERE id = ?");
        $upd->execute([$messageId, $convId]);
        
        // Récupérer les participants (avec muted_until pour filtrer les notifications
        // push/desktop des participants en sourdine — ils reçoivent tout de même le message).
        $participantsStmt = $pdo->prepare("
            SELECT user_id, user_type, muted_until FROM conversation_participants
            WHERE conversation_id = ? AND is_deleted = 0
        ");
        $participantsStmt->execute([$convId]);
        $participants = $participantsStmt->fetchAll();
        
        // Déterminer le type de notification
        $notificationType = 'unread';
        if ($estAnnonce) {
            $notificationType = 'broadcast';
        } elseif ($importance === 'important' || $importance === 'urgent') {
            $notificationType = 'important';
        } elseif ($parentMessageId) {
            $notificationType = 'reply';
        }
        
        // Créer des notifications pour chaque participant
        $addNotification = $pdo->prepare("
            INSERT INTO message_notifications (user_id, user_type, message_id, notification_type, is_read, read_at) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        // Mettre à jour le last_read_message_id pour l'expéditeur
        $updateReadId = $pdo->prepare("
            UPDATE conversation_participants
            SET last_read_message_id = ?, version = version + 1
            WHERE conversation_id = ? AND user_id = ? AND user_type = ?
        ");
        $updateReadId->execute([$messageId, $convId, $senderId, $senderType]);

        // Incrémenter le compteur non lu de TOUS les participants (sauf l'expéditeur)
        // en UNE SEULE instruction. Cela verrouille les lignes dans un ordre déterministe
        // (parcours d'index unique), ce qui réduit fortement les deadlocks InnoDB (1213)
        // par rapport à une boucle d'UPDATE par participant à l'ordre non garanti.
        $incrementUnread = $pdo->prepare("
            UPDATE conversation_participants
            SET unread_count = unread_count + 1
            WHERE conversation_id = ? AND is_deleted = 0
              AND NOT (user_id = ? AND user_type = ?)
        ");
        $incrementUnread->execute([$convId, $senderId, $senderType]);

        foreach ($participants as $p) {
            // L'expéditeur n'a ni notification ni incrément (son last_read est déjà à jour).
            if ($p['user_id'] == $senderId && $p['user_type'] == $senderType) {
                continue;
            }

            // Pour les autres participants, créer une notification
            $isRead = 0;
            $readAt = null;

            // Créer la notification
            $addNotification->execute([
                $p['user_id'],
                $p['user_type'],
                $messageId,
                $notificationType,
                $isRead,
                $readAt
            ]);
        }
        
        // Traiter les pièces jointes via FileUploadService centralisé
        if (!empty($filesData) && isset($filesData['name']) && is_array($filesData['name']) && !empty($filesData['name'][0])) {
            $fileUploader = new \API\Services\FileUploadService('messagerie');
            $uploadResults = $fileUploader->uploadMultiple($filesData);
            $saveStmt = $pdo->prepare("INSERT INTO message_attachments (message_id, file_name, file_path, uploaded_at) VALUES (?, ?, ?, NOW())");
            foreach ($uploadResults as $r) {
                if ($r['success']) {
                    $saveStmt->execute([$messageId, $r['nom_original'], $r['chemin']]);
                }
            }
        }
        
        if ($ownTransaction) {
            $pdo->commit();
        }

    } catch (Exception $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // Push WebSocket hors transaction : un échec ici ne doit pas annuler le message déjà enregistré
    try {
        if (file_exists(__DIR__ . '/../../../API/Core/WebSocket.php')) {
            require_once __DIR__ . '/../../../API/Core/WebSocket.php';
            $messageData = getMessageById($messageId);

            // Diffusion temps réel dans la room de la conversation ('conversation:<id>').
            // C'est la LIVRAISON du message : elle a lieu pour TOUS les participants,
            // y compris ceux en sourdine (le mute ne coupe QUE la notification push/desktop).
            if ($messageData && method_exists('\API\Core\WebSocket', 'notifyNewMessage')) {
                \API\Core\WebSocket::notifyNewMessage($convId, $messageData);
            }

            // Précharger les préférences de notification de tous les participants en un lot.
            $prefsByUser = [];
            if (!empty($participants)) {
                $prefsByUser = loadNotificationPreferences($participants);
            }

            if (!empty($participants) && method_exists('\API\Core\WebSocket', 'notifyUser')) {
                foreach ($participants as $p) {
                    if ($p['user_id'] == $senderId && $p['user_type'] == $senderType) continue;

                    // MUTE : le participant a coupé les notifications de cette conversation
                    // (muted_until > maintenant). On lui livre le message (déjà diffusé
                    // ci-dessus) mais SANS notification push/desktop.
                    if (!empty($p['muted_until']) && strtotime($p['muted_until']) > time()) {
                        continue;
                    }

                    // PRÉFÉRENCES : respecter user_notification_preferences. Le maître
                    // browser_notifications=0 coupe tout ; les bascules par type coupent
                    // les réponses / messages importants selon $notificationType.
                    if (!shouldNotifyParticipant($prefsByUser, $p, $notificationType)) {
                        continue;
                    }

                    \API\Core\WebSocket::notifyUser($p['user_id'], [
                        'type'       => 'message',
                        'convId'     => $convId,
                        'messageId'  => $messageId,
                        'senderName' => $messageData['expediteur_nom'] ?? 'Inconnu',
                        'preview'    => mb_substr($content, 0, 100),
                    ], $p['user_type'] ?? null);
                }
            }
        }
    } catch (\Exception $wsEx) {
        error_log("WebSocket push failed (non-fatal): " . $wsEx->getMessage());
    }

    return $messageId;
}

/**
 * Marque un message comme lu
 *
 * @param int $messageId ID du message
 * @param int $userId ID de l'utilisateur
 * @param string $userType Type d'utilisateur
 * @param int $maxRetries Nombre maximum de tentatives en cas d'échec
 * @return bool Succès de l'opération
 */
function markMessageAsRead($messageId, $userId, $userType, $maxRetries = 3) {
    global $pdo;
    
    $retriesLeft = $maxRetries;
    
    while ($retriesLeft > 0) {
        try {
            // Récupérer l'ID de la conversation
            $stmt = $pdo->prepare("SELECT conversation_id FROM messages WHERE id = ?");
            $stmt->execute([$messageId]);
            $convId = $stmt->fetchColumn();
            
            if (!$convId) {
                return false;
            }
            
            // Commencer une transaction
            $pdo->beginTransaction();
            
            // Vérifier le dernier message lu
            $stmt = $pdo->prepare("
                SELECT last_read_message_id 
                FROM conversation_participants 
                WHERE conversation_id = ? AND user_id = ? AND user_type = ?
                FOR UPDATE
            ");
            $stmt->execute([$convId, $userId, $userType]);
            $currentLastReadId = $stmt->fetchColumn();
            
            if ($currentLastReadId === null || $messageId > $currentLastReadId) {
                // Mettre à jour le dernier message lu
                $updateStmt = $pdo->prepare("
                    UPDATE conversation_participants 
                    SET last_read_message_id = ?, last_read_at = NOW()
                    WHERE conversation_id = ? AND user_id = ? AND user_type = ?
                ");
                $updateStmt->execute([$messageId, $convId, $userId, $userType]);
                
                // Marquer la notification correspondante comme lue
                $updateNotif = $pdo->prepare("
                    UPDATE message_notifications
                    SET is_read = 1, read_at = NOW()
                    WHERE message_id = ? AND user_id = ? AND user_type = ?
                ");
                $updateNotif->execute([$messageId, $userId, $userType]);
                
                // Recalculer précisément le compteur unread_count.
                // NB : MySQL interdit (erreur 1093) une UPDATE dont la sous-requête lit
                // la même table conversation_participants. On calcule donc d'abord la
                // valeur dans un SELECT séparé, puis on l'affecte via une valeur liée.
                // last_read_message_id vient d'être positionné à $messageId ci-dessus,
                // donc les non-lus sont les messages d'id > $messageId non émis par soi.
                $countStmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM messages m
                    WHERE m.conversation_id = ?
                    AND m.id > ?
                    AND NOT (m.sender_id = ? AND m.sender_type = ?)
                ");
                $countStmt->execute([$convId, $messageId, $userId, $userType]);
                $newUnread = (int) $countStmt->fetchColumn();

                $updateCount = $pdo->prepare("
                    UPDATE conversation_participants
                    SET unread_count = ?
                    WHERE conversation_id = ? AND user_id = ? AND user_type = ?
                ");
                $updateCount->execute([$newUnread, $convId, $userId, $userType]);
                
                $pdo->commit();
                return true;
            } else {
                // Conflit détecté, la version a changé entre-temps
                $pdo->rollBack();
                $retriesLeft--;
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $retriesLeft--;
            
            if ($retriesLeft === 0) {
                // Journaliser l'erreur après toutes les tentatives
                error_log("Erreur lors du marquage du message comme lu: " . $e->getMessage());
                return false;
            }
            
            // Attendre avant la prochaine tentative
            usleep(100000); // 100 ms
        }
    }
    
    return false;
}

/**
 * Récupère le statut de lecture d'un message avec des informations détaillées
 * @param int $messageId
 * @return array
 */
function getMessageReadStatus($messageId) {
    global $pdo;
    
    // Récupérer l'ID de la conversation pour ce message
    $stmt = $pdo->prepare("SELECT conversation_id FROM messages WHERE id = ?");
    $stmt->execute([$messageId]);
    $result = $stmt->fetch();
    
    if (!$result) {
        return [
            'message_id' => $messageId,
            'total_participants' => 0,
            'read_by_count' => 0,
            'all_read' => false,
            'percentage' => 0,
            'readers' => []
        ];
    }
    
    $convId = $result['conversation_id'];
    
    // Récupérer le nombre total de participants et le nombre de participants qui ont lu
    $readInfoStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_participants,
            SUM(CASE WHEN cp.last_read_message_id >= ? THEN 1 ELSE 0 END) as read_count
        FROM conversation_participants cp
        WHERE cp.conversation_id = ? AND cp.is_deleted = 0
        AND NOT (cp.user_id = (SELECT sender_id FROM messages WHERE id = ?)
             AND cp.user_type = (SELECT sender_type FROM messages WHERE id = ?))
    ");
    $readInfoStmt->execute([$messageId, $convId, $messageId, $messageId]);
    $readInfo = $readInfoStmt->fetch();
    
    // Récupérer les participants qui ont lu
    $readersStmt = $pdo->prepare("
        SELECT cp.user_id, cp.user_type,
               CASE 
                   WHEN cp.user_type = 'eleve' THEN 
                       (SELECT CONCAT(e.prenom, ' ', e.nom) FROM eleves e WHERE e.id = cp.user_id)
                   WHEN cp.user_type = 'parent' THEN 
                       (SELECT CONCAT(p.prenom, ' ', p.nom) FROM parents p WHERE p.id = cp.user_id)
                   WHEN cp.user_type = 'professeur' THEN 
                       (SELECT CONCAT(p.prenom, ' ', p.nom) FROM professeurs p WHERE p.id = cp.user_id)
                   WHEN cp.user_type = 'vie_scolaire' THEN 
                       (SELECT CONCAT(v.prenom, ' ', v.nom) FROM vie_scolaire v WHERE v.id = cp.user_id)
                   WHEN cp.user_type = 'administrateur' THEN 
                       (SELECT CONCAT(a.prenom, ' ', a.nom) FROM administrateurs a WHERE a.id = cp.user_id)
                   ELSE 'Inconnu'
               END as nom_complet
        FROM conversation_participants cp
        WHERE cp.conversation_id = ? AND cp.last_read_message_id >= ? AND cp.is_deleted = 0
        AND NOT (cp.user_id = (SELECT sender_id FROM messages WHERE id = ?)
             AND cp.user_type = (SELECT sender_type FROM messages WHERE id = ?))
    ");
    $readersStmt->execute([$convId, $messageId, $messageId, $messageId]);
    $readers = $readersStmt->fetchAll();
    
    return [
        'message_id' => $messageId,
        'total_participants' => (int)$readInfo['total_participants'],
        'read_by_count' => (int)$readInfo['read_count'],
        'all_read' => (int)$readInfo['read_count'] === (int)$readInfo['total_participants'] && (int)$readInfo['total_participants'] > 0,
        'percentage' => $readInfo['total_participants'] > 0 ? 
                      round(($readInfo['read_count'] / $readInfo['total_participants']) * 100) : 0,
        'readers' => $readers
    ];
}

/**
 * Marque un message comme non lu
 * @param int $messageId
 * @param int $userId
 * @param string $userType
 * @return bool
 */
function markMessageAsUnread($messageId, $userId, $userType) {
    global $pdo;
    
    // Récupérer l'ID de la conversation pour ce message
    $stmt = $pdo->prepare("SELECT conversation_id FROM messages WHERE id = ?");
    $stmt->execute([$messageId]);
    $result = $stmt->fetch();
    
    if (!$result) {
        return false;
    }
    
    $convId = $result['conversation_id'];
    
    $pdo->beginTransaction();
    try {
        // Récupérer tous les messages de la conversation triés par ID
        $messagesStmt = $pdo->prepare("
            SELECT id FROM messages
            WHERE conversation_id = ?
            ORDER BY id ASC
        ");
        $messagesStmt->execute([$convId]);
        $messages = $messagesStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Trouver le message précédent
        $prevMessageId = null;
        foreach ($messages as $mId) {
            if ((int)$mId === (int)$messageId) {
                break;
            }
            $prevMessageId = $mId;
        }
        
        // Mettre à jour le last_read_message_id avec le message précédent
        $version = time(); // Utiliser le timestamp comme nouvelle version
        $updateStmt = $pdo->prepare("
            UPDATE conversation_participants 
            SET last_read_message_id = ?, version = ?
            WHERE conversation_id = ? AND user_id = ? AND user_type = ?
        ");
        $updateStmt->execute([$prevMessageId, $version, $convId, $userId, $userType]);
        
        // Vérifier si la notification existe
        $checkStmt = $pdo->prepare("
            SELECT id, is_read FROM message_notifications 
            WHERE message_id = ? AND user_id = ? AND user_type = ?
        ");
        $checkStmt->execute([$messageId, $userId, $userType]);
        $notification = $checkStmt->fetch();
        
        if ($notification) {
            // Si la notification existe et est déjà lue, la marquer comme non lue
            if ($notification['is_read']) {
                // Marquer comme non lu et réinitialiser la date de lecture
                $updNotif = $pdo->prepare("
                    UPDATE message_notifications 
                    SET is_read = 0, read_at = NULL 
                    WHERE id = ?
                ");
                $updNotif->execute([$notification['id']]);
                
                // Recalculer le compteur de messages non lus
                $recalcUnread = $pdo->prepare("
                    UPDATE conversation_participants cp
                    SET unread_count = (
                        SELECT COUNT(*) 
                        FROM messages m
                        LEFT JOIN message_notifications mn ON m.id = mn.message_id AND mn.user_id = ? AND mn.user_type = ?
                        WHERE m.conversation_id = ?
                        AND (mn.id IS NULL OR mn.is_read = 0)
                        AND NOT (m.sender_id = ? AND m.sender_type = ?)
                    )
                    WHERE cp.conversation_id = ? AND cp.user_id = ? AND cp.user_type = ?
                ");
                $recalcUnread->execute([
                    $userId, $userType, $convId, $userId, $userType, $convId, $userId, $userType
                ]);
            }
        } else {
            // Si la notification n'existe pas, on la crée comme non lue
            $createNotif = $pdo->prepare("
                INSERT INTO message_notifications 
                (user_id, user_type, message_id, notification_type, is_read, read_at) 
                VALUES (?, ?, ?, 'unread', 0, NULL)
            ");
            $createNotif->execute([$userId, $userType, $messageId]);
            
            // Incrémenter le compteur de messages non lus
            $updCount = $pdo->prepare("
                UPDATE conversation_participants 
                SET unread_count = unread_count + 1 
                WHERE conversation_id = ? AND user_id = ? AND user_type = ?
            ");
            $updCount->execute([$convId, $userId, $userType]);
        }
        
        $pdo->commit();
        return true;
    } catch (Exception $ex) {
        $pdo->rollBack();
        error_log("markMessageAsUnread error: " . $ex->getMessage());
        return false;
    }
}

/**
 * Suppression soft d'un message (avec affichage "Ce message a été supprimé")
 */
function deleteMessage($messageId, $userId, $userType) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT conversation_id, sender_id, sender_type FROM messages WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$messageId]);
    $message = $stmt->fetch();
    
    if (!$message) return false;
    
    // Vérifier : auteur ou modérateur
    $isAuthor = ($message['sender_id'] == $userId && $message['sender_type'] == $userType);
    if (!$isAuthor) {
        $modStmt = $pdo->prepare("
            SELECT id FROM conversation_participants
            WHERE conversation_id = ? AND user_id = ? AND user_type = ? 
            AND (is_moderator = 1 OR is_admin = 1) AND is_deleted = 0
        ");
        $modStmt->execute([$message['conversation_id'], $userId, $userType]);
        if (!$modStmt->fetch()) return false;
    }
    
    $del = $pdo->prepare("
        UPDATE messages SET deleted_at = NOW(), deleted_by_id = ?, deleted_by_type = ?,
                            body = '[Message supprimé]'
        WHERE id = ?
    ");
    $del->execute([$userId, $userType, $messageId]);

    // Temps réel : notifier la room de la suppression (kind='delete').
    if ($del->rowCount() > 0) {
        dispatchMessageUpdate($message['conversation_id'], $messageId, 'delete', []);
    }

    return $del->rowCount() > 0;
}

/**
 * Édite un message (autorisé pendant 5 minutes après envoi)
 */
function editMessage($messageId, $userId, $userType, $newBody) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT id, conversation_id, sender_id, sender_type, body, created_at
        FROM messages
        WHERE id = ? AND deleted_at IS NULL
    ");
    $stmt->execute([$messageId]);
    $message = $stmt->fetch();

    if (!$message) {
        throw new \OutOfBoundsException("Message introuvable");
    }

    // Seul l'auteur peut éditer
    if ($message['sender_id'] != $userId || $message['sender_type'] != $userType) {
        throw new \RuntimeException("Vous ne pouvez modifier que vos propres messages");
    }

    // Vérifier le délai de 5 minutes avec l'HORLOGE DE LA BASE : created_at est écrit
    // par NOW() côté MySQL ; comparer à time() côté PHP fait échouer TOUTE édition dès
    // qu'il existe un décalage de fuseau PHP/MySQL. On évalue donc la fenêtre en SQL.
    $windowStmt = $pdo->prepare("
        SELECT (created_at >= (NOW() - INTERVAL 5 MINUTE)) AS within_window
        FROM messages WHERE id = ?
    ");
    $windowStmt->execute([$messageId]);
    if (!(int) $windowStmt->fetchColumn()) {
        throw new \RuntimeException("Le délai de modification de 5 minutes est dépassé.");
    }

    $newBody = trim($newBody);
    if (empty($newBody) || mb_strlen($newBody) > 10000) {
        throw new \InvalidArgumentException("Le contenu du message est invalide");
    }
    
    $upd = $pdo->prepare("
        UPDATE messages 
        SET body = ?, original_body = COALESCE(original_body, ?), edited_at = NOW(), updated_at = NOW()
        WHERE id = ?
    ");
    $upd->execute([$newBody, $message['body'], $messageId]);

    // Temps réel : notifier la room de l'édition (kind='edit') avec le nouveau corps.
    dispatchMessageUpdate($message['conversation_id'], $messageId, 'edit', ['body' => $newBody]);

    return $upd->rowCount() > 0;
}

/**
 * Épingle ou désépingle un message (modérateurs/admins uniquement)
 */
function togglePinMessage($messageId, $userId, $userType) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT conversation_id, is_pinned FROM messages WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$messageId]);
    $message = $stmt->fetch();

    if (!$message) {
        throw new \OutOfBoundsException("Message introuvable");
    }

    // Vérifier que l'utilisateur est modérateur/admin
    $modStmt = $pdo->prepare("
        SELECT id FROM conversation_participants
        WHERE conversation_id = ? AND user_id = ? AND user_type = ?
        AND (is_moderator = 1 OR is_admin = 1) AND is_deleted = 0
    ");
    $modStmt->execute([$message['conversation_id'], $userId, $userType]);
    if (!$modStmt->fetch()) {
        throw new \RuntimeException("Seuls les modérateurs peuvent épingler des messages");
    }
    
    $newState = $message['is_pinned'] ? 0 : 1;
    
    $upd = $pdo->prepare("
        UPDATE messages 
        SET is_pinned = ?, 
            pinned_at = IF(? = 1, NOW(), NULL),
            pinned_by_id = IF(? = 1, ?, NULL),
            pinned_by_type = IF(? = 1, ?, NULL)
        WHERE id = ?
    ");
    $upd->execute([$newState, $newState, $newState, $userId, $newState, $userType, $messageId]);

    // Temps réel : notifier la room de l'épinglage/désépinglage (kind='pin').
    dispatchMessageUpdate($message['conversation_id'], $messageId, 'pin', ['pinned' => (bool) $newState]);

    return ['pinned' => (bool) $newState];
}

/**
 * Ajoute ou retire une réaction à un message
 */
function toggleReaction($messageId, $userId, $userType, $reaction) {
    global $pdo;
    
    // Vérifier que le message existe et que l'utilisateur est participant
    $stmt = $pdo->prepare("
        SELECT m.conversation_id FROM messages m
        JOIN conversation_participants cp ON m.conversation_id = cp.conversation_id
        WHERE m.id = ? AND cp.user_id = ? AND cp.user_type = ? AND cp.is_deleted = 0 AND m.deleted_at IS NULL
    ");
    $stmt->execute([$messageId, $userId, $userType]);
    $convRow = $stmt->fetch();
    if (!$convRow) {
        throw new \RuntimeException("Message introuvable ou accès refusé");
    }
    $convId = $convRow['conversation_id'];
    
    // Vérifier si la réaction existe déjà
    $existing = $pdo->prepare("
        SELECT id FROM message_reactions 
        WHERE message_id = ? AND user_id = ? AND user_type = ? AND reaction = ?
    ");
    $existing->execute([$messageId, $userId, $userType, $reaction]);
    
    if ($existing->fetch()) {
        // Retirer la réaction
        $del = $pdo->prepare("
            DELETE FROM message_reactions 
            WHERE message_id = ? AND user_id = ? AND user_type = ? AND reaction = ?
        ");
        $del->execute([$messageId, $userId, $userType, $reaction]);
        $action = 'removed';
    } else {
        // Ajouter la réaction
        $ins = $pdo->prepare("
            INSERT INTO message_reactions (message_id, user_id, user_type, reaction) VALUES (?, ?, ?, ?)
        ");
        $ins->execute([$messageId, $userId, $userType, $reaction]);
        $action = 'added';
    }
    
    // Retourner le nouveau comptage
    $countStmt = $pdo->prepare("
        SELECT reaction as emoji, COUNT(*) as count FROM message_reactions WHERE message_id = ? GROUP BY reaction
    ");
    $countStmt->execute([$messageId]);
    $reactions = $countStmt->fetchAll(PDO::FETCH_ASSOC);

    // Temps réel : notifier la room du changement de réaction (kind='reaction')
    // avec le comptage agrégé à jour pour rafraîchir l'affichage.
    dispatchMessageUpdate($convId, $messageId, 'reaction', [
        'action'    => $action,
        'reaction'  => $reaction,
        'reactions' => $reactions,
    ]);

    return ['action' => $action, 'reactions' => $reactions];
}

/* 
 * canReplyToAnnouncement() et canSetMessageImportance() sont dans core/utils.php
 */

/**
 * Fonctions liées aux messages (doublons supprimés — sendMessageToClass est dans models/class.php)
 */
require_once __DIR__ . '/../core/utils.php';