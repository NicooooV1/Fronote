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

    /**
     * Vérification bcrypt factice (constant-time) exécutée quand AUCUN compte ne
     * correspond, pour que le temps de réponse soit comparable à celui d'un mot de
     * passe erroné sur un compte existant. Empêche l'énumération de comptes par canal
     * temporel. Mutualisée par les logins plateforme ET établissement (identifiant inconnu).
     */
    public static function dummyVerify(): void
    {
        // Hash bcrypt valide de MÊME coût (10) que les mots de passe réellement stockés
        // dans platform_accounts → password_verify consomme le même temps CPU qu'une
        // vérification sur compte existant. Un coût différent (ex. 12) rendrait le login
        // d'un compte inconnu ~4× plus lent, rouvrant un oracle temporel d'énumération.
        password_verify('dummy_password', '$2y$10$dxC/SO/ZG/0EfkH.q8WwHe58dLnQ4MIiHwY3okP9zP9GLBxmTLLfC');
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
