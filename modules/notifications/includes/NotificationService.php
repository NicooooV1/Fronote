<?php
declare(strict_types=1);
/**
 * M12 – Notifications — Service
 *
 * @global-scope Scoping implicite via user_id + user_type : chaque notification est
 * attachée à un utilisateur précis, lui-même rattaché à un établissement. Pas de
 * listing global cross-tenant : tout `creer()`/`marquerLue()` est par-utilisateur.
 */
require_once __DIR__ . '/../../../API/Services/AbstractTenantService.php';

class NotificationService extends \API\Services\AbstractTenantService
{

    // ── Créer une notification ──
    // Depuis 4.3 : relais vers le centre de notifications (préférences, regroupement,
    // temps réel, normalisation de l'importance et des liens). Signature inchangée.
    public function creer(int $userId, string $userType, string $type, string $titre, ?string $contenu = null, ?string $lien = null, string $importance = 'normale', ?string $sourceType = null, ?int $sourceId = null): int
    {
        return \API\Services\Notifications\NotificationCenter::make()->send(
            [['id' => $userId, 'type' => $userType]], $type, $titre, $contenu, $lien,
            ['importance' => $importance, 'source_type' => $sourceType, 'source_id' => $sourceId]
        );
    }

    // ── Créer pour plusieurs destinataires ── ($destinataires = [['id'=>int,'type'=>string], ...])
    public function creerPourGroupe(array $destinataires, string $type, string $titre, ?string $contenu = null, ?string $lien = null, string $importance = 'normale', ?string $sourceType = null, ?int $sourceId = null): int
    {
        return \API\Services\Notifications\NotificationCenter::make()->send(
            $destinataires, $type, $titre, $contenu, $lien,
            ['importance' => $importance, 'source_type' => $sourceType, 'source_id' => $sourceId]
        );
    }

    private function insertBatch(array $recipients, string $type, string $titre, ?string $contenu, ?string $lien, string $importance, ?string $sourceType = null, ?int $sourceId = null): int
    {
        return $this->creerPourGroupe($recipients, $type, $titre, $contenu, $lien, $importance, $sourceType, $sourceId);
    }

    // ── Notifications d'un utilisateur ──
    public function getNotifications(int $userId, string $userType, int $limit = 50, int $offset = 0, ?bool $lu = null): array
    {
        $sql = "SELECT * FROM notifications_globales WHERE user_id = ? AND user_type = ? AND etablissement_id = ?";
        $params = [$userId, $userType, \API\Core\EstablishmentContext::id()];
        if ($lu !== null) {
            $sql .= " AND lu = ?";
            $params[] = $lu ? 1 : 0;
        }
        $sql .= " ORDER BY date_creation DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Nombre de non lues ──
    public function countNonLues(int $userId, string $userType): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM notifications_globales WHERE user_id = ? AND user_type = ? AND etablissement_id = ? AND lu = 0");
        $stmt->execute([$userId, $userType, \API\Core\EstablishmentContext::id()]);
        return (int)$stmt->fetchColumn();
    }

    // ── Marquer une notification comme lue ──
    public function marquerLue(int $id, int $userId, string $userType): bool
    {
        $stmt = $this->pdo->prepare("UPDATE notifications_globales SET lu = 1, date_lecture = NOW() WHERE id = ? AND user_id = ? AND user_type = ? AND etablissement_id = ?");
        return $stmt->execute([$id, $userId, $userType, \API\Core\EstablishmentContext::id()]);
    }

    // ── Marquer toutes comme lues ──
    public function marquerToutesLues(int $userId, string $userType): bool
    {
        $stmt = $this->pdo->prepare("UPDATE notifications_globales SET lu = 1, date_lecture = NOW() WHERE user_id = ? AND user_type = ? AND etablissement_id = ? AND lu = 0");
        return $stmt->execute([$userId, $userType, \API\Core\EstablishmentContext::id()]);
    }

