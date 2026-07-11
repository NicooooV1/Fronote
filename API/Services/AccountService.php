<?php
declare(strict_types=1);

namespace API\Services;

use PDO;

/**
 * AccountService — gestion de l'identité de connexion unifiée (table `accounts`).
 *
 * PRÉPARATION (cahier des charges §5.1, §12.1) : ces comptes ne sont PAS encore la
 * source d'authentification. Tant que FEATURE_ACCOUNTS est faux, l'app continue de
 * se connecter via les 5 tables héritées ; `accounts` n'est qu'un miroir/cible de
 * migration. Le jour du basculement, UserProvider lira cette table.
 *
 * SQL volontairement portable (pas d'ON DUPLICATE) → testable sur SQLite.
 */
final class AccountService
{
    public const TYPES = ['platform', 'personnel', 'student', 'family', 'external', 'system', 'temporary'];
    public const STATUSES = ['active', 'inactive', 'pending', 'locked', 'archived', 'deleted'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Le système de comptes unifiés est-il activé ? (flag d'environnement, défaut: non). */
    public static function isEnabled(): bool
    {
        $v = getenv('FEATURE_ACCOUNTS');
        if ($v === false || $v === '') {
            $v = $_ENV['FEATURE_ACCOUNTS'] ?? $_SERVER['FEATURE_ACCOUNTS'] ?? '';
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Crée un compte. $data : account_type (requis), username (requis), email, password_hash,
     * first_name, last_name, display_name, phone, status, etablissement_id, legacy_type,
     * legacy_id, must_change_password, created_by.
     * @throws \RuntimeException si le type est invalide ou le username manquant.
     */
    public function createAccount(array $data): int
    {
        $type = (string) ($data['account_type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            throw new \RuntimeException("Type de compte invalide : « {$type} ».");
        }
        $username = trim((string) ($data['username'] ?? ''));
        if ($username === '') {
            throw new \RuntimeException('Identifiant (username) obligatoire.');
        }
        $status = (string) ($data['status'] ?? 'pending');
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'pending';
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO accounts
                (account_type, username, email, password_hash, first_name, last_name, display_name,
                 phone, status, etablissement_id, legacy_type, legacy_id, must_change_password, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $type, $username,
            $data['email'] ?? null,
            $data['password_hash'] ?? null,
            $data['first_name'] ?? null,
            $data['last_name'] ?? null,
            $data['display_name'] ?? null,
            $data['phone'] ?? null,
            $status,
            isset($data['etablissement_id']) && $data['etablissement_id'] !== '' ? (int) $data['etablissement_id'] : null,
            $data['legacy_type'] ?? null,
            isset($data['legacy_id']) ? (int) $data['legacy_id'] : null,
            isset($data['must_change_password']) ? (int) (bool) $data['must_change_password'] : 1,
            isset($data['created_by']) ? (int) $data['created_by'] : null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Met à jour des champs autorisés d'un compte. */
    public function updateAccount(int $id, array $data): bool
    {
        $allowed = ['email', 'first_name', 'last_name', 'display_name', 'phone', 'status', 'etablissement_id'];
        $sets = [];
        $args = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                if ($col === 'status' && !in_array($data[$col], self::STATUSES, true)) {
                    continue;
                }
                $sets[] = "`{$col}` = ?";
                $args[] = $data[$col] === '' ? null : $data[$col];
            }
        }
        if ($sets === []) {
            return false;
        }
        $args[] = $id;
        return $this->pdo->prepare("UPDATE accounts SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
    }

    /**
     * Synchronise le hash de mot de passe du compte unifié correspondant à une ligne
     * héritée. Appelé à CHAQUE changement de mot de passe legacy pour garder
     * accounts.password_hash à jour — prérequis du basculement COMPLET (auth vérifiée
     * directement contre accounts). No-op si la table/le compte n'existe pas (le miroir
     * reste simplement absent ; le login retombe alors sur la table héritée).
     */
    public function syncPassword(string $legacyType, int $legacyId, string $hash): bool
    {
        if ($legacyType === '' || $legacyId <= 0 || $hash === '') {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE accounts SET password_hash = ?, must_change_password = 0
                  WHERE legacy_type = ? AND legacy_id = ?"
            );
            $stmt->execute([$hash, $legacyType, $legacyId]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            return false;
        }
    }

    public function disableAccount(int $id, string $reason = ''): bool
    {
        return $this->setStatus($id, 'inactive');
    }

    public function lockAccount(int $id, ?string $until = null): bool
    {
        return $this->pdo->prepare("UPDATE accounts SET status = 'locked', locked_until = ? WHERE id = ?")
            ->execute([$until, $id]);
    }

    public function archiveAccount(int $id): bool
    {
        return $this->setStatus($id, 'archived');
    }

    /** Compte correspondant à une ligne héritée (pour la migration et le shim de compat). */
    public function findByLegacy(string $legacyType, int $legacyId): ?array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM accounts WHERE legacy_type = ? AND legacy_id = ? LIMIT 1");
            $stmt->execute([$legacyType, $legacyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\PDOException $e) {
            return null;
        }
    }

    /**
     * Mapping table héritée → [account_type unifié, legacy_type CANONIQUE].
     * legacy_type = le TYPE d'utilisateur (eleve/parent/…), PAS le nom de table — c'est ce
     * qu'attend la voie d'auth (UserProvider::loadLegacyForAuth → getTableForUserType) et ce que
     * stocke la session (user_type). Cohérent avec retrieveById($id, $userType).
     */
    private const LEGACY_TABLES = [
        // table => [account_type, legacy_type]
        'eleves'          => ['student',   'eleve'],
        'parents'         => ['family',    'parent'],
        'professeurs'     => ['personnel', 'professeur'],
        'vie_scolaire'    => ['personnel', 'vie_scolaire'],
        'administrateurs' => ['personnel', 'administrateur'],
    ];

    /**
     * Peuple/rafraîchit la table `accounts` comme MIROIR (lecture) des 5 tables d'auth héritées.
     * Idempotent (upsert sur uk_acc_legacy = legacy_type+legacy_id). N'altère PAS l'authentification :
     * tant que FEATURE_ACCOUNTS est faux, l'app lit toujours les tables héritées. Ceci est le
     * premier pas SÛR de l'unification d'identité : `accounts` devient une image complète et courante
     * (id/identifiant/mail/hash/statut/2FA), sans rien retirer. Les tables héritées restent la source
     * de DONNÉES (classe, matière…) et de vérité tant que la bascule n'est pas validée.
     *
     * @return array{synced:int,errors:string[]}
     */
    public function syncFromLegacy(): array
    {
        $synced = 0;
        $errors = [];
        $sql = "INSERT INTO accounts
                    (account_type, username, email, password_hash, first_name, last_name, display_name,
                     status, etablissement_id, legacy_type, legacy_id, two_factor_enabled,
                     last_login_at, locked_until, must_change_password)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)
                ON DUPLICATE KEY UPDATE
                    account_type=VALUES(account_type), username=VALUES(username), email=VALUES(email),
                    password_hash=VALUES(password_hash), first_name=VALUES(first_name),
                    last_name=VALUES(last_name), display_name=VALUES(display_name), status=VALUES(status),
                    etablissement_id=VALUES(etablissement_id), two_factor_enabled=VALUES(two_factor_enabled),
                    last_login_at=VALUES(last_login_at), locked_until=VALUES(locked_until), updated_at=NOW()";
        $ins = $this->pdo->prepare($sql);
        foreach (self::LEGACY_TABLES as $table => [$accType, $legacyType]) {
            try {
                $rows = $this->pdo->query(
                    "SELECT id, etablissement_id, nom, prenom, mail, identifiant, mot_de_passe, actif,
                            last_login, locked_until, two_factor_enabled FROM `{$table}`"
                )->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $display = trim(((string) ($r['prenom'] ?? '')) . ' ' . ((string) ($r['nom'] ?? '')));
                    $ins->execute([
                        $accType,
                        (string) ($r['identifiant'] ?? ''),
                        ($r['mail'] ?? '') !== '' ? $r['mail'] : null,
                        $r['mot_de_passe'] ?? null,
                        $r['prenom'] ?? null,
                        $r['nom'] ?? null,
                        $display !== '' ? $display : null,
                        ((int) ($r['actif'] ?? 1) === 1) ? 'active' : 'inactive',
                        $r['etablissement_id'] ?? null,
                        $legacyType,
                        (int) $r['id'],
                        (int) ($r['two_factor_enabled'] ?? 0),
                        $r['last_login'] ?? null,
                        $r['locked_until'] ?? null,
                    ]);
                    $synced++;
                }
            } catch (\Throwable $e) {
                $errors[] = "{$table}: " . $e->getMessage();
            }
        }
        return ['synced' => $synced, 'errors' => $errors];
    }

    private function setStatus(int $id, string $status): bool
    {
        return $this->pdo->prepare("UPDATE accounts SET status = ? WHERE id = ?")->execute([$status, $id]);
    }
}
