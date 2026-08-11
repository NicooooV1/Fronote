<?php
declare(strict_types=1);
/**
 * Modèle pour la gestion des conversations
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/utils.php';
require_once __DIR__ . '/participant.php'; // participantInEstablishment() — cloisonnement tenant

/**
 * Sous-requête réutilisable pour obtenir le nom complet d'un utilisateur
 * Utilise la vue v_users (UNION ALL des 5 tables) pour éviter le CASE à 5 branches
 * @return string SQL subquery expression
 */
function getUserNameCaseSQL(string $idCol = 'cp.user_id', string $typeCol = 'cp.user_type'): string {
    return "(SELECT vu.nom_complet FROM v_users vu WHERE vu.id = {$idCol} AND vu.user_type = {$typeCol} LIMIT 1)";
}

/**
 * Récupère les conversations d'un utilisateur avec pagination
 * Corrige le problème N+1 en batch-chargeant les participants
 *
 * @param int $userId
 * @param string $userType
 * @param string $dossier
 * @param int $limit  Nombre de conversations par page
 * @param int $offset Décalage pour la pagination
 * @return array ['conversations' => [...], 'total' => int, 'has_more' => bool]
 */
function getConversations($userId, $userType, $dossier = 'reception', $limit = 20, $offset = 0) {
    global $pdo;
    
    $baseQuery = "
        SELECT c.id, c.subject as titre, 
               c.type,
               c.created_at as date_creation, 
               c.updated_at as dernier_message,
               lm.body as apercu,
               lm.status as status,
               cp.unread_count as non_lus,
               cp.is_pinned as is_pinned,
               cp.muted_until as muted_until,
               CASE WHEN cp.muted_until IS NOT NULL AND cp.muted_until > NOW() THEN 1 ELSE 0 END as is_muted
        FROM conversations c
        JOIN conversation_participants cp ON c.id = cp.conversation_id
        LEFT JOIN messages lm ON lm.id = c.last_message_id
        WHERE cp.user_id = ? AND cp.user_type = ?
    ";
    
    $countQuery = "
        SELECT COUNT(*) FROM conversations c
        JOIN conversation_participants cp ON c.id = cp.conversation_id
        WHERE cp.user_id = ? AND cp.user_type = ?
    ";
    
    $params = [$userId, $userType];
    $countParams = [$userId, $userType];
    
    $folderCondition = '';
    switch ($dossier) {
        case 'archives':
            $folderCondition = " AND cp.is_archived = 1 AND cp.is_deleted = 0";
            break;
        case 'corbeille':
            $folderCondition = " AND cp.is_deleted = 1";
            break;
        case 'envoyes':
            $folderCondition = " AND cp.is_archived = 0 AND cp.is_deleted = 0 
                          AND EXISTS (SELECT 1 FROM messages WHERE conversation_id = c.id AND sender_id = ? AND sender_type = ?)";
            $params[] = $userId;
            $params[] = $userType;
            $countParams[] = $userId;
            $countParams[] = $userType;
            break;
        case 'information':
            $folderCondition = " AND cp.is_archived = 0 AND cp.is_deleted = 0 
                          AND EXISTS (SELECT 1 FROM messages WHERE conversation_id = c.id AND status = 'annonce')";
            break;
        case 'reception':
        default:
            $folderCondition = " AND cp.is_archived = 0 AND cp.is_deleted = 0 
                          AND NOT EXISTS (SELECT 1 FROM messages WHERE conversation_id = c.id AND status = 'annonce')";
    }
    
    // Épinglées d'abord (par participant), puis par récence du dernier message.
    $baseQuery .= $folderCondition . " ORDER BY cp.is_pinned DESC, c.updated_at DESC LIMIT ? OFFSET ?";
    $countQuery .= $folderCondition;
    
    // Compter le total
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($countParams);
    $total = (int) $countStmt->fetchColumn();
    
    // Récupérer la page courante
    $params[] = $limit;
    $params[] = $offset;
    $stmt = $pdo->prepare($baseQuery);
    $stmt->execute($params);
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ── FIX N+1 : batch-charger les participants en UNE seule requête ──
    if (!empty($conversations)) {
        $convIds = array_column($conversations, 'id');
        $placeholders = implode(',', array_fill(0, count($convIds), '?'));
        $nameCase = getUserNameCaseSQL();
        
        $participantsStmt = $pdo->prepare("
            SELECT cp.conversation_id, cp.user_id, cp.user_type, cp.is_admin, cp.is_moderator,
                   {$nameCase} as nom_complet
            FROM conversation_participants cp
            WHERE cp.conversation_id IN ({$placeholders}) AND cp.is_deleted = 0
        ");
        $participantsStmt->execute($convIds);
        $allParticipants = $participantsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Indexer par conversation_id
        $participantsByConv = [];
        foreach ($allParticipants as $p) {
            $participantsByConv[$p['conversation_id']][] = $p;
        }
        
        foreach ($conversations as &$conversation) {
            $conversation['participants'] = $participantsByConv[$conversation['id']] ?? [];
        }
        unset($conversation);
    }
    
    return [
        'conversations' => $conversations,
        'total' => $total,
        'has_more' => ($offset + $limit) < $total
    ];
}

/**
 * Recherche dans les conversations d'un utilisateur (full-text)
 *
 * @param int $userId
 * @param string $userType
 * @param string $query Texte recherché
 * @param int $limit
 * @param int $offset
 * @return array
 */
function searchConversations($userId, $userType, $query, $limit = 20, $offset = 0) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.id, c.subject as titre,
               c.type,
               c.created_at as date_creation,
               c.updated_at as dernier_message,
               cp.unread_count as non_lus,
               MATCH(c.subject) AGAINST (? IN BOOLEAN MODE) as relevance_subject,
               (SELECT MAX(MATCH(m2.body) AGAINST (? IN BOOLEAN MODE))
                FROM messages m2 WHERE m2.conversation_id = c.id) as relevance_body
        FROM conversations c
        JOIN conversation_participants cp ON c.id = cp.conversation_id
        LEFT JOIN messages m ON m.conversation_id = c.id
        WHERE cp.user_id = ? AND cp.user_type = ? AND cp.is_deleted = 0
          AND (MATCH(c.subject) AGAINST (? IN BOOLEAN MODE) OR MATCH(m.body) AGAINST (? IN BOOLEAN MODE))
        ORDER BY (COALESCE(relevance_subject, 0) * 2 + COALESCE(relevance_body, 0)) DESC
        LIMIT ? OFFSET ?
    ");
    // Neutraliser les opérateurs du mode booléen fulltext (+ - < > ( ) ~ * " @) : laissés bruts,
    // ils forment une syntaxe invalide et provoquent une erreur 1064. On les remplace par des espaces
    // puis on normalise les blancs avant de construire le terme booléen.
    $clean = preg_replace('/[+\-<>()~*"@]+/u', ' ', (string) $query);
    $clean = trim(preg_replace('/\s+/u', ' ', $clean));
    $searchTerm = $clean === '' ? '' : '*' . str_replace(' ', '* *', $clean) . '*';

    try {
        $stmt->execute([$searchTerm, $searchTerm, $userId, $userType, $searchTerm, $searchTerm, $limit, $offset]);
        $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        // Repli sûr : si le fulltext échoue malgré tout (syntaxe booléenne, index absent…),
        // basculer sur une recherche LIKE qui ne peut pas provoquer d'erreur de syntaxe.
        $like = '%' . addcslashes($clean, '%_\\') . '%';
        $fallback = $pdo->prepare("
            SELECT DISTINCT c.id, c.subject as titre,
                   c.type,
                   c.created_at as date_creation,
                   c.updated_at as dernier_message,
                   cp.unread_count as non_lus
            FROM conversations c
            JOIN conversation_participants cp ON c.id = cp.conversation_id
            LEFT JOIN messages m ON m.conversation_id = c.id
            WHERE cp.user_id = ? AND cp.user_type = ? AND cp.is_deleted = 0
              AND (c.subject LIKE ? OR m.body LIKE ?)
            ORDER BY c.updated_at DESC
            LIMIT ? OFFSET ?
        ");
        $fallback->execute([$userId, $userType, $like, $like, $limit, $offset]);
        $conversations = $fallback->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Batch-charger les participants
    if (!empty($conversations)) {
        $convIds = array_column($conversations, 'id');
        $placeholders = implode(',', array_fill(0, count($convIds), '?'));
        $nameCase = getUserNameCaseSQL();
        
        $pStmt = $pdo->prepare("
            SELECT cp.conversation_id, cp.user_id, cp.user_type, cp.is_admin, cp.is_moderator,
                   {$nameCase} as nom_complet
            FROM conversation_participants cp
            WHERE cp.conversation_id IN ({$placeholders}) AND cp.is_deleted = 0
        ");
        $pStmt->execute($convIds);
        $grouped = [];
        foreach ($pStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $grouped[$p['conversation_id']][] = $p;
        }
        foreach ($conversations as &$conv) {
            $conv['participants'] = $grouped[$conv['id']] ?? [];
        }
        unset($conv);
    }
    
    return $conversations;
}

/**
 * Crée une nouvelle conversation
 * @param string $titre
 * @param string $type
 * @param int $createurId
 * @param string $createurType
 * @param array $participants
 * @return int
 */
function createConversation($titre, $type, $createurId, $createurType, $participants) {
    global $pdo;

    // Nesting-aware : createConversation est appelé seul (new_message) ou depuis un
    // handler ayant déjà ouvert une transaction (handleSendAnnouncement, sendMessageToClass).
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $validTypes = ['individuelle', 'groupe', 'annonce', 'classe', 'information'];
        $type = in_array($type, $validTypes) ? $type : 'individuelle';
        $sql = "INSERT INTO conversations (etablissement_id, subject, type, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([\API\Core\EstablishmentContext::id(), $titre, $type]);
        $convId = $pdo->lastInsertId();
        
        $sql = "INSERT INTO conversation_participants 
                (conversation_id, user_id, user_type, joined_at, is_admin) 
                VALUES (?, ?, ?, NOW(), 1)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$convId, $createurId, $createurType]);
        
        // Cloisonnement tenant : valider TOUS les participants AVANT insertion. Un seul participant
        // hors établissement fait échouer (et rollback) toute la création — anti-injection cross-tenant.
        foreach ($participants as $p) {
            if (!participantInEstablishment((int) $p['id'], (string) $p['type'])) {
                throw new \RuntimeException("Participant hors de votre établissement.");
            }
        }

        $sql = "INSERT INTO conversation_participants
                (conversation_id, user_id, user_type, joined_at)
                VALUES (?, ?, ?, NOW())";
        $stmt = $pdo->prepare($sql);
        foreach ($participants as $p) {
            $stmt->execute([$convId, $p['id'], $p['type']]);
        }
        
        if ($ownTransaction) {
            $pdo->commit();
        }
        return $convId;
    } catch (Exception $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Récupère les informations d'une conversation
 * @param int $convId
 * @return array|false
 */
function getConversationInfo($convId) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT c.id, c.subject as titre, c.type, c.allow_replies
        FROM conversations c
        WHERE c.id = ? AND c.etablissement_id = ?
    ");
    $stmt->execute([$convId, \API\Core\EstablishmentContext::id()]);
    return $stmt->fetch();
}

/**
 * Archiver une conversation pour un utilisateur
 * @param int $convId
 * @param int $userId
 * @param string $userType
 * @return bool
 */
function archiveConversation($convId, $userId, $userType) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        UPDATE conversation_participants 
        SET is_archived = 1 
        WHERE conversation_id = ? AND user_id = ? AND user_type = ?
    ");
    $stmt->execute([$convId, $userId, $userType]);
    
    return $stmt->rowCount() > 0;
}

