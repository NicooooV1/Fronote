<?php
declare(strict_types=1);

namespace API\Tenant;

use PDO;

/**
 * TenantAccountService — CRUD des comptes établissement (tenant_accounts).
 * Ces comptes ne reçoivent jamais de rôle plateforme. SQL portable (testable SQLite).
 */
final class TenantAccountService
{
    public const TYPES    = ['director', 'staff', 'teacher', 'student', 'family', 'external', 'temporary', 'system'];
    public const STATUSES = ['pending', 'active', 'inactive', 'locked', 'archived', 'deleted'];

    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /**
     * Crée un compte établissement. $data : account_type (requis), username (requis),
     * first_name, last_name, email, password|password_hash, status, legacy_type, legacy_id.
     * @throws \RuntimeException type/username manquant ou invalide.
     */
    public function createAccount(array $data): int
    {
        $type = (string) ($data['account_type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            throw new \RuntimeException("Type de compte établissement invalide : « {$type} ».");
        }
        $username = trim((string) ($data['username'] ?? ''));
        if ($username === '') {
            throw new \RuntimeException('Identifiant (username) obligatoire.');
        }
        $hash = $data['password_hash'] ?? null;
        if ($hash === null && !empty($data['password'])) {
            $hash = \API\Security\PasswordPolicy::hash((string) $data['password']);
        }
        $status = (string) ($data['status'] ?? 'pending');
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'pending';
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO tenant_accounts
                (email, username, password_hash, first_name, last_name, display_name, account_type,
                 status, must_change_password, legacy_type, legacy_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $data['email'] ?? null, $username, $hash,
            (string) ($data['first_name'] ?? ''), (string) ($data['last_name'] ?? ''),
            $data['display_name'] ?? null, $type, $status,
            isset($data['must_change_password']) ? (int) (bool) $data['must_change_password'] : 1,
            $data['legacy_type'] ?? null, isset($data['legacy_id']) ? (int) $data['legacy_id'] : null,
            isset($data['created_by']) ? (int) $data['created_by'] : null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function findByLegacy(string $legacyType, int $legacyId): ?array
    {
        try {
            $st = $this->pdo->prepare("SELECT * FROM tenant_accounts WHERE legacy_type = ? AND legacy_id = ? LIMIT 1");
            $st->execute([$legacyType, $legacyId]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\PDOException $e) { return null; }
    }

    public function disableAccount(int $id): bool { return $this->setStatus($id, 'inactive'); }
    public function archiveAccount(int $id): bool { return $this->setStatus($id, 'archived'); }

    public function lockAccount(int $id, ?string $until = null): bool
    {
        return $this->pdo->prepare("UPDATE tenant_accounts SET status = 'locked', locked_until = ? WHERE id = ?")
            ->execute([$until, $id]);
    }

    private function setStatus(int $id, string $status): bool
    {
        return $this->pdo->prepare("UPDATE tenant_accounts SET status = ? WHERE id = ?")->execute([$status, $id]);
    }
}
