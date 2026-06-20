<?php
declare(strict_types=1);

namespace API\Platform;

use PDO;

/**
 * PlatformEstablishmentService — gestion du PARC d'établissements depuis la plateforme :
 * suspension (bloque les connexions), archivage (lecture seule), suppression logique,
 * et PURGE définitive (SuperAdmin). Chaque action est journalisée (platform_audit_logs).
 *
 * La purge supprime les données des TROIS MONDES rattachées à l'établissement
 * (appartenances, rôles, périmètres, support, comptes devenus orphelins, relations)
 * dans une transaction, puis marque l'établissement « purged ». Elle NE touche PAS
 * aux tables de modules héritées scopées par etablissement_id (shred dédié, hors scope).
 */
final class PlatformEstablishmentService
{
    public const STATUSES = ['draft', 'onboarding', 'active', 'suspended', 'archived', 'deleted', 'purged'];

    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** Liste du parc avec quelques statistiques. */
    public function listAll(): array
    {
        try {
            return $this->pdo->query(
                "SELECT e.id, e.nom, e.slug, e.type, e.status, e.ville,
                        (SELECT COUNT(*) FROM tenant_memberships m WHERE m.establishment_id = e.id AND m.status = 'active') AS members,
                        (SELECT COUNT(*) FROM support_sessions s WHERE s.establishment_id = e.id AND s.status = 'active') AS support_sessions
                   FROM etablissements e ORDER BY e.nom"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            error_log('[PlatformEstablishmentService] ' . $e->getMessage());
            return [];
        }
    }

    public function get(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM etablissements WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function suspend(int $id, int $actorId): bool   { return $this->transition($id, 'suspended', $actorId, 'establishment.suspend'); }
    public function archive(int $id, int $actorId): bool   { return $this->transition($id, 'archived', $actorId, 'establishment.archive'); }
    public function activate(int $id, int $actorId): bool  { return $this->transition($id, 'active', $actorId, 'establishment.activate'); }
    public function softDelete(int $id, int $actorId): bool{ return $this->transition($id, 'deleted', $actorId, 'establishment.delete'); }

    private function transition(int $id, string $status, int $actorId, string $action): bool
    {
        $before = $this->get($id);
        if (!$before || $before['status'] === 'purged') { return false; }
        $ok = $this->pdo->prepare("UPDATE etablissements SET status = ? WHERE id = ?")->execute([$status, $id]);
        if ($ok) {
            $this->audit($actorId, $id, $action, ['from' => $before['status'], 'to' => $status]);
        }
        return (bool) $ok;
    }

    /**
     * PURGE définitive (SuperAdmin uniquement). Supprime les données 3-mondes de
     * l'établissement et marque le statut « purged ».
     * @return array{purged_memberships:int, purged_accounts:int, purged_tickets:int}
     * @throws \RuntimeException
     */
    public function purge(int $id, int $actorId): array
    {
        $estab = $this->get($id);
        if (!$estab) { throw new \RuntimeException('Établissement introuvable.'); }

        $eid = (int) $id;
        $this->pdo->beginTransaction();
        try {
            // Comptes rattachés (avant suppression des appartenances) + nb de tickets.
            $accIds = $this->pdo->query("SELECT DISTINCT tenant_account_id FROM tenant_memberships WHERE establishment_id = {$eid}")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $tickets = (int) $this->pdo->query("SELECT COUNT(*) FROM support_tickets WHERE establishment_id = {$eid}")->fetchColumn();

            // Support (enfants → parents) — DELETE … WHERE … IN (SELECT …) : portable MySQL/SQLite.
            $this->pdo->exec("DELETE FROM support_session_restrictions WHERE support_session_id IN (SELECT id FROM support_sessions WHERE establishment_id = {$eid})");
            $this->pdo->exec("DELETE FROM support_session_audit_logs WHERE establishment_id = {$eid}");
            $this->pdo->exec("DELETE FROM support_sessions WHERE establishment_id = {$eid}");
            $this->pdo->exec("DELETE FROM support_access_request_approvals WHERE access_request_id IN (SELECT id FROM support_access_requests WHERE establishment_id = {$eid})");
            $this->pdo->exec("DELETE FROM support_access_requests WHERE establishment_id = {$eid}");
            $this->pdo->exec("DELETE FROM support_bridge_messages WHERE ticket_id IN (SELECT id FROM support_tickets WHERE establishment_id = {$eid})");
            $this->pdo->exec("DELETE FROM support_tickets WHERE establishment_id = {$eid}");

            // Appartenances + rôles + périmètres.
            $this->pdo->exec("DELETE FROM tenant_membership_role_scope_values WHERE membership_role_id IN (SELECT id FROM tenant_membership_roles WHERE membership_id IN (SELECT id FROM tenant_memberships WHERE establishment_id = {$eid}))");
            $this->pdo->exec("DELETE FROM tenant_membership_roles WHERE membership_id IN (SELECT id FROM tenant_memberships WHERE establishment_id = {$eid})");
            $purgedMemberships = $this->pdo->exec("DELETE FROM tenant_memberships WHERE establishment_id = {$eid}") ?: 0;

            // Comptes devenus orphelins (plus aucune appartenance) → suppression + dépendances.
            $purgedAccounts = 0;
            foreach ($accIds as $accId) {
                $accId = (int) $accId;
                $still = (int) $this->pdo->query("SELECT COUNT(*) FROM tenant_memberships WHERE tenant_account_id = {$accId}")->fetchColumn();
                if ($still === 0) {
                    $this->pdo->exec("DELETE FROM account_relationships WHERE (source_type='tenant_account' AND source_id={$accId}) OR (target_type='tenant_account' AND target_id={$accId})");
                    $this->pdo->exec("DELETE FROM tenant_account_profiles WHERE tenant_account_id = {$accId}");
                    $this->pdo->exec("DELETE FROM tenant_accounts WHERE id = {$accId}");
                    $purgedAccounts++;
                }
            }

            $this->pdo->prepare("UPDATE etablissements SET status = 'purged', actif = 0 WHERE id = ?")->execute([$id]);
            $this->audit($actorId, $id, 'establishment.purge', ['memberships' => (int) $purgedMemberships, 'accounts' => $purgedAccounts, 'tickets' => $tickets]);

            $this->pdo->commit();
            return ['purged_memberships' => (int) $purgedMemberships, 'purged_accounts' => $purgedAccounts, 'purged_tickets' => $tickets];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function audit(int $actorId, int $establishmentId, string $action, array $detail): void
    {
        try {
            $this->pdo->prepare(
                "INSERT INTO platform_audit_logs (platform_account_id, establishment_id, action, target_type, target_id, new_value, ip_address, user_agent)
                 VALUES (?, ?, ?, 'establishment', ?, ?, ?, ?)"
            )->execute([
                $actorId, $establishmentId, $action, $establishmentId,
                json_encode($detail, JSON_UNESCAPED_UNICODE),
                $_SERVER['REMOTE_ADDR'] ?? '', substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ]);
        } catch (\PDOException $e) {
            error_log('[PlatformEstablishmentService] audit: ' . $e->getMessage());
        }
    }
}
