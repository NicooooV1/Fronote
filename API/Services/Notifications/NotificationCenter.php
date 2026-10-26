<?php
declare(strict_types=1);

namespace API\Services\Notifications;

use PDO;

/**
 * Centre de notifications — point d'entrée UNIQUE pour notifier un utilisateur.
 *
 *  - Catalogue de types → catégorie, icône, couleur (une notification « parle » d'un coup d'œil).
 *  - Préférences par CATÉGORIE et par canal (application / e-mail récapitulatif / push).
 *  - Regroupement : une rafale (5 messages d'une même conversation, 3 notes d'un même
 *    contrôle) devient UNE notification avec un compteur, au lieu d'inonder la cloche.
 *  - Temps réel : chaque envoi est poussé au navigateur (WebSocket) ; repli en lecture rapide.
 *  - Requêtes bornées et indexées (user, établissement, lu, date).
 *
 * Usage :
 *   NotificationCenter::make()->send(
 *       [['id' => 12, 'type' => 'eleve'], ['id' => 4, 'type' => 'parent']],
 *       'note', 'Nouvelle note en Mathématiques', '15/20 — Contrôle',
 *       '/modules/notes/notes.php', ['group_key' => 'note:eval:88', 'group_title' => '%d nouvelles notes']
 *   );
 */
final class NotificationCenter
{
    /** catégorie => [libellé, description, icône] */
    public const CATEGORIES = [
        'scolarite'     => ['Scolarité', 'Notes, devoirs, bulletins, compétences', 'fa-graduation-cap'],
        'vie_scolaire'  => ['Vie scolaire', 'Absences, retards, discipline', 'fa-user-clock'],
        'messages'      => ['Messagerie', 'Nouveaux messages et réponses', 'fa-envelope'],
        'etablissement' => ['Vie de l\'établissement', 'Annonces, agenda, réunions, sorties', 'fa-school'],
        'services'      => ['Services', 'Cantine, transports, garderie, bibliothèque, facturation', 'fa-concierge-bell'],
        'systeme'       => ['Compte et sécurité', 'Connexions, sécurité, informations du compte', 'fa-shield-alt'],
    ];

    /** type canonique => [catégorie, icône, couleur] ; les anciens types y sont ramenés par alias(). */
    public const TYPES = [
        'note'        => ['scolarite', 'fa-star', 'notes'],
        'devoir'      => ['scolarite', 'fa-book-open', 'cahier'],
        'bulletin'    => ['scolarite', 'fa-file-alt', 'notes'],
        'competence'  => ['scolarite', 'fa-check-double', 'notes'],
        'absence'     => ['vie_scolaire', 'fa-user-times', 'absences'],
        'retard'      => ['vie_scolaire', 'fa-clock', 'absences'],
        'discipline'  => ['vie_scolaire', 'fa-gavel', 'absences'],
        'signalement' => ['vie_scolaire', 'fa-exclamation-triangle', 'absences'],
        'message'     => ['messages', 'fa-envelope', 'messagerie'],
        'annonce'     => ['etablissement', 'fa-bullhorn', 'agenda'],
        'evenement'   => ['etablissement', 'fa-calendar-day', 'agenda'],
        'reunion'     => ['etablissement', 'fa-handshake', 'agenda'],
        'service'     => ['services', 'fa-concierge-bell', 'agenda'],
        'facture'     => ['services', 'fa-file-invoice-euro', 'agenda'],
        'systeme'     => ['systeme', 'fa-shield-alt', 'notes'],
    ];

    private const IMPORTANCE = ['basse', 'normale', 'haute', 'urgente'];
    /** Fenêtre de regroupement d'une rafale (heures). */
    private const GROUP_WINDOW_H = 48;

    private PDO $pdo;
    private int $etab;
    /** @var array<string,array<string,array<string,bool>>> cache des préférences par "type:id" */
    private array $prefCache = [];

    public function __construct(PDO $pdo, ?int $etab = null)
    {
        $this->pdo = $pdo;
        $this->etab = $etab ?? (int) \API\Core\EstablishmentContext::id();
    }

    public static function make(): self
    {
        return new self(getPDO());
    }