    // ── Supprimer une notification ──
    public function supprimer(int $id, int $userId, string $userType): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM notifications_globales WHERE id = ? AND user_id = ? AND user_type = ? AND etablissement_id = ?");
        return $stmt->execute([$id, $userId, $userType, \API\Core\EstablishmentContext::id()]);
    }

    // ── Supprimer les anciennes ──
    public function nettoyerAnciennes(int $joursRetention = 90): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM notifications_globales WHERE date_creation < DATE_SUB(NOW(), INTERVAL ? DAY) AND etablissement_id = ?");
        $stmt->execute([$joursRetention, \API\Core\EstablishmentContext::id()]);
        return $stmt->rowCount();
    }

    // ── Préférences ──
    public function getPreferences(int $userId, string $userType): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM notification_preferences WHERE user_id = ? AND user_type = ? AND etablissement_id = ?");
        $stmt->execute([$userId, $userType, \API\Core\EstablishmentContext::id()]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $prefs = [];
        foreach ($rows as $row) {
            $prefs[$row['type_notification']] = $row;
        }
        // Remplir les defaults
        foreach (self::typesNotification() as $type => $label) {
            if (!isset($prefs[$type])) {
                $prefs[$type] = ['type_notification' => $type, 'canal_email' => 1, 'canal_web' => 1, 'canal_push' => 0, 'actif' => 1];
            }
        }
        return $prefs;
    }

    // ── Sauvegarder préférences ──
    public function sauvegarderPreferences(int $userId, string $userType, array $prefs): void
    {
        $etabId = \API\Core\EstablishmentContext::id();
        $stmt = $this->pdo->prepare("
            INSERT INTO notification_preferences (etablissement_id, user_id, user_type, type_notification, canal_email, canal_web, canal_push, actif)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE canal_email = VALUES(canal_email), canal_web = VALUES(canal_web), canal_push = VALUES(canal_push), actif = VALUES(actif)
        ");
        foreach ($prefs as $type => $pref) {
            $stmt->execute([
                $etabId, $userId, $userType, $type,
                (int)($pref['canal_email'] ?? 1),
                (int)($pref['canal_web'] ?? 1),
                (int)($pref['canal_push'] ?? 0),
                (int)($pref['actif'] ?? 1)
            ]);
        }
    }

    // ── Statistiques ──
    public function getStats(int $userId, string $userType): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(lu = 0) as non_lues,
                SUM(importance = 'urgente' AND lu = 0) as urgentes
            FROM notifications_globales WHERE user_id = ? AND user_type = ? AND etablissement_id = ?
        ");
        $stmt->execute([$userId, $userType, \API\Core\EstablishmentContext::id()]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'non_lues' => 0, 'urgentes' => 0];
    }

    // ── Helpers statiques ──
    public static function typesNotification(): array
    {
        return [
            'nouvelle_note'  => 'Nouvelle note',
            'absence'        => 'Absence signalée',
            'message'        => 'Nouveau message',
            'devoir'         => 'Nouveau devoir',
            'bulletin'       => 'Bulletin publié',
            'incident'       => 'Incident disciplinaire',
            'annonce'        => 'Nouvelle annonce',
            'reunion'        => 'Réunion / Convocation',
            'general'        => 'Information générale',
        ];
    }

    public static function iconeParType(string $type): string
    {
        $map = [
            'nouvelle_note'  => 'fa-chart-bar',
            'absence'        => 'fa-calendar-times',
            'message'        => 'fa-envelope',
            'devoir'         => 'fa-tasks',
            'bulletin'       => 'fa-file-alt',
            'incident'       => 'fa-exclamation-triangle',
            'annonce'        => 'fa-bullhorn',
            'reunion'        => 'fa-handshake',
            'general'        => 'fa-bell',
        ];
        return $map[$type] ?? 'fa-bell';
    }

    // ── Digest mode ──

    /**
     * Collect unsent notifications for digest (grouped email).
     * Returns notifications grouped by user, only for users with digest_mode enabled.
     */
    public function getDigestPending(): array
    {
        $etabId = (int)\API\Core\EstablishmentContext::id();
        $stmt = $this->pdo->query("
            SELECT ng.*, np.canal_email
            FROM notifications_globales ng
            JOIN notification_preferences np
                ON ng.user_id = np.user_id AND ng.user_type = np.user_type
                AND np.type_notification = ng.type AND np.digest_mode = 1
            WHERE ng.lu = 0 AND ng.digest_sent = 0 AND ng.etablissement_id = {$etabId}
            ORDER BY ng.user_id, ng.user_type, ng.date_creation
        ");
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($rows as $r) {
            $key = $r['user_type'] . ':' . $r['user_id'];
            $grouped[$key][] = $r;
        }
        return $grouped;
    }

    /**
     * Mark notifications as digest-sent.
     */
    public function markDigestSent(array $ids): void
    {
        if (empty($ids)) return;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = array_values($ids);
        $params[] = \API\Core\EstablishmentContext::id();
        $this->pdo->prepare("UPDATE notifications_globales SET digest_sent = 1 WHERE id IN ($ph) AND etablissement_id = ?")->execute($params);
    }

    /**
     * Send digest emails for all pending users.
     * @return int number of emails sent
     */
    /** Récapitulatif e-mail : délégué au centre de notifications (4.3). */
    public function sendDigests(): int
    {
        return \API\Services\Notifications\NotificationCenter::make()->sendDigests();
    }

    public function supprimerToutes(int $userId, string $userType): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM notifications_globales WHERE user_id = ? AND user_type = ? AND etablissement_id = ?");
        $stmt->execute([$userId, $userType, \API\Core\EstablishmentContext::id()]);
        return $stmt->rowCount();
    }

    // ── Filtered listing ──

    /**
     * Get notifications filtered by category/type.
     */
    public function getNotificationsFiltered(int $userId, string $userType, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT * FROM notifications_globales WHERE user_id = ? AND user_type = ? AND etablissement_id = ?";
        $params = [$userId, $userType, \API\Core\EstablishmentContext::id()];

        if (!empty($filters['type'])) {
            $sql .= " AND type = ?";
            $params[] = $filters['type'];
        }
        if (!empty($filters['importance'])) {
            $sql .= " AND importance = ?";
            $params[] = $filters['importance'];
        }
        if (isset($filters['lu'])) {
            $sql .= " AND lu = ?";
            $params[] = (int)$filters['lu'];
        }
        if (!empty($filters['date_debut'])) {
            $sql .= " AND date_creation >= ?";
            $params[] = $filters['date_debut'];
        }

        $sql .= " ORDER BY date_creation DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function importanceBadge(string $imp): string
    {
        $map = [
            'basse'   => '<span class="badge badge-secondary">Basse</span>',
            'normale' => '<span class="badge badge-info">Normale</span>',
            'haute'   => '<span class="badge badge-warning">Haute</span>',
            'urgente' => '<span class="badge badge-danger">Urgente</span>',
        ];
        return $map[$imp] ?? $map['normale'];
    }

    // ─── NOTIFICATION PROGRAMMÉE ───

    public function planifierNotification(int $userId, string $userType, string $type, string $titre, string $contenu, string $dateEnvoi, ?string $lien = null, string $importance = 'normale'): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO notifications_planifiees (user_id, user_type, type, titre, contenu, lien, importance, date_envoi_prevue, statut)
            VALUES (:u, :ut, :t, :ti, :c, :l, :i, :d, 'planifiee')
        ");
        $stmt->execute([':u' => $userId, ':ut' => $userType, ':t' => $type, ':ti' => $titre, ':c' => $contenu, ':l' => $lien, ':i' => $importance, ':d' => $dateEnvoi]);
        return (int)$this->pdo->lastInsertId();
    }

    public function envoyerNotificationsPlanifiees(): int
    {
        $stmt = $this->pdo->query("SELECT * FROM notifications_planifiees WHERE statut = 'planifiee' AND date_envoi_prevue <= NOW()");
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $count = 0;
        foreach ($rows as $r) {
            $this->creer($r['user_id'], $r['user_type'], $r['type'], $r['titre'], $r['contenu'], $r['lien'], $r['importance']);
            $this->pdo->prepare("UPDATE notifications_planifiees SET statut = 'envoyee', date_envoi_effective = NOW() WHERE id = ?")->execute([$r['id']]);
            $count++;
        }
        return $count;
    }

    // ─── NOTIFICATION GROUPE ───

    public function envoyerAGroupe(string $groupe, string $type, string $titre, string $contenu, ?string $lien = null, string $importance = 'normale'): int
    {
        $tableMap = ['eleves' => 'eleve', 'professeurs' => 'professeur', 'parents' => 'parent', 'administrateurs' => 'administrateur', 'vie_scolaire' => 'vie_scolaire'];
        $userType = $tableMap[$groupe] ?? null;
        if (!$userType) return 0;

        $stmt = $this->pdo->query("SELECT id FROM {$groupe} WHERE actif = 1 AND etablissement_id = " . (int)\API\Core\EstablishmentContext::id());
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $recipients = array_map(static fn($id) => ['id' => (int) $id, 'type' => $userType], $ids);
        return $this->insertBatch($recipients, $type, $titre, $contenu, $lien, $importance);
    }

    public function envoyerAClasse(int $classeId, string $titre, string $contenu, ?string $lien = null): int
    {
        $etabId = \API\Core\EstablishmentContext::id();
        $stmt = $this->pdo->prepare("SELECT id FROM eleves WHERE classe = (SELECT nom FROM classes WHERE id = ? AND etablissement_id = ?) AND etablissement_id = ? AND actif = 1");
        $stmt->execute([$classeId, $etabId, $etabId]);
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $recipients = array_map(static fn($id) => ['id' => (int) $id, 'type' => 'eleve'], $ids);
        return $this->insertBatch($recipients, 'annonce', $titre, $contenu, $lien, 'normale');
    }

    // ─── HISTORIQUE / ANALYTICS ───

    public function getHistorique(int $userId, string $userType, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM notifications_globales WHERE user_id = :u AND user_type = :t AND etablissement_id = :e ORDER BY date_creation DESC LIMIT :l");
        $stmt->bindValue(':u', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':t', $userType);
        $stmt->bindValue(':e', \API\Core\EstablishmentContext::id(), \PDO::PARAM_INT);
        $stmt->bindValue(':l', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getAnalytics(string $dateDebut, string $dateFin): array
    {
        $stmt = $this->pdo->prepare("
            SELECT type, importance, COUNT(*) AS total,
                   SUM(lu) AS lues, SUM(1 - lu) AS non_lues
            FROM notifications_globales
            WHERE date_creation BETWEEN :d AND :f AND etablissement_id = :e
            GROUP BY type, importance ORDER BY total DESC
        ");
        $stmt->execute([':d' => $dateDebut, ':f' => $dateFin . ' 23:59:59', ':e' => \API\Core\EstablishmentContext::id()]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
