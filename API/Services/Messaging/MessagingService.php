<?php
declare(strict_types=1);

namespace API\Services\Messaging;

use API\Services\Notifications\NotificationCenter;
use PDO;
use RuntimeException;

/**
 * Messagerie — service métier (conversations, messages, lecture, temps réel).
 *
 * Types de conversation :
 *   - individuelle : deux personnes ;
 *   - groupe       : plusieurs personnes, tout le monde voit les réponses (personnel uniquement,
 *                    ou personnel + une seule famille/élève) ;
 *   - information  : envoi à plusieurs élèves et/ou familles. Les destinataires ne se voient pas
 *                    entre eux et répondent EN PRIVÉ à l'expéditeur (confidentialité des familles).
 */
final class MessagingService
{
    public const MAX_BODY = 10000;
    public const PAGE = 40;
    private const STAFF = ['professeur', 'vie_scolaire', 'administrateur'];

    private PDO $pdo;
    private int $etab;
    private int $uid;
    private string $utype;
    private People $people;
    private RecipientPolicy $policy;

    public function __construct(PDO $pdo, int $etab, int $userId, string $userType)
    {
        if (!People::valid($userType)) { throw new RuntimeException('Type de compte non pris en charge par la messagerie.'); }
        $this->pdo = $pdo; $this->etab = $etab; $this->uid = $userId; $this->utype = $userType;
        $this->people = new People($pdo, $etab);
        $this->policy = new RecipientPolicy($pdo, $etab, $this->people, $userId, $userType);
    }

    public static function forCurrentUser(): self
    {
        return new self(getPDO(), (int) \API\Core\EstablishmentContext::id(), (int) getUserId(), (string) getUserRole());
    }

    public function policy(): RecipientPolicy { return $this->policy; }
    public function people(): People { return $this->people; }
    public function me(): array { return $this->people->get($this->uid, $this->utype) ?? ['id' => $this->uid, 'type' => $this->utype, 'name' => '', 'initials' => '?', 'detail' => '']; }

    // ───────────────────────────── Lecture ─────────────────────────────

