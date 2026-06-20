<?php
declare(strict_types=1);

namespace API\Support;

use PDO;

/**
 * SupportTicketService — cycle de vie des tickets. Un ticket DÉCRIT un problème ;
 * il n'accorde AUCUN accès (l'accès passe par la demande puis la session).
 */
final class SupportTicketService
{
    public const CATEGORIES = ['account', 'permissions', 'configuration', 'module', 'data', 'bug', 'security', 'performance', 'billing', 'other'];

    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function create(int $establishmentId, ?int $createdByAccountId, ?int $membershipId, string $title, string $category, string $description, array $opts = []): int
    {
        if (!in_array($category, self::CATEGORIES, true)) { $category = 'other'; }
        $this->pdo->prepare(
            "INSERT INTO support_tickets
                (establishment_id, created_by_tenant_account_id, created_by_membership_id, title, category, priority, description, status, sensitive_flag)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'submitted', ?)"
        )->execute([
            $establishmentId, $createdByAccountId, $membershipId, $title, $category,
            $opts['priority'] ?? 'normal', $description, !empty($opts['sensitive_flag']) ? 1 : 0,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function reply(int $ticketId, string $authorType, ?int $authorId, string $message, array $opts = []): int
    {
        $this->pdo->prepare(
            "INSERT INTO support_bridge_messages
                (ticket_id, author_type, tenant_account_id, platform_account_id, message, is_internal_note, visible_to_tenant)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $ticketId, $authorType,
            $authorType === 'tenant' ? $authorId : null,
            $authorType === 'platform' ? $authorId : null,
            $message, !empty($opts['is_internal_note']) ? 1 : 0,
            isset($opts['visible_to_tenant']) ? (int) (bool) $opts['visible_to_tenant'] : 1,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function setStatus(int $ticketId, string $status): bool
    {
        return $this->pdo->prepare("UPDATE support_tickets SET status = ?, updated_at = ? WHERE id = ?")
            ->execute([$status, date('Y-m-d H:i:s'), $ticketId]);
    }

    public function assign(int $ticketId, int $platformAccountId): bool
    {
        return $this->pdo->prepare("UPDATE support_tickets SET assigned_platform_account_id = ?, status = 'triaged' WHERE id = ?")
            ->execute([$platformAccountId, $ticketId]);
    }

    public function get(int $ticketId): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM support_tickets WHERE id = ? LIMIT 1");
        $st->execute([$ticketId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Tickets d'un établissement (vue Direction). */
    public function listForEstablishment(int $establishmentId): array
    {
        $st = $this->pdo->prepare("SELECT * FROM support_tickets WHERE establishment_id = ? ORDER BY created_at DESC LIMIT 200");
        $st->execute([$establishmentId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Tous les tickets (vue Support plateforme), filtrables par statut/établissement. */
    public function listAll(array $filters = []): array
    {
        $sql = "SELECT t.*, e.nom AS establishment_name FROM support_tickets t
                JOIN etablissements e ON e.id = t.establishment_id WHERE 1=1";
        $args = [];
        if (!empty($filters['status']))           { $sql .= " AND t.status = ?";           $args[] = $filters['status']; }
        if (!empty($filters['establishment_id'])) { $sql .= " AND t.establishment_id = ?"; $args[] = (int) $filters['establishment_id']; }
        $sql .= " ORDER BY t.created_at DESC LIMIT 200";
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Fil de discussion d'un ticket. $includeInternal=false masque les notes internes (côté établissement). */
    public function messages(int $ticketId, bool $includeInternal = true): array
    {
        $sql = "SELECT * FROM support_bridge_messages WHERE ticket_id = ?"
             . ($includeInternal ? '' : ' AND is_internal_note = 0 AND visible_to_tenant = 1')
             . " ORDER BY created_at ASC";
        $st = $this->pdo->prepare($sql);
        $st->execute([$ticketId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