    /** Ramène un type historique (« nouvelle_note », « signalement_urgent »…) au catalogue. */
    public static function alias(string $type): string
    {
        $t = strtolower($type);
        if (isset(self::TYPES[$t])) { return $t; }
        $map = [
            'nouvelle_note' => 'note', 'notes' => 'note', 'grade' => 'note',
            'devoirs' => 'devoir', 'cahier' => 'devoir', 'cahierdetextes' => 'devoir',
            'absences' => 'absence', 'retards' => 'retard', 'incident' => 'discipline', 'sanction' => 'discipline',
            'signalement_urgent' => 'signalement', 'messagerie' => 'message', 'nouveau_message' => 'message',
            'annonces' => 'annonce', 'agenda' => 'evenement', 'event' => 'evenement',
            'transport' => 'service', 'garderie' => 'service', 'cantine' => 'service', 'bibliotheque' => 'service',
            'facturation' => 'facture', 'paiement' => 'facture', 'general' => 'systeme', 'securite' => 'systeme',
        ];
        if (isset($map[$t])) { return $map[$t]; }
        foreach ($map as $k => $v) { if (str_starts_with($t, $k)) { return $v; } }
        return 'systeme';
    }

    public static function meta(string $type): array
    {
        $t = self::alias($type);
        [$cat, $icon, $color] = self::TYPES[$t];
        return ['type' => $t, 'category' => $cat, 'icon' => $icon, 'color' => $color];
    }

    private static function importance(string $imp): string
    {
        $imp = strtolower($imp);
        if (in_array($imp, self::IMPORTANCE, true)) { return $imp; }
        return in_array($imp, ['importante', 'important', 'high'], true) ? 'haute'
            : (in_array($imp, ['critique', 'urgent'], true) ? 'urgente' : 'normale');
    }

    /** Liens historiques relatifs à un module (« /transports/lignes.php ») → chemin absolu applicatif. */
    private static function link(?string $link): ?string
    {
        if ($link === null || $link === '') { return null; }
        if (preg_match('#^https?://#i', $link)) { return $link; }
        $l = '/' . ltrim($link, '/');
        if (!preg_match('#^/(modules|admin|accueil|login|parametres|rgpd|demo)/#', $l)) {
            $l = '/modules' . $l;
        }
        return $l;
    }

    // ───────────────────────────── Envoi ─────────────────────────────

