<?php
declare(strict_types=1);

namespace API\Platform;

use PDO;

/**
 * PlatformAccountService — CRUD des comptes internes Fronote (platform_accounts).
 * Monde plateforme uniquement : ces comptes ne reçoivent jamais de rôle établissement
 * et ne se connectent qu'au portail /platform. SQL portable (testable sur SQLite).
 */
final class PlatformAccountService
{
    public const STATUSES = ['active', 'inactive', 'locked', 'archived'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Crée un compte plateforme. $data : email, username, password (clair) | password_hash,
     * first_name, last_name, status, legacy_super_admin_id.
     * @throws \RuntimeException si email/username/nom manquants.
     */
    public function createAccount(array $data): int
    {
        $email    = trim((string) ($data['email'] ?? ''));
        $username = trim((string) ($data['username'] ?? ''));
        if ($email === '' || $username === '') {
            throw new \RuntimeException('Email et identifiant obligatoires pour un compte plateforme.');
        }
        if (($data['first_name'] ?? '') === '' || ($data['last_name'] ?? '') === '') {
            throw new \RuntimeException('Nom et prénom obligatoires.');
        }
        $hash = $data['password_hash'] ?? null;
        if ($hash === null) {
            $plain = (string) ($data['password'] ?? '');
            if ($plain === '') {
                throw new \RuntimeException('Mot de passe obligatoire.');
            }
            $hash = \API\Security\PasswordPolicy::hash($plain);
        }
        $status = (string) ($data['status'] ?? 'active');
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'active';
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO platform_accounts
                (email, username, password_hash, first_name, last_name, status, legacy_super_admin_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $email, $username, $hash,
            (string) $data['first_name'], (string) $data['last_name'], $status,
            isset($data['legacy_super_admin_id']) ? (int) $data['legacy_super_admin_id'] : null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Recherche un compte plateforme ACTIF par email ou username (pour le login). */
    public function findActiveByLogin(string $login): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM platform_accounts
                  WHERE (email = ? OR username = ?) AND status = 'active' LIMIT 1"
            );
            $stmt->execute([$login, $login]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\PDOException $e) {
            error_log('[PlatformAccountService] ' . $e->getMessage());
            return null;
        }
    }

    public function findByLegacySuperAdmin(int $superAdminId): ?array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM platform_accounts WHERE legacy_super_admin_id = ? LIMIT 1");
            $stmt->execute([$superAdminId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\PDOException $e) {
            return null;
        }
    }

    public function disableAccount(int $id): bool { return $this->setStatus($id, 'inactive'); }
    public function archiveAccount(int $id): bool { return $this->setStatus($id, 'archived'); }

    public function lockAccount(int $id, ?string $until = null): bool
    {
        return $this->pdo->prepare("UPDATE platform_accounts SET status = 'locked', locked_until = ? WHERE id = ?")
            ->execute([$until, $id]);
    }

    private function setStatus(int $id, string $status): bool
    {
        return $this->pdo->prepare("UPDATE platform_accounts SET status = ? WHERE id = ?")->execute([$status, $id]);
    }
}
