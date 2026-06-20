<?php
declare(strict_types=1);

namespace API\Support;

use PDO;

/**
 * SupportSessionService — cœur du pont Support : cycle de vie des sessions d'accès
 * temporaire d'un platform_account (Support) à un établissement, + périmètre,
 * restrictions, niveaux d'accès et journal d'intervention APPEND-ONLY.
 *
 * Invariants de sécurité (cahier des charges §7.14) :
 *  - pas de session sans demande d'accès approuvée (start refuse sinon) ;
 *  - expiration automatique (expires_at) ;
 *  - le Support ne peut PAS prolonger sa session (aucune méthode d'extension) ;
 *  - la Direction peut révoquer à tout moment ;
 *  - toute action est auditée (table append-only) ;
 *  - données sensibles masquées par défaut (restrictions).
 */
final class SupportSessionService
{
    /** Hiérarchie des niveaux (du moins au plus sensible). */
    public const LEVELS = [
        'read_only' => 1, 'diagnostic' => 2, 'configuration' => 3,
        'account_assistance' => 4, 'data_assistance' => 5,
        'impersonation' => 6, 'sensitive_data_access' => 7,
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    private function now(?string $now): string { return $now ?? date('Y-m-d H:i:s'); }

    /**
     * Démarre une session (par le Support). N'autorise QUE si une demande d'accès
     * approuvée existe et qu'aucune session active n'est déjà ouverte pour elle.
     * @throws \RuntimeException si la demande n'est pas approuvée.
     */
    public function start(int $sessionId, int $platformAccountId, ?string $now = null): bool
    {
        $s = $this->get($sessionId);
        if (!$s) { throw new \RuntimeException('Session introuvable.'); }
        if ((int) $s['platform_account_id'] !== $platformAccountId) {
            throw new \RuntimeException("Cette session n'appartient pas à ce compte support.");
        }
        if ($s['status'] !== 'pending_start') {
            throw new \RuntimeException("Session non démarrable (statut « {$s['status']} »).");
        }
        // Garde : la demande d'accès liée doit être approuvée.
        $req = $this->pdo->prepare("SELECT status FROM support_access_requests WHERE id = ? LIMIT 1");
        $req->execute([(int) $s['access_request_id']]);
        $reqStatus = (string) $req->fetchColumn();
        if (!in_array($reqStatus, ['approved', 'approved_with_changes'], true)) {
            throw new \RuntimeException("Aucune demande d'accès approuvée pour cette session.");
        }
        $now = $this->now($now);
        $ok = $this->pdo->prepare("UPDATE support_sessions SET status='active', started_at=? WHERE id=? AND status='pending_start'")
            ->execute([$now, $sessionId]);
        if ($ok) {
            $this->audit($sessionId, 'session_started', ['access_level' => $s['access_level']]);
        }
        return (bool) $ok;
    }

    /** Session active et NON expirée pour (compte support, établissement). Auto-expire au passage. */
    public function activeFor(int $platformAccountId, int $establishmentId, ?string $now = null): ?array
    {
        $now = $this->now($now);
        try {
            $st = $this->pdo->prepare(
                "SELECT * FROM support_sessions
                  WHERE platform_account_id = ? AND establishment_id = ? AND status = 'active'
                  ORDER BY id DESC LIMIT 1"
            );
            $st->execute([$platformAccountId, $establishmentId]);
            $s = $st->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) { return null; }
        if (!$s) { return null; }
        if (strtotime((string) $s['expires_at']) < strtotime($now)) {
            $this->pdo->prepare("UPDATE support_sessions SET status='expired', ended_at=?, end_reason='auto-expired' WHERE id=? AND status='active'")
                ->execute([$now, (int) $s['id']]);
            $this->audit((int) $s['id'], 'session_expired', []);
            return null;
        }
        return $s;
    }

    /** Révocation par la Direction (à tout moment). */
    public function revokeByDirection(int $sessionId, int $membershipId, string $reason, ?string $now = null): bool
    {
        $now = $this->now($now);
        $ok = $this->pdo->prepare(
            "UPDATE support_sessions SET status='revoked', ended_at=?, ended_by_type='tenant', ended_by_membership_id=?, end_reason=?
              WHERE id=? AND status IN ('active','pending_start','paused')"
        )->execute([$now, $membershipId, $reason, $sessionId]);
        if ($ok) { $this->audit($sessionId, 'session_revoked', ['by_membership' => $membershipId, 'reason' => $reason]); }
        return (bool) $ok;
    }

    /** Clôture par le Support (fin normale ; NE PEUT PAS prolonger). */
    public function endBySupport(int $sessionId, int $platformAccountId, ?string $reason = null, ?string $now = null): bool
    {
        $now = $this->now($now);
        $ok = $this->pdo->prepare(
            "UPDATE support_sessions SET status='ended', ended_at=?, ended_by_type='platform', ended_by_platform_account_id=?, end_reason=?
              WHERE id=? AND platform_account_id=? AND status='active'"
        )->execute([$now, $platformAccountId, $reason, $sessionId, $platformAccountId]);
        if ($ok) { $this->audit($sessionId, 'session_ended', ['reason' => $reason]); }
        return (bool) $ok;
    }

    /** Le niveau de la session permet-il une action exigeant $requiredLevel ? */
    public function meetsLevel(array $session, string $requiredLevel): bool
    {
        $have = self::LEVELS[$session['access_level'] ?? ''] ?? 0;
        $need = self::LEVELS[$requiredLevel] ?? 99;
        return $have >= $need;
    }

    /** La ressource est-elle dans le périmètre approuvé (+ restrictions) ? fail-closed. */
    public function isInScope(array $session, string $resourceType, int $resourceId, bool $sensitive = false): bool
    {
        // Données sensibles : interdites sauf niveau sensitive_data_access, et toujours
        // bloquées si une restriction de masquage est posée.
        if ($sensitive) {
            if (($session['access_level'] ?? '') !== 'sensitive_data_access') { return false; }
            if ($this->hasRestriction((int) $session['id'], 'hide_' . $resourceType . '_data')) { return false; }
        }
        $payload = $this->decode($session['active_scope_payload'] ?? null);
        // Périmètre établissement entier explicite.
        if (!empty($payload['establishment'])) {
            $allowed = true;
        } else {
            $list = $payload[$resourceType] ?? $payload[$resourceType . 's'] ?? $payload[$resourceType . '_ids'] ?? null;
            if ($list === null) { return false; } // pas de périmètre pour ce type → fail-closed
            $allowed = in_array($resourceId, array_map('intval', (array) $list), true);
        }
        if (!$allowed) { return false; }
        // Restriction de liste blanche (ex. allowed_student_ids) → resserre encore.
        $restr = $this->restrictionValue((int) $session['id'], 'allowed_' . $resourceType . '_ids');
        if ($restr !== null) {
            return in_array($resourceId, array_map('intval', (array) $restr), true);
        }
        return true;
    }

    public function addRestriction(int $sessionId, string $key, $value = true): void
    {
        $this->pdo->prepare("INSERT INTO support_session_restrictions (support_session_id, restriction_key, restriction_value) VALUES (?, ?, ?)")
            ->execute([$sessionId, $key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
    }

    public function hasRestriction(int $sessionId, string $key): bool
    {
        return $this->restrictionValue($sessionId, $key) !== null
            ? (bool) $this->restrictionValue($sessionId, $key) : false;
    }

    private function restrictionValue(int $sessionId, string $key)
    {
        try {
            $st = $this->pdo->prepare("SELECT restriction_value FROM support_session_restrictions WHERE support_session_id=? AND restriction_key=? LIMIT 1");
            $st->execute([$sessionId, $key]);
            $v = $st->fetchColumn();
            return $v === false ? null : json_decode((string) $v, true);
        } catch (\PDOException $e) { return null; }
    }

    /** Journal d'intervention APPEND-ONLY. */
    public function audit(int $sessionId, string $action, array $ctx = []): void
    {
        try {
            $s = $this->get($sessionId);
            if (!$s) { return; }
            $this->pdo->prepare(
                "INSERT INTO support_session_audit_logs
                    (support_session_id, ticket_id, establishment_id, platform_account_id, action,
                     target_type, target_id, permission_used, access_level, sensitive, new_value, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $sessionId, (int) $s['ticket_id'], (int) $s['establishment_id'], (int) $s['platform_account_id'], $action,
                $ctx['target_type'] ?? null, isset($ctx['target_id']) ? (int) $ctx['target_id'] : null,
                $ctx['permission'] ?? null, $s['access_level'], !empty($ctx['sensitive']) ? 1 : 0,
                json_encode($ctx, JSON_UNESCAPED_UNICODE),
                $_SERVER['REMOTE_ADDR'] ?? '', substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ]);
        } catch (\PDOException $e) {
            error_log('[SupportSessionService] audit: ' . $e->getMessage());
        }
    }

    public function get(int $sessionId): ?array
    {
        try {
            $st = $this->pdo->prepare("SELECT * FROM support_sessions WHERE id = ? LIMIT 1");
            $st->execute([$sessionId]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\PDOException $e) { return null; }
    }

    /** Sessions liées à un ticket (toutes statuts). */
    public function forTicket(int $ticketId): array
    {
        try {
            $st = $this->pdo->prepare("SELECT * FROM support_sessions WHERE ticket_id = ? ORDER BY id DESC");
            $st->execute([$ticketId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) { return []; }
    }

    /** Sessions en cours ou à démarrer pour un établissement (vue Direction). */
    public function liveForEstablishment(int $establishmentId): array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT * FROM support_sessions WHERE establishment_id = ? AND status IN ('pending_start','active','paused') ORDER BY id DESC"
            );
            $st->execute([$establishmentId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) { return []; }
    }

    /** Lignes du journal d'intervention d'une session (consultation). */
    public function auditTrail(int $sessionId): array
    {
        try {
            $st = $this->pdo->prepare("SELECT * FROM support_session_audit_logs WHERE support_session_id = ? ORDER BY created_at ASC, id ASC");
            $st->execute([$sessionId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) { return []; }
    }

    private function decode($json): array
    {
        if (is_array($json)) { return $json; }
        if (!$json) { return []; }
        $d = json_decode((string) $json, true);
        return is_array($d) ? $d : [];
    }
}
