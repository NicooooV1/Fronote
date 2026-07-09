<?php
declare(strict_types=1);
/**
 * M15 – Trombinoscope — Service
 */

class TrombinoscopeService {
    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /**
     * Récupère la liste des classes
     */
    public function getClasses(): array {
        $s = $this->pdo->prepare("SELECT * FROM classes WHERE etablissement_id = ? ORDER BY niveau, nom");
        $s->execute([\API\Core\EstablishmentContext::id()]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Récupère les élèves d'une classe avec leur info
     */
    public function getElevesClasse(int $classeId): array {
        $stmt = $this->pdo->prepare("
            SELECT e.id, e.nom, e.prenom, e.date_naissance, e.mail AS email, NULL AS genre,
                   c.nom AS classe_nom, c.niveau AS classe_niveau
            FROM eleves e
            JOIN classes c ON e.classe = c.nom
            WHERE c.id = ? AND e.etablissement_id = ?
            ORDER BY e.nom, e.prenom
        ");
        $stmt->execute([$classeId, \API\Core\EstablishmentContext::id()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Récupère les professeurs (optionnel : filtrage par matière)
     */
    public function getProfesseurs(?int $matiereId = null): array {
        $sql = "
            SELECT p.id, p.nom, p.prenom, p.mail AS email, p.matiere AS specialite,
                   m.nom AS matiere_nom
            FROM professeurs p
            LEFT JOIN matieres m ON p.matiere = m.nom
        ";
        $sql .= " WHERE p.etablissement_id = ?";
        $params = [\API\Core\EstablishmentContext::id()];
        if ($matiereId) {
            $sql .= " AND m.id = ?";
            $params[] = $matiereId;
        }
        $sql .= " ORDER BY p.nom, p.prenom";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Récupère les matières
     */
    public function getMatieres(): array {
        $s = $this->pdo->prepare("SELECT * FROM matieres WHERE etablissement_id = ? ORDER BY nom");
        $s->execute([\API\Core\EstablishmentContext::id()]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Recherche globale élèves + profs
     */
    public function rechercher(string $q): array {
        $like = '%' . $q . '%';
        $etab = \API\Core\EstablishmentContext::id();
        $stmt = $this->pdo->prepare("
            SELECT 'eleve' AS type, e.id, e.nom, e.prenom, c.nom AS detail
            FROM eleves e LEFT JOIN classes c ON e.classe = c.nom
            WHERE (e.nom LIKE ? OR e.prenom LIKE ?) AND e.etablissement_id = ?
            UNION ALL
            SELECT 'professeur' AS type, p.id, p.nom, p.prenom, m.nom AS detail
            FROM professeurs p LEFT JOIN matieres m ON p.matiere = m.nom
            WHERE (p.nom LIKE ? OR p.prenom LIKE ?) AND p.etablissement_id = ?
            ORDER BY nom, prenom
            LIMIT 50
        ");
        $stmt->execute([$like, $like, $etab, $like, $like, $etab]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Récupère le personnel vie scolaire
     */
    public function getVieScolaire(): array {
        $s = $this->pdo->prepare("SELECT id, nom, prenom, mail AS email FROM vie_scolaire WHERE etablissement_id = ? ORDER BY nom, prenom");
        $s->execute([\API\Core\EstablishmentContext::id()]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Statistiques globales
     */
    public function getStats(): array {
        $etab = \API\Core\EstablishmentContext::id();
        $q = function (string $sql) use ($etab) {
            $st = $this->pdo->prepare($sql);
            $st->execute([$etab]);
            return (int) $st->fetchColumn();
        };
        $eleves = $q("SELECT COUNT(*) FROM eleves WHERE etablissement_id = ?");
        $profs = $q("SELECT COUNT(*) FROM professeurs WHERE etablissement_id = ?");
        $classes = $q("SELECT COUNT(*) FROM classes WHERE etablissement_id = ?");
        return compact('eleves', 'profs', 'classes');
    }

    /**
     * Avatar initiales
     */
    public static function initials(string $prenom, string $nom): string {
        return mb_strtoupper(mb_substr($prenom, 0, 1) . mb_substr($nom, 0, 1));
    }

    /**
     * Couleur pseudo-aléatoire basée sur le nom
     */
    public static function avatarColor(string $nom): string {
        $colors = ['#4f46e5','#0891b2','#059669','#d97706','#dc2626','#7c3aed','#db2777','#2563eb','#0d9488','#ca8a04'];
        return $colors[crc32($nom) % count($colors)];
    }

    /* ==================== PHOTO CONSENT (RGPD) ==================== */

    /**
     * Check if a student has photo consent.
     */
    public function hasPhotoConsent(int $eleveId): bool
    {
        $stmt = $this->pdo->prepare("SELECT photo_consent FROM eleves WHERE id = ? AND etablissement_id = ?");
        $stmt->execute([$eleveId, \API\Core\EstablishmentContext::id()]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Set photo consent for a student.
     */
    public function setPhotoConsent(int $eleveId, bool $consent): void
    {
        $this->pdo->prepare("UPDATE eleves SET photo_consent = ? WHERE id = ? AND etablissement_id = ?")
                   ->execute([$consent ? 1 : 0, $eleveId, \API\Core\EstablishmentContext::id()]);
    }

    /**
     * Get students with/without photo consent for a class.
     */
    public function getConsentStatus(int $classeId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT e.id, e.prenom, e.nom, COALESCE(e.photo_consent, 0) AS photo_consent
            FROM eleves e JOIN classes c ON e.classe = c.nom WHERE c.id = ? AND e.etablissement_id = ?
            ORDER BY e.nom, e.prenom
        ");
        $stmt->execute([$classeId, \API\Core\EstablishmentContext::id()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get students filtered by photo consent (only those with consent for PDF export).
     */
    public function getElevesAvecConsent(int $classeId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT e.id, e.prenom, e.nom, e.date_naissance, c.nom AS classe_nom
            FROM eleves e
            JOIN classes c ON e.classe = c.nom
            WHERE c.id = ? AND e.photo_consent = 1 AND e.etablissement_id = ?
            ORDER BY e.nom, e.prenom
        ");
        $stmt->execute([$classeId, \API\Core\EstablishmentContext::id()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ==================== EXPORT ==================== */

    public function getElevesForExport(?int $classeId = null): array
    {
        if ($classeId) {
            $rows = $this->getElevesClasse($classeId);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT e.id, e.nom, e.prenom, e.date_naissance, e.mail AS email, NULL AS genre,
                       c.nom AS classe_nom, c.niveau AS classe_niveau
                FROM eleves e
                LEFT JOIN classes c ON e.classe = c.nom
                WHERE e.etablissement_id = ?
                ORDER BY c.nom, e.nom, e.prenom
            ");
            $stmt->execute([\API\Core\EstablishmentContext::id()]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return array_map(fn($e) => [
            $e['nom'],
            $e['prenom'],
            $e['classe_nom'] ?? '-',
            $e['classe_niveau'] ?? '-',
            $e['date_naissance'] ?? '-',
            $e['email'] ?? '-',
            ucfirst($e['genre'] ?? '-'),
        ], $rows);
    }

    public function getProfesseursForExport(?int $matiereId = null): array
    {
        $profs = $this->getProfesseurs($matiereId);
        return array_map(fn($p) => [
            $p['nom'],
            $p['prenom'],
            $p['email'] ?? '-',
            $p['specialite'] ?? '-',
            $p['matiere_nom'] ?? '-',
        ], $profs);
    }

    // ─── RECHERCHE FILTRÉE ───

    public function search(string $query, array $filters = []): array
    {
        $sql = "SELECT id, nom, prenom, classe FROM eleves WHERE actif = 1 AND etablissement_id = :etab AND (nom LIKE :q OR prenom LIKE :q2)";
        $params = [':etab' => \API\Core\EstablishmentContext::id(), ':q' => "%{$query}%", ':q2' => "%{$query}%"];
        if (!empty($filters['classe'])) { $sql .= " AND classe = :c"; $params[':c'] = $filters['classe']; }
        if (!empty($filters['niveau'])) { $sql .= " AND niveau = :n"; $params[':n'] = $filters['niveau']; }
        $sql .= " ORDER BY nom, prenom LIMIT 50";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ─── PDF TROMBINOSCOPE ───

    public function genererTrombData(string $classe): array
    {
        $stmt = $this->pdo->prepare("SELECT id, nom, prenom FROM eleves WHERE classe = :c AND actif = 1 AND etablissement_id = :etab ORDER BY nom, prenom");
        $stmt->execute([':c' => $classe, ':etab' => \API\Core\EstablishmentContext::id()]);
        return ['classe' => $classe, 'eleves' => $stmt->fetchAll(\PDO::FETCH_ASSOC), 'date' => date('Y-m-d')];
    }

    // ─── BADGES QR ───

    public function genererBadgesData(string $classe): array
    {
        $stmt = $this->pdo->prepare("SELECT id, nom, prenom, classe FROM eleves WHERE classe = :c AND actif = 1 AND etablissement_id = :etab ORDER BY nom, prenom");
        $stmt->execute([':c' => $classe, ':etab' => \API\Core\EstablishmentContext::id()]);
        $eleves = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($eleves as &$e) {
            $e['qr_data'] = 'ELEVE-' . str_pad((string)$e['id'], 6, '0', STR_PAD_LEFT);
        }

        return ['classe' => $classe, 'eleves' => $eleves];
    }
}
