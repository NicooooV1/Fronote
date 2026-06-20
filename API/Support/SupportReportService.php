<?php
declare(strict_types=1);

namespace API\Support;

use PDO;

/**
 * SupportReportService — rapport de fin d'intervention : consolide une session support
 * (métadonnées, demande/ticket liés, journal d'intervention append-only, restrictions,
 * durée, synthèse) pour consultation par le Support ET par la Direction (transparence).
 */
final class SupportReportService
{
    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** @return array|null Rapport structuré, ou null si la session est introuvable. */
    public function build(int $sessionId): ?array
    {
        $svc = new SupportSessionService($this->pdo);
        $s = $svc->get($sessionId);
        if (!$s) { return null; }

        $ticket = $this->one("SELECT * FROM support_tickets WHERE id = ?", [(int) $s['ticket_id']]);
        $request = $this->one("SELECT * FROM support_access_requests WHERE id = ?", [(int) $s['access_request_id']]);
        $trail = $svc->auditTrail($sessionId);
        $restrictions = $this->all("SELECT restriction_key, restriction_value FROM support_session_restrictions WHERE support_session_id = ?", [$sessionId]);

        $duration = null;
        if (!empty($s['started_at']) && !empty($s['ended_at'])) {
            $duration = (int) round((strtotime((string) $s['ended_at']) - strtotime((string) $s['started_at'])) / 60);
        }

        $counts = [];
        $sensitiveActions = 0;
        foreach ($trail as $row) {
            $a = (string) $row['action'];
            $counts[$a] = ($counts[$a] ?? 0) + 1;
            if (!empty($row['sensitive'])) { $sensitiveActions++; }
        }

        return [
            'session'           => $s,
            'ticket'            => $ticket,
            'request'           => $request,
            'trail'             => $trail,
            'restrictions'      => $restrictions,
            'duration_minutes'  => $duration,
            'action_counts'     => $counts,
            'sensitive_actions' => $sensitiveActions,
            'summary'           => $s['intervention_summary'] ?? null,
        ];
    }

    private function one(string $sql, array $args): ?array
    {
        try { $st = $this->pdo->prepare($sql); $st->execute($args); return $st->fetch(PDO::FETCH_ASSOC) ?: null; }
        catch (\PDOException $e) { return null; }
    }

    private function all(string $sql, array $args): array
    {
        try { $st = $this->pdo->prepare($sql); $st->execute($args); return $st->fetchAll(PDO::FETCH_ASSOC) ?: []; }
        catch (\PDOException $e) { return []; }
    }
}
