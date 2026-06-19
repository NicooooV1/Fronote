<?php
declare(strict_types=1);

namespace API\Services;

use PDO;

/**
 * Manages super-administrators who operate across all establishments.
 */
class SuperAdminService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Authenticate a super-admin by login + password.
     * Returns the user array or null.
     */
    public function authenticate(string $login, string $password): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM super_admins
            WHERE (identifiant = ? OR mail = ?) AND actif = 1
            LIMIT 1
        ");
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['mot_de_passe'])) {
            return null;
        }

        // Update last_login
        $this->pdo->prepare("UPDATE super_admins SET last_login = NOW() WHERE id = ?")
            ->execute([$user['id']]);

        unset($user['mot_de_passe']);
        $user['type'] = 'super_admin';
        return $user;
    }

    /**
     * Get all super-admins.
     */
    public function getAll(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, nom, prenom, mail, identifiant, actif, date_creation, last_login
            FROM super_admins ORDER BY nom, prenom
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a super-admin by ID.
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, nom, prenom, mail, identifiant, actif, date_creation, last_login
            FROM super_admins WHERE id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Create a new super-admin.
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO super_admins (nom, prenom, mail, identifiant, mot_de_passe)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['nom'],
            $data['prenom'],
            $data['mail'],
            $data['identifiant'],
            \API\Security\PasswordPolicy::hash($data['mot_de_passe']),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update a super-admin.
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $values = [];

        foreach (['nom', 'prenom', 'mail', 'identifiant', 'actif'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "`{$field}` = ?";
                $values[] = $data[$field];
            }
        }

        if (isset($data['mot_de_passe']) && $data['mot_de_passe'] !== '') {
            $fields[] = '`mot_de_passe` = ?';
            $values[] = \API\Security\PasswordPolicy::hash($data['mot_de_passe']);
        }

        if (empty($fields)) {
            return false;
        }

        $values[] = $id;
        $stmt = $this->pdo->prepare("UPDATE super_admins SET " . implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($values);
    }

    /**
     * Delete a super-admin (soft: deactivate).
     */
    public function deactivate(int $id): bool
    {
        $stmt = $this->pdo->prepare("UPDATE super_admins SET actif = 0 WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * List all establishments (cross-establishment view).
     */
    public function getEstablishments(): array
    {
        $stmt = $this->pdo->query("
            SELECT e.*,
                   (SELECT COUNT(*) FROM administrateurs a WHERE a.etablissement_id = e.id) AS admin_count,
                   (SELECT COUNT(*) FROM eleves el WHERE el.etablissement_id = e.id) AS student_count,
                   (SELECT COUNT(*) FROM professeurs p WHERE p.etablissement_id = e.id) AS teacher_count
            FROM etablissements e
            ORDER BY e.nom
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Purge TOTALE d'un établissement (réservé super_admin ; appelé par purge.php après
     * confirmation forte + sauvegarde obligatoire). Supprime, en transaction, toutes les
     * lignes scopées `etablissement_id` de TOUTES les tables qui possèdent cette colonne,
     * puis l'établissement lui-même. Découverte des tables via information_schema (pas de
     * liste codée en dur). Refuse de purger le dernier établissement.
     *
     * @return array{establishment:int, tables:array<string,int>}
     */
    public function purgeEstablishment(int $id): array
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('Identifiant d\'établissement invalide.');
        }
        $total = (int) $this->pdo->query("SELECT COUNT(*) FROM etablissements")->fetchColumn();
        if ($total <= 1) {
            throw new \RuntimeException('Impossible de purger le dernier établissement.');
        }

        $db = (string) $this->pdo->query("SELECT DATABASE()")->fetchColumn();
        $stmt = $this->pdo->prepare(
            "SELECT TABLE_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'etablissement_id'"
        );
        $stmt->execute([$db]);
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $deleted = [];
        $this->pdo->beginTransaction();
        try {
            // Désactive les contraintes le temps de la purge pour s'affranchir de l'ordre des FK.
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach ($tables as $t) {
                if ($t === 'etablissements') {
                    continue;
                }
                // $t provient d'information_schema (pas d'entrée utilisateur) → interpolation sûre.
                $d = $this->pdo->prepare("DELETE FROM `{$t}` WHERE etablissement_id = ?");
                $d->execute([$id]);
                if ($d->rowCount() > 0) {
                    $deleted[$t] = $d->rowCount();
                }
            }
            $this->pdo->prepare("DELETE FROM etablissements WHERE id = ?")->execute([$id]);
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            try { $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (\Throwable $e2) { error_log('[SuperAdminService.php] ' . $e2->getMessage()); }
            throw $e;
        }

        return ['establishment' => $id, 'tables' => $deleted];
    }

    /**
     * Check if a user is a super-admin (by session).
     */
    public static function isSuperAdmin(): bool
    {
        return ($_SESSION['user_type'] ?? '') === 'super_admin';
    }
}
