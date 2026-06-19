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
}
