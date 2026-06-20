<?php
declare(strict_types=1);

namespace API\Support;

use PDO;

/**
 * SupportAccessRequestService — demandes d'accès créées par le Support après analyse
 * d'un ticket. Précise NIVEAU, PÉRIMÈTRE, DURÉE et justification. N'accorde aucun
 * accès tant qu'elle n'est pas approuvée par la Direction.
 */
final class SupportAccessRequestService
{
    public const LEVELS = ['read_only', 'diagnostic', 'configuration', 'account_assistance', 'data_assistance', 'impersonation', 'sensitive_data_access'];

    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /**
     * Crée et envoie une demande d'accès à la Direction.
     * @throws \RuntimeException niveau invalide ou périmètre global interdit.
     */
    public function create(int $supportAccountId, int $ticketId, int $establishmentId, string $level, string $scopeType, array $scopePayload, string $reason, int $durationMinutes, array $opts = []): int
    {
        if (!in_array($level, self::LEVELS, true)) {
            throw new \RuntimeException("Niveau d'accès invalide : « {$level} ».");
        }
        // Le Support ne demande jamais un accès global par facilité.
        if ($scopeType === 'global' || (!empty($scopePayload['global']))) {
            throw new \RuntimeException('Un accès global ne peut pas être demandé.');
        }
        if ($durationMinutes <= 0) {
            throw new \RuntimeException('Durée demandée invalide.');
        }
        $now = $opts['now'] ?? date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', strtotime($now) + $durationMinutes * 60);
        $sensitive = $level === 'sensitive_data_access' || !empty($opts['sensitive']);

        $this->pdo->prepare(
            "INSERT INTO support_access_requests
                (ticket_id, establishment_id, requested_by_platform_account_id, access_level, requested_scope_type,
                 requested_scope_payload, reason, detailed_reason, planned_actions, requested_duration_minutes,
                 requested_expires_at, status, requires_sensitive_validation, sensitive_validation_status, sent_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sent_to_direction', ?, ?, ?)"
        )->execute([
            $ticketId, $establishmentId, $supportAccountId, $level, $scopeType,
            json_encode($scopePayload, JSON_UNESCAPED_UNICODE), $reason,
            $opts['detailed_reason'] ?? null, $opts['planned_actions'] ?? null, $durationMinutes,
            $expires, $sensitive ? 1 : 0, $sensitive ? 'pending' : 'not_required', $now,
        ]);
        $id = (int) $this->pdo->lastInsertId();

        // Le ticket reflète l'attente de validation.
        $this->pdo->prepare("UPDATE support_tickets SET status = 'access_requested' WHERE id = ?")->execute([$ticketId]);
        return $id;
    }

    public function get(int $requestId): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM support_access_requests WHERE id = ? LIMIT 1");
        $st->execute([$requestId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Demandes d'accès liées à un ticket. */
    public function forTicket(int $ticketId): array
    {
        $st = $this->pdo->prepare("SELECT * FROM support_access_requests WHERE ticket_id = ? ORDER BY created_at DESC");
        $st->execute([$ticketId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Demandes en attente de décision pour un établissement (vue Direction). */
    public function pendingForEstablishment(int $establishmentId): array
    {
        $st = $this->pdo->prepare(
            "SELECT ar.*, t.title AS ticket_title FROM support_access_requests ar
               JOIN support_tickets t ON t.id = ar.ticket_id
              WHERE ar.establishment_id = ? AND ar.status IN ('sent_to_direction','waiting_direction','more_info_requested')
              ORDER BY ar.created_at DESC"
        );
        $st->execute([$establishmentId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Expire les demandes non traitées dépassées (à planifier). */
    public function expireStale(?string $now = null): int
    {
        $now = $now ?? date('Y-m-d H:i:s');
        $st = $this->pdo->prepare(
            "UPDATE support_access_requests SET status = 'expired'
              WHERE status IN ('sent_to_direction','waiting_direction','more_info_requested')
                AND requested_expires_at < ?"
        );
        $st->execute([$now]);
        return $st->rowCount();
    }
}