/**
 * Désarchive une conversation pour un utilisateur
 * @param int $convId
 * @param int $userId
 * @param string $userType
 * @return bool
 */
function unarchiveConversation($convId, $userId, $userType) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        UPDATE conversation_participants 
        SET is_archived = 0 
        WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
    ");
    $stmt->execute([$convId, $userId, $userType]);
    
    return $stmt->rowCount() > 0;
}

/**
 * Supprimer une conversation pour un utilisateur
 * @param int $convId
 * @param int $userId
 * @param string $userType
 * @return bool
 */
/**
 * Sentinelle « mute permanent » : muted_until fixé très loin dans le futur.
 * Un simple SELECT muted_until > NOW() suffit alors partout (liste, notify),
 * sans colonne booléenne supplémentaire.
 */
const MUTE_FOREVER = '2099-12-31 23:59:59';

/**
 * Coupe les notifications d'une conversation pour le participant courant.
 * Le participant continue de RECEVOIR les messages ; seule la notification
 * push/desktop est supprimée (cf. notify path dans models/message.php).
 *
 * @param int         $convId
 * @param int         $userId
 * @param string      $userType
 * @param string|null $mutedUntil DATETIME 'Y-m-d H:i:s' déjà résolu, ou NULL = mute permanent (sentinelle far-future)
 * @return bool true si la ligne participant a été mise à jour
 */