    /** Participation de l'utilisateur courant (ou null). */
    private function membership(int $convId): ?array
    {
        $st = $this->pdo->prepare('SELECT cp.*, c.subject, c.type, c.allow_replies, c.last_message_id, c.created_at AS conv_created, c.updated_at AS conv_updated
                                     FROM conversation_participants cp JOIN conversations c ON c.id = cp.conversation_id
                                    WHERE cp.conversation_id = ? AND cp.user_id = ? AND cp.user_type = ? AND cp.is_deleted = 0
                                      AND (c.etablissement_id = ? OR c.etablissement_id IS NULL)');
        $st->execute([$convId, $this->uid, $this->utype, $this->etab]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function creatorOf(int $convId): ?array
    {
        $st = $this->pdo->prepare('SELECT user_id, user_type FROM conversation_participants WHERE conversation_id = ? AND is_admin = 1 ORDER BY id LIMIT 1');
        $st->execute([$convId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) { // conversations historiques : l'auteur du premier message
            $st = $this->pdo->prepare('SELECT sender_id AS user_id, sender_type AS user_type FROM messages WHERE conversation_id = ? ORDER BY id LIMIT 1');
            $st->execute([$convId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
        }
        return $r ? ['id' => (int) $r['user_id'], 'type' => (string) $r['user_type']] : null;
    }

    private function canReply(array $m, ?array $creator): bool
    {
        if ((int) $m['allow_replies'] === 1 && $m['type'] !== 'information' && $m['type'] !== 'annonce') { return true; }
        return $creator && $creator['id'] === $this->uid && $creator['type'] === $this->utype; // l'expéditeur peut compléter
    }

    /**
     * @param array{box?:string,q?:string,before?:string} $f box = inbox|unread|archived
     * @return array{items:array,next:?string}
     */
    public function conversations(array $f = [], int $limit = 30): array
    {
        $limit = max(1, min(60, $limit));
        $box = $f['box'] ?? 'inbox';
        $sql = 'SELECT c.id, c.subject, c.type, c.allow_replies, c.updated_at, c.last_message_id,
                       cp.unread_count, cp.is_archived, cp.is_pinned, cp.muted_until, cp.is_admin,
                       m.body AS last_body, m.sender_id AS last_sid, m.sender_type AS last_stype, m.is_deleted AS last_deleted,
                       m.status AS last_status
                  FROM conversation_participants cp
                  JOIN conversations c ON c.id = cp.conversation_id
                  LEFT JOIN messages m ON m.id = c.last_message_id
                 WHERE cp.user_id = ? AND cp.user_type = ? AND cp.is_deleted = 0
                   AND (c.etablissement_id = ? OR c.etablissement_id IS NULL)';
        $p = [$this->uid, $this->utype, $this->etab];
        if ($box === 'archived') { $sql .= ' AND cp.is_archived = 1'; } else { $sql .= ' AND cp.is_archived = 0'; }
        if ($box === 'unread') { $sql .= ' AND cp.unread_count > 0'; }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $sql .= ' AND (c.subject LIKE ? OR EXISTS (SELECT 1 FROM messages mm WHERE mm.conversation_id = c.id AND mm.is_deleted = 0 AND mm.body LIKE ?))';
            array_push($p, $like, $like);
        }
        if (!empty($f['before']) && preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\|(\d+)$/', (string) $f['before'], $mm)) {
            $sql .= ' AND (c.updated_at < ? OR (c.updated_at = ? AND c.id < ?))';
            array_push($p, $mm[1], $mm[1], (int) $mm[2]);
        }
        $sql .= ' ORDER BY ' . ($box === 'inbox' ? 'cp.is_pinned DESC, ' : '') . 'c.updated_at DESC, c.id DESC LIMIT ' . ($limit + 1);
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $next = null;
        if (count($rows) > $limit) { array_pop($rows); $l = end($rows); $next = $l['updated_at'] . '|' . $l['id']; }
        if (!$rows) { return ['items' => [], 'next' => null]; }

        // Participants (hors moi) en une requête, pour l'affichage « avec qui »
        $ids = array_map(fn($r) => (int) $r['id'], $rows);
        $st = $this->pdo->prepare('SELECT conversation_id, user_id, user_type, is_admin FROM conversation_participants
                                    WHERE conversation_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND is_deleted = 0 ORDER BY id');
        $st->execute($ids);
        $parts = [];
        $refs = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $parts[(int) $r['conversation_id']][] = $r;
            $refs[] = [(int) $r['user_id'], (string) $r['user_type']];
        }
        foreach ($rows as $r) { if ($r['last_sid']) { $refs[] = [(int) $r['last_sid'], (string) $r['last_stype']]; } }
        $this->people->load($refs);

        $items = [];
        foreach ($rows as $r) {
            $cid = (int) $r['id'];
            $others = array_values(array_filter($parts[$cid] ?? [], fn($x) => !((int) $x['user_id'] === $this->uid && $x['user_type'] === $this->utype)));
            $isInfoRecipient = $r['type'] === 'information' && !(int) $r['is_admin'];
            if ($isInfoRecipient) { $others = array_values(array_filter($others, fn($x) => (int) $x['is_admin'] === 1)); }
            $names = []; $first = null;
            foreach (array_slice($others, 0, 3) as $o) {
                $pp = $this->people->get((int) $o['user_id'], (string) $o['user_type']);
                if ($pp) { $names[] = $pp['name']; $first ??= $pp; }
            }
            $last = $r['last_sid'] ? $this->people->get((int) $r['last_sid'], (string) $r['last_stype']) : null;
            $mine = $r['last_sid'] && (int) $r['last_sid'] === $this->uid && $r['last_stype'] === $this->utype;
            $preview = (int) $r['last_deleted'] ? 'Message supprimé' : self::preview((string) $r['last_body']);
            $items[] = [
                'id' => $cid, 'subject' => (string) $r['subject'], 'type' => (string) $r['type'],
                'updated_at' => (string) $r['updated_at'], 'unread' => (int) $r['unread_count'],
                'archived' => (bool) $r['is_archived'], 'pinned' => (bool) $r['is_pinned'],
                'muted' => $r['muted_until'] && strtotime((string) $r['muted_until']) > time(),
                'with' => $names, 'with_count' => count($others), 'avatar' => $first,
                'last' => ['preview' => $preview, 'mine' => $mine, 'sender' => $last['first'] ?? '', 'status' => (string) ($r['last_status'] ?? 'normal')],
                'cursor' => $r['updated_at'] . '|' . $cid,
            ];
        }
        return ['items' => $items, 'next' => $next];
    }

    public static function preview(string $body): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
        return mb_strlen($t) > 120 ? mb_substr($t, 0, 117) . '…' : $t;
    }

    /** Détails d'une conversation (participants visibles, droits). */
    public function conversation(int $convId): array
    {
        $m = $this->membership($convId);
        if (!$m) { throw new RuntimeException('Conversation introuvable.'); }
        $creator = $this->creatorOf($convId);
        $st = $this->pdo->prepare('SELECT user_id, user_type, is_admin, last_read_message_id FROM conversation_participants WHERE conversation_id = ? AND is_deleted = 0 ORDER BY is_admin DESC, id');
        $st->execute([$convId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $this->people->load(array_map(fn($r) => [(int) $r['user_id'], (string) $r['user_type']], $rows));
        $isInfoRecipient = $m['type'] === 'information' && !(int) $m['is_admin'];
        $participants = [];
        foreach ($rows as $r) {
            if ($isInfoRecipient && !(int) $r['is_admin'] && !((int) $r['user_id'] === $this->uid && $r['user_type'] === $this->utype)) { continue; }
            $pp = $this->people->get((int) $r['user_id'], (string) $r['user_type']);
            if (!$pp) { continue; }
            $pp['me'] = (int) $r['user_id'] === $this->uid && $r['user_type'] === $this->utype;
            $pp['author'] = (bool) $r['is_admin'];
            $pp['last_read'] = (int) ($r['last_read_message_id'] ?? 0);
            $participants[] = $pp;
        }
        $canReply = $this->canReply($m, $creator);
        return [
            'id' => $convId, 'subject' => (string) $m['subject'], 'type' => (string) $m['type'],
            'archived' => (bool) $m['is_archived'], 'pinned' => (bool) $m['is_pinned'],
            'muted' => $m['muted_until'] && strtotime((string) $m['muted_until']) > time(),
            'participants' => $participants, 'recipient_count' => max(0, count($rows) - 1),
            'can_reply' => $canReply,
            'private_reply' => !$canReply && $creator && !($creator['id'] === $this->uid && $creator['type'] === $this->utype),
            'is_author' => $creator && $creator['id'] === $this->uid && $creator['type'] === $this->utype,
            'created_at' => (string) $m['conv_created'],
        ];
    }

    /** Messages (du plus ancien au plus récent), pagination vers le passé ou nouveaux depuis $after. */
    public function messages(int $convId, ?int $before = null, ?int $after = null, int $limit = self::PAGE): array
    {
        if (!$this->membership($convId)) { throw new RuntimeException('Conversation introuvable.'); }
        $limit = max(1, min(100, $limit));
        $sql = 'SELECT id, sender_id, sender_type, body, status, created_at, edited_at, is_deleted, parent_message_id FROM messages WHERE conversation_id = ?';
        $p = [$convId];
        if ($after !== null) { $sql .= ' AND id > ? ORDER BY id ASC LIMIT 200'; $p[] = $after; }
        else {
            if ($before !== null) { $sql .= ' AND id < ?'; $p[] = $before; }
            $sql .= ' ORDER BY id DESC LIMIT ' . ($limit + 1);
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $more = false;
        if ($after === null) { if (count($rows) > $limit) { array_pop($rows); $more = true; } $rows = array_reverse($rows); }
        $this->people->load(array_map(fn($r) => [(int) $r['sender_id'], (string) $r['sender_type']], $rows));
        $att = [];
        if ($rows) {
            $ids = array_map(fn($r) => (int) $r['id'], $rows);
            $st = $this->pdo->prepare('SELECT id, message_id, file_name, type_mime, taille FROM message_attachments WHERE message_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $att[(int) $a['message_id']][] = ['id' => (int) $a['id'], 'name' => (string) $a['file_name'], 'mime' => (string) $a['type_mime'], 'size' => (int) $a['taille']];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $s = $this->people->get((int) $r['sender_id'], (string) $r['sender_type']);
            $del = (bool) $r['is_deleted'];
            $out[] = [
                'id' => (int) $r['id'], 'body' => $del ? '' : (string) $r['body'], 'deleted' => $del,
                'status' => (string) ($r['status'] ?? 'normal'), 'at' => (string) $r['created_at'], 'edited' => !empty($r['edited_at']),
                'mine' => (int) $r['sender_id'] === $this->uid && $r['sender_type'] === $this->utype,
                'sender' => $s ? ['id' => $s['id'], 'type' => $s['type'], 'name' => $s['name'], 'initials' => $s['initials'], 'detail' => $s['detail']]
                               : ['id' => (int) $r['sender_id'], 'type' => (string) $r['sender_type'], 'name' => 'Compte supprimé', 'initials' => '?', 'detail' => ''],
                'attachments' => $del ? [] : ($att[(int) $r['id']] ?? []),
            ];
        }
        return ['items' => $out, 'more' => $more];
    }

    public function unreadTotal(): int
    {
        $st = $this->pdo->prepare('SELECT COALESCE(SUM(cp.unread_count),0) FROM conversation_participants cp JOIN conversations c ON c.id = cp.conversation_id
                                    WHERE cp.user_id = ? AND cp.user_type = ? AND cp.is_deleted = 0 AND cp.is_archived = 0
                                      AND (c.etablissement_id = ? OR c.etablissement_id IS NULL)');
        $st->execute([$this->uid, $this->utype, $this->etab]);
        return (int) $st->fetchColumn();
    }

    // ───────────────────────────── Écriture ─────────────────────────────

    private static function cleanBody(string $body): string
    {
        $body = str_replace("\r\n", "\n", $body);
        $body = preg_replace('/[^\P{C}\n\t]/u', '', $body) ?? ''; // caractères de contrôle
        $body = trim($body);
        if ($body === '') { throw new RuntimeException('Le message est vide.'); }
        if (mb_strlen($body) > self::MAX_BODY) { throw new RuntimeException('Message trop long (' . self::MAX_BODY . ' caractères maximum).'); }
        return $body;
    }

    private function status(string $s): string
    {
        if (!in_array($this->utype, self::STAFF, true)) { return 'normal'; }
        return in_array($s, ['important', 'urgent'], true) ? $s : 'normal';
    }

    /**
     * Nouvelle conversation.
     * @param array<int,array{id:int,type:string}> $people  destinataires individuels
     * @param string[] $groups clés de groupes d'envoi (classe entière…)
     * @return int id de la conversation
     */
    public function create(array $people, array $groups, string $subject, string $body, string $status = 'normal', array $files = []): int
    {
        $body = self::cleanBody($body);
        $subject = trim(preg_replace('/\s+/u', ' ', $subject) ?? '');
        $subject = mb_substr($subject !== '' ? $subject : self::preview($body), 0, 200);

        $recipients = [];
        foreach ($people as $r) {
            $id = (int) ($r['id'] ?? 0); $t = (string) ($r['type'] ?? '');
            if (!$this->policy->canContact($id, $t)) { throw new RuntimeException('Vous ne pouvez pas écrire à l\'un des destinataires sélectionnés.'); }
            $recipients[$t . ':' . $id] = ['id' => $id, 'type' => $t];
        }
        foreach ($groups as $g) {
            $exp = $this->policy->expandGroup((string) $g);
            if (!$exp) { throw new RuntimeException('Groupe de destinataires non autorisé.'); }
            foreach ($exp as $r) { if (!($r['id'] === $this->uid && $r['type'] === $this->utype)) { $recipients[$r['type'] . ':' . $r['id']] = $r; } }
        }
        if (!$recipients) { throw new RuntimeException('Choisissez au moins un destinataire.'); }
        if (count($recipients) > 1500) { throw new RuntimeException('Trop de destinataires.'); }

        // Type : plusieurs élèves/familles → information (réponses privées) ; sinon groupe/individuelle
        $families = count(array_filter($recipients, fn($r) => in_array($r['type'], ['eleve', 'parent'], true)));
        if (count($recipients) === 1) { $type = 'individuelle'; }
        elseif ($families > 1 || $groups) { $type = 'information'; }
        else { $type = 'groupe'; }
        $allow = $type === 'information' ? 0 : 1;

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO conversations (etablissement_id, subject, type, allow_replies, created_at, updated_at) VALUES (?,?,?,?,NOW(),NOW())')
                ->execute([$this->etab, $subject, $type, $allow]);
            $cid = (int) $this->pdo->lastInsertId();
            $ins = $this->pdo->prepare('INSERT INTO conversation_participants (conversation_id, user_id, user_type, joined_at, unread_count, is_admin) VALUES (?,?,?,NOW(),?,?)');
            $ins->execute([$cid, $this->uid, $this->utype, 0, 1]);
            foreach ($recipients as $r) { $ins->execute([$cid, $r['id'], $r['type'], 0, 0]); }
            $mid = $this->insertMessage($cid, $body, $this->status($status), $files);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->afterMessage($cid, $mid, $subject, $body, true);
        return $cid;
    }

    public function reply(int $convId, string $body, string $status = 'normal', array $files = []): int
    {
        $m = $this->membership($convId);
        if (!$m) { throw new RuntimeException('Conversation introuvable.'); }
        if (!$this->canReply($m, $this->creatorOf($convId))) { throw new RuntimeException('Les réponses sont fermées dans cette conversation : répondez en privé à l\'expéditeur.'); }
        $body = self::cleanBody($body);
        $this->pdo->beginTransaction();
        try {
            $mid = $this->insertMessage($convId, $body, $this->status($status), $files);
            $this->pdo->commit();
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
        $this->afterMessage($convId, $mid, (string) $m['subject'], $body, false);
        return $mid;
    }

    /** Conversation privée avec l'expéditeur d'une information (créée ou réutilisée). */
    public function privateReply(int $convId): int
    {
        $c = $this->conversation($convId);
        $creator = $this->creatorOf($convId);
        if (!$creator || ($creator['id'] === $this->uid && $creator['type'] === $this->utype)) { throw new RuntimeException('Rien à faire.'); }
        $subject = mb_substr('Re : ' . $c['subject'], 0, 200);
        $st = $this->pdo->prepare("SELECT c.id FROM conversations c
            JOIN conversation_participants a ON a.conversation_id = c.id AND a.user_id = ? AND a.user_type = ? AND a.is_deleted = 0
            JOIN conversation_participants b ON b.conversation_id = c.id AND b.user_id = ? AND b.user_type = ?
            WHERE c.type = 'individuelle' AND c.subject = ? AND c.etablissement_id = ? ORDER BY c.id DESC LIMIT 1");
        $st->execute([$this->uid, $this->utype, $creator['id'], $creator['type'], $subject, $this->etab]);
        $existing = $st->fetchColumn();
        if ($existing) { return (int) $existing; }
        $this->pdo->prepare("INSERT INTO conversations (etablissement_id, subject, type, allow_replies, created_at, updated_at) VALUES (?,?, 'individuelle', 1, NOW(), NOW())")
            ->execute([$this->etab, $subject]);
        $cid = (int) $this->pdo->lastInsertId();
        $ins = $this->pdo->prepare('INSERT INTO conversation_participants (conversation_id, user_id, user_type, joined_at, unread_count, is_admin) VALUES (?,?,?,NOW(),0,?)');
        $ins->execute([$cid, $this->uid, $this->utype, 1]);
        $ins->execute([$cid, $creator['id'], $creator['type'], 0]);
        return $cid;
    }

    private function insertMessage(int $convId, string $body, string $status, array $files): int
    {
        $this->pdo->prepare('INSERT INTO messages (conversation_id, sender_id, sender_type, body, status, created_at) VALUES (?,?,?,?,?,NOW())')
            ->execute([$convId, $this->uid, $this->utype, $body, $status]);
        $mid = (int) $this->pdo->lastInsertId();
        if ($files) { (new AttachmentStore($this->pdo))->storeAll($mid, $files); }
        $this->pdo->prepare('UPDATE conversations SET last_message_id = ?, updated_at = NOW() WHERE id = ?')->execute([$mid, $convId]);
        // non-lus : +1 pour les autres ; la conversation revient dans leur boîte (désarchivée)
        $this->pdo->prepare('UPDATE conversation_participants SET unread_count = unread_count + 1, is_archived = 0
                              WHERE conversation_id = ? AND is_deleted = 0 AND NOT (user_id = ? AND user_type = ?)')
            ->execute([$convId, $this->uid, $this->utype]);
        $this->pdo->prepare('UPDATE conversation_participants SET last_read_message_id = ?, unread_count = 0, last_read_at = NOW()
                              WHERE conversation_id = ? AND user_id = ? AND user_type = ?')
            ->execute([$mid, $convId, $this->uid, $this->utype]);
        return $mid;
    }

    /** Notification + temps réel après un nouveau message. */
    private function afterMessage(int $convId, int $mid, string $subject, string $body, bool $isNew): void
    {
        $st = $this->pdo->prepare('SELECT user_id, user_type, muted_until FROM conversation_participants WHERE conversation_id = ? AND is_deleted = 0');
        $st->execute([$convId]);
        $all = $st->fetchAll(PDO::FETCH_ASSOC);
        $me = $this->me();
        $targets = []; $notify = [];
        foreach ($all as $p) {
            $targets[] = ['user_id' => (int) $p['user_id'], 'user_type' => (string) $p['user_type']];
            $isMe = (int) $p['user_id'] === $this->uid && $p['user_type'] === $this->utype;
            $muted = $p['muted_until'] && strtotime((string) $p['muted_until']) > time();
            if (!$isMe && !$muted) { $notify[] = ['id' => (int) $p['user_id'], 'type' => (string) $p['user_type']]; }
        }
        try {
            NotificationCenter::make()->send($notify, 'message',
                ($me['name'] ?: 'Nouveau message') . ' — ' . $subject, self::preview($body),
                '/modules/messagerie/?c=' . $convId,
                ['group_key' => 'msg:' . $convId, 'group_title' => '%d nouveaux messages — ' . mb_substr($subject, 0, 120),
                 'actor' => $me['name'], 'source_type' => 'messagerie', 'source_id' => $convId]);
        } catch (\Throwable $e) { error_log('[messagerie] notification: ' . $e->getMessage()); }
        try {
            if (class_exists('\API\Core\WebSocket')) {
                \API\Core\WebSocket::dispatch('/notify/message', [
                    'etablissement_id' => $this->etab, 'conversationId' => $convId, 'messageId' => $mid, 'isNew' => $isNew,
                    'targets' => $targets, 'sender_name' => $me['name'], 'sender' => ['id' => $this->uid, 'type' => $this->utype],
                    'preview' => self::preview($body), 'subject' => $subject,
                ]);
            }
        } catch (\Throwable $e) {}
    }

    public function markRead(int $convId): void
    {
        $m = $this->membership($convId);
        if (!$m) { return; }
        $this->pdo->prepare('UPDATE conversation_participants SET unread_count = 0, last_read_message_id = ?, last_read_at = NOW() WHERE id = ?')
            ->execute([(int) $m['last_message_id'], (int) $m['id']]);
        try { NotificationCenter::make()->markReadByGroup($this->uid, $this->utype, 'msg:' . $convId); } catch (\Throwable $e) {}
        try {
            if ((int) $m['unread_count'] > 0 && class_exists('\API\Core\WebSocket')) {
                \API\Core\WebSocket::dispatch('/notify/read', ['etablissement_id' => $this->etab, 'conversationId' => $convId,
                    'userId' => $this->uid, 'userType' => $this->utype, 'lastReadMessageId' => (int) $m['last_message_id']]);
            }
        } catch (\Throwable $e) {}
    }

    public function setFlag(int $convId, string $flag, bool $on): void
    {
        $m = $this->membership($convId);
        if (!$m) { throw new RuntimeException('Conversation introuvable.'); }
        $col = match ($flag) { 'archived' => 'is_archived', 'pinned' => 'is_pinned', 'deleted' => 'is_deleted', 'muted' => 'muted_until', default => null };
        if (!$col) { throw new RuntimeException('Action inconnue.'); }
        $val = $col === 'muted_until' ? ($on ? date('Y-m-d H:i:s', strtotime('+10 years')) : null) : ($on ? 1 : 0);
        $this->pdo->prepare("UPDATE conversation_participants SET {$col} = ? WHERE id = ?")->execute([$val, (int) $m['id']]);
    }

    public function deleteMessage(int $messageId): int
    {
        $st = $this->pdo->prepare('SELECT conversation_id FROM messages WHERE id = ? AND sender_id = ? AND sender_type = ? AND is_deleted = 0');
        $st->execute([$messageId, $this->uid, $this->utype]);
        $cid = (int) $st->fetchColumn();
        if (!$cid || !$this->membership($cid)) { throw new RuntimeException('Message introuvable.'); }
        $this->pdo->prepare('UPDATE messages SET is_deleted = 1, deleted_at = NOW(), deleted_by_id = ?, deleted_by_type = ? WHERE id = ?')
            ->execute([$this->uid, $this->utype, $messageId]);
        try {
            \API\Core\WebSocket::dispatch('/notify/message:updated', ['etablissement_id' => $this->etab, 'conversationId' => $cid, 'messageId' => $messageId, 'kind' => 'delete']);
        } catch (\Throwable $e) {}
        return $cid;
    }

    /** Pièce jointe téléchargeable par l'utilisateur courant ? @return array{path:string,name:string,mime:string}|null */
    public function attachment(int $attId): ?array
    {
        $st = $this->pdo->prepare('SELECT a.file_name, a.file_path, a.type_mime, m.conversation_id, m.is_deleted FROM message_attachments a JOIN messages m ON m.id = a.message_id WHERE a.id = ?');
        $st->execute([$attId]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a || (int) $a['is_deleted'] || !$this->membership((int) $a['conversation_id'])) { return null; }
        $path = AttachmentStore::resolve((string) $a['file_path']);
        return $path ? ['path' => $path, 'name' => (string) $a['file_name'], 'mime' => (string) $a['type_mime']] : null;
    }
}
