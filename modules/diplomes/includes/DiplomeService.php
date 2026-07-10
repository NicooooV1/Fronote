<?php
declare(strict_types=1);
/**
 * M44 – Diplômes & Relevés — Service
 */
class DiplomeService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /* ───── DIPLÔMES ───── */

    public function getDiplomes(array $filters = []): array
    {
        $sql = "SELECT d.*, CONCAT(e.prenom, ' ', e.nom) AS eleve_nom, c.nom AS classe_nom
                FROM diplomes d
                JOIN eleves e ON d.eleve_id = e.id
                LEFT JOIN classes c ON e.classe = c.nom
                WHERE e.etablissement_id = ?";
        $params = [\API\Core\EstablishmentContext::id()];
        if (!empty($filters['type'])) { $sql .= ' AND d.type = ?'; $params[] = $filters['type']; }
        if (!empty($filters['eleve_id'])) { $sql .= ' AND d.eleve_id = ?'; $params[] = $filters['eleve_id']; }
        if (!empty($filters['mention'])) { $sql .= ' AND d.mention = ?'; $params[] = $filters['mention']; }
        if (!empty($filters['annee'])) { $sql .= ' AND YEAR(d.date_obtention) = ?'; $params[] = $filters['annee']; }
        $sql .= ' ORDER BY d.date_obtention DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getDiplome(int $id): ?array
    {
        // Cloisonnement établissement (anti-IDOR cross-tenant) : le diplôme n'est
        // renvoyé que si l'élève rattaché appartient à l'établissement courant.
        $stmt = $this->pdo->prepare("SELECT d.*, CONCAT(e.prenom, ' ', e.nom) AS eleve_nom, c.nom AS classe_nom
                FROM diplomes d JOIN eleves e ON d.eleve_id = e.id LEFT JOIN classes c ON e.classe = c.nom
                WHERE d.id = ? AND e.etablissement_id = ?");
        $stmt->execute([$id, \API\Core\EstablishmentContext::id()]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function creerDiplome(array $d): int
    {
        // Cloisonnement multi-tenant : l'élève ciblé doit appartenir à l'établissement courant
        // (diplomes n'a pas de colonne etablissement_id → scope via eleves).
        $etab = \API\Core\EstablishmentContext::id();
        $chk = $this->pdo->prepare("SELECT 1 FROM eleves WHERE id = ? AND etablissement_id = ? LIMIT 1");
        $chk->execute([(int) $d['eleve_id'], $etab]);
        if (!$chk->fetchColumn()) return 0;
        $numero = strtoupper(substr($d['type'], 0, 3)) . '-' . date('Y') . '-' . str_pad((string) mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
        $stmt = $this->pdo->prepare("INSERT INTO diplomes (eleve_id, intitule, type, mention, date_obtention, numero_diplome, fichier_path, description)
                VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$d['eleve_id'], $d['intitule'], $d['type'], $d['mention'] ?? null,
                        $d['date_obtention'], $numero, $d['fichier_path'] ?? null, $d['description'] ?? null]);
        return (int) $this->pdo->lastInsertId();
    }

    public function modifierDiplome(int $id, array $d): void
    {
        // Anti-IDOR : ne modifier qu'un diplôme d'un élève de l'établissement courant.
        $stmt = $this->pdo->prepare("UPDATE diplomes SET intitule=?, type=?, mention=?, date_obtention=?, description=?
            WHERE id=? AND eleve_id IN (SELECT id FROM eleves WHERE etablissement_id = ?)");
        $stmt->execute([$d['intitule'], $d['type'], $d['mention'] ?? null, $d['date_obtention'], $d['description'] ?? null, $id, \API\Core\EstablishmentContext::id()]);
    }

    public function supprimerDiplome(int $id): void
    {
        $this->pdo->prepare("DELETE FROM diplomes WHERE id = ? AND eleve_id IN (SELECT id FROM eleves WHERE etablissement_id = ?)")
            ->execute([$id, \API\Core\EstablishmentContext::id()]);
    }

    /* ───── ACCÈS PAR RÔLE ───── */

    public function getMesDiplomes(int $eleveId): array
    {
        return $this->getDiplomes(['eleve_id' => $eleveId]);
    }

    public function getDiplomesParent(int $parentId): array
    {
        $stmt = $this->pdo->prepare("SELECT d.*, CONCAT(e.prenom, ' ', e.nom) AS eleve_nom
            FROM diplomes d
            JOIN eleves e ON d.eleve_id = e.id
            JOIN parent_eleve pe ON e.id = pe.id_eleve
            WHERE pe.id_parent = ?
            ORDER BY d.date_obtention DESC");
        $stmt->execute([$parentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ───── HELPERS ───── */

    public function getEleves(): array
    {
        $s = $this->pdo->prepare("SELECT e.id, e.prenom, e.nom, c.nom AS classe_nom FROM eleves e LEFT JOIN classes c ON e.classe = c.nom WHERE e.etablissement_id = ? ORDER BY e.nom");
        $s->execute([\API\Core\EstablishmentContext::id()]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStats(): array
    {
        // Cloisonnement multi-tenant : les compteurs ne portent que sur l'établissement courant.
        $etab = \API\Core\EstablishmentContext::id();
        $t = $this->pdo->prepare("SELECT COUNT(*) FROM diplomes WHERE etablissement_id = ?");
        $t->execute([$etab]);
        $total = $t->fetchColumn();
        $a = $this->pdo->prepare("SELECT COUNT(*) FROM diplomes WHERE etablissement_id = ? AND YEAR(date_obtention) = YEAR(CURDATE())");
        $a->execute([$etab]);
        $annee = $a->fetchColumn();
        return ['total' => $total, 'annee_courante' => $annee];
    }

    /* ───── STATISTICS ───── */

    /**
     * Get success rates by diploma type and year.
     */
    public function getTauxReussite(?int $annee = null): array
    {
        $sql = "SELECT type,
                       COUNT(*) AS total,
                       SUM(CASE WHEN mention IS NOT NULL AND mention != 'sans' THEN 1 ELSE 0 END) AS avec_mention,
                       YEAR(date_obtention) AS annee
                FROM diplomes WHERE etablissement_id = ?";
        // Cloisonnement multi-tenant : agrégats limités à l'établissement courant.
        $params = [\API\Core\EstablishmentContext::id()];
        if ($annee) { $sql .= ' AND YEAR(date_obtention) = ?'; $params[] = $annee; }
        $sql .= ' GROUP BY type, YEAR(date_obtention) ORDER BY annee DESC, type';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get mention distribution for a diploma type.
     */
    public function getRepartitionMentions(string $type, ?int $annee = null): array
    {
        // Cloisonnement multi-tenant : répartition limitée à l'établissement courant.
        $sql = "SELECT mention, COUNT(*) AS total FROM diplomes WHERE etablissement_id = ? AND type = ?";
        $params = [\API\Core\EstablishmentContext::id(), $type];
        if ($annee) { $sql .= ' AND YEAR(date_obtention) = ?'; $params[] = $annee; }
        $sql .= ' GROUP BY mention ORDER BY total DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ───── STATIC ───── */

    public static function typesDiplome(): array
    {
        return [
            'brevet' => 'Brevet des collèges', 'bac' => 'Baccalauréat', 'cap' => 'CAP',
            'bep' => 'BEP', 'bts' => 'BTS', 'licence' => 'Licence',
            'attestation' => 'Attestation', 'certificat' => 'Certificat', 'autre' => 'Autre'
        ];
    }

    public static function mentions(): array
    {
        return [
            'sans' => 'Sans mention', 'ab' => 'Assez bien', 'bien' => 'Bien',
            'tb' => 'Très bien', 'felicitations' => 'Félicitations du jury'
        ];
    }

    public static function badgeMention(?string $m): string
    {
        if (!$m) return '';
        $c = ['sans' => 'secondary', 'ab' => 'info', 'bien' => 'primary', 'tb' => 'success', 'felicitations' => 'warning'];
        $labels = self::mentions();
        return '<span class="badge badge-' . ($c[$m] ?? 'secondary') . '">' . ($labels[$m] ?? $m) . '</span>';
    }

    /* ───── EXPORT ───── */

    public function getDiplomesForExport(array $filters = []): array
    {
        $diplomes = $this->getDiplomes($filters);
        $types = self::typesDiplome();
        $mentions = self::mentions();
        return array_map(fn($d) => [
            $d['numero_diplome'] ?? $d['numero'] ?? '-',
            $d['eleve_nom'],
            $d['classe_nom'] ?? '-',
            $d['intitule'],
            $types[$d['type']] ?? $d['type'],
            $mentions[$d['mention']] ?? ($d['mention'] ?? '-'),
            $d['date_obtention'],
        ], $diplomes);
    }

    // ─── DIPLOME NUMÉRIQUE QR VÉRIFIABLE ───

    public function genererDiplomeVerifiable(int $diplomeId): string
    {
        $token = bin2hex(random_bytes(16));
        $this->pdo->prepare("UPDATE diplomes SET verification_token = :t WHERE id = :id")
            ->execute([':t' => $token, ':id' => $diplomeId]);
        return $token;
    }

    public function verifierDiplome(string $token): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT d.*, CONCAT(e.prenom, ' ', e.nom) AS eleve_nom, c.nom AS classe_nom
            FROM diplomes d
            JOIN eleves e ON d.eleve_id = e.id
            LEFT JOIN classes c ON e.classe = c.nom
            WHERE d.verification_token = :t
        ");
        $stmt->execute([':t' => $token]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    // ─── GÉNÉRATION PAR LOT ───

    public function genererEnMasse(string $classe, string $type, string $intitule, ?string $mention = null): int
    {
        $stmt = $this->pdo->prepare("
            SELECT e.id FROM eleves e
            JOIN classes c ON e.classe = c.nom
            WHERE c.nom = :c AND e.etablissement_id = :etab AND e.id NOT IN (
                SELECT eleve_id FROM diplomes WHERE type = :t AND YEAR(date_obtention) = YEAR(CURDATE())
            )
        ");
        $stmt->execute([':c' => $classe, ':t' => $type, ':etab' => \API\Core\EstablishmentContext::id()]);
        $eleves = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $maxNum = (int)$this->pdo->query("SELECT COALESCE(MAX(numero_registre), 0) FROM diplomes WHERE YEAR(date_obtention) = YEAR(CURDATE())")->fetchColumn();
        $count = 0;
        $ins = $this->pdo->prepare("
            INSERT INTO diplomes (eleve_id, type, intitule, mention, date_obtention, numero_registre, registre_annee)
            VALUES (:eid, :t, :i, :m, CURDATE(), :nr, YEAR(CURDATE()))
        ");

        foreach ($eleves as $eleveId) {
            $maxNum++;
            $ins->execute([':eid' => $eleveId, ':t' => $type, ':i' => $intitule, ':m' => $mention, ':nr' => $maxNum]);
            $count++;
        }
        return $count;
    }

    // ─── REGISTRE OFFICIEL ───

    public function getRegistreOfficiel(?int $annee = null): array
    {
        $sql = "SELECT d.*, CONCAT(e.prenom, ' ', e.nom) AS eleve_nom, c.nom AS classe_nom
                FROM diplomes d
                JOIN eleves e ON d.eleve_id = e.id
                LEFT JOIN classes c ON e.classe = c.nom
                WHERE d.numero_registre IS NOT NULL";
        $params = [];
        if ($annee) { $sql .= " AND d.registre_annee = :a"; $params[':a'] = $annee; }
        $sql .= " ORDER BY d.registre_annee DESC, d.numero_registre ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ─── SUIVI TÉLÉCHARGEMENTS ───

    public function enregistrerTelechargement(int $diplomeId, int $userId, string $userType): void
    {
        $this->pdo->prepare("INSERT INTO diplomes_telechargements (diplome_id, user_id, user_type, downloaded_at) VALUES (:d, :u, :ut, NOW())")
            ->execute([':d' => $diplomeId, ':u' => $userId, ':ut' => $userType]);
    }

    public function getTelechargements(int $diplomeId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM diplomes_telechargements WHERE diplome_id = :d ORDER BY downloaded_at DESC");
        $stmt->execute([':d' => $diplomeId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getNbTelechargements(int $diplomeId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM diplomes_telechargements WHERE diplome_id = ?");
        $stmt->execute([$diplomeId]);
        return (int)$stmt->fetchColumn();
    }
}