function muteConversation($convId, $userId, $userType, $mutedUntil = null) {
    global $pdo;

    // NULL / "forever" → sentinelle far-future : la conversation reste muette
    // tant que l'utilisateur ne l'a pas réactivée explicitement.
    $value = ($mutedUntil === null || $mutedUntil === '') ? MUTE_FOREVER : $mutedUntil;

    $stmt = $pdo->prepare("
        UPDATE conversation_participants
        SET muted_until = ?
        WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
    ");
    $stmt->execute([$value, $convId, $userId, $userType]);

    return $stmt->rowCount() > 0;
}

/**
 * Réactive les notifications d'une conversation pour le participant courant.
 * @return bool true si la ligne participant a été mise à jour
 */
function unmuteConversation($convId, $userId, $userType) {
    global $pdo;

    $stmt = $pdo->prepare("
        UPDATE conversation_participants
        SET muted_until = NULL
        WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
    ");
    $stmt->execute([$convId, $userId, $userType]);

    return $stmt->rowCount() > 0;
}

/**
 * Épingle une conversation en tête de liste pour le participant courant.
 * L'épinglage est PROPRE à chaque participant (colonne sur conversation_participants).
 * @return bool
 */
function pinConversation($convId, $userId, $userType) {
    global $pdo;

    $stmt = $pdo->prepare("
        UPDATE conversation_participants
        SET is_pinned = 1
        WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
    ");
    $stmt->execute([$convId, $userId, $userType]);

    return $stmt->rowCount() > 0;
}

/**
 * Désépingle une conversation pour le participant courant.
 * @return bool
 */
function unpinConversation($convId, $userId, $userType) {
    global $pdo;

    $stmt = $pdo->prepare("
        UPDATE conversation_participants
        SET is_pinned = 0
        WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
    ");
    $stmt->execute([$convId, $userId, $userType]);

    return $stmt->rowCount() > 0;
}

/**
 * Résout un mot-clé de durée de mute en DATETIME 'Y-m-d H:i:s'.
 * Accepte : '1h', '8h', 'tomorrow', 'forever'/'' /null, ou un datetime ISO/SQL.
 * Retourne NULL pour un mute permanent (le modèle applique alors la sentinelle).
 *
 * @param string|null $until
 * @return string|null  DATETIME résolu, ou NULL pour permanent
 * @throws \InvalidArgumentException si la valeur est non reconnue / dans le passé
 */
function resolveMuteUntil($until) {
    if ($until === null || $until === '' || $until === 'forever') {
        return null; // permanent
    }

    $now = new \DateTimeImmutable('now');
    switch ($until) {
        case '1h':
            return $now->modify('+1 hour')->format('Y-m-d H:i:s');
        case '8h':
            return $now->modify('+8 hours')->format('Y-m-d H:i:s');
        case 'tomorrow':
            return $now->modify('+1 day')->setTime(8, 0, 0)->format('Y-m-d H:i:s');
    }

    // Datetime explicite (ISO 8601 ou 'Y-m-d H:i:s'). On le normalise et on
    // refuse une date déjà passée (mute sans effet) pour éviter les valeurs absurdes.
    try {
        $dt = new \DateTimeImmutable((string) $until);
    } catch (\Exception $e) {
        throw new \InvalidArgumentException("Date de mise en sourdine invalide");
    }
    if ($dt <= $now) {
        throw new \InvalidArgumentException("La date de mise en sourdine doit être dans le futur");
    }
    return $dt->format('Y-m-d H:i:s');
}

function deleteConversation($convId, $userId, $userType) {
    global $pdo;

    // Marquer les notifications comme lues d'abord
    $stmt = $pdo->prepare("
        UPDATE message_notifications AS mn
        JOIN messages AS m ON mn.message_id = m.id
        SET mn.is_read = 1
        WHERE m.conversation_id = ? AND mn.user_id = ? AND mn.user_type = ? AND mn.is_read = 0
    ");
    $stmt->execute([$convId, $userId, $userType]);
    
    // Mettre à jour la dernière lecture
    $stmt = $pdo->prepare("
        UPDATE conversation_participants 
        SET last_read_at = NOW() 
        WHERE conversation_id = ? AND user_id = ? AND user_type = ?
    ");
    $stmt->execute([$convId, $userId, $userType]);
    
    // Marquer comme supprimé
    $stmt = $pdo->prepare("
        UPDATE conversation_participants 
        SET is_deleted = 1 
        WHERE conversation_id = ? AND user_id = ? AND user_type = ?
    ");
    $stmt->execute([$convId, $userId, $userType]);
    
    return $stmt->rowCount() > 0;
}

/**
 * Restaure une conversation depuis la corbeille
 * @param int $convId
 * @param int $userId
 * @param string $userType
 * @return bool
 */
function restoreConversation($convId, $userId, $userType) {
    global $pdo;
    
    $pdo->beginTransaction();
    
    try {
        // Vérifier si un participant actif existe déjà
        $checkStmt = $pdo->prepare("
            SELECT COUNT(*) FROM conversation_participants 
            WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
        ");
        $checkStmt->execute([$convId, $userId, $userType]);
        $exists = $checkStmt->fetchColumn() > 0;
        
        if ($exists) {
            // Un participant actif existe déjà : le sortir des archives. Restaurer une conversation
            // archivée-mais-non-supprimée doit la désarchiver, sinon 'restore' ne fait rien.
            $unarchiveStmt = $pdo->prepare("
                UPDATE conversation_participants
                SET is_archived = 0
                WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 0
            ");
            $unarchiveStmt->execute([$convId, $userId, $userType]);
            $pdo->commit();
            return true;
        }
        
        // Récupérer l'ID du participant supprimé
        $getIdStmt = $pdo->prepare("
            SELECT id FROM conversation_participants 
            WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND is_deleted = 1
            ORDER BY id ASC LIMIT 1
        ");
        $getIdStmt->execute([$convId, $userId, $userType]);
        $recordId = $getIdStmt->fetchColumn();
        
        if ($recordId) {
            // Restaurer le participant
            $updateStmt = $pdo->prepare("
                UPDATE conversation_participants 
                SET is_deleted = 0, is_archived = 0 
                WHERE id = ?
            ");
            $updateStmt->execute([$recordId]);
            
            // Supprimer les doublons
            $deleteOthersStmt = $pdo->prepare("
                DELETE FROM conversation_participants 
                WHERE conversation_id = ? AND user_id = ? AND user_type = ? AND id != ?
            ");
            $deleteOthersStmt->execute([$convId, $userId, $userType, $recordId]);
        } else {
            // Anti-IDOR tenant : ne pas créer un participant « frais » (jamais membre) pour une
            // conversation d'un autre établissement — sinon on rejoindrait une conversation étrangère.
            $etabChk = $pdo->prepare("SELECT 1 FROM conversations WHERE id = ? AND etablissement_id = ? LIMIT 1");
            $etabChk->execute([$convId, \API\Core\EstablishmentContext::id()]);
            if (!$etabChk->fetchColumn()) {
                throw new \RuntimeException("Conversation hors de votre établissement.");
            }
            // Créer un nouveau participant
            $insertStmt = $pdo->prepare("
                INSERT INTO conversation_participants
                (conversation_id, user_id, user_type, joined_at, is_deleted, is_archived)
                VALUES (?, ?, ?, NOW(), 0, 0)
            ");
            $insertStmt->execute([$convId, $userId, $userType]);
        }
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Supprime définitivement une conversation pour un utilisateur
 * @param int $convId
 * @param int $userId
 * @param string $userType
 * @return bool
 */
function deletePermanently($convId, $userId, $userType) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        DELETE FROM conversation_participants 
        WHERE conversation_id = ? AND user_id = ? AND user_type = ?
    ");
    $stmt->execute([$convId, $userId, $userType]);
    
    return $stmt->rowCount() > 0;
}

/**
 * Supprime définitivement plusieurs conversations pour un utilisateur
 * @param array $convIds
 * @param int $userId
 * @param string $userType
 * @return int
 */
function deleteMultipleConversations($convIds, $userId, $userType) {
    global $pdo;
    
    if (empty($convIds)) {
        return 0;
    }
    
    $placeholders = implode(',', array_fill(0, count($convIds), '?'));
    
    $stmt = $pdo->prepare("
        DELETE FROM conversation_participants 
        WHERE conversation_id IN ($placeholders) AND user_id = ? AND user_type = ?
    ");
    
    $params = array_merge($convIds, [$userId, $userType]);
    $stmt->execute($params);
    
    return $stmt->rowCount();
}