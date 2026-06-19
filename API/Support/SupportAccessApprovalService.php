<?php
declare(strict_types=1);

namespace API\Support;

use PDO;

/**
 * SupportAccessApprovalService — décision de la Direction sur une demande d'accès.
 * La Direction peut approuver, approuver avec restrictions (niveau/périmètre/durée
 * réduits), refuser, ou demander des informations. Une approbation CRÉE la session
 * (pending_start) que le Support pourra démarrer. C'est ici que se matérialise le
 * contrôle de l'établissement sur le pont.
 */
final class SupportAccessApprovalService
{
    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /**
     * Approuve (éventuellement avec modifications). Crée la session pending_start.
     * $opts : approved_access_level, approved_scope_payload (array), approved_duration_minutes,
     *         comment, now.
     * @return int id de la session créée.
     * @throws \RuntimeException si la demande n'est pas en attente de décision.
     */
    public function approve(int $approverMembershipId, int $requestId, array $opts = []): int
    {
        $req = $this->request($requestId);
        if (!$req) { throw new \RuntimeException('Demande introuvable.'); }
        if (!in_array($req['status'], ['sent_to_direction', 'waiting_direction', 'more_info_requested'], true)) {
            throw new \RuntimeException("Demande non décidable (statut « {$req['status']} »).");
        }
        $now = $opts['now'] ?? date('Y-m-d H:i:s');

        $level   = $opts['approved_access_level'] ?? $req['access_level'];
        $payload = array_key_exists('approved_scope_payload', $opts)
            ? $opts['approved_scope_payload']
            : json_decode((string) ($req['requested_scope_payload'] ?? '[]'), true);
        $duration = (int) ($opts['approved_duration_minutes'] ?? $req['requested_duration_minutes']);
        $changed  = ($level !== $req['access_level'])
            || ($duration !== (int) $req['requested_duration_minutes'])
            || array_key_exists('approved_scope_payload', $opts);
        $expires  = date('Y-m-d H:i:s', strtotime($now) + max(1, $duration) * 60);

        // Trace d'approbation.
        $this->pdo->prepare(
            "INSERT INTO support_access_request_approvals
                (access_request_id, approver_membership_id, decision, approved_access_level, approved_scope_payload, approved_duration_minutes, comment)
             VALUES (?, ?, 'approved', ?, ?, ?, ?)"
        )->execute([$requestId, $approverMembershipId, $level, json_encode($payload, JSON_UNESCAPED_UNICODE), $duration, $opts['comment'] ?? null]);

        // Mise à jour de la demande.
        $this->pdo->prepare(
            "UPDATE support_access_requests
                SET status = ?, reviewed_by_membership_id = ?, reviewed_at = ?,
                    approved_duration_minutes = ?, approved_expires_at = ?, direction_comment = ?
              WHERE id = ?"
        )->execute([
            $changed ? 'approved_with_changes' : 'approved', $approverMembershipId, $now,
            $duration, $expires, $opts['comment'] ?? null, $requestId,
        ]);

        // Création de la session (pending_start).
        $this->pdo->prepare(
            "INSERT INTO support_sessions
                (access_request_id, ticket_id, establishment_id, platform_account_id, access_level, active_scope_payload, status, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending_start', ?)"
        )->execute([
            $requestId, (int) $req['ticket_id'], (int) $req['establishment_id'],
            (int) $req['requested_by_platform_account_id'], $level,
            json_encode($payload, JSON_UNESCAPED_UNICODE), $expires,
        ]);
        $sessionId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("UPDATE support_tickets SET status = 'access_approved' WHERE id = ?")->execute([(int) $req['ticket_id']]);
        return $sessionId;
    }

    public function refuse(int $approverMembershipId, int $requestId, string $reason, ?string $now = null): bool
    {
        $req = $this->request($requestId);
        if (!$req) { return false; }
        $now = $now ?? date('Y-m-d H:i:s');
        $this->pdo->prepare(
            "INSERT INTO support_access_request_approvals (access_request_id, approver_membership_id, decision, comment)
             VALUES (?, ?, 'refused', ?)"
        )->execute([$requestId, $approverMembershipId, $reason]);
        $this->pdo->prepare(
            "UPDATE support_access_requests SET status='refused', reviewed_by_membership_id=?, reviewed_at=?, refusal_reason=? WHERE id=?"
        )->execute([$approverMembershipId, $now, $reason, $requestId]);
        $this->pdo->prepare("UPDATE support_tickets SET status='access_refused' WHERE id=?")->execute([(int) $req['ticket_id']]);
        return true;
    }

    public function requestMoreInfo(int $approverMembershipId, int $requestId, string $comment, ?string $now = null): bool
    {
        $now = $now ?? date('Y-m-d H:i:s');
        $this->pdo->prepare(
            "INSERT INTO support_access_request_approvals (access_request_id, approver_membership_id, decision, comment)
             VALUES (?, ?, 'more_info_requested', ?)"
        )->execute([$requestId, $approverMembershipId, $comment]);
        return $this->pdo->prepare(
            "UPDATE support_access_requests SET status='more_info_requested', reviewed_by_membership_id=?, reviewed_at=?, direction_comment=? WHERE id=?"
        )->execute([$approverMembershipId, $now, $comment, $requestId]);
    }

    private function request(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM support_access_requests WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
