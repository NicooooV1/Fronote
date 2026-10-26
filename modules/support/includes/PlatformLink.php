<?php
declare(strict_types=1);

/**
 * Liaison avec la PLATEFORME Fronote (platform.nicov1.fr) pour les demandes escaladées.
 *
 * Seule exception au principe « une instance n'initie jamais vers la plateforme » : l'équipe de
 * direction transmet une demande. Tout passe par une FILE D'ATTENTE en base :
 *   - escalader() enregistre la demande (envoi = a_envoyer) puis tente un envoi immédiat ;
 *   - synchroniser() (tâche planifiée toutes les 5 min, et à l'ouverture des pages du support)
 *     envoie ce qui attend, avec un nouvel essai progressif si la plateforme est injoignable,
 *     puis relève les réponses et les statuts.
 *
 * Identité de l'instance : jeton d'installation (.fronote-license, installations distribuées)
 * ou clé d'API émise par la plateforme (PLATFORM_API_KEY dans .env). Sans l'un ni l'autre,
 * l'escalade est indisponible (les demandes internes fonctionnent toujours).
 */
final class PlatformLink
{
    private const TIMEOUT = 6;

    private PDO $pdo;
    private string $url;
    private array $auth = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->url = rtrim((string) (self::env('PLATFORM_URL') ?: 'https://platform.nicov1.fr'), '/');
        $key = (string) self::env('PLATFORM_API_KEY');
        if ($key !== '') {
            $this->auth = ['X-Fronote-Key: ' . $key];
        } else {
            $tok = self::licenseToken();
            if ($tok !== '') { $this->auth = ['X-Fronote-Install: ' . $tok]; }
        }
    }

    private static function env(string $k): string
    {
        $v = getenv($k);
        if ($v === false) { $v = $_ENV[$k] ?? $_SERVER[$k] ?? ''; }
        return trim((string) $v);
    }

    private static function licenseToken(): string
    {
        $f = dirname(__DIR__, 3) . '/.fronote-license';
        if (!is_readable($f)) { return ''; }
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with($line, 'INSTALL_TOKEN=')) { return trim(substr($line, 14)); }
        }
        return '';
    }

    /** L'instance peut-elle joindre la plateforme (identifiants présents) ? */
    public function disponible(): bool { return $this->auth !== []; }

    public function url(): string { return $this->url; }

    // ── Escalade ────────────────────────────────────────────────────────────────

    /**
     * Enregistre une demande pour la plateforme (et tente l'envoi tout de suite).
     * @return int id de l'escalade
     */
    public function escalader(array $d): int
    {
        $etab = class_exists('\\API\\Core\\EstablishmentContext') ? (int) \API\Core\EstablishmentContext::id() : 1;
        $ref = 'E' . date('ymd') . '-' . bin2hex(random_bytes(5));
        $this->pdo->beginTransaction();
        $this->pdo->prepare('INSERT INTO support_escalations (etablissement_id, ticket_id, ref, sujet, categorie, priorite, cree_par_id, cree_par_type, cree_par_nom)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$etab, $d['ticket_id'] ?? null, $ref, mb_substr((string) $d['sujet'], 0, 255), (string) ($d['categorie'] ?? 'technique'),
                in_array($d['priorite'] ?? '', ['basse', 'normale', 'haute', 'urgente'], true) ? $d['priorite'] : 'normale',
                (int) $d['user_id'], (string) $d['user_type'], mb_substr((string) $d['user_nom'], 0, 160)]);
        $id = (int) $this->pdo->lastInsertId();
        // Le message d'origine fait partie de la création (envoyé avec la demande).
        $this->pdo->prepare("INSERT INTO support_escalation_messages (escalation_id, sens, auteur, contenu, a_envoyer) VALUES (?, 'sortant', ?, ?, 0)")
            ->execute([$id, mb_substr((string) $d['user_nom'], 0, 160), (string) $d['message']]);
        $this->pdo->commit();
        $this->synchroniser(true);
        return $id;
    }

    /** Ajoute un message à une demande déjà escaladée (mis en file, puis envoyé). */
    public function repondre(int $escId, string $auteur, string $contenu, bool $cloturer = false): void
    {
        $this->pdo->prepare("INSERT INTO support_escalation_messages (escalation_id, sens, auteur, contenu, a_envoyer) VALUES (?, 'sortant', ?, ?, 1)")
            ->execute([$escId, mb_substr($auteur, 0, 160), $cloturer && $contenu === '' ? '(demande close par l\'établissement)' : $contenu]);
        if ($cloturer) {
            $this->pdo->prepare("UPDATE support_escalations SET statut = 'ferme_demande' WHERE id = ?")->execute([$escId]);
        }
        $this->synchroniser(true);
    }

    // ── Synchronisation ─────────────────────────────────────────────────────────

    /**
     * Envoie la file et relève les réponses. $vite = appel depuis une page (une seule tentative
     * courte, sans relève si la dernière date de moins de 2 min).
     * @return array{envoyees:int, messages:int, recus:int, erreur:?string}
     */
    public function synchroniser(bool $vite = false): array
    {
        $out = ['envoyees' => 0, 'messages' => 0, 'recus' => 0, 'erreur' => null];
        if (!$this->disponible()) { $out['erreur'] = 'non_configuree'; return $out; }
        try {
            // 1) Demandes à créer
            $todo = $this->pdo->query("SELECT * FROM support_escalations WHERE envoi = 'a_envoyer' AND (prochain_essai IS NULL OR prochain_essai <= NOW()) ORDER BY id LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($todo as $e) {
                $first = $this->pdo->prepare("SELECT contenu FROM support_escalation_messages WHERE escalation_id = ? AND sens = 'sortant' ORDER BY id LIMIT 1");
                $first->execute([(int) $e['id']]);
                [$code, $r] = $this->call('POST', '/api/instance/tickets', [
                    'ref' => $e['ref'], 'subject' => $e['sujet'], 'category' => $e['categorie'], 'priority' => $e['priorite'],
                    'body' => (string) $first->fetchColumn(), 'sent_by' => $e['cree_par_nom'],
                    'context' => ['app_version' => self::version(), 'ticket_interne' => $e['ticket_id'] ? '#' . $e['ticket_id'] : null],
                ]);
                if ($code === 200 || $code === 201) {
                    $this->pdo->prepare("UPDATE support_escalations SET envoi = 'envoye', statut = ?, derniere_erreur = NULL, tentatives = tentatives + 1 WHERE id = ?")
                        ->execute([(string) ($r['status'] ?? 'nouveau'), (int) $e['id']]);
                    $out['envoyees']++;
                } else {
                    $this->echec((int) $e['id'], (int) $e['tentatives'], $code, $r);
                    if ($code === 0) { $out['erreur'] = 'injoignable'; return $out; } // inutile d'insister ce tour-ci
                }
            }
            // 2) Messages en attente sur des demandes déjà créées
            $msgs = $this->pdo->query("SELECT m.*, e.ref, e.statut FROM support_escalation_messages m JOIN support_escalations e ON e.id = m.escalation_id
                WHERE m.a_envoyer = 1 AND e.envoi = 'envoye' ORDER BY m.id LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($msgs as $m) {
                [$code, $r] = $this->call('POST', '/api/instance/tickets/' . rawurlencode($m['ref']) . '/messages', [
                    'body' => $m['contenu'] === '(demande close par l\'établissement)' ? '' : $m['contenu'],
                    'author' => $m['auteur'], 'close' => $m['statut'] === 'ferme_demande',
                ]);
                if ($code !== 200) { $out['erreur'] = $code === 0 ? 'injoignable' : 'refus_' . $code; break; }
                $this->pdo->prepare('UPDATE support_escalation_messages SET a_envoyer = 0 WHERE id = ?')->execute([(int) $m['id']]);
                $this->pdo->prepare('UPDATE support_escalations SET statut = ? WHERE id = ?')->execute([(string) ($r['status'] ?? $m['statut']), (int) $m['escalation_id']]);
                $out['messages']++;
            }
            // 3) Relève des réponses (au plus toutes les 2 min depuis une page)
            if ($vite && (int) $this->etat('derniere_releve', '0') > time() - 120) { return $out; }
            $hasSent = (int) $this->pdo->query("SELECT COUNT(*) FROM support_escalations WHERE envoi = 'envoye'")->fetchColumn();
            if ($hasSent === 0) { return $out; }
            [$code, $r] = $this->call('GET', '/api/instance/updates?since=' . (int) $this->etat('curseur', '0'));
            $this->setEtat('derniere_releve', (string) time());
            if ($code !== 200) { $out['erreur'] = $code === 0 ? 'injoignable' : 'refus_' . $code; return $out; }
            $byRef = $this->pdo->prepare('SELECT id, cree_par_id, cree_par_type, sujet FROM support_escalations WHERE ref = ?');
            foreach (($r['messages'] ?? []) as $pm) {
                $byRef->execute([(string) $pm['ref']]);
                $esc = $byRef->fetch(PDO::FETCH_ASSOC);
                if (!$esc) { continue; }
                $ins = $this->pdo->prepare("INSERT IGNORE INTO support_escalation_messages (escalation_id, sens, auteur, contenu, plateforme_id, date_creation) VALUES (?, 'entrant', ?, ?, ?, ?)");
                $ins->execute([(int) $esc['id'], 'Support Fronote — ' . mb_substr((string) $pm['author'], 0, 120), (string) $pm['body'], (int) $pm['id'], (string) $pm['created_at']]);
                if ($ins->rowCount() > 0) {
                    $out['recus']++;
                    $this->notifier((int) $esc['cree_par_id'], (string) $esc['cree_par_type'], 'Réponse de la plateforme Fronote', (string) $esc['sujet'], 'modules/support/plateforme_demande.php?id=' . (int) $esc['id']);
                }
            }
            foreach (($r['statuses'] ?? []) as $s) {
                $this->pdo->prepare("UPDATE support_escalations SET statut = ? WHERE ref = ? AND statut <> 'ferme_demande'")->execute([(string) $s['status'], (string) $s['ref']]);
            }
            $this->setEtat('curseur', (string) max((int) $this->etat('curseur', '0'), (int) ($r['cursor'] ?? 0)));
        } catch (\Throwable $e) {
            error_log('[support/plateforme] ' . $e->getMessage());
            $out['erreur'] = 'exception';
        }
        return $out;
    }

    private function echec(int $id, int $tentatives, int $code, array $r): void
    {
        // Refus définitif (identifiants inconnus, licence révoquée…) : on arrête de réessayer.
        $definitif = in_array($code, [400, 401, 403], true);
        $delai = min(360, 2 ** min(8, $tentatives)); // 1, 2, 4… jusqu'à 6 h
        $msg = match (true) {
            $code === 0 => 'Plateforme injoignable — nouvel essai automatique.',
            $code === 401 => 'Instance inconnue de la plateforme (identifiants refusés).',
            $code === 403 => 'Accès refusé par la plateforme (' . (string) ($r['error'] ?? '') . ').',
            $code === 429 => 'Trop de demandes envoyées : nouvel essai plus tard.',
            default => 'Erreur de la plateforme (' . $code . ') — nouvel essai automatique.',
        };
        $this->pdo->prepare('UPDATE support_escalations SET tentatives = tentatives + 1, derniere_erreur = ?, envoi = ?, prochain_essai = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?')
            ->execute([$msg, $definitif ? 'refuse' : 'a_envoyer', $delai, $id]);
    }

    /** @return array{0:int,1:array} */
    private function call(string $method, string $path, ?array $body = null): array
    {
        if (!function_exists('curl_init')) { return [0, []]; }
        $ch = curl_init($this->url . $path);
        $headers = array_merge($this->auth, ['Accept: application/json']);
        if ($body !== null) { $headers[] = 'Content-Type: application/json'; }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => self::TIMEOUT, CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_POSTFIELDS => $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        $raw = curl_exec($ch);
        $code = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        return [$code, is_array($j) ? $j : []];
    }

    private function etat(string $k, string $def): string
    {
        $s = $this->pdo->prepare('SELECT v FROM support_platform_state WHERE k = ?');
        $s->execute([$k]);
        $v = $s->fetchColumn();
        return $v === false ? $def : (string) $v;
    }

    private function setEtat(string $k, string $v): void
    {
        $this->pdo->prepare('INSERT INTO support_platform_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]);
    }

    private function notifier(int $userId, string $userType, string $titre, string $contenu, string $lien): void
    {
        try {
            $this->pdo->prepare("INSERT INTO notifications_globales (user_id, user_type, type, titre, contenu, lien, icone, importance, source_type)
                VALUES (?, ?, 'support', ?, ?, ?, 'fas fa-life-ring', 'normale', 'support')")->execute([$userId, $userType, $titre, mb_substr($contenu, 0, 140), $lien]);
        } catch (\Throwable $e) { /* notifications optionnelles */ }
    }

    private static function version(): string
    {
        $j = json_decode((string) @file_get_contents(dirname(__DIR__, 3) . '/version.json'), true);
        return (string) ($j['version'] ?? '');
    }

    // ── Lecture ─────────────────────────────────────────────────────────────────

    public function liste(): array
    {
        $etab = class_exists('\\API\\Core\\EstablishmentContext') ? (int) \API\Core\EstablishmentContext::id() : 1;
        $s = $this->pdo->prepare("SELECT e.*, (SELECT COUNT(*) FROM support_escalation_messages m WHERE m.escalation_id = e.id AND m.sens = 'entrant') AS nb_reponses,
                (SELECT MAX(m.date_creation) FROM support_escalation_messages m WHERE m.escalation_id = e.id) AS derniere_activite
            FROM support_escalations e WHERE e.etablissement_id = ? ORDER BY e.date_modification DESC LIMIT 200");
        $s->execute([$etab]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id): ?array
    {
        $etab = class_exists('\\API\\Core\\EstablishmentContext') ? (int) \API\Core\EstablishmentContext::id() : 1;
        $s = $this->pdo->prepare('SELECT * FROM support_escalations WHERE id = ? AND etablissement_id = ?');
        $s->execute([$id, $etab]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function messages(int $id): array
    {
        $s = $this->pdo->prepare('SELECT * FROM support_escalation_messages WHERE escalation_id = ? ORDER BY date_creation, id');
        $s->execute([$id]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function pourTicket(int $ticketId): array
    {
        $s = $this->pdo->prepare('SELECT id, ref, statut, envoi FROM support_escalations WHERE ticket_id = ? ORDER BY id DESC');
        $s->execute([$ticketId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public const STATUTS = [
        'en_attente' => ['En attente d\'envoi', 'warning'],
        'nouveau' => ['Reçue par la plateforme', 'info'],
        'en_cours' => ['En cours de traitement', 'info'],
        'attente_etablissement' => ['Réponse reçue — à vous', 'warning'],
        'resolu' => ['Résolue', 'success'],
        'ferme' => ['Fermée', 'secondary'],
        'ferme_demande' => ['Close par l\'établissement', 'secondary'],
    ];
}
