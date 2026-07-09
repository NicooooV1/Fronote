<?php
/**
 * M34 – Support & Aide — Service
 *
 * Les tickets de support portent une colonne `etablissement_id` (schéma
 * tickets_support) : les lectures admin sont scopées sur l'établissement courant
 * via EstablishmentContext, et les créations injectent l'etablissement_id.
 */
class SupportService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    private function etabId(): ?int
    {
        try {
            return \API\Core\EstablishmentContext::id();
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── Tickets ──

    public function creerTicket(int $userId, string $userType, string $sujet, string $description, string $categorie = 'technique', string $priorite = 'normale'): int
    {
        $etabId = $this->etabId();
        $stmt = $this->pdo->prepare("INSERT INTO tickets_support (etablissement_id, user_id, user_type, sujet, description, categorie, priorite) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$etabId, $userId, $userType, $sujet, $description, $categorie, $priorite]);
        $ticketId = (int)$this->pdo->lastInsertId();
        // Notifier le staff de l'établissement (best-effort).
        $this->notifierStaff($etabId, $sujet, $ticketId);
        return $ticketId;
    }

    public function getTicketsUser(int $userId, string $userType): array
    {
        $etabId = $this->etabId();
        if ($etabId === null) return [];
        $stmt = $this->pdo->prepare("SELECT * FROM tickets_support WHERE user_id = ? AND user_type = ? AND etablissement_id = ? ORDER BY date_creation DESC");
        $stmt->execute([$userId, $userType, $etabId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTousTickets(array $filters = []): array
    {
        $etabId = $this->etabId();
        if ($etabId === null) return [];
        $sql = "SELECT t.*, COALESCE(
            (SELECT CONCAT(e.prenom, ' ', e.nom) FROM eleves e WHERE e.id = t.user_id AND t.user_type = 'eleve'),
            (SELECT CONCAT(p.prenom, ' ', p.nom) FROM parents p WHERE p.id = t.user_id AND t.user_type = 'parent'),
            (SELECT CONCAT(pr.prenom, ' ', pr.nom) FROM professeurs pr WHERE pr.id = t.user_id AND t.user_type = 'professeur'),
            (SELECT CONCAT(v.prenom, ' ', v.nom) FROM vie_scolaire v WHERE v.id = t.user_id AND t.user_type = 'vie_scolaire'),
            (SELECT CONCAT(a.prenom, ' ', a.nom) FROM administrateurs a WHERE a.id = t.user_id AND t.user_type = 'administrateur')
        ) AS nom_utilisateur FROM tickets_support t WHERE t.etablissement_id = ?";
        $params = [$etabId];
        if (!empty($filters['statut'])) { $sql .= " AND t.statut = ?"; $params[] = $filters['statut']; }
        if (!empty($filters['categorie'])) { $sql .= " AND t.categorie = ?"; $params[] = $filters['categorie']; }
        if (!empty($filters['priorite'])) { $sql .= " AND t.priorite = ?"; $params[] = $filters['priorite']; }
        $sql .= " ORDER BY FIELD(t.priorite, 'urgente', 'haute', 'normale', 'basse'), t.date_creation DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTicket(int $id): ?array
    {
        $etabId = $this->etabId();
        if ($etabId === null) return null;
        $stmt = $this->pdo->prepare("SELECT * FROM tickets_support WHERE id = ? AND etablissement_id = ?");
        $stmt->execute([$id, $etabId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function repondre(int $id, string $reponse, int $adminId, string $adminType = 'administrateur'): bool
    {
        $ticket = $this->getTicket($id);
        if (!$ticket) return false;
        // Ajoute la réponse au fil de discussion + marque la 1ère réponse.
        $this->addMessage($id, $adminId, $adminType, $reponse, true);
        $this->recordFirstResponse($id);
        // On NE force PLUS 'resolu' : une réponse passe un ticket 'ouvert' à
        // 'en_cours' (le staff clôt explicitement via le statut). Conserve
        // `reponse` = dernière réponse (rétro-compat export/affichage legacy).
        $this->pdo->prepare("UPDATE tickets_support SET reponse = ?, traite_par = ?, date_reponse = NOW(), statut = CASE WHEN statut = 'ouvert' THEN 'en_cours' ELSE statut END WHERE id = ? AND etablissement_id = ?")
            ->execute([$reponse, $adminId, $id, (int)$ticket['etablissement_id']]);
        $this->notifier((int) $ticket['user_id'], $ticket['user_type'], 'Réponse à votre ticket #' . $id,
            mb_substr($reponse, 0, 140), 'modules/support/voir_ticket.php?id=' . $id);
        return true;
    }

    public function changerStatut(int $id, string $statut): bool
    {
        $ok = $this->pdo->prepare("UPDATE tickets_support SET statut = ? WHERE id = ? AND etablissement_id = ?")->execute([$statut, $id, $this->etabId()]);
        if ($ok) {
            $t = $this->getTicket($id);
            if ($t) {
                $labels = ['ouvert' => 'Ouvert', 'en_cours' => 'En cours', 'resolu' => 'Résolu', 'ferme' => 'Fermé'];
                $this->notifier((int) $t['user_id'], $t['user_type'], 'Ticket #' . $id . ' : statut mis à jour',
                    'Nouveau statut : ' . ($labels[$statut] ?? $statut), 'modules/support/voir_ticket.php?id=' . $id);
            }
        }
        return $ok;
    }

    // ── Fil de discussion ──

    public function addMessage(int $ticketId, int $auteurId, string $auteurType, string $contenu, bool $isStaff = false): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO support_ticket_messages (ticket_id, auteur_id, auteur_type, contenu, is_staff) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$ticketId, $auteurId, $auteurType, $contenu, $isStaff ? 1 : 0]);
        return (int)$this->pdo->lastInsertId();
    }

    public function getMessages(int $ticketId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM support_ticket_messages WHERE ticket_id = ? ORDER BY date_creation ASC, id ASC");
        $stmt->execute([$ticketId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Le demandeur répond dans son propre ticket (notifie le staff). */
    public function repondreUtilisateur(int $id, int $userId, string $userType, string $contenu): bool
    {
        $ticket = $this->getTicket($id);
        if (!$ticket) return false;
        $this->addMessage($id, $userId, $userType, $contenu, false);
        // Rouvre un ticket résolu si le demandeur relance.
        $this->pdo->prepare("UPDATE tickets_support SET statut = CASE WHEN statut IN ('resolu','ferme') THEN 'en_cours' ELSE statut END, date_modification = NOW() WHERE id = ? AND etablissement_id = ?")->execute([$id, (int)$ticket['etablissement_id']]);
        $this->notifierStaff($this->etabId(), 'Réponse au ticket #' . $id . ' : ' . ($ticket['sujet'] ?? ''), $id);
        return true;
    }

    // ── Notifications (best-effort, table notifications_globales) ──

    private function notifier(int $userId, string $userType, string $titre, string $contenu, string $lien): void
    {
        try {
            $this->pdo->prepare(
                "INSERT INTO notifications_globales (user_id, user_type, type, titre, contenu, lien, icone, importance, source_type, source_id)
                 VALUES (?, ?, 'support', ?, ?, ?, 'fas fa-life-ring', 'normale', 'support', NULL)"
            )->execute([$userId, $userType, $titre, $contenu, $lien]);
        } catch (\Throwable $e) { /* notifications optionnelles */ }
    }

    private function notifierStaff(?int $etabId, string $sujet, int $ticketId): void
    {
        try {
            $lien = 'modules/support/voir_ticket.php?id=' . $ticketId;
            foreach (['administrateurs' => 'administrateur', 'vie_scolaire' => 'vie_scolaire'] as $table => $type) {
                $sql = "SELECT id FROM `{$table}`" . ($etabId !== null ? ' WHERE etablissement_id = ?' : '');
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($etabId !== null ? [$etabId] : []);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sid) {
                    $this->notifier((int) $sid, $type, 'Nouveau ticket de support', mb_substr($sujet, 0, 140), $lien);
                }
            }
        } catch (\Throwable $e) { /* best-effort */ }
    }

    public function getStatsTickets(): array
    {
        $etabId = $this->etabId();
        if ($etabId === null) return ['total' => 0, 'ouverts' => 0, 'en_cours' => 0, 'resolus' => 0, 'urgents' => 0];
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(statut = 'ouvert') as ouverts,
                SUM(statut = 'en_cours') as en_cours,
                SUM(statut = 'resolu') as resolus,
                SUM(priorite = 'urgente' AND statut IN ('ouvert','en_cours')) as urgents
            FROM tickets_support
            WHERE etablissement_id = ?
        ");
        $stmt->execute([$etabId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    // ── FAQ ──

    public function getFaqArticles(?string $categorie = null, ?string $recherche = null): array
    {
        $sql = "SELECT * FROM faq_articles WHERE actif = 1";
        $params = [];
        $etabId = $this->etabId();
        if ($etabId !== null) { $sql .= " AND etablissement_id = ?"; $params[] = $etabId; }
        if ($categorie) { $sql .= " AND categorie = ?"; $params[] = $categorie; }
        if ($recherche) { $sql .= " AND (question LIKE ? OR reponse LIKE ?)"; $params[] = "%$recherche%"; $params[] = "%$recherche%"; }
        $sql .= " ORDER BY ordre, vues DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getFaqArticle(int $id): ?array
    {
        $sql = "SELECT * FROM faq_articles WHERE id = ?";
        $params = [$id];
        $etabId = $this->etabId();
        if ($etabId !== null) { $sql .= " AND etablissement_id = ?"; $params[] = $etabId; }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function incrementerVues(int $id): void
    {
        $this->pdo->prepare("UPDATE faq_articles SET vues = vues + 1 WHERE id = ? AND etablissement_id = ?")->execute([$id, $this->etabId()]);
    }

    public function voterUtile(int $id, bool $utile): void
    {
        $col = $utile ? 'utile_oui' : 'utile_non';
        $this->pdo->prepare("UPDATE faq_articles SET $col = $col + 1 WHERE id = ? AND etablissement_id = ?")->execute([$id, $this->etabId()]);
    }

    public function creerFaq(string $question, string $reponse, string $categorie, int $ordre = 0, ?int $auteurId = null): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO faq_articles (etablissement_id, question, reponse, categorie, ordre, auteur_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$this->etabId(), $question, $reponse, $categorie, $ordre, $auteurId]);
        return (int)$this->pdo->lastInsertId();
    }

    public function modifierFaq(int $id, string $question, string $reponse, string $categorie, int $ordre = 0): bool
    {
        $stmt = $this->pdo->prepare("UPDATE faq_articles SET question = ?, reponse = ?, categorie = ?, ordre = ? WHERE id = ? AND etablissement_id = ?");
        return $stmt->execute([$question, $reponse, $categorie, $ordre, $id, $this->etabId()]);
    }

    public function supprimerFaq(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM faq_articles WHERE id = ? AND etablissement_id = ?");
        return $stmt->execute([$id, $this->etabId()]);
    }

    // ── Helpers statiques ──

    public static function categoriesTicket(): array
    {
        return [
            'technique'     => 'Problème technique',
            'pedagogique'   => 'Question pédagogique',
            'administratif' => 'Question administrative',
            'compte'        => 'Mon compte',
            'autre'         => 'Autre',
        ];
    }

    public static function categoriesFaq(): array
    {
        return [
            'general'        => 'Général',
            'notes'          => 'Notes & Bulletins',
            'absences'       => 'Absences & Retards',
            'messagerie'     => 'Messagerie',
            'emploi_du_temps'=> 'Emploi du temps',
            'devoirs'        => 'Devoirs',
            'bulletins'      => 'Bulletins',
            'compte'         => 'Mon compte',
        ];
    }

    public static function statutBadge(string $statut): string
    {
        $map = [
            'ouvert'   => '<span class="badge badge-info">Ouvert</span>',
            'en_cours' => '<span class="badge badge-warning">En cours</span>',
            'resolu'   => '<span class="badge badge-success">Résolu</span>',
            'ferme'    => '<span class="badge badge-secondary">Fermé</span>',
        ];
        return $map[$statut] ?? $statut;
    }

    public static function prioriteBadge(string $p): string
    {
        $map = [
            'basse'   => '<span class="badge badge-secondary">Basse</span>',
            'normale' => '<span class="badge badge-info">Normale</span>',
            'haute'   => '<span class="badge badge-warning">Haute</span>',
            'urgente' => '<span class="badge badge-danger">Urgente</span>',
        ];
        return $map[$p] ?? $p;
    }

    /* ───── SLA TRACKING ───── */

    /**
     * SLA targets by priority (in hours).
     */
    public static function slaTargets(): array
    {
        return [
            'urgente' => ['first_response' => 1, 'resolution' => 4],
            'haute'   => ['first_response' => 4, 'resolution' => 24],
            'normale' => ['first_response' => 24, 'resolution' => 72],
            'basse'   => ['first_response' => 48, 'resolution' => 168],
        ];
    }

    /**
     * Calculate SLA status for a ticket.
     */
    public function getSlaStatus(array $ticket): array
    {
        $targets = self::slaTargets();
        $priority = $ticket['priorite'] ?? 'normale';
        $target = $targets[$priority] ?? $targets['normale'];

        $createdAt = strtotime($ticket['date_creation']);
        $now = time();
        $firstResponse = !empty($ticket['first_response_at']) ? strtotime($ticket['first_response_at']) : null;
        $resolved = !empty($ticket['date_reponse']) ? strtotime($ticket['date_reponse']) : null;

        $responseDeadline = $createdAt + ($target['first_response'] * 3600);
        $resolutionDeadline = $createdAt + ($target['resolution'] * 3600);

        return [
            'priority' => $priority,
            'first_response_target_hours' => $target['first_response'],
            'resolution_target_hours' => $target['resolution'],
            'response_deadline' => date('Y-m-d H:i', $responseDeadline),
            'resolution_deadline' => date('Y-m-d H:i', $resolutionDeadline),
            'response_met' => $firstResponse ? ($firstResponse <= $responseDeadline) : ($now <= $responseDeadline),
            'resolution_met' => $resolved ? ($resolved <= $resolutionDeadline) : ($now <= $resolutionDeadline),
            'response_overdue' => !$firstResponse && ($now > $responseDeadline),
            'resolution_overdue' => !$resolved && ($now > $resolutionDeadline) && in_array($ticket['statut'], ['ouvert', 'en_cours']),
        ];
    }

    /**
     * Get SLA dashboard metrics.
     */
    public function getSlaMetrics(): array
    {
        $tickets = $this->getTousTickets();
        $metrics = ['total' => count($tickets), 'response_met' => 0, 'response_breached' => 0, 'resolution_met' => 0, 'resolution_breached' => 0];

        foreach ($tickets as $t) {
            $sla = $this->getSlaStatus($t);
            if ($sla['response_met']) $metrics['response_met']++;
            if ($sla['response_overdue']) $metrics['response_breached']++;
            if ($sla['resolution_met'] && !empty($t['date_reponse'])) $metrics['resolution_met']++;
            if ($sla['resolution_overdue']) $metrics['resolution_breached']++;
        }

        $metrics['response_rate'] = $metrics['total'] > 0 ? round($metrics['response_met'] / $metrics['total'] * 100, 1) : 100;
        return $metrics;
    }

    /**
     * Record first response time on a ticket.
     */
    public function recordFirstResponse(int $ticketId): void
    {
        $this->pdo->prepare("UPDATE tickets_support SET first_response_at = NOW() WHERE id = ? AND first_response_at IS NULL AND etablissement_id = ?")
                   ->execute([$ticketId, $this->etabId()]);
    }

    // ─── SLA PAR CATÉGORIE ───

    public function getSla(string $categorie, string $priorite = 'normale'): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM support_sla WHERE categorie = :c AND priorite = :p AND etablissement_id = :etab");
        $stmt->execute([':c' => $categorie, ':p' => $priorite, ':etab' => $this->etabId()]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    // ─── RÉPONSES TYPES ───

    public function getReponsesTypes(?string $categorie = null): array
    {
        $sql = "SELECT * FROM support_reponses_types WHERE etablissement_id = :etab";
        $params = [':etab' => $this->etabId()];
        if ($categorie) { $sql .= " AND categorie = :c"; $params[':c'] = $categorie; }
        $sql .= " ORDER BY titre";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ─── NOTE SATISFACTION ───

    public function noterSatisfaction(int $ticketId, int $note, string $commentaire = ''): void
    {
        $this->pdo->prepare("UPDATE tickets_support SET satisfaction_note = :n, satisfaction_commentaire = :c WHERE id = :id AND etablissement_id = :etab")
            ->execute([':n' => $note, ':c' => $commentaire, ':id' => $ticketId, ':etab' => $this->etabId()]);
    }

    // ─── AUTO-SUGGEST FAQ ───

    public function suggestFaq(string $sujet): array
    {
        $etab = $this->etabId();
        $stmt = $this->pdo->prepare("SELECT id, question, reponse FROM faq_articles WHERE MATCH(question, reponse) AGAINST(:s IN NATURAL LANGUAGE MODE) AND etablissement_id = :etab LIMIT 5");
        try {
            $stmt->execute([':s' => $sujet, ':etab' => $etab]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Fallback LIKE search if FULLTEXT not available
            $stmt = $this->pdo->prepare("SELECT id, question, reponse FROM faq_articles WHERE (question LIKE :q OR reponse LIKE :q2) AND etablissement_id = :etab LIMIT 5");
            $stmt->execute([':q' => "%{$sujet}%", ':q2' => "%{$sujet}%", ':etab' => $etab]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
    }

    // ─── NOTES INTERNES ───

    public function ajouterNoteInterne(int $ticketId, string $contenu, int $auteurId): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO support_notes_internes (ticket_id, contenu, auteur_id) VALUES (:tid, :c, :aid)");
        $stmt->execute([':tid' => $ticketId, ':c' => $contenu, ':aid' => $auteurId]);
        return (int)$this->pdo->lastInsertId();
    }

    public function getNotesInternes(int $ticketId): array
    {
        $stmt = $this->pdo->prepare("SELECT ni.*, COALESCE(
                (SELECT CONCAT(prenom,' ',nom) FROM administrateurs WHERE id = ni.auteur_id),
                (SELECT CONCAT(prenom,' ',nom) FROM vie_scolaire WHERE id = ni.auteur_id),
                (SELECT CONCAT(prenom,' ',nom) FROM professeurs WHERE id = ni.auteur_id)
            ) AS auteur_nom
            FROM support_notes_internes ni WHERE ni.ticket_id = :tid ORDER BY ni.created_at ASC");
        $stmt->execute([':tid' => $ticketId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