    /**
     * @param array<int,array{id:int|string,type:string}> $recipients
     * @param array{importance?:string,source_type?:string,source_id?:int,group_key?:string,group_title?:string,actor?:string,exclude?:array} $opt
     * @return int nombre de destinataires effectivement notifiés
     */
    public function send(array $recipients, string $type, string $title, ?string $body = null, ?string $link = null, array $opt = []): int
    {
        $meta = self::meta($type);
        $importance = self::importance((string) ($opt['importance'] ?? 'normale'));
        $link = self::link($link);
        $groupKey = isset($opt['group_key']) ? substr((string) $opt['group_key'], 0, 120) : null;
        // Regroupement par défaut des rafales prévisibles : notes et devoirs du jour.
        if ($groupKey === null && in_array($meta['type'], ['note', 'devoir'], true)) {
            $groupKey = $meta['type'] . ':' . date('Y-m-d');
            $opt['group_title'] ??= $meta['type'] === 'note' ? '%d nouvelles notes' : '%d nouveaux devoirs au cahier de textes';
        }
        $title = mb_substr(trim($title), 0, 250);
        $body = $body !== null ? mb_substr(trim($body), 0, 600) : null;

        // Dédoublonnage + exclusions (ne jamais se notifier soi-même)
        $seen = [];
        foreach ((array) ($opt['exclude'] ?? []) as $x) { $seen[(string) $x['type'] . ':' . (int) $x['id']] = true; }
        $targets = [];
        foreach ($recipients as $r) {
            $id = (int) ($r['id'] ?? 0); $ut = (string) ($r['type'] ?? '');
            if ($id <= 0 || $ut === '' || isset($seen[$ut . ':' . $id])) { continue; }
            $seen[$ut . ':' . $id] = true;
            if (!$this->wants($id, $ut, $meta['category'], 'web') && $importance !== 'urgente') { continue; }
            $targets[] = ['user_id' => $id, 'user_type' => $ut];
        }
        if (!$targets) { return 0; }

        $ins = $this->pdo->prepare(
            'INSERT INTO notifications_globales (etablissement_id, user_id, user_type, type, titre, contenu, lien, icone, importance,
                source_type, source_id, group_key, group_count, acteur)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?)'
        );
        $find = $groupKey ? $this->pdo->prepare(
            'SELECT id, group_count FROM notifications_globales
              WHERE user_id = ? AND user_type = ? AND etablissement_id = ? AND group_key = ? AND lu = 0
                AND date_creation > DATE_SUB(NOW(), INTERVAL ' . self::GROUP_WINDOW_H . ' HOUR)
              ORDER BY id DESC LIMIT 1'
        ) : null;
        $upd = $groupKey ? $this->pdo->prepare(
            'UPDATE notifications_globales SET titre = ?, contenu = ?, lien = ?, importance = ?, group_count = group_count + 1,
                    acteur = ?, date_creation = NOW() WHERE id = ?'
        ) : null;

        $actor = isset($opt['actor']) ? mb_substr((string) $opt['actor'], 0, 120) : null;
        foreach ($targets as $t) {
            $row = false;
            if ($find) { $find->execute([$t['user_id'], $t['user_type'], $this->etab, $groupKey]); $row = $find->fetch(PDO::FETCH_ASSOC); }
            if ($row) {
                $n = (int) $row['group_count'] + 1;
                $gTitle = isset($opt['group_title']) ? sprintf((string) $opt['group_title'], $n) : $title;
                $upd->execute([mb_substr($gTitle, 0, 250), $body, $link, $importance, $actor, (int) $row['id']]);
            } else {
                $ins->execute([$this->etab, $t['user_id'], $t['user_type'], $meta['type'], $title, $body, $link, $meta['icon'], $importance,
                    $opt['source_type'] ?? null, isset($opt['source_id']) ? (int) $opt['source_id'] : null, $groupKey, $actor]);
            }
        }

        // Poussée temps réel (best-effort : la notification existe déjà en base).
        try {
            if (class_exists('\API\Core\WebSocket')) {
                \API\Core\WebSocket::dispatch('/notify/notification', [
                    'etablissement_id' => $this->etab,
                    'targets' => $targets,
                    'kind' => $meta['type'], 'category' => $meta['category'], 'icon' => $meta['icon'],
                    'title' => $title, 'body' => $body, 'link' => $link, 'importance' => $importance,
                ]);
            }
        } catch (\Throwable $e) { /* le repli client (lecture périodique) prend le relais */ }

        return count($targets);
    }

    // ─────────────────────────── Lecture ────────────────────────────

    /** @return array{items:array,next:?int} */
    public function list(int $userId, string $userType, array $f = [], int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $sql = 'SELECT id, type, titre, contenu, lien, icone, importance, lu, date_creation, group_count, acteur, source_type
                  FROM notifications_globales WHERE user_id = ? AND user_type = ? AND etablissement_id = ?';
        $p = [$userId, $userType, $this->etab];
        if (!empty($f['unread'])) { $sql .= ' AND lu = 0'; }
        if (!empty($f['before'])) { $sql .= ' AND id < ?'; $p[] = (int) $f['before']; }
        if (!empty($f['category']) && isset(self::CATEGORIES[$f['category']])) {
            $types = array_keys(array_filter(self::TYPES, fn($m) => $m[0] === $f['category']));
            $sql .= ' AND type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
            array_push($p, ...$types);
        }
        $sql .= ' ORDER BY date_creation DESC, id DESC LIMIT ' . ($limit + 1);
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $next = null;
        if (count($rows) > $limit) { array_pop($rows); $next = (int) end($rows)['id']; }
        $items = array_map(function ($r) {
            $m = self::meta((string) $r['type']);
            return [
                'id' => (int) $r['id'], 'type' => $m['type'], 'category' => $m['category'], 'icon' => $m['icon'], 'color' => $m['color'],
                'title' => (string) $r['titre'], 'body' => (string) ($r['contenu'] ?? ''), 'link' => self::link($r['lien']),
                'importance' => (string) $r['importance'], 'read' => (bool) $r['lu'], 'count' => max(1, (int) ($r['group_count'] ?? 1)),
                'actor' => $r['acteur'] ?? null, 'at' => (string) $r['date_creation'],
            ];
        }, $rows);
        return ['items' => $items, 'next' => $next];
    }

    public function unreadCount(int $userId, string $userType): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM notifications_globales WHERE user_id = ? AND user_type = ? AND etablissement_id = ? AND lu = 0');
        $st->execute([$userId, $userType, $this->etab]);
        return (int) $st->fetchColumn();
    }

    /** Empreinte légère pour la lecture périodique (évite de renvoyer la liste si rien n'a changé). */
    public function fingerprint(int $userId, string $userType): string
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) c, COALESCE(MAX(id),0) m, COALESCE(MAX(date_creation),0) d, SUM(lu = 0) u
                                     FROM notifications_globales WHERE user_id = ? AND user_type = ? AND etablissement_id = ?');
        $st->execute([$userId, $userType, $this->etab]);
        return substr(sha1(implode('|', (array) $st->fetch(PDO::FETCH_NUM))), 0, 16);
    }

    /** @param int[]|null $ids null = tout */
    public function markRead(int $userId, string $userType, ?array $ids = null): int
    {
        $sql = 'UPDATE notifications_globales SET lu = 1, date_lecture = NOW() WHERE user_id = ? AND user_type = ? AND etablissement_id = ? AND lu = 0';
        $p = [$userId, $userType, $this->etab];
        if ($ids !== null) {
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if (!$ids) { return 0; }
            $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($p, ...$ids);
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        return $st->rowCount();
    }

    /** Marque lues les notifications liées à une ressource consultée (ex. ouvrir la conversation). */
    public function markReadByGroup(int $userId, string $userType, string $groupKey): int
    {
        $st = $this->pdo->prepare('UPDATE notifications_globales SET lu = 1, date_lecture = NOW()
            WHERE user_id = ? AND user_type = ? AND etablissement_id = ? AND group_key = ? AND lu = 0');
        $st->execute([$userId, $userType, $this->etab, $groupKey]);
        return $st->rowCount();
    }

    public function delete(int $userId, string $userType, ?int $id = null, bool $onlyRead = false): int
    {
        $sql = 'DELETE FROM notifications_globales WHERE user_id = ? AND user_type = ? AND etablissement_id = ?';
        $p = [$userId, $userType, $this->etab];
        if ($id !== null) { $sql .= ' AND id = ?'; $p[] = $id; }
        if ($onlyRead) { $sql .= ' AND lu = 1'; }
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        return $st->rowCount();
    }

    // ───────────────────────── Préférences ──────────────────────────

    /** Défauts : tout dans l'application ; e-mail récapitulatif pour vie scolaire et messages ; push messages + vie scolaire. */
    public static function defaults(): array
    {
        $d = [];
        foreach (self::CATEGORIES as $c => $_) {
            $d[$c] = ['web' => true, 'email' => in_array($c, ['vie_scolaire', 'messages'], true), 'push' => in_array($c, ['vie_scolaire', 'messages'], true)];
        }
        return $d;
    }

    /** @return array<string,array{web:bool,email:bool,push:bool}> */
    public function preferences(int $userId, string $userType): array
    {
        $k = $userType . ':' . $userId;
        if (isset($this->prefCache[$k])) { return $this->prefCache[$k]; }
        $prefs = self::defaults();
        try {
            $st = $this->pdo->prepare('SELECT type_notification, canal_web, canal_email, canal_push, actif FROM notification_preferences
                                        WHERE user_id = ? AND user_type = ? AND etablissement_id = ?');
            $st->execute([$userId, $userType, $this->etab]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $c = (string) $r['type_notification'];
                if (!isset($prefs[$c])) { continue; }
                $on = (bool) $r['actif'];
                $prefs[$c] = ['web' => $on && (bool) $r['canal_web'], 'email' => $on && (bool) $r['canal_email'], 'push' => $on && (bool) $r['canal_push']];
            }
        } catch (\Throwable $e) {}
        return $this->prefCache[$k] = $prefs;
    }

    public function wants(int $userId, string $userType, string $category, string $channel): bool
    {
        return (bool) ($this->preferences($userId, $userType)[$category][$channel] ?? true);
    }

    /** @param array<string,array{web?:bool,email?:bool,push?:bool}> $prefs */
    public function savePreferences(int $userId, string $userType, array $prefs): void
    {
        $del = $this->pdo->prepare('DELETE FROM notification_preferences WHERE user_id = ? AND user_type = ? AND etablissement_id = ? AND type_notification = ?');
        $ins = $this->pdo->prepare('INSERT INTO notification_preferences (etablissement_id, user_id, user_type, type_notification, canal_web, canal_email, canal_push, actif)
                                    VALUES (?,?,?,?,?,?,?,1)');
        foreach (self::CATEGORIES as $c => $_) {
            $p = $prefs[$c] ?? [];
            $del->execute([$userId, $userType, $this->etab, $c]);
            $ins->execute([$this->etab, $userId, $userType, $c, !empty($p['web']) ? 1 : 0, !empty($p['email']) ? 1 : 0, !empty($p['push']) ? 1 : 0]);
        }
        unset($this->prefCache[$userType . ':' . $userId]);
    }

    /**
     * Récapitulatif quotidien par e-mail : notifications NON LUES des dernières 36 h, des
     * catégories pour lesquelles l'utilisateur a choisi l'e-mail. Chaque notification n'est
     * récapitulée qu'une fois (digest_sent). @return int e-mails envoyés
     */
    public function sendDigests(int $max = 500): int
    {
        $st = $this->pdo->prepare('SELECT id, user_id, user_type, type, titre, contenu, lien, group_count FROM notifications_globales
            WHERE etablissement_id = ? AND lu = 0 AND digest_sent = 0 AND date_creation > DATE_SUB(NOW(), INTERVAL 36 HOUR)
            ORDER BY user_type, user_id, date_creation DESC LIMIT 20000');
        $st->execute([$this->etab]);
        $byUser = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $byUser[$r['user_type'] . ':' . $r['user_id']][] = $r; }
        $tables = ['eleve' => 'eleves', 'parent' => 'parents', 'professeur' => 'professeurs', 'administrateur' => 'administrateurs', 'vie_scolaire' => 'vie_scolaire'];
        $mailer = class_exists('\API\Services\EmailService') ? new \API\Services\EmailService($this->pdo) : null;
        $base = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        $mark = $this->pdo->prepare('UPDATE notifications_globales SET digest_sent = 1 WHERE id = ?');
        $sent = 0;
        foreach ($byUser as $k => $rows) {
            if ($sent >= $max) { break; }
            [$ut, $uid] = explode(':', $k);
            $uid = (int) $uid;
            if (!isset($tables[$ut])) { continue; }
            $keep = array_values(array_filter($rows, fn($r) => $this->wants($uid, $ut, self::meta((string) $r['type'])['category'], 'email')));
            foreach ($rows as $r) { $mark->execute([(int) $r['id']]); }
            if (!$keep || !$mailer) { continue; }
            $u = $this->pdo->prepare("SELECT prenom, mail FROM `{$tables[$ut]}` WHERE id = ? AND etablissement_id = ?");
            $u->execute([$uid, $this->etab]);
            $user = $u->fetch(PDO::FETCH_ASSOC);
            if (!$user || !filter_var((string) $user['mail'], FILTER_VALIDATE_EMAIL)) { continue; }
            $cats = [];
            foreach ($keep as $r) { $cats[self::meta((string) $r['type'])['category']][] = $r; }
            $html = '<p>Bonjour ' . htmlspecialchars((string) $user['prenom']) . ',</p><p>Voici ce qui s\'est passé sur Fronote :</p>';
            $txt = 'Bonjour ' . $user['prenom'] . ",\n\nVoici ce qui s'est passé sur Fronote :\n";
            foreach ($cats as $c => $list) {
                $label = self::CATEGORIES[$c][0] ?? $c;
                $html .= '<h3 style="margin:18px 0 6px;font-size:15px">' . htmlspecialchars($label) . '</h3><ul style="padding-left:18px;margin:0">';
                $txt .= "\n" . $label . "\n";
                foreach (array_slice($list, 0, 10) as $r) {
                    $t = (string) $r['titre'] . ((int) $r['group_count'] > 1 ? ' (×' . (int) $r['group_count'] . ')' : '');
                    $href = $r['lien'] ? $base . self::link((string) $r['lien']) : '';
                    $html .= '<li style="margin:4px 0">' . ($href ? '<a href="' . htmlspecialchars($href) . '">' . htmlspecialchars($t) . '</a>' : htmlspecialchars($t))
                        . ($r['contenu'] ? '<br><span style="color:#64748b">' . htmlspecialchars(mb_substr((string) $r['contenu'], 0, 160)) . '</span>' : '') . '</li>';
                    $txt .= '- ' . $t . "\n";
                }
                $html .= '</ul>';
            }
            $html .= '<p style="color:#64748b;font-size:13px;margin-top:22px">Vous recevez ce récapitulatif selon vos préférences de notification'
                . ($base ? ' (<a href="' . htmlspecialchars($base . '/modules/notifications/preferences.php') . '">les modifier</a>)' : '') . '.</p>';
            try {
                $res = $mailer->send((string) $user['mail'], 'Votre récapitulatif Fronote — ' . count($keep) . ' nouveauté' . (count($keep) > 1 ? 's' : ''), $html, $txt);
                if (!empty($res['success'])) { $sent++; }
            } catch (\Throwable $e) { error_log('[notifications] récapitulatif : ' . $e->getMessage()); }
        }
        return $sent;
    }

    /** Purge : lues de plus de $readDays jours, toutes de plus de $allDays jours. */
    public function purge(int $readDays = 60, int $allDays = 180): int
    {
        $st = $this->pdo->prepare('DELETE FROM notifications_globales WHERE etablissement_id = ?
             AND ((lu = 1 AND date_creation < DATE_SUB(NOW(), INTERVAL ? DAY)) OR date_creation < DATE_SUB(NOW(), INTERVAL ? DAY))');
        $st->execute([$this->etab, $readDays, $allDays]);
        return $st->rowCount();
    }
}
